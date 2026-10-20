<?php

namespace Tests\Feature;

use App\Enums\RefreshOutcome;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Refresh\ProfilePayloadParser;
use App\Upstream\UpstreamResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Real FansAPI integration, driven by a captured response (tests/Fixtures/upstream/fansapi_profile_madison420ivy.json,
 * bio replaced). No test reaches the real API: phpunit.xml points FANSAPI_BASE_URL at .invalid and stray requests throw.
 */
class FansApiRefreshTest extends TestCase
{
    use RefreshDatabase;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        config(['refresh.handler' => 'fixed', 'upstream.drivers.fansapi.token' => 'test-key']);
        $this->profile = Profile::factory()
            ->for(Account::factory()->fansApi()->create(['name' => 'fansapi']))
            ->create(['username' => 'madison420ivy']);
    }

    /**
     * The captured response, optionally modified, served for the next call(s).
     */
    private function fansApiReturns(?callable $modify = null, array $headers = []): array
    {
        $body = json_decode(file_get_contents(base_path('tests/Fixtures/upstream/fansapi_profile_madison420ivy.json')), true);
        $body = $modify ? $modify($body) : $body;

        Http::fake(['*' => Http::response($body, 200, $headers)]);

        return $body;
    }

    private function refresh(): Profile
    {
        RefreshProfile::dispatchSync($this->profile->id);

        return $this->profile->refresh();
    }

    public function test_stores_the_full_profile_from_the_real_response_shape(): void
    {
        $this->fansApiReturns();

        $profile = $this->refresh();

        $this->assertSame(609771, $profile->likes);
        $this->assertSame(5140520, $profile->upstream_id);
        $this->assertSame('Madison Ivy', $profile->name);
        $this->assertSame([176, 621, 201, 0], [$profile->posts_count, $profile->photos_count, $profile->videos_count, $profile->audios_count]);
        $this->assertStringStartsWith('https://public.onlyfans.com/', $profile->avatar_url);
        $this->assertStringStartsWith('https://public.onlyfans.com/', $profile->header_url);
        $this->assertSame(Carbon::parse('2026-10-20T08:04:01+00:00')->getTimestampMs(), $profile->revision);
        $this->assertSame(RefreshOutcome::Updated, $profile->last_outcome);
        $this->assertSame('fansapi', $profile->refreshAttempts()->sole()->response_format);
        Http::assertSent(fn (Request $request) => $request->url() === 'http://fansapi.invalid/api/profiles/madison420ivy'
            && $request->header('Authorization') === ['Bearer test-key']);
    }

    public function test_same_cached_copy_twice_is_a_duplicate(): void
    {
        $this->fansApiReturns();

        $this->refresh();
        $profile = $this->refresh();

        $this->assertSame(
            [RefreshOutcome::Updated, RefreshOutcome::Duplicate],
            $profile->refreshAttempts()->orderBy('id')->pluck('outcome')->all(),
        );
    }

    public function test_older_cached_copy_arriving_after_newer_data_is_stale(): void
    {
        $older = json_decode(file_get_contents(base_path('tests/Fixtures/upstream/fansapi_profile_madison420ivy.json')), true); // cached_at 08:04
        $newer = $older;
        $newer['data']['favoritedCount'] = 610000;
        $newer['_meta']['_cache']['cached_at'] = '2026-10-20T09:00:00+00:00';

        // (a sequence: a second Http::fake() would not replace the first matching stub)
        Http::fakeSequence()->push($newer)->push($older);

        $this->refresh();
        $profile = $this->refresh(); // the 08:04 cached copy shows up after the 09:00 data

        $this->assertSame(610000, $profile->likes);
        $this->assertSame(RefreshOutcome::Stale, $profile->last_outcome);
    }

    public function test_fresh_answer_is_versioned_by_the_upstream_date_header(): void
    {
        $this->fansApiReturns(function ($body) {
            $body['_meta']['_cache'] = ['is_cached' => false, 'note' => 'Cache MISS'];

            return $body;
        }, ['Date' => 'Tue, 20 Oct 2026 10:00:00 GMT']);

        $profile = $this->refresh();

        $this->assertSame(Carbon::parse('2026-10-20T10:00:00Z')->getTimestampMs(), $profile->revision);
    }

    public function test_missing_favorited_count_is_rejected_and_keeps_stored_likes(): void
    {
        $this->profile->update(['likes' => 609000, 'revision' => 1]);
        $this->fansApiReturns(function ($body) {
            unset($body['data']['favoritedCount']);

            return $body;
        });

        $profile = $this->refresh();

        $this->assertSame(609000, $profile->likes);
        $this->assertSame(RefreshOutcome::InvalidPayload, $profile->last_outcome);
        $this->assertStringContainsString('favoritedCount', $profile->last_error);
    }

    public function test_invalid_optional_fields_are_skipped_not_blanked(): void
    {
        $this->profile->update(['name' => 'Madison Ivy', 'posts_count' => 170]);
        $this->fansApiReturns(function ($body) {
            $body['data']['name'] = null;
            $body['data']['postsCount'] = 'lots';
            $body['data']['avatar'] = 'javascript:alert(1)';

            return $body;
        });

        $profile = $this->refresh();

        $this->assertSame(609771, $profile->likes);
        $this->assertSame('Madison Ivy', $profile->name);
        $this->assertSame(170, $profile->posts_count);
        $this->assertNull($profile->avatar_url);
    }

    public function test_long_bio_is_summarized_in_the_changes_diff(): void
    {
        $this->fansApiReturns(function ($body) {
            $body['data']['about'] = str_repeat('x', 500);

            return $body;
        });

        $changes = $this->refresh()->refreshAttempts()->sole()->changes;

        $this->assertSame([null, '<text, 500 chars>'], $changes['about']);
    }

    public function test_cached_response_without_cached_at_has_no_version_and_is_rejected(): void
    {
        $parser = new ProfilePayloadParser;
        $body = json_encode(['data' => ['favoritedCount' => 5], '_meta' => ['_cache' => ['is_cached' => true]]]);

        $this->expectExceptionMessage('cached response without cached_at');

        $parser->parse(new UpstreamResponse(200, $body, 1));
    }

    public function test_client_logs_cache_credits_and_rate_limit(): void
    {
        $log = $this->captureRefreshLog();
        $this->fansApiReturns();

        $this->refresh();

        $response = collect($log->getRecords())->first(fn ($record) => $record->message === 'upstream.response');
        $this->assertTrue($response->context['cache_hit']);
        $this->assertSame(0, $response->context['credits_used']);
        $this->assertSame(59, $response->context['rate_limit_remaining_minute']);
    }
}
