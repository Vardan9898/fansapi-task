<?php

namespace App\Refresh;

/**
 * A validated upstream profile. Only built by ProfilePayloadParser, so any instance is safe to store.
 */
final readonly class ParsedProfile
{
    /**
     * @param  string  $format  which response shape it came from: "top_level", "nested" or "fansapi"
     * @param  array<string, mixed>  $details  optional profile columns that passed validation (name, counts, payload, ...)
     */
    public function __construct(
        public int $likes,
        public int $revision,
        public string $format,
        public array $details = [],
    ) {}

    /**
     * Profile columns to write. Optional fields that are missing or invalid are simply not written,
     * so a partial response never blanks out data we already have.
     */
    public function attributes(): array
    {
        return [
            ...$this->details,
            'likes' => $this->likes,
            'revision' => $this->revision,
        ];
    }
}
