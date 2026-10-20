<?php

use App\Models\RefreshAttempt;
use App\Upstream\Fake\FakeUpstream;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Retention: drop refresh_attempts older than LOG_REFRESH_DAYS (log files rotate on their own)
Schedule::command('model:prune', ['--model' => [RefreshAttempt::class]])
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Fake upstream controls (local/testing only)
|--------------------------------------------------------------------------
| e.g. php artisan fake-upstream:push default madison420ivy new_format --likes=121000 --revision=11
*/

Artisan::command('fake-upstream:push {account} {username} {type} {--likes=} {--revision=} {--times=1}', function (FakeUpstream $fake) {
    $step = array_filter([
        'type' => $this->argument('type'),
        'likes' => $this->option('likes') === null ? null : (int) $this->option('likes'),
        'revision' => $this->option('revision') === null ? null : (int) $this->option('revision'),
    ], fn ($value) => $value !== null);

    try {
        $fake->push($this->argument('account'), $this->argument('username'), $step, (int) $this->option('times'));
    } catch (InvalidArgumentException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $this->info("Queued {$this->option('times')}x ".json_encode($step)." for {$this->argument('account')}/{$this->argument('username')}");
})->purpose('Script the next fake upstream response(s) for a profile');

Artisan::command('fake-upstream:default {account} {type=new_format} {--username=*} {--likes=} {--revision=} {--auto}', function (FakeUpstream $fake) {
    $step = $this->option('auto')
        ? ['type' => 'new_format', 'auto' => true]
        : array_filter([
            'type' => $this->argument('type'),
            'likes' => $this->option('likes') === null ? null : (int) $this->option('likes'),
            'revision' => $this->option('revision') === null ? null : (int) $this->option('revision'),
        ], fn ($value) => $value !== null);

    try {
        foreach ($this->option('username') ?: ['*'] as $username) {
            $fake->setDefault($this->argument('account'), $username, $step);
        }
    } catch (InvalidArgumentException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $this->info('Default '.json_encode($step).' set');
})->purpose('Set the repeated fake upstream response (whole account unless --username)');

Artisan::command('fake-upstream:rate-limit {account} {seconds}', function (FakeUpstream $fake) {
    $fake->rateLimit($this->argument('account'), (int) $this->argument('seconds'));
    $this->info((int) $this->argument('seconds') > 0
        ? "Account {$this->argument('account')} returns 429 for {$this->argument('seconds')}s"
        : "Rate limit cleared for {$this->argument('account')}");
})->purpose('Make the fake upstream rate-limit an account for N seconds (0 clears)');

Artisan::command('fake-upstream:reset', function (FakeUpstream $fake) {
    $fake->reset();
    $this->info('Fake upstream scenarios cleared');
})->purpose('Clear all fake upstream scenarios');

Artisan::command('fake-upstream:latency {account} {ms}', function (FakeUpstream $fake) {
    $fake->latency($this->argument('account'), (int) $this->argument('ms'));
    $this->info("Account {$this->argument('account')} responses take {$this->argument('ms')}ms");
})->purpose('Make every fake upstream response for an account slow (0 clears)');
