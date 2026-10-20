<?php

namespace App\Upstream;

/**
 * Raw upstream answer, deliberately NOT interpreted: deciding what a body means
 * (which format, valid or not) is the handler's job, so a broken handler and a
 * fixed one can be compared on exactly the same input.
 */
final readonly class UpstreamResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public int $durationMs,
        public ?string $retryAfter = null,
        public bool $truncated = false, // body exceeded max_body_bytes: download aborted, body discarded
    ) {}

    /**
     * Decoded JSON object, or null when the body is empty, not JSON, not an object or too large.
     */
    public function json(): ?array
    {
        if ($this->truncated || $this->body === '') {
            return null;
        }

        $data = json_decode($this->body, true);

        return is_array($data) ? $data : null;
    }

    public function bodyBytes(): int
    {
        return strlen($this->body);
    }
}
