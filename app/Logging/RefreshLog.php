<?php

namespace App\Logging;

use App\Models\Profile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for the `refresh` channel (storage/logs/refresh-YYYY-MM-DD.log, see /log-viewer).
 *
 * Events are dot-named nouns ("fetch.started", "profile.updated") so they're easy to filter.
 * Levels: debug = request/response detail, info = normal outcome, warning = retryable failure
 * or skipped write, error = permanent failure / rejected payload / blocked account.
 */
class RefreshLog
{
    public const CHANNEL = 'refresh';

    /**
     * Run $callback with account/profile/job/attempt attached to every log line written inside it
     * (any channel), then restore the previous context. Laravel also carries Context into
     * jobs dispatched from inside the callback.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function forProfile(Profile $profile, callable $callback, ?string $jobUuid = null, ?int $attempt = null): mixed
    {
        return Context::scope($callback, array_filter([
            'account_id' => $profile->account_id,
            'profile_id' => $profile->id,
            'username' => $profile->username,
            'job_uuid' => $jobUuid,
            'attempt' => $attempt,
        ], fn ($value) => $value !== null));
    }

    public static function debug(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->debug($event, $context);
    }

    public static function info(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->info($event, $context);
    }

    public static function warning(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->warning($event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        Log::channel(self::CHANNEL)->error($event, $context);
    }
}
