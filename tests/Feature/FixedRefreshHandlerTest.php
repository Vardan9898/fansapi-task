<?php

namespace Tests\Feature;

use App\Enums\RefreshOutcome;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Refresh\AttemptContext;
use App\Refresh\AttemptRecorder;
use App\Upstream\Fake\FakeUpstream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixed-handler behaviour beyond the brief's required cases (those live in ProfileRefreshTest).
 */
class FixedRefreshHandlerTest extends TestCase
{
    use RefreshDatabase;

    private FakeUpstream $upstream;

    protected function setUp(): void
    {
        parent::setUp();

        config(['refresh.handler' => 'fixed']);
        $this->upstream = $this->routeUpstreamToFake();
    }

    private function profile(?int $likes = 120000, ?int $revision = 10): Profile
    {
        $factory = Profile::factory()->for(Account::factory()->create(['name' => 'agency-a']));

        return ($likes === null ? $factory : $factory->refreshed($likes, $revision))
            ->create(['username' => 'madison420ivy']);
    }

    private function refreshWith(Profile $profile, array ...$steps): Profile
    {
        foreach ($steps as $step) {
            $this->upstream->push('agency-a', 'madison420ivy', $step);
        }

        foreach ($steps as $step) {
            RefreshProfile::dispatchSync($profile->id);
        }

        return $profile->refresh();
    }

    public function test_never_fetched_profile_accepts_the_first_valid_response(): void
    {
        $profile = $this->refreshWith($this->profile(likes: null), ['type' => 'new_format', 'likes' => 5, 'revision' => 1]);

        $this->assertSame(5, $profile->likes);
        $this->assertSame(1, $profile->revision);
        $this->assertSame(RefreshOutcome::Updated, $profile->last_outcome);
    }

    public function test_not_found_is_a_permanent_failure_and_keeps_data(): void
    {
        $profile = $this->refreshWith($this->profile(), ['type' => 'not_found']);

        $this->assertSame(120000, $profile->likes);
        $this->assertSame(RefreshOutcome::Failed, $profile->last_outcome);
        $this->assertSame('HTTP 404', $profile->last_error);
    }

    public function test_oversized_body_is_invalid_and_keeps_data(): void
    {
        config(['upstream.max_body_bytes' => 1000]);

        $profile = $this->refreshWith($this->profile(), ['type' => 'oversized', 'likes' => 1, 'revision' => 11]);

        $this->assertSame(120000, $profile->likes);
        $this->assertSame(RefreshOutcome::InvalidPayload, $profile->last_outcome);
    }

    public function test_failures_count_up_and_reset_on_success(): void
    {
        $profile = $this->refreshWith($this->profile(),
            ['type' => 'server_error'],
            ['type' => 'timeout'],
            ['type' => 'server_error'],
        );
        $this->assertSame(3, $profile->consecutive_failures);
        $this->assertSame(RefreshOutcome::ServerError, $profile->last_outcome);

        $profile = $this->refreshWith($profile, ['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);
        $this->assertSame(0, $profile->consecutive_failures);
        $this->assertNull($profile->last_error);
    }

    public function test_failure_counter_is_incremented_atomically(): void
    {
        $profile = $this->profile();
        $staleCopy = Profile::find($profile->id); // another worker's in-memory copy (counter 0)
        $recorder = app(AttemptRecorder::class);
        $context = new AttemptContext(null);

        $recorder->record($profile, 'fixed', RefreshOutcome::ServerError, $context);
        $recorder->record($staleCopy, 'fixed', RefreshOutcome::Timeout, $context);

        $this->assertSame(2, $profile->fresh()->consecutive_failures); // not 1: the second write did not overwrite the first
    }

    public function test_finished_log_line_reports_peak_memory(): void
    {
        $log = $this->captureRefreshLog();

        $this->refreshWith($this->profile(), ['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);

        $finished = collect($log->getRecords())->first(fn ($r) => $r->message === 'refresh.finished');
        $this->assertGreaterThan(0, $finished->context['peak_memory_mb']);
    }

    public function test_stale_response_does_not_count_as_failure(): void
    {
        $profile = $this->refreshWith($this->profile(likes: 121000, revision: 11), ['type' => 'old_format', 'likes' => 120000, 'revision' => 10]);

        $this->assertSame(RefreshOutcome::Stale, $profile->last_outcome);
        $this->assertSame(0, $profile->consecutive_failures);
    }

    public function test_attempt_row_records_format_revisions_and_changes(): void
    {
        $profile = $this->refreshWith($this->profile(), ['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);

        $attempt = $profile->refreshAttempts()->sole();
        $this->assertSame('fixed', $attempt->handler);
        $this->assertSame('nested', $attempt->response_format);
        $this->assertSame(200, $attempt->http_status);
        $this->assertSame(10, $attempt->revision_before);
        $this->assertSame(11, $attempt->revision_received);
        $this->assertSame(['likes' => [120000, 121000], 'revision' => [10, 11]], $attempt->changes);
        $this->assertNotNull($attempt->job_uuid);
    }

    public function test_large_drop_is_stored_but_logged_as_suspicious(): void
    {
        $log = $this->captureRefreshLog();

        $profile = $this->refreshWith($this->profile(), ['type' => 'new_format', 'likes' => 0, 'revision' => 11]);

        $this->assertSame(0, $profile->likes); // explicit 0 with a newer revision is valid data
        $this->assertTrue($log->hasWarningThatContains('refresh.suspicious_drop'));
    }

    public function test_small_drop_is_not_flagged(): void
    {
        $log = $this->captureRefreshLog();

        $this->refreshWith($this->profile(), ['type' => 'new_format', 'likes' => 119000, 'revision' => 11]);

        $this->assertFalse($log->hasWarningThatContains('refresh.suspicious_drop'));
    }

    public function test_same_revision_with_different_data_keeps_stored_and_warns(): void
    {
        $log = $this->captureRefreshLog();

        $profile = $this->refreshWith($this->profile(), ['type' => 'old_format', 'likes' => 999, 'revision' => 10]);

        $this->assertSame(120000, $profile->likes);
        $this->assertSame(RefreshOutcome::Duplicate, $profile->last_outcome);
        $this->assertTrue($log->hasWarningThatContains('refresh.revision_conflict'));
    }

    public function test_every_attempt_writes_one_log_line_with_full_context(): void
    {
        $log = $this->captureRefreshLog();

        $this->refreshWith($this->profile(), ['type' => 'rate_limited']);

        $records = array_values(array_filter($log->getRecords(), fn ($r) => $r->message === 'refresh.attempt'));
        $this->assertCount(1, $records);
        $this->assertSame('WARNING', $records[0]->level->getName());
        $this->assertSame('rate_limited', $records[0]->context['outcome']);
        $this->assertSame(429, $records[0]->context['http_status']);
        $this->assertArrayHasKey('job_uuid', $records[0]->extra);
        $this->assertSame('madison420ivy', $records[0]->extra['username']);
    }
}
