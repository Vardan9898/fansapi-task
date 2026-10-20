<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP limits (all upstreams)
    |--------------------------------------------------------------------------
    |
    | timeout: whole request in seconds. Must stay below the job timeout (see
    | config/horizon.php) so a hung upstream fails the attempt, not the worker.
    | max_body_bytes: the download is aborted past this size, so an unexpected
    | huge response can't blow up worker memory; it is treated as invalid.
    |
    */

    'timeout' => (float) env('UPSTREAM_TIMEOUT', 5),

    'connect_timeout' => (float) env('UPSTREAM_CONNECT_TIMEOUT', 2),

    'max_body_bytes' => (int) env('UPSTREAM_MAX_BODY_BYTES', 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Drivers (chosen per account: accounts.upstream)
    |--------------------------------------------------------------------------
    */

    'drivers' => [

        // FansAPI / OnlyFansAPI public profile endpoint: GET /api/profiles/{username}
        'fansapi' => [
            'base_url' => env('FANSAPI_BASE_URL', 'https://app.onlyfansapi.com/api'),
            'token' => env('FANSAPI_KEY'),
            'profile_path' => '/profiles/{username}',
        ],

        // Scriptable stand-in served by this app (routes/fake-upstream.php)
        'fake' => [
            'base_url' => env('FAKE_UPSTREAM_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/fake-upstream'),
            'token' => null,
            'profile_path' => '/{account}/profiles/{username}',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Fake upstream server
    |--------------------------------------------------------------------------
    |
    | Never enabled in production. timeout_sleep must exceed `timeout` above
    | so the "timeout" scenario really trips the client timeout.
    |
    */

    'fake' => [
        'enabled' => (bool) env('FAKE_UPSTREAM_ENABLED', env('APP_ENV') !== 'production'),
        'timeout_sleep' => (int) env('FAKE_UPSTREAM_TIMEOUT_SLEEP', 7),
    ],

];
