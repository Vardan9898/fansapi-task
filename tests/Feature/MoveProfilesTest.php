<?php

namespace Tests\Feature;

use App\Actions\MoveProfiles;
use App\Enums\RefreshOutcome;
use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MoveProfilesTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(Profile $profile, RefreshOutcome $outcome): RefreshAttempt
    {
        return RefreshAttempt::create([
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'handler' => 'fixed',
            'outcome' => $outcome,
        ]);
    }

    public function test_moves_profiles_off_a_blocked_account_keeping_data_and_history(): void
    {
        $blocked = Account::factory()->blocked()->create();
        $healthy = Account::factory()->create();
        $profile = Profile::factory()->for($blocked)->refreshed(likes: 120000, revision: 10)->create();
        $this->attempt($profile, RefreshOutcome::Updated);
        $this->attempt($profile, RefreshOutcome::RateLimited);

        $result = app(MoveProfiles::class)->handle($blocked, $healthy);

        $this->assertSame(['moved' => 1, 'merged' => 0], $result);

        $profile->refresh();
        $this->assertSame($healthy->id, $profile->account_id);
        $this->assertSame(120000, $profile->likes);
        $this->assertSame(10, $profile->revision);

        // History is kept and still says which account actually made each attempt
        $this->assertSame(2, $profile->refreshAttempts()->count());
        $this->assertSame([$blocked->id], $profile->refreshAttempts()->distinct()->pluck('account_id')->all());
    }

    public function test_moves_only_selected_profiles(): void
    {
        $from = Account::factory()->create();
        $to = Account::factory()->create();
        [$a, $b] = Profile::factory()->for($from)->count(2)->create();

        app(MoveProfiles::class)->handle($from, $to, [$a->id]);

        $this->assertSame($to->id, $a->fresh()->account_id);
        $this->assertSame($from->id, $b->fresh()->account_id);
    }

    public function test_merge_keeps_newer_source_data_and_both_histories(): void
    {
        $from = Account::factory()->create();
        $to = Account::factory()->create();
        $source = Profile::factory()->for($from)->refreshed(likes: 121000, revision: 11)->create(['username' => 'madison420ivy']);
        $target = Profile::factory()->for($to)->refreshed(likes: 120000, revision: 10)->create(['username' => 'madison420ivy']);
        $this->attempt($source, RefreshOutcome::Updated);
        $this->attempt($target, RefreshOutcome::Updated);

        $result = app(MoveProfiles::class)->handle($from, $to);

        $this->assertSame(['moved' => 0, 'merged' => 1], $result);
        $this->assertModelMissing($source);

        $target->refresh();
        $this->assertSame(121000, $target->likes);
        $this->assertSame(11, $target->revision);
        $this->assertSame(2, $target->refreshAttempts()->count());
    }

    public function test_merge_never_overwrites_newer_target_data_with_older_source(): void
    {
        $from = Account::factory()->create();
        $to = Account::factory()->create();
        Profile::factory()->for($from)->refreshed(likes: 120000, revision: 10)->create(['username' => 'madison420ivy']);
        $target = Profile::factory()->for($to)->refreshed(likes: 121000, revision: 11)->create(['username' => 'madison420ivy']);

        app(MoveProfiles::class)->handle($from, $to);

        $target->refresh();
        $this->assertSame(121000, $target->likes);
        $this->assertSame(11, $target->revision);
        $this->assertSame(1, Profile::count());
    }

    public function test_never_fetched_source_does_not_overwrite_target(): void
    {
        $from = Account::factory()->create();
        $to = Account::factory()->create();
        Profile::factory()->for($from)->create(['username' => 'madison420ivy']); // likes/revision null
        $target = Profile::factory()->for($to)->refreshed(likes: 120000, revision: 10)->create(['username' => 'madison420ivy']);

        app(MoveProfiles::class)->handle($from, $to);

        $this->assertSame(120000, $target->fresh()->likes);
    }

    public function test_refuses_moving_between_different_upstreams(): void
    {
        $fake = Account::factory()->create();
        $real = Account::factory()->fansApi()->create();
        $profile = Profile::factory()->for($fake)->refreshed(likes: 120000, revision: 10)->create();

        try {
            app(MoveProfiles::class)->handle($fake, $real);
            $this->fail('Cross-upstream move should be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not comparable', $e->getMessage());
        }

        $this->assertSame($fake->id, $profile->fresh()->account_id);
    }

    public function test_refuses_blocked_target_and_same_account(): void
    {
        $account = Account::factory()->create();
        $blocked = Account::factory()->blocked()->create();

        try {
            app(MoveProfiles::class)->handle($account, $blocked);
            $this->fail('Moving to a blocked account should be refused.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        app(MoveProfiles::class)->handle($account, $account);
    }

    public function test_artisan_command_moves_by_account_name_and_username(): void
    {
        $from = Account::factory()->blocked()->create(['name' => 'agency-a']);
        Account::factory()->create(['name' => 'agency-b']);
        $keep = Profile::factory()->for($from)->create(['username' => 'stays_here']);
        $move = Profile::factory()->for($from)->create(['username' => 'madison420ivy']);

        $this->artisan('profiles:move', ['from' => 'agency-a', 'to' => 'agency-b', '--profile' => ['madison420ivy']])
            ->expectsOutputToContain('Moved 1, merged 0')
            ->assertSuccessful();

        $this->assertSame('agency-b', $move->fresh()->account->name);
        $this->assertSame('agency-a', $keep->fresh()->account->name);
    }

    public function test_artisan_command_fails_cleanly_on_unknown_account(): void
    {
        Account::factory()->create(['name' => 'agency-a']);

        $this->artisan('profiles:move', ['from' => 'agency-a', 'to' => 'nope'])
            ->expectsOutputToContain('Unknown account: nope')
            ->assertFailed();
    }

    public function test_artisan_command_fails_cleanly_on_blocked_target(): void
    {
        Account::factory()->create(['name' => 'agency-a']);
        Account::factory()->blocked()->create(['name' => 'agency-b']);

        $this->artisan('profiles:move', ['from' => 'agency-a', 'to' => 'agency-b'])
            ->expectsOutputToContain('is blocked')
            ->assertFailed();
    }
}
