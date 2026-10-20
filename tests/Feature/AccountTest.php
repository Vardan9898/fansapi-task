<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\RefreshOutcome;
use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_account_is_active(): void
    {
        $account = Account::create(['name' => 'agency-a'])->fresh();

        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertFalse($account->isBlocked());
    }

    public function test_block_and_unblock(): void
    {
        $account = Account::factory()->create();

        $account->block('upstream 403');

        $this->assertTrue($account->fresh()->isBlocked());
        $this->assertSame('upstream 403', $account->fresh()->blocked_reason);
        $this->assertNotNull($account->fresh()->blocked_at);

        $account->unblock();

        $this->assertSame(AccountStatus::Active, $account->fresh()->status);
        $this->assertNull($account->fresh()->blocked_at);
        $this->assertNull($account->fresh()->blocked_reason);
    }

    public function test_active_scope_excludes_blocked_accounts(): void
    {
        $active = Account::factory()->create();
        Account::factory()->blocked()->create();

        $this->assertEquals([$active->id], Account::active()->pluck('id')->all());
    }

    public function test_account_with_profiles_cannot_be_deleted(): void
    {
        $profile = Profile::factory()->refreshed(likes: 120000, revision: 10)->create();

        try {
            $profile->account->delete();
            $this->fail('Deleting an account with profiles should be restricted.');
        } catch (QueryException) {
            // expected: FK restrict
        }

        $this->assertSame(120000, $profile->fresh()->likes);
    }

    public function test_account_with_attempt_history_cannot_be_deleted(): void
    {
        $account = Account::factory()->create();
        $profile = Profile::factory()->create();
        RefreshAttempt::create([
            'profile_id' => $profile->id,
            'account_id' => $account->id,
            'handler' => 'fixed',
            'outcome' => RefreshOutcome::RateLimited,
        ]);

        $this->expectException(QueryException::class);

        $account->delete();
    }
}
