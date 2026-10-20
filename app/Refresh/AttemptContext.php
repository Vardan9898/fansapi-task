<?php

namespace App\Refresh;

/**
 * Which queue job execution an attempt belongs to (null job uuid = run outside the queue).
 */
final readonly class AttemptContext
{
    public function __construct(
        public ?string $jobUuid,
        public int $attempt = 1,
    ) {}
}
