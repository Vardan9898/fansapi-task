<?php

namespace App\Refresh;

use App\Enums\RefreshOutcome;
use App\Logging\RefreshLog;
use App\Models\Profile;
use App\Upstream\UpstreamClient;
use App\Upstream\UpstreamResponse;
use App\Upstream\UpstreamTimeout;
use Illuminate\Support\Facades\DB;

/**
 * The fix. Every attempt ends in exactly one recorded outcome, and stored data only ever
 * changes from a validated response with a newer revision.
 */
class FixedRefreshHandler implements RefreshHandler
{
    public function __construct(
        private UpstreamClient $client,
        private ProfilePayloadParser $parser,
        private AttemptRecorder $recorder,
        private AccountThrottle $throttle,
    ) {}

    public function name(): string
    {
        return 'fixed';
    }

    public function refresh(Profile $profile, AttemptContext $context): RefreshOutcome
    {
        try {
            $response = $this->client->fetchProfile($profile);
        } catch (UpstreamTimeout $e) {
            return $this->record($profile, RefreshOutcome::Timeout, $context, null, ['error' => $e->getMessage()]);
        }

        if (! $response->isSuccessful()) {
            $outcome = $this->classifyFailure($response);

            if ($outcome === RefreshOutcome::RateLimited) {
                $this->throttle->rateLimited($profile->account, $response->retryAfter);
            }

            if ($response->status === 401) {
                // Credentials rejected: every profile of this account would fail the same way
                $profile->account->block('upstream rejected credentials (HTTP 401)');
            }

            return $this->record($profile, $outcome, $context, $response, [
                'error' => "HTTP {$response->status}".($response->body === '' ? ' (empty body)' : ''),
            ]);
        }

        $this->throttle->succeeded($profile->account);

        try {
            $parsed = $this->parser->parse($response);
        } catch (InvalidPayload $e) {
            return $this->record($profile, RefreshOutcome::InvalidPayload, $context, $response, [
                'response_format' => $e->format,
                'error' => $e->getMessage(),
            ]);
        }

        [$outcome, $profile, $before] = $this->write($profile, $parsed);

        return $this->record($profile, $outcome, $context, $response, [
            'response_format' => $parsed->format,
            'revision_before' => $before['revision'],
            'revision_received' => $parsed->revision,
            'changes' => $outcome === RefreshOutcome::Updated ? AttemptRecorder::changes($before, $profile) : [],
        ]);
    }

    /**
     * Compare-and-write under a row lock (the HTTP call already happened, so the lock is held
     * for a few milliseconds). Two workers holding responses for the same profile serialize
     * here, and only a strictly newer revision is written.
     *
     * @return array{0: RefreshOutcome, 1: Profile, 2: array} [outcome, profile as stored now, data before]
     */
    private function write(Profile $profile, ParsedProfile $parsed): array
    {
        return DB::transaction(function () use ($profile, $parsed) {
            $current = Profile::with('account')->lockForUpdate()->findOrFail($profile->id);
            $before = $current->only(Profile::DATA_COLUMNS);

            if ($current->revision !== null && $parsed->revision < $current->revision) {
                return [RefreshOutcome::Stale, $current, $before];
            }

            if ($current->revision === $parsed->revision) {
                if ($current->likes !== $parsed->likes) {
                    // Contract says one revision = one version of the data; keep what we have, flag it
                    RefreshLog::warning('refresh.revision_conflict', [
                        'revision' => $parsed->revision,
                        'stored_likes' => $current->likes,
                        'received_likes' => $parsed->likes,
                    ]);
                }

                return [RefreshOutcome::Duplicate, $current, $before];
            }

            $current->update($parsed->attributes());
            $this->warnOnSuspiciousDrop($before['likes'], $parsed->likes);

            return [RefreshOutcome::Updated, $current, $before];
        });
    }

    private function classifyFailure(UpstreamResponse $response): RefreshOutcome
    {
        return match (true) {
            $response->status === 429 => RefreshOutcome::RateLimited,
            $response->status >= 500 => RefreshOutcome::ServerError,
            default => RefreshOutcome::Failed, // 401, 403, 404, ...: retrying won't help
        };
    }

    /**
     * A valid, newer response may legitimately carry fewer likes, so it is stored; but a large
     * drop is exactly what the incident looked like, so it is made visible.
     */
    private function warnOnSuspiciousDrop(?int $previous, int $new): void
    {
        $ratio = config('refresh.suspicious_drop_ratio');

        if ($previous !== null && $previous > 0 && ($previous - $new) / $previous > $ratio) {
            RefreshLog::warning('refresh.suspicious_drop', [
                'previous_likes' => $previous,
                'new_likes' => $new,
                'drop_ratio' => round(($previous - $new) / $previous, 3),
            ]);
        }
    }

    private function record(Profile $profile, RefreshOutcome $outcome, AttemptContext $context, ?UpstreamResponse $response, array $details): RefreshOutcome
    {
        $this->recorder->record($profile, $this->name(), $outcome, $context, $response, $details);

        return $outcome;
    }
}
