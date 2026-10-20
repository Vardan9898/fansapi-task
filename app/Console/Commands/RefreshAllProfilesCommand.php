<?php

namespace App\Console\Commands;

use App\Jobs\RefreshProfile;
use App\Logging\RefreshLog;
use App\Models\Account;
use Illuminate\Console\Command;

class RefreshAllProfilesCommand extends Command
{
    protected $signature = 'profiles:refresh-all {--account=* : Only these account names}';

    protected $description = 'Queue a refresh for every profile of every active (not blocked) account';

    public function handle(): int
    {
        $skipped = Account::query()
            ->when($this->option('account'), fn ($query, $names) => $query->whereIn('name', $names))
            ->whereNot(fn ($query) => $query->active())
            ->pluck('name');

        $queued = 0;

        Account::active()
            ->when($this->option('account'), fn ($query, $names) => $query->whereIn('name', $names))
            ->each(function (Account $account) use (&$queued) {
                // lazyById: constant memory however many profiles, ids only
                $account->profiles()->select('id')->lazyById(1000)->each(function ($profile) use (&$queued) {
                    RefreshProfile::dispatch($profile->id);
                    $queued++;
                });
            });

        RefreshLog::info('refresh.dispatched', ['queued' => $queued, 'skipped_blocked_accounts' => $skipped->all()]);

        $this->info("Queued {$queued} refresh job(s).".($skipped->isEmpty() ? '' : ' Skipped blocked: '.$skipped->implode(', ')));

        return self::SUCCESS;
    }
}
