<?php

namespace Database\Seeders;

use App\Enums\RefreshOutcome;
use App\Enums\UpstreamDriver;
use App\Models\Account;
use App\Models\Profile;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * "default" (fake upstream): the brief's starting state, likes=120000 at revision 10.
     * "fansapi" (real FansAPI, needs FANSAPI_KEY): madison420ivy, never fetched yet.
     */
    public function run(): void
    {
        $account = Account::firstOrCreate(['name' => 'default']);

        Profile::updateOrCreate(
            ['account_id' => $account->id, 'username' => 'madison420ivy'],
            [
                'likes' => 120000,
                'revision' => 10,
                'last_success_at' => now(),
                'last_attempt_at' => now(),
                'last_outcome' => RefreshOutcome::Updated,
            ],
        );

        $real = Account::firstOrCreate(['name' => 'fansapi'], ['upstream' => UpstreamDriver::FansApi]);
        Profile::firstOrCreate(['account_id' => $real->id, 'username' => 'madison420ivy']);
    }
}
