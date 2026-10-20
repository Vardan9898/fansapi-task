<?php

namespace App\Refresh;

use RuntimeException;

/**
 * Marks a refresh job as failed (visible in Horizon) when retrying cannot help:
 * 4xx, an invalid payload, or retries exhausted.
 */
class PermanentRefreshFailure extends RuntimeException {}
