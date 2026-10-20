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
    | legacy: the production code that caused the incident (kept to reproduce it
    | and to run the same workload before/after the fix).
    |
    */

    'handler' => env('REFRESH_HANDLER', 'legacy'),

];
