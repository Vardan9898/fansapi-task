<?php

namespace App\Enums;

/**
 * Which upstream an account fetches profiles from (config/upstream.php `drivers`).
 */
enum UpstreamDriver: string
{
    case Fake = 'fake';       // scriptable stand-in for the incident scenarios
    case FansApi = 'fansapi'; // real data via FansAPI's public profile endpoint
}
