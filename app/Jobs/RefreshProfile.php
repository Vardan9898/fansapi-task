<?php

namespace App\Jobs;

use App\Logging\RefreshLog;
use App\Models\Profile;
use App\Refresh\AttemptContext;
use App\Refresh\RefreshHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refreshes one profile. Carries only the id (no models, no config reads in the constructor);
 * everything is resolved when the job runs, so a moved profile uses its new account.
 */
class RefreshProfile implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $profileId) {}

    public function handle(RefreshHandler $handler): void
    {
        $profile = Profile::with('account')->find($this->profileId);

        if ($profile === null) {
            RefreshLog::warning('refresh.profile_missing', ['profile_id' => $this->profileId]);

            return;
        }

        $context = new AttemptContext($this->job?->uuid(), $this->attempts());

        RefreshLog::forProfile($profile, function () use ($handler, $profile, $context) {
            RefreshLog::info('refresh.started', ['handler' => $handler->name()]);

            $outcome = $handler->refresh($profile, $context);

            RefreshLog::info('refresh.finished', ['handler' => $handler->name(), 'outcome' => $outcome->value]);
        }, $context->jobUuid, $context->attempt);
    }
}
