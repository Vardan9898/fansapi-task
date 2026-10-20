<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\UpstreamDriver;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
        ];
    }

    public function fansApi(): static
    {
        return $this->state(fn () => ['upstream' => UpstreamDriver::FansApi]);
    }

    public function blocked(string $reason = 'upstream 403'): static
    {
        return $this->state(fn () => [
            'status' => AccountStatus::Blocked,
            'blocked_at' => now(),
            'blocked_reason' => $reason,
        ]);
    }
}
