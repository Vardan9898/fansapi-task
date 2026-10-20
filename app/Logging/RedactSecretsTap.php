<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Channel "tap": attaches the redaction processor to file channels (daily, single, refresh).
 * Laravel only reads the `processors` config key for the `monolog` driver, so taps are used instead.
 */
class RedactSecretsTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new RedactSensitiveData);
    }
}
