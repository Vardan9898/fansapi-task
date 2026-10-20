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

];
