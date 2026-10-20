<?php

use App\Models\RefreshAttempt;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention: drop refresh_attempts older than LOG_REFRESH_DAYS (log files rotate on their own)
Schedule::command('model:prune', ['--model' => [RefreshAttempt::class]])
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();
