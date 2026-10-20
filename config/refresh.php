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

];
