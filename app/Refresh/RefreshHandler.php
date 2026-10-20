<?php

namespace App\Refresh;

use App\Enums\RefreshOutcome;
use App\Models\Profile;

/**
 * One refresh attempt for one profile: fetch from upstream, decide, write, record.
 * Implementations: LegacyRefreshHandler (the production bug), selected by config('refresh.handler').
 */
interface RefreshHandler
{
    /**
     * Short name stored on every attempt row ("legacy", "fixed").
     */
    public function name(): string;

    public function refresh(Profile $profile, AttemptContext $context): RefreshOutcome;
}
