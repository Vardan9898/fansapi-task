<?php

namespace Tests\Feature;

use App\Enums\RefreshOutcome;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Models\User;
use App\Refresh\AccountThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.viewer_emails' => ['ops@example.com']]);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.com']));
    }

    private function attempt(Profile $profile, RefreshOutcome $outcome): void
    {
        RefreshAttempt::create([
            'profile_id' => $profile->id, 'account_id' => $profile->account_id,
            'handler' => 'fixed', 'outcome' => $outcome,
        ]);
    }

    // --- page ---------------------------------------------------------------------------------

    public function test_shows_profiles_with_freshness_badges_and_tool_links(): void
    {
        $account = Account::factory()->create(['name' => 'agency-a']);
        Profile::factory()->for($account)->refreshed(120000, 10)->create(['username' => 'fresh_one']);
        Profile::factory()->for($account)->refreshed(5, 1)->create(['username' => 'stale_one', 'last_success_at' => now()->subHours(3)]);
        Profile::factory()->for($account)->refreshed(7, 2)->create(['username' => 'failing_one', 'consecutive_failures' => 3, 'last_outcome' => RefreshOutcome::RateLimited, 'last_error' => 'HTTP 429 (empty body)']);
        Profile::factory()->for($account)->create(['username' => 'never_one']);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['agency-a', 'active'])
            ->assertSee('120,000')
            ->assertSee('fresh', false)
            ->assertSee('stale', false)
            ->assertSee('failing ×3')
            ->assertSee('never fetched')
            ->assertSee('HTTP 429 (empty body)')
            ->assertSee('/horizon')
            ->assertSee('/log-viewer');
    }

    public function test_freshness_rules(): void
    {
        $this->assertSame('never', Profile::factory()->make()->freshness());
        $this->assertSame('fresh', Profile::factory()->make(['last_success_at' => now()])->freshness());
        $this->assertSame('stale', Profile::factory()->make(['last_success_at' => now()->subMinutes(61)])->freshness());
        $this->assertSame('failing', Profile::factory()->make(['last_success_at' => now(), 'consecutive_failures' => 1])->freshness());
    }

    public function test_account_status_badges_blocked_cooling_and_success_rate(): void
    {
        Account::factory()->blocked('upstream rejected credentials (HTTP 401)')->create(['name' => 'agency-blocked']);
        $cooling = Account::factory()->create(['name' => 'agency-cooling']);
        app(AccountThrottle::class)->rateLimited($cooling, '40');
        $profile = Profile::factory()->for($cooling)->create();
        $this->attempt($profile, RefreshOutcome::Updated);
        $this->attempt($profile, RefreshOutcome::RateLimited);

        $this->get('/')
            ->assertSee('blocked')
            ->assertSee('upstream rejected credentials (HTTP 401)')
            ->assertSee('cooling down')
            ->assertSee('50%');
    }

    public function test_search_uses_scout_and_filters_profiles(): void
    {
        Profile::factory()->create(['username' => 'madison420ivy']);
        Profile::factory()->create(['username' => 'someone_else']);

        $this->get('/?q=madison')
            ->assertOk()
            ->assertSee('madison420ivy')
            ->assertDontSee('someone_else');
    }

    public function test_profile_list_is_paginated(): void
    {
        Profile::factory()->count(30)->create();

        $this->get('/')->assertOk()->assertSee('Next');
    }

    // --- actions ------------------------------------------------------------------------------------

    public function test_refresh_button_queues_a_job(): void
    {
        Queue::fake();
        $profile = Profile::factory()->create();

        $this->post("/profiles/{$profile->id}/refresh")->assertRedirect()->assertSessionHas('status');

        Queue::assertPushed(RefreshProfile::class, fn ($job) => $job->profileId === $profile->id);
    }

    public function test_block_and_unblock_an_account(): void
    {
        $account = Account::factory()->create();

        $this->post("/accounts/{$account->id}/block", ['reason' => 'credentials leaked'])->assertSessionHas('status');
        $this->assertTrue($account->refresh()->isBlocked());
        $this->assertSame('credentials leaked', $account->blocked_reason);

        $this->post("/accounts/{$account->id}/unblock")->assertSessionHas('status');
        $this->assertFalse($account->refresh()->isBlocked());
    }

    public function test_moving_all_profiles_off_a_blocked_account_keeps_data_and_history(): void
    {
        $blocked = Account::factory()->blocked()->create(['name' => 'agency-blocked']);
        $healthy = Account::factory()->create(['name' => 'agency-healthy']);
        $profile = Profile::factory()->for($blocked)->refreshed(120000, 10)->create(['username' => 'madison420ivy']);
        $this->attempt($profile, RefreshOutcome::Updated);
        $this->attempt($profile, RefreshOutcome::Failed);

        $this->post("/accounts/{$blocked->id}/move-profiles", ['account_id' => $healthy->id])
            ->assertRedirect()
            ->assertSessionHas('status', 'Moved 1, merged 0 profile(s) from agency-blocked to agency-healthy.');

        $profile->refresh();
        $this->assertSame($healthy->id, $profile->account_id);
        $this->assertSame(120000, $profile->likes);
        $this->assertSame(10, $profile->revision);
        $this->assertSame(2, $profile->refreshAttempts()->count());
        $this->assertSame([$blocked->id], $profile->refreshAttempts()->distinct()->pluck('account_id')->all());
    }

    public function test_moving_a_single_profile(): void
    {
        $from = Account::factory()->create();
        $to = Account::factory()->create(['name' => 'agency-b']);
        [$move, $stay] = Profile::factory()->for($from)->count(2)->create();

        $this->post("/profiles/{$move->id}/move", ['account_id' => $to->id])
            ->assertSessionHas('status', "{$move->username} moved to agency-b.");

        $this->assertSame($to->id, $move->refresh()->account_id);
        $this->assertSame($from->id, $stay->refresh()->account_id);
    }

    public function test_moving_to_a_blocked_account_is_refused_with_a_message(): void
    {
        $from = Account::factory()->create();
        $blocked = Account::factory()->blocked()->create(['name' => 'agency-blocked']);
        $profile = Profile::factory()->for($from)->create();

        $this->post("/profiles/{$profile->id}/move", ['account_id' => $blocked->id])
            ->assertSessionHasErrors(['move' => 'Target account [agency-blocked] is blocked.']);

        $this->assertSame($from->id, $profile->refresh()->account_id);
    }

    public function test_move_requires_a_target_account(): void
    {
        $profile = Profile::factory()->create();

        $this->post("/profiles/{$profile->id}/move", [])->assertSessionHasErrors('account_id');
    }

    // --- access ----------------------------------------------------------------------------------------

    public function test_guests_outside_local_cannot_see_or_act(): void
    {
        auth()->logout();
        $profile = Profile::factory()->create();

        $this->get('/')->assertForbidden();
        $this->post("/profiles/{$profile->id}/refresh")->assertForbidden();
        $this->post("/accounts/{$profile->account_id}/block")->assertForbidden();
    }

    public function test_dashboard_is_open_locally(): void
    {
        auth()->logout();
        $this->app['env'] = 'local';

        $this->get('/')->assertOk();
    }
}
