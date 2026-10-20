<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Profile;
use App\Upstream\UpstreamClient;
use App\Upstream\UpstreamTimeout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpstreamClientTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): string
    {
        return trim(file_get_contents(base_path("tests/Fixtures/upstream/{$name}.json")));
    }

    public function test_fake_driver_calls_the_fake_server_url_and_returns_the_raw_body(): void
    {
        config(['upstream.drivers.fake.base_url' => 'http://upstream.test/fake-upstream']);
        Http::fake(['*' => Http::response($this->fixture('new_format'), 200)]);
        $profile = Profile::factory()
            ->for(Account::factory()->create(['name' => 'agency-a']))
            ->create(['username' => 'madison420ivy']);

        $response = app(UpstreamClient::class)->fetchProfile($profile);

        $this->assertSame(200, $response->status);
        $this->assertSame(['profile' => ['likes' => 121000], 'revision' => 11], $response->json());
        $this->assertGreaterThanOrEqual(0, $response->durationMs);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://upstream.test/fake-upstream/agency-a/profiles/madison420ivy'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_fansapi_driver_uses_bearer_token_and_profile_endpoint(): void
    {
        config(['upstream.drivers.fansapi.token' => 'test-key-123', 'upstream.drivers.fansapi.base_url' => 'https://app.onlyfansapi.com/api']);
        Http::fake(['*' => Http::response('{"data":{"username":"madison420ivy"}}', 200)]);
        $profile = Profile::factory()
            ->for(Account::factory()->fansApi()->create())
            ->create(['username' => 'madison420ivy']);

        app(UpstreamClient::class)->fetchProfile($profile);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://app.onlyfansapi.com/api/profiles/madison420ivy'
            && $request->header('Authorization') === ['Bearer test-key-123']
            && $request->header('Accept') === ['application/json']);
    }

    public function test_429_without_retry_after_and_500_with_empty_body_are_returned_not_thrown(): void
    {
        Http::fakeSequence()->push('', 429)->push('', 500);
        $profile = Profile::factory()->create();

        $rateLimited = app(UpstreamClient::class)->fetchProfile($profile);
        $serverError = app(UpstreamClient::class)->fetchProfile($profile);

        $this->assertSame(429, $rateLimited->status);
        $this->assertNull($rateLimited->retryAfter);
        $this->assertSame(500, $serverError->status);
        $this->assertSame('', $serverError->body);
        $this->assertNull($serverError->json());
    }

    public function test_retry_after_header_is_captured_when_present(): void
    {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '17'])]);

        $response = app(UpstreamClient::class)->fetchProfile(Profile::factory()->create());

        $this->assertSame('17', $response->retryAfter);
    }

    public function test_connection_failure_becomes_upstream_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->expectException(UpstreamTimeout::class);

        app(UpstreamClient::class)->fetchProfile(Profile::factory()->create());
    }

    public function test_body_over_the_size_limit_is_discarded_and_not_parsed(): void
    {
        config(['upstream.max_body_bytes' => 100]);
        Http::fake(['*' => Http::response(json_encode(['likes' => 1, 'padding' => str_repeat('x', 500)]), 200)]);

        $response = app(UpstreamClient::class)->fetchProfile(Profile::factory()->create());

        $this->assertTrue($response->truncated);
        $this->assertSame(0, $response->bodyBytes());
        $this->assertNull($response->json());
    }

    public function test_request_and_response_are_logged_without_the_api_key(): void
    {
        config(['upstream.drivers.fansapi.token' => 'super-secret-key']);
        $log = $this->captureRefreshLog();
        Http::fake(['*' => Http::response('{}', 200)]);

        app(UpstreamClient::class)->fetchProfile(Profile::factory()->for(Account::factory()->fansApi())->create());

        $this->assertTrue($log->hasDebugThatContains('upstream.request'));
        $this->assertTrue($log->hasDebugThatContains('upstream.response'));
        foreach ($log->getRecords() as $record) {
            $this->assertStringNotContainsString('super-secret-key', json_encode($record->toArray()));
        }
    }
}
