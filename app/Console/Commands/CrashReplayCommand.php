<?php

namespace App\Console\Commands;

use App\Enums\RefreshOutcome;
use App\Enums\UpstreamDriver;
use App\Jobs\Middleware\SkipIfProfileBeingRefreshed;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Refresh\AccountThrottle;
use App\Upstream\Fake\FakeUpstream;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Symfony\Component\Process\Exception\ProcessSignaledException;

/**
 * Real crash, not a simulation in-process: a queue worker is SIGKILLed right after the DB commit
 * (before it acknowledges the job), then Redis redelivers the job to a second worker after retry_after.
 */
class CrashReplayCommand extends Command
{
    protected $signature = 'workload:crash-replay {--save= : also write the output to this file}';

    protected $description = 'Kill a worker after its DB write, let Redis redeliver the job, show the data is applied once';

    private array $transcript = [];

    public function handle(FakeUpstream $fake, AccountThrottle $throttle): int
    {
        if (app()->isProduction() || app(MasterSupervisorRepository::class)->names() !== []) {
            $this->error('Needs a non-production app with Horizon stopped (it would grab the job).');

            return self::FAILURE;
        }

        $account = Account::firstOrCreate(['name' => 'wl-crash'], ['upstream' => UpstreamDriver::Fake]);
        $throttle->reset($account);
        RefreshAttempt::where('account_id', $account->id)->delete();
        $profile = Profile::updateOrCreate(['account_id' => $account->id, 'username' => 'crash-demo'], [
            'likes' => 120000, 'revision' => 10, 'last_outcome' => RefreshOutcome::Updated, 'consecutive_failures' => 0,
        ]);

        // A previous demo run's killed worker may still hold this profile's overlap lock
        Cache::lock((new SkipIfProfileBeingRefreshed($profile->id, $account->id, 1))->getLockKey(new RefreshProfile($profile->id)))->forceRelease();

        // Upstream doesn't change between the two deliveries (same answer, same revision)
        $fake->setDefault('wl-crash', 'crash-demo', ['type' => 'new_format', 'likes' => 121000, 'revision' => 11]);
        Queue::connection('redis')->clear('refresh');

        RefreshProfile::dispatch($profile->id);
        $this->say("1. Dispatched RefreshProfile for profile #{$profile->id} (stored: 120000 / r10). Upstream answers 121000 / r11.");

        try {
            Process::path(base_path())
                ->env(['REFRESH_HANDLER' => 'fixed', 'REFRESH_CHAOS_KILL_AFTER_WRITE' => '1'])
                ->timeout(60)
                ->run(['php', 'artisan', 'queue:work', 'redis', '--queue=refresh', '--once']);
            $death = 'exited normally (chaos hook did not fire!)';
        } catch (ProcessSignaledException $e) {
            $death = "killed by signal {$e->getSignal()} (SIGKILL)";
        }

        $this->say("2. Worker #1 ran the job and was {$death} right after the DB commit, before acknowledging the job.");
        $this->state($profile, '   After the crash');

        $expiresAt = (int) collect(Redis::zrange('queues:refresh:reserved', 0, -1, ['withscores' => true]))->first();
        $wait = max(0, $expiresAt - time()) + 1;
        $this->say("3. The job is still reserved (never acknowledged). Redis redelivers it when the reservation expires (retry_after): waiting {$wait}s ...");
        sleep($wait);

        Process::path(base_path())
            ->env(['REFRESH_HANDLER' => 'fixed'])
            ->timeout(60)
            ->run(['php', 'artisan', 'queue:work', 'redis', '--queue=refresh', '--once']);

        $this->say('4. Worker #2 picked up the redelivered job and ran it.');
        $this->state($profile, '   After the replay');

        $attempts = $profile->refreshAttempts()->orderBy('id')->get();
        $this->table(['attempt row', 'job uuid', 'attempt #', 'outcome', 'changes'], $attempts->map(fn ($a) => [
            $a->id, $a->job_uuid, $a->attempt, $a->outcome->value, json_encode($a->changes),
        ])->all());
        $this->transcript[] = $attempts->map(fn ($a) => "   row {$a->id}: job {$a->job_uuid} attempt #{$a->attempt} {$a->outcome->value} changes=".json_encode($a->changes))->implode("\n");

        $profile->refresh();
        $ok = $profile->likes === 121000 && $profile->revision === 11
            && $attempts->pluck('outcome')->all() === [RefreshOutcome::Updated, RefreshOutcome::Duplicate]
            && $attempts->pluck('job_uuid')->unique()->count() === 1;

        $this->say($ok
            ? 'RESULT: OK. Same job delivered twice; the data was applied once and the replay was recorded as a duplicate.'
            : 'RESULT: UNEXPECTED. See the table above.');

        if ($path = $this->option('save')) {
            file_put_contents(base_path($path), implode("\n", $this->transcript)."\n");
            $this->info("Saved to {$path}");
        }

        $fake->reset();

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function state(Profile $profile, string $label): void
    {
        $profile->refresh();
        $this->say(sprintf(
            '%s: stored %s / r%s, attempt rows %d, queue ready %d, reserved %d',
            $label, $profile->likes, $profile->revision, $profile->refreshAttempts()->count(),
            Redis::llen('queues:refresh'), Redis::zcard('queues:refresh:reserved'),
        ));
    }

    private function say(string $line): void
    {
        $this->line($line);
        $this->transcript[] = $line;
    }
}
