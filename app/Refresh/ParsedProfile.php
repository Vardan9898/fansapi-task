<?php

namespace App\Refresh;

/**
 * A validated upstream profile. Only built by ProfilePayloadParser, so any instance is safe to store.
 */
final readonly class ParsedProfile
{
    /**
     * @param  string  $format  which response shape it came from: "top_level" or "nested"
     */
    public function __construct(
        public int $likes,
        public int $revision,
        public string $format,
    ) {}

    /**
     * Profile columns to write.
     */
    public function attributes(): array
    {
        return [
            'likes' => $this->likes,
            'revision' => $this->revision,
        ];
    }
}
