<?php

namespace App\Enums;

/**
 * What a single fetch attempt actually achieved. Stored per attempt, so a "completed"
 * queue job can be told apart from a profile that really got fresh data.
 */
enum RefreshOutcome: string
{
    case Updated = 'updated';                // valid, newer revision written
    case Duplicate = 'duplicate';            // valid, same revision already stored (re-run / replay)
    case Stale = 'stale';                    // valid, but older than what's stored (arrived out of order)
    case InvalidPayload = 'invalid_payload'; // response didn't pass validation, nothing written
    case RateLimited = 'rate_limited';       // HTTP 429
    case ServerError = 'server_error';       // HTTP 5xx
    case Timeout = 'timeout';                // connect/read timeout
    case Failed = 'failed';                  // permanent failure (e.g. 404), not retried

    /**
     * Stored data is confirmed current after this attempt.
     */
    public function isSuccessful(): bool
    {
        return in_array($this, [self::Updated, self::Duplicate], true);
    }
}
