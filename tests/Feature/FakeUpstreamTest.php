<?php

namespace Tests\Feature;

use App\Upstream\Fake\FakeUpstream;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Tests\TestCase;

class FakeUpstreamTest extends TestCase
{
    private FakeUpstream $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = app(FakeUpstream::class);
        $this->fake->reset();
    }

    private function hit(string $account = 'agency-a', string $username = 'madison420ivy')
    {
        return $this->get("/fake-upstream/{$account}/profiles/{$username}");
    }

    public function test_serves_scripted_steps_in_order_then_falls_back(): void
    {
        $this->fake->push('agency-a', 'madison420ivy', ['type' => 'old_format', 'likes' => 120000, 'revision' => 10]);
        $this->fake->push('agency-a', 'madison420ivy', ['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);

        $this->hit()->assertOk()->assertExactJson(['likes' => 120000, 'revision' => 10]);
        $this->hit()->assertOk()->assertExactJson(['profile' => ['likes' => 121000], 'revision' => 11]);
        $this->hit()->assertNotFound(); // script used up, nothing else configured
    }

    public function test_rate_limit_is_429_with_empty_body_and_no_retry_after(): void
    {
        $this->fake->setDefault('agency-a', '*', ['type' => 'new_format', 'likes' => 1, 'revision' => 1]);
        $this->fake->rateLimit('agency-a', 30);

        $response = $this->hit();

        $response->assertStatus(429);
        $this->assertSame('', $response->getContent());
        $this->assertFalse($response->headers->has('Retry-After'));
    }

    public function test_rate_limit_is_per_account_and_clears(): void
    {
        $this->fake->setDefault('agency-a', '*', ['type' => 'new_format', 'likes' => 1, 'revision' => 1]);
        $this->fake->setDefault('agency-b', '*', ['type' => 'new_format', 'likes' => 2, 'revision' => 1]);
        $this->fake->rateLimit('agency-a', 30);

        $this->hit('agency-a')->assertStatus(429);
        $this->hit('agency-b')->assertOk(); // the other account is unaffected

        $this->assertGreaterThan(0, Redis::ttl('fake-upstream:agency-a:*:rate_limited')); // expires on its own

        $this->fake->rateLimit('agency-a', 0);
        $this->hit('agency-a')->assertOk();
    }

    public function test_server_error_has_empty_body(): void
    {
        $this->fake->push('agency-a', 'madison420ivy', ['type' => 'server_error']);

        $response = $this->hit();

        $response->assertStatus(500);
        $this->assertSame('', $response->getContent());
    }

    public function test_profile_default_wins_over_account_default(): void
    {
        $this->fake->setDefault('agency-a', '*', ['type' => 'old_format', 'likes' => 1, 'revision' => 1]);
        $this->fake->setDefault('agency-a', 'madison420ivy', ['type' => 'new_format', 'likes' => 2, 'revision' => 2]);

        $this->hit()->assertExactJson(['profile' => ['likes' => 2], 'revision' => 2]);
        $this->hit('agency-a', 'someone_else')->assertExactJson(['likes' => 1, 'revision' => 1]);
    }

    public function test_auto_default_serves_increasing_revisions_per_profile(): void
    {
        $this->fake->setDefault('agency-b', '*', ['type' => 'new_format', 'auto' => true]);

        $this->hit('agency-b')->assertExactJson(['profile' => ['likes' => 100001], 'revision' => 1]);
        $this->hit('agency-b')->assertExactJson(['profile' => ['likes' => 100002], 'revision' => 2]);
        $this->hit('agency-b', 'other')->assertJsonPath('revision', 1);
    }

    public function test_reset_clears_everything(): void
    {
        $this->fake->push('agency-a', 'madison420ivy', ['type' => 'old_format', 'likes' => 1, 'revision' => 1]);
        $this->fake->rateLimit('agency-a', 30);

        $this->fake->reset();

        $this->hit()->assertNotFound();
    }

    public function test_rejects_unknown_step_types(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fake->push('agency-a', 'madison420ivy', ['type' => 'bogus']);
    }

    public function test_artisan_command_reports_unknown_type_cleanly(): void
    {
        $this->artisan('fake-upstream:push', ['account' => 'agency-a', 'username' => 'madison420ivy', 'type' => 'bogus'])
            ->expectsOutputToContain('Unknown fake response type [bogus]')
            ->assertFailed();
    }

    public function test_artisan_commands_drive_the_fake(): void
    {
        $this->artisan('fake-upstream:push', [
            'account' => 'agency-a', 'username' => 'madison420ivy', 'type' => 'new_format',
            '--likes' => 121000, '--revision' => 11, '--times' => 2,
        ])->assertSuccessful();

        $this->hit()->assertExactJson(['profile' => ['likes' => 121000], 'revision' => 11]);
        $this->hit()->assertExactJson(['profile' => ['likes' => 121000], 'revision' => 11]);

        $this->artisan('fake-upstream:rate-limit', ['account' => 'agency-a', 'seconds' => 30])->assertSuccessful();
        $this->hit()->assertStatus(429);

        $this->artisan('fake-upstream:reset')->assertSuccessful();
        $this->hit()->assertNotFound();
    }
}
