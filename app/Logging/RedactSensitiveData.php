<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Last line of defence: masks credentials before any log line is written,
 * even if a caller accidentally logs request headers or a config array.
 */
class RedactSensitiveData implements ProcessorInterface
{
    public const MASK = '[REDACTED]';

    /**
     * Matched against array keys (case-insensitive), at any nesting depth.
     * Includes the OnlyFans request-signing headers (sign, x-bc, app-token, user-id).
     */
    private const SENSITIVE_KEY = '/authorization|cookie|token|secret|password|passwd|api[_-]?key|session|^sess$|^sign$|^x-bc$|^user-id$/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactString($record->message),
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->redactString($value);
            }
        }

        return $data;
    }

    /**
     * Free-text values: bearer tokens and credential-looking query parameters.
     */
    private function redactString(string $value): string
    {
        $value = preg_replace('/(Bearer\s+)\S+/i', '$1'.self::MASK, $value);

        return preg_replace('/([?&](?:api[_-]?key|token|access_token|sign)=)[^&\s]+/i', '$1'.self::MASK, $value);
    }
}
