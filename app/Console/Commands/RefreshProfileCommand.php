<?php

namespace App\Console\Commands;

use App\Jobs\RefreshProfile;
use App\Models\Profile;
use Illuminate\Console\Command;

class RefreshProfileCommand extends Command
{
    protected $signature = 'profiles:refresh
        {account : Account name}
        {username : Profile username}
        {--sync : Run in this process instead of queueing for Horizon}';

    protected $description = 'Refresh one profile now (queued, or --sync to run inline and print the result)';

    public function handle(): int
    {
        $profile = Profile::query()
            ->whereRelation('account', 'name', $this->argument('account'))
            ->where('username', $this->argument('username'))
            ->first();

        if ($profile === null) {
            $this->error("Profile {$this->argument('account')}/{$this->argument('username')} not found");

            return self::FAILURE;
        }

        if (! $this->option('sync')) {
            RefreshProfile::dispatch($profile->id);
            $this->info("Queued refresh for profile #{$profile->id}");

            return self::SUCCESS;
        }

        RefreshProfile::dispatchSync($profile->id);

        $profile->refresh();
        $attempt = $profile->refreshAttempts()->latest('id')->first();

        $this->table(['likes', 'revision', 'outcome', 'http', 'last_success_at', 'changes'], [[
            $profile->likes ?? 'null',
            $profile->revision ?? 'null',
            $attempt?->outcome->value,
            $attempt?->http_status,
            $profile->last_success_at?->toDateTimeString() ?? 'never',
            json_encode($attempt?->changes),
        ]]);

        return self::SUCCESS;
    }
}
