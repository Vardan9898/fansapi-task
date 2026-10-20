<?php

namespace Database\Factories;

use App\Enums\RefreshOutcome;
use App\Models\Account;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    /**
     * Default: a profile that has never been fetched (all data null).
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'username' => fake()->unique()->userName(),
        ];
    }

    /**
     * A profile whose last valid refresh stored the given likes/revision.
     */
    public function refreshed(int $likes, int $revision): static
    {
        return $this->state(fn () => [
            'likes' => $likes,
            'revision' => $revision,
            'last_success_at' => now(),
            'last_attempt_at' => now(),
            'last_outcome' => RefreshOutcome::Updated,
        ]);
    }
}
