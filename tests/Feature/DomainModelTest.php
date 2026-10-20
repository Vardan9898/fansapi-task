<?php

namespace Tests\Feature;

use App\Enums\RefreshOutcome;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_brief_starting_state(): void
    {
        $this->seed(DatabaseSeeder::class);

        $profile = Profile::where('username', 'madison420ivy')->sole();

        $this->assertSame(120000, $profile->likes);
        $this->assertSame(10, $profile->revision);
        $this->assertSame(RefreshOutcome::Updated, $profile->last_outcome);
    }

    public function test_seeder_is_rerunnable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Profile::count());
    }

    public function test_never_fetched_profile_has_null_data_not_zero(): void
    {
        $profile = Profile::factory()->create()->fresh();

        $this->assertNull($profile->likes);
        $this->assertNull($profile->revision);
        $this->assertNull($profile->last_success_at);
    }

    public function test_username_is_unique_per_account(): void
    {
        $profile = Profile::factory()->create();

        $this->expectException(QueryException::class);

        Profile::factory()->create([
            'account_id' => $profile->account_id,
            'username' => $profile->username,
        ]);
    }

    public function test_refresh_attempt_is_append_only_with_casts(): void
    {
        $profile = Profile::factory()->refreshed(likes: 120000, revision: 10)->create();

        $attempt = RefreshAttempt::create([
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'handler' => 'fixed',
            'outcome' => RefreshOutcome::Updated,
            'revision_before' => 10,
            'revision_received' => 11,
            'changes' => ['likes' => [120000, 121000], 'revision' => [10, 11]],
        ])->fresh();

        $this->assertSame(RefreshOutcome::Updated, $attempt->outcome);
        $this->assertSame([120000, 121000], $attempt->changes['likes']);
        $this->assertNotNull($attempt->created_at);
        $this->assertArrayNotHasKey('updated_at', $attempt->getAttributes());
    }

    public function test_profiles_are_searchable_by_username_via_scout(): void
    {
        Profile::factory()->create(['username' => 'madison420ivy']);
        Profile::factory()->create(['username' => 'someone_else']);

        $results = Profile::search('madison')->get();

        $this->assertCount(1, $results);
        $this->assertSame('madison420ivy', $results->first()->username);
    }

    public function test_outcome_success_classification(): void
    {
        $this->assertTrue(RefreshOutcome::Updated->isSuccessful());
        $this->assertTrue(RefreshOutcome::Duplicate->isSuccessful());
        $this->assertFalse(RefreshOutcome::Stale->isSuccessful());
        $this->assertFalse(RefreshOutcome::InvalidPayload->isSuccessful());
        $this->assertFalse(RefreshOutcome::RateLimited->isSuccessful());
    }
}
