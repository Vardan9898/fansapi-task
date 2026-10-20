<?php

namespace App\Refresh;

use App\Enums\RefreshOutcome;
use App\Models\Profile;
use App\Upstream\UpstreamClient;

/**
 * THE PRODUCTION BUG, kept on purpose (REFRESH_HANDLER=legacy) so the incident can be
 * reproduced and measured before/after. Do not "fix" this class: the fix is a separate handler.
 *
 * What is wrong with it:
 *  1. reads likes only from the top level, so the new {"profile": {"likes": N}} shape is missed
 *  2. defaults a missing value to 0, so a 429/500 with an empty body or a format change wipes likes
 *  3. no validation and no revision check, so an older response arriving last overwrites newer data
 *  4. marks every response as a successful refresh, so dashboards and last_success_at lie
 *  5. no error handling, so a timeout throws and fails the job without any record
 */
class LegacyRefreshHandler implements RefreshHandler
{
    public function __construct(
        private UpstreamClient $client,
        private AttemptRecorder $recorder,
    ) {}

    public function name(): string
    {
        return 'legacy';
    }

    public function refresh(Profile $profile, AttemptContext $context): RefreshOutcome
    {
        $response = $this->client->fetchProfile($profile);

        $data = json_decode($response->body, true);
        $before = $profile->only(Profile::DATA_COLUMNS);

        $profile->update([
            'likes' => $data['likes'] ?? 0,
            'revision' => $data['revision'] ?? $profile->revision,
        ]);

        $this->recorder->record($profile, $this->name(), RefreshOutcome::Updated, $context, $response, [
            'revision_before' => $before['revision'],
            'revision_received' => $data['revision'] ?? null,
            'changes' => AttemptRecorder::changes($before, $profile),
        ]);

        return RefreshOutcome::Updated;
    }
}
