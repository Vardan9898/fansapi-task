<?php

namespace Tests\Feature;

use App\Enums\RefreshOutcome;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Upstream\Fake\FakeUpstream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How a profile refresh MUST behave, run against the configured handler (REFRESH_HANDLER).
 * Starting state from the brief: last valid likes 120000 at revision 10.
 *
 * Against the legacy handler these tests fail: that is the reproduction of the incident.
 */
class ProfileRefreshTest extends TestCase
{
    use RefreshDatabase;

    private FakeUpstream $upstream;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->upstream = $this->routeUpstreamToFake();
        $this->profile = Profile::factory()
            ->for(Account::factory()->create(['name' => 'agency-a']))
            ->refreshed(likes: 120000, revision: 10)
            ->create(['username' => 'madison420ivy', 'last_success_at' => now()->subHour()]);
    }

    /**
     * Script the upstream answers, run the job once per answer.
     */
    private function refreshWith(array ...$steps): Profile
    {
        foreach ($steps as $step) {
            $this->upstream->push('agency-a', 'madison420ivy', $step);
        }

        foreach ($steps as $step) {
            RefreshProfile::dispatchSync($this->profile->id);
        }

        return $this->profile->refresh();
    }

    private function outcomes(): array
    {
        return $this->profile->refreshAttempts()->orderBy('id')->pluck('outcome')->all();
    }

    private function assertStoredDataUnchanged(Profile $profile): void
    {
        $this->assertSame(120000, $profile->likes, 'stored likes must not change');
        $this->assertSame(10, $profile->revision, 'stored revision must not change');
        $this->assertTrue($profile->last_success_at->lt(now()->subMinutes(30)), 'a failed attempt must not count as a successful refresh');
    }

    // --- response format -------------------------------------------------------------------

    public function test_new_format_response_updates_likes_from_profile_object(): void
    {
        $profile = $this->refreshWith(['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);

        $this->assertSame(121000, $profile->likes);
        $this->assertSame(11, $profile->revision);
        $this->assertSame([RefreshOutcome::Updated], $this->outcomes());
        $this->assertSame(
            ['likes' => [120000, 121000], 'revision' => [10, 11]],
            $profile->refreshAttempts()->sole()->changes,
        );
    }

    public function test_old_format_response_is_still_accepted(): void
    {
        $profile = $this->refreshWith(['type' => 'old_format', 'likes' => 120500, 'revision' => 11]);

        $this->assertSame(120500, $profile->likes);
        $this->assertSame(11, $profile->revision);
        $this->assertSame([RefreshOutcome::Updated], $this->outcomes());
    }

    public function test_response_without_likes_is_rejected_and_stored_value_kept(): void
    {
        $profile = $this->refreshWith(['type' => 'invalid', 'revision' => 12]);

        $this->assertStoredDataUnchanged($profile);
        $this->assertSame([RefreshOutcome::InvalidPayload], $this->outcomes());
    }

    // --- failed requests -------------------------------------------------------------------

    public function test_rate_limited_response_keeps_stored_data_and_is_not_a_success(): void
    {
        $profile = $this->refreshWith(['type' => 'rate_limited']);

        $this->assertStoredDataUnchanged($profile);
        $this->assertSame([RefreshOutcome::RateLimited], $this->outcomes());
        $this->assertSame(1, $profile->consecutive_failures);
    }

    public function test_server_error_with_empty_body_keeps_stored_data(): void
    {
        $profile = $this->refreshWith(['type' => 'server_error']);

        $this->assertStoredDataUnchanged($profile);
        $this->assertSame([RefreshOutcome::ServerError], $this->outcomes());
    }

    public function test_timeout_is_recorded_and_keeps_stored_data(): void
    {
        $profile = $this->refreshWith(['type' => 'timeout']);

        $this->assertStoredDataUnchanged($profile);
        $this->assertSame([RefreshOutcome::Timeout], $this->outcomes());
    }

    // --- duplicate execution and ordering ----------------------------------------------------

    public function test_duplicate_execution_does_not_apply_the_same_revision_twice(): void
    {
        $profile = $this->refreshWith(
            ['type' => 'new_format', 'likes' => 121000, 'revision' => 11],
            ['type' => 'new_format', 'likes' => 121000, 'revision' => 11],
        );

        $this->assertSame(121000, $profile->likes);
        $this->assertSame(11, $profile->revision);
        $this->assertSame([RefreshOutcome::Updated, RefreshOutcome::Duplicate], $this->outcomes());
        $this->assertNull($profile->refreshAttempts()->orderByDesc('id')->first()->changes);
    }

    public function test_older_response_arriving_last_does_not_overwrite_newer_data(): void
    {
        $profile = $this->refreshWith(
            ['type' => 'new_format', 'likes' => 121000, 'revision' => 11],
            ['type' => 'old_format', 'likes' => 120000, 'revision' => 10],
        );

        $this->assertSame(121000, $profile->likes);
        $this->assertSame(11, $profile->revision);
        $this->assertSame([RefreshOutcome::Updated, RefreshOutcome::Stale], $this->outcomes());
    }
}
