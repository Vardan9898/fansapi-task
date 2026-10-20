<?php

namespace App\Refresh;

use App\Enums\RefreshOutcome;
use App\Logging\RefreshLog;
use App\Models\Profile;
use App\Models\RefreshAttempt;
use App\Upstream\UpstreamResponse;
use Illuminate\Support\Str;

/**
 * Records what one attempt achieved: an append-only refresh_attempts row, the profile's
 * health fields and one `refresh.attempt` log line. Shared by every handler, so the
 * broken and the fixed handler are measured the same way.
 */
class AttemptRecorder
{
    /**
     * @param  array{response_format?: string, revision_before?: int|null, revision_received?: int|null, changes?: array, error?: string}  $details
     */
    public function record(
        Profile $profile,
        string $handler,
        RefreshOutcome $outcome,
        AttemptContext $context,
        ?UpstreamResponse $response = null,
        array $details = [],
    ): RefreshAttempt {
        $error = isset($details['error']) ? Str::limit($details['error'], 490) : null;

        $attempt = RefreshAttempt::create([
            'profile_id' => $profile->id,
            'account_id' => $profile->account_id,
            'job_uuid' => $context->jobUuid,
            'attempt' => $context->attempt,
            'handler' => $handler,
            'outcome' => $outcome,
            'http_status' => $response?->status,
            'duration_ms' => $response?->durationMs,
            'response_format' => $details['response_format'] ?? null,
            'revision_before' => $details['revision_before'] ?? null,
            'revision_received' => $details['revision_received'] ?? null,
            'changes' => ($details['changes'] ?? []) ?: null,
            'error' => $error,
        ]);

        $profile->update([
            'last_attempt_at' => now(),
            'last_outcome' => $outcome,
            'last_error' => $outcome->isSuccessful() ? null : ($error ?? $outcome->value),
            'consecutive_failures' => match (true) {
                $outcome->isSuccessful() => 0,
                $outcome->isFailure() => $profile->consecutive_failures + 1,
                default => $profile->consecutive_failures, // stale: upstream fine, data just older
            },
            ...($outcome->isSuccessful() ? ['last_success_at' => now()] : []),
        ]);

        $level = match (true) {
            $outcome->isSuccessful() => 'info',
            $outcome === RefreshOutcome::InvalidPayload, $outcome === RefreshOutcome::Failed => 'error',
            default => 'warning',
        };

        RefreshLog::{$level}('refresh.attempt', [
            'handler' => $handler,
            'outcome' => $outcome->value,
            'http_status' => $response?->status,
            'duration_ms' => $response?->durationMs,
            'response_format' => $details['response_format'] ?? null,
            'revision_before' => $details['revision_before'] ?? null,
            'revision_received' => $details['revision_received'] ?? null,
            'changes' => $details['changes'] ?? [],
            'error' => $error,
            'consecutive_failures' => $profile->consecutive_failures,
        ]);

        return $attempt;
    }

    /**
     * Upstream data fields that differ between $before and the profile now: ['likes' => [old, new], ...].
     */
    public static function changes(array $before, Profile $profile): array
    {
        $changes = [];

        foreach (array_diff(Profile::DATA_COLUMNS, ['last_success_at']) as $column) {
            if (($before[$column] ?? null) !== $profile->{$column}) {
                $changes[$column] = [$before[$column] ?? null, $profile->{$column}];
            }
        }

        return $changes;
    }
}
