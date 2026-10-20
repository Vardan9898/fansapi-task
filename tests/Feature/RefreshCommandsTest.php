<?php

namespace Tests\Feature;

use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Refresh\RefreshHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class RefreshCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profiles_refresh_queues_a_job_by_default(): void
    {
        Queue::fake();
        $profile = Profile::factory()->for(Account::factory()->create(['name' => 'agency-a']))->create(['username' => 'madison420ivy']);

        $this->artisan('profiles:refresh', ['account' => 'agency-a', 'username' => 'madison420ivy'])->assertSuccessful();

        Queue::assertPushed(RefreshProfile::class, fn (RefreshProfile $job) => $job->profileId === $profile->id);
    }

    public function test_profiles_refresh_sync_runs_inline_and_prints_the_result(): void
    {
        $fake = $this->routeUpstreamToFake();
        Profile::factory()->for(Account::factory()->create(['name' => 'agency-a']))->create(['username' => 'madison420ivy']);
        $fake->push('agency-a', 'madison420ivy', ['type' => 'old_format', 'likes' => 120000, 'revision' => 10]);

        $this->artisan('profiles:refresh', ['account' => 'agency-a', 'username' => 'madison420ivy', '--sync' => true])
            ->expectsOutputToContain('120000')
            ->assertSuccessful();
    }

    public function test_profiles_refresh_reports_unknown_profile(): void
    {
        $this->artisan('profiles:refresh', ['account' => 'nope', 'username' => 'nobody'])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }

    public function test_incident_reproduce_shows_every_case_failing_on_the_legacy_handler(): void
    {
        $this->routeUpstreamToFake();

        $this->artisan('incident:reproduce', ['--handler' => 'legacy'])
            ->expectsOutputToContain('0/8 cases correct')
            ->assertSuccessful();
    }

    public function test_unknown_handler_name_is_rejected(): void
    {
        config(['refresh.handler' => 'bogus']);

        $this->expectException(InvalidArgumentException::class);

        app(RefreshHandler::class);
    }
}
