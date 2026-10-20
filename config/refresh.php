<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | One knob for both the refresh log files and refresh_attempts rows, so the
    | log viewer and the DB history always cover the same period.
    |
    */

    'retention_days' => (int) env('LOG_REFRESH_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Refresh handler
    |--------------------------------------------------------------------------
    |
    | fixed:  validates both response formats, writes only newer revisions and
    |         records the real outcome of every attempt.
    | legacy: the production code that caused the incident (kept to reproduce it
    |         and to run the same workload before/after the fix).
    |
    */

    'handler' => env('REFRESH_HANDLER', 'fixed'),

    // A valid newer response that drops likes by more than this share is stored but logged as suspicious
    'suspicious_drop_ratio' => (float) env('REFRESH_SUSPICIOUS_DROP_RATIO', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Queue behaviour (fixed handler)
    |--------------------------------------------------------------------------
    |
    | account_concurrency: max workers refreshing one account at the same time;
    |   a bursting account can never take more than this many workers.
    | slot_ttl_seconds: a concurrency slot held by a worker that died is freed
    |   after this (must be >= the job timeout, RefreshProfile::TIMEOUT).
    | cooldown: after a 429 without Retry-After, the account pauses for a
    |   randomized, exponentially growing time (base * 2^(streak-1), max).
    | backoff / max_retries: 5xx and timeouts retry with exponential backoff and
    |   jitter, at most max_retries times; 429s are not counted (they are
    |   bounded by retry_window_minutes, after which the job fails).
    |
    */

    'account_concurrency' => (int) env('REFRESH_ACCOUNT_CONCURRENCY', 2),

    'slot_ttl_seconds' => (int) env('REFRESH_SLOT_TTL', 30),

    'cooldown' => [
        'base_seconds' => (int) env('REFRESH_COOLDOWN_BASE', 5),
        'max_seconds' => (int) env('REFRESH_COOLDOWN_MAX', 300),
    ],

    'backoff' => [
        'base_seconds' => (int) env('REFRESH_BACKOFF_BASE', 2),
        'max_seconds' => (int) env('REFRESH_BACKOFF_MAX', 60),
    ],

    'max_retries' => (int) env('REFRESH_MAX_RETRIES', 4),

    'retry_window_minutes' => (int) env('REFRESH_RETRY_WINDOW', 30),

    // Chaos testing only (workload:crash-replay): SIGKILL the worker right after a successful DB write
    'chaos_kill_after_write' => (bool) env('REFRESH_CHAOS_KILL_AFTER_WRITE', false),

];
