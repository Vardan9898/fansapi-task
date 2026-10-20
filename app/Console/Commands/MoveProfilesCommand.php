<?php

namespace App\Console\Commands;

use App\Actions\MoveProfiles;
use App\Models\Account;
use Illuminate\Console\Command;
use InvalidArgumentException;

class MoveProfilesCommand extends Command
{
    protected $signature = 'profiles:move
        {from : Source account name}
        {to : Target account name}
        {--profile=* : Only move these usernames (default: all)}';

    protected $description = 'Move profiles to another account (e.g. off a blocked one) without losing data';

    public function handle(MoveProfiles $moveProfiles): int
    {
        $from = Account::firstWhere('name', $this->argument('from'));
        $to = Account::firstWhere('name', $this->argument('to'));

        if ($from === null || $to === null) {
            $this->error('Unknown account: '.($from === null ? $this->argument('from') : $this->argument('to')));

            return self::FAILURE;
        }

        $usernames = $this->option('profile');
        $profileIds = $usernames === []
            ? null
            : $from->profiles()->whereIn('username', $usernames)->pluck('id')->all();

        try {
            $result = $moveProfiles->handle($from, $to, $profileIds);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Moved {$result['moved']}, merged {$result['merged']} profile(s) from [{$from->name}] to [{$to->name}].");

        return self::SUCCESS;
    }
}
