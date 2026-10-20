<?php

namespace App\Console\Commands;

use App\Enums\UpstreamDriver;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Refresh\AccountThrottle;
use App\Upstream\Fake\FakeUpstream;
use App\Workload\QueueProbe;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

/**
 * Repeatable before/after workload. Starts its own Horizon with the chosen handler, runs:
 *   busy account:    a burst of jobs at t=0; its upstream is slow and returns 429 (no Retry-After) for N seconds
 *   healthy account: a steady stream of jobs; its upstream is fast and valid
 * samples the queue every second, stops Horizon and reports per account.
 *
 * The fake upstream serves likes = 100000 + revision, so stored data can be verified afterwards.
 */
class WorkloadRunCommand extends Command
{
    protected $signature = 'workload:run
        {--handler=fixed : legacy (before the fix) or fixed (after)}
        {--busy-profiles=20} {--busy-rounds=3 : jobs per busy profile, all dispatched at t=0}
        {--healthy-profiles=5} {--healthy-every=1 : seconds between healthy rounds} {--healthy-for=20 : seconds of healthy rounds}
        {--rate-limit=15 : seconds the busy account gets 429s}
        {--busy-latency=1000 : ms per busy upstream response} {--healthy-latency=50 : ms per healthy upstream response}
        {--workers=6 : Horizon refresh workers}
        {--max-seconds=150 : give up waiting for the queue to drain after this}
        {--save= : also write the report to this file}';

    protected $description = 'Run the busy-vs-healthy account workload under Horizon and report before/after metrics';

    private const BUSY = 'wl-busy';

    private const HEALTHY = 'wl-healthy';

    public function handle(FakeUpstream $fake, AccountThrottle $throttle, QueueProbe $probe): int
    {
        $handler = $this->option('handler');

        if (! $this->preflight()) {
            return self::FAILURE;
        }

        [$busy, $healthy, $accountByProfile] = $this->prepare($fake, $throttle);

        $horizon = $this->startHorizon($handler);

        if ($horizon === null) {
            return self::FAILURE;
        }

        $this->info("Running workload with handler={$handler} ...");
        [$samples, $dispatched, $start, $elapsed, $drained] = $this->drive($busy, $healthy, $accountByProfile, $probe);

        $this->stopHorizon($horizon);

        $report = $this->report($handler, $busy, $healthy, $samples, $dispatched, $start, $elapsed, $drained);
        $this->line($report);

        if ($path = $this->option('save')) {
            file_put_contents(base_path($path), $report."\n");
            $this->info("Saved to {$path}");
        }

        $fake->reset();

        return self::SUCCESS;
    }

    private function preflight(): bool
    {
        if (app()->isProduction() || ! config('upstream.fake.enabled')) {
            $this->error('The workload needs the fake upstream (never in production).');

            return false;
        }

        if (app(MasterSupervisorRepository::class)->names() !== []) {
            $this->error('Horizon is already running. Stop it first: php artisan horizon:terminate');

            return false;
        }

        try {
            Http::timeout(3)->get(config('upstream.drivers.fake.base_url').'/__probe/profiles/__probe');
        } catch (ConnectionException) {
            $this->error('Fake upstream unreachable at '.config('upstream.drivers.fake.base_url').'. Start it: PHP_CLI_SERVER_WORKERS=8 php artisan serve');

            return false;
        }

        return true;
    }

    /**
     * Fresh accounts/profiles (likes 100000, revision 0 = valid starting data), fake upstream scenario, empty queue.
     */
    private function prepare(FakeUpstream $fake, AccountThrottle $throttle): array
    {
        $accounts = [];

        foreach ([self::BUSY => 'busy-profiles', self::HEALTHY => 'healthy-profiles'] as $name => $option) {
            $account = Account::firstOrCreate(['name' => $name], ['upstream' => UpstreamDriver::Fake]);
            $account->unblock();
            $throttle->reset($account);
            RefreshAttempt::where('account_id', $account->id)->delete();
            Redis::del("refresh:stats:account:{$account->id}:skipped_overlapping");

            foreach (range(1, (int) $this->option($option)) as $i) {
                Profile::updateOrCreate(['account_id' => $account->id, 'username' => "{$name}-p{$i}"], [
                    'likes' => 100000, 'revision' => 0, 'last_success_at' => null, 'last_attempt_at' => null,
                    'last_outcome' => null, 'last_error' => null, 'consecutive_failures' => 0,
                ]);
            }

            $accounts[$name] = $account;
        }

        Queue::connection('redis')->clear('refresh');

        $fake->reset();
        $fake->setDefault(self::BUSY, '*', ['type' => 'new_format', 'auto' => true]);
        $fake->setDefault(self::HEALTHY, '*', ['type' => 'new_format', 'auto' => true]);
        $fake->latency(self::BUSY, (int) $this->option('busy-latency'));
        $fake->latency(self::HEALTHY, (int) $this->option('healthy-latency'));

        $accountByProfile = Profile::whereIn('account_id', collect($accounts)->pluck('id'))
            ->pluck('account_id', 'id')
            ->map(fn ($accountId) => $accountId === $accounts[self::BUSY]->id ? self::BUSY : self::HEALTHY)
            ->all();

        return [$accounts[self::BUSY], $accounts[self::HEALTHY], $accountByProfile];
    }

    private function startHorizon(string $handler): ?InvokedProcess
    {
        $process = Process::path(base_path())
            ->env(['REFRESH_HANDLER' => $handler, 'HORIZON_REFRESH_PROCESSES' => (string) $this->option('workers')])
            ->forever()
            ->start(['php', 'artisan', 'horizon']);

        // Wait until the refresh supervisor reports its worker processes
        for ($i = 0; $i < 40; $i++) {
            usleep(500_000);

            $workers = collect(app(SupervisorRepository::class)->all())
                ->filter(fn ($supervisor) => str_contains($supervisor->name, 'supervisor-refresh'))
                ->sum(fn ($supervisor) => array_sum((array) $supervisor->processes));

            if ($workers >= (int) $this->option('workers')) {
                return $process;
            }
        }

        $this->error("Horizon did not start its refresh workers.\n".$process->latestOutput().$process->latestErrorOutput());
        $process->stop();

        return null;
    }

    private function stopHorizon(InvokedProcess $process): void
    {
        Artisan::call('horizon:terminate');

        for ($i = 0; $i < 60 && $process->running(); $i++) {
            usleep(500_000);
        }

        if ($process->running()) {
            $process->stop();
        }
    }

    /**
     * Dispatch the burst and the steady stream, sample the queue once per second until it drains.
     */
    private function drive(Account $busy, Account $healthy, array $accountByProfile, QueueProbe $probe): array
    {
        $start = Carbon::now()->startOfSecond();
        $t0 = microtime(true);
        $dispatched = [self::BUSY => 0, self::HEALTHY => 0];
        $samples = [];

        // The busy account's upstream rate-limits from the moment the burst lands
        app(FakeUpstream::class)->rateLimit(self::BUSY, (int) $this->option('rate-limit'));

        foreach (range(1, (int) $this->option('busy-rounds')) as $round) {
            foreach ($busy->profiles()->pluck('id') as $id) {
                RefreshProfile::dispatch($id);
                $dispatched[self::BUSY]++;
            }
        }

        $healthyIds = $healthy->profiles()->pluck('id');
        $healthyUntil = (int) $this->option('healthy-for');
        $nextHealthy = 0.0;
        $nextSample = 0.0;
        $drained = false;

        while (($elapsed = microtime(true) - $t0) < (int) $this->option('max-seconds')) {
            if ($elapsed >= $nextHealthy && $elapsed < $healthyUntil) {
                foreach ($healthyIds as $id) {
                    RefreshProfile::dispatch($id);
                    $dispatched[self::HEALTHY]++;
                }
                $nextHealthy += (float) $this->option('healthy-every');
            }

            if ($elapsed >= $nextSample) {
                $sample = $probe->sample($accountByProfile);
                $samples[] = ['t' => (int) round($elapsed)] + $sample;
                $nextSample += 1.0;

                if ($elapsed >= $healthyUntil && $sample['ready'] + $sample['delayed'] + $sample['reserved'] === 0) {
                    $drained = true;
                    break;
                }
            }

            usleep(100_000);
        }

        return [$samples, $dispatched, $start, microtime(true) - $t0, $drained];
    }

    private function report(string $handler, Account $busy, Account $healthy, array $samples, array $dispatched, Carbon $start, float $elapsed, bool $drained): string
    {
        $lines = [];
        $lines[] = "=== Workload report: handler={$handler} ===";
        $lines[] = sprintf(
            'busy (%s): %d profiles x %d rounds = %d jobs at t=0; upstream %dms/response, 429 (no Retry-After) for the first %ds',
            self::BUSY, $this->option('busy-profiles'), $this->option('busy-rounds'), $dispatched[self::BUSY],
            $this->option('busy-latency'), $this->option('rate-limit'),
        );
        $lines[] = sprintf(
            'healthy (%s): %d profiles every %ss for %ss = %d jobs; upstream %dms/response',
            self::HEALTHY, $this->option('healthy-profiles'), $this->option('healthy-every'), $this->option('healthy-for'),
            $dispatched[self::HEALTHY], $this->option('healthy-latency'),
        );
        $lines[] = sprintf(
            'Horizon refresh workers: %d, account_concurrency: %s, run: %.1fs (%s), failed jobs: %d',
            $this->option('workers'), $handler === 'legacy' ? 'n/a (no isolation)' : config('refresh.account_concurrency'),
            $elapsed, $drained ? 'queue drained' : 'stopped at max-seconds', DB::table('failed_jobs')->where('failed_at', '>=', $start)->count(),
        );
        $lines[] = '';

        $rows = [];
        foreach ([self::BUSY => $busy, self::HEALTHY => $healthy] as $name => $account) {
            $attempts = RefreshAttempt::where('account_id', $account->id)->get(); // wiped in prepare(): this run only
            $successful = $attempts->filter(fn ($attempt) => $attempt->outcome->isSuccessful());
            $outcomes = $attempts->countBy(fn ($attempt) => $attempt->outcome->value)->sortKeys()->map(fn ($count, $outcome) => "{$outcome}:{$count}")->implode(' ');
            $oldest = collect($samples)->pluck("oldest.{$name}")->filter(fn ($age) => $age !== null);
            $profiles = $account->profiles()->get();
            $valid = $profiles->filter(fn ($p) => $p->revision >= 1 && $p->likes === 100000 + $p->revision)->count();
            $zeroed = $profiles->where('likes', 0)->count();
            $firstOk = $successful->min('created_at');
            $lastOk = $successful->max('created_at');

            $skipped = (int) Redis::get("refresh:stats:account:{$account->id}:skipped_overlapping");

            $rows[] = [
                $name,
                $dispatched[$name],
                $attempts->count(),
                $skipped,
                $successful->count(),
                $successful->isEmpty() ? 'n/a' : number_format($attempts->count() / $successful->count(), 2),
                $oldest->isEmpty() ? '0s' : $oldest->max().'s',
                $firstOk ? sprintf('%ds..%ds', $start->diffInSeconds($firstOk), $start->diffInSeconds($lastOk)) : 'never',
                "{$valid}/{$profiles->count()} valid".($zeroed ? ", {$zeroed} zeroed" : ''),
                $outcomes,
            ];
        }

        $lines[] = $this->renderTable(
            ['Account', 'Jobs', 'Upstream attempts', 'Skipped (already refreshing)', 'Successful (recorded)', 'Attempts/success', 'Oldest waiting (max)', 'Successes at', 'Stored data', 'Outcomes'],
            $rows,
        );
        $lines[] = 'Stored data check: valid = likes == 100000 + revision (what the fake upstream served) and refreshed at least once.';
        $lines[] = '';
        $lines[] = 'Timeline (queue sampled every second; oldest = age since first dispatch, ready + delayed):';
        $lines[] = $this->renderTable(
            ['t', 'ready', 'delayed', 'running', "oldest {$this->short(self::BUSY)}", "oldest {$this->short(self::HEALTHY)}"],
            collect($samples)->filter(fn ($s, $i) => $i % 2 === 0 || $i === count($samples) - 1)->map(fn ($s) => [
                $s['t'].'s', $s['ready'], $s['delayed'], $s['reserved'],
                ($s['oldest'][self::BUSY] ?? null) === null ? '-' : $s['oldest'][self::BUSY].'s',
                ($s['oldest'][self::HEALTHY] ?? null) === null ? '-' : $s['oldest'][self::HEALTHY].'s',
            ])->values()->all(),
        );

        return implode("\n", $lines);
    }

    private function short(string $account): string
    {
        return str_replace('wl-', '', $account);
    }

    /**
     * Plain-text table (same look as $this->table(), but returned as a string so it can be saved).
     */
    private function renderTable(array $headers, array $rows): string
    {
        $widths = array_map(fn ($i) => max(array_map(fn ($row) => mb_strlen((string) $row[$i]), [$headers, ...$rows])), array_keys($headers));
        $line = '+'.implode('+', array_map(fn ($w) => str_repeat('-', $w + 2), $widths)).'+';
        $format = fn ($row) => '| '.implode(' | ', array_map(fn ($cell, $w) => str_pad((string) $cell, $w + strlen((string) $cell) - mb_strlen((string) $cell)), $row, $widths)).' |';

        return implode("\n", [$line, $format($headers), $line, ...array_map($format, $rows), $line]);
    }
}
