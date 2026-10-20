<?php

namespace App\Refresh;

use RuntimeException;

/**
 * The upstream answered 2xx but the body can't be trusted; nothing may be written.
 */
class InvalidPayload extends RuntimeException
{
    public function __construct(string $reason, public readonly ?string $format = null)
    {
        parent::__construct($reason);
    }
}
