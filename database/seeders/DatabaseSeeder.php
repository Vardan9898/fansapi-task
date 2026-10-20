<?php

namespace Database\Seeders;

use App\Enums\RefreshOutcome;
use App\Models\Account;
use App\Models\Profile;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Starting state from the brief: last valid refresh stored likes=120000 at revision 10.
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
    }
}
