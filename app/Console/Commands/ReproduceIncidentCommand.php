<?php

namespace App\Console\Commands;

use App\Enums\RefreshOutcome;
use App\Enums\UpstreamDriver;
use App\Jobs\RefreshProfile;
use App\Models\Account;
use App\Models\Profile;
use App\Upstream\Fake\FakeUpstream;
use Illuminate\Console\Command;
use Throwable;

/**
 * Replays every incident case through the real job + HTTP client against the fake upstream,
 * starting each case from the brief's state (likes 120000, revision 10), and prints what the
 * selected handler stored and recorded vs what it should have.
 */
class ReproduceIncidentCommand extends Command
{
    protected $signature = 'incident:reproduce {--handler= : legacy or fixed (default: config refresh.handler)}';

    protected $description = 'Reproduce the incident cases against the fake upstream and show stored data per case';

    private const ACCOUNT = 'incident-demo';

    private const USERNAME = 'madison420ivy';

    /**
     * steps: what the fake upstream answers, one job run per step.
     * expect: [likes, revision] that should be stored afterwards, and the outcome(s) that should be recorded.
     */
    private const CASES = [
        ['Old format, same data', [['type' => 'old_format', 'likes' => 120000, 'revision' => 10]], [120000, 10], ['duplicate']],
        ['New format profile.likes=121000 r11', [['type' => 'new_format', 'likes' => 121000, 'revision' => 11]], [121000, 11], ['updated']],
        ['Likes missing everywhere r12', [['type' => 'invalid', 'revision' => 12]], [120000, 10], ['invalid_payload']],
        ['429, empty body, no Retry-After', [['type' => 'rate_limited']], [120000, 10], ['rate_limited']],
        ['500, empty body', [['type' => 'server_error']], [120000, 10], ['server_error']],
        ['Timeout (no response)', [['type' => 'timeout']], [120000, 10], ['timeout']],
        ['Duplicate: r11 delivered twice', [
            ['type' => 'new_format', 'likes' => 121000, 'revision' => 11],
            ['type' => 'new_format', 'likes' => 121000, 'revision' => 11],
        ], [121000, 11], ['updated', 'duplicate']],
        ['Out of order: r11, then older r10', [
            ['type' => 'new_format', 'likes' => 121000, 'revision' => 11],
            ['type' => 'old_format', 'likes' => 120000, 'revision' => 10],
        ], [121000, 11], ['updated', 'stale']],
    ];

    public function handle(FakeUpstream $fake): int
    {
        if ($this->option('handler')) {
            config(['refresh.handler' => $this->option('handler')]);
        }

        $account = Account::firstOrCreate(['name' => self::ACCOUNT], ['upstream' => UpstreamDriver::Fake]);
        $profile = Profile::firstOrCreate(['account_id' => $account->id, 'username' => self::USERNAME]);

        $this->info('Handler: '.config('refresh.handler').'   upstream: '.config('upstream.drivers.fake.base_url'));

        $rows = [];
        $correct = 0;

        foreach (self::CASES as [$name, $steps, [$expectLikes, $expectRevision], $expectOutcomes]) {
            $this->resetProfile($profile);
            $lastAttemptId = (int) $profile->refreshAttempts()->max('id');

            $fake->reset();
            foreach ($steps as $step) {
                $fake->push(self::ACCOUNT, self::USERNAME, $step);
            }

            $errors = [];
            foreach ($steps as $step) {
                try {
                    RefreshProfile::dispatchSync($profile->id);
                } catch (Throwable $e) {
                    $errors[] = 'job failed: '.class_basename($e);
                }
            }

            $profile->refresh();
            $outcomes = $profile->refreshAttempts()->where('id', '>', $lastAttemptId)->orderBy('id')
                ->pluck('outcome')->map(fn (RefreshOutcome $outcome) => $outcome->value)->all();

            $ok = $profile->likes === $expectLikes && $profile->revision === $expectRevision && $outcomes === $expectOutcomes;
            $correct += (int) $ok;

            $rows[] = [
                $name,
                ($profile->likes ?? 'null').' / r'.($profile->revision ?? 'null'),
                implode(', ', [...$outcomes, ...$errors]) ?: '(nothing recorded)',
                "{$expectLikes} / r{$expectRevision}: ".implode(', ', $expectOutcomes),
                $ok ? 'OK' : 'BUG',
            ];
        }

        $fake->reset();

        $this->table(['Case (start: 120000 / r10)', 'Stored after', 'Recorded', 'Expected', 'Result'], $rows);
        $this->line("{$correct}/".count(self::CASES).' cases correct');

        return self::SUCCESS;
    }

    private function resetProfile(Profile $profile): void
    {
        $profile->update([
            'likes' => 120000,
            'revision' => 10,
            'last_success_at' => now()->subHour(),
            'last_attempt_at' => now()->subHour(),
            'last_outcome' => RefreshOutcome::Updated,
            'last_error' => null,
            'consecutive_failures' => 0,
        ]);
    }
}
