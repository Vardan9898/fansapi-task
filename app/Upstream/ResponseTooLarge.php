<?php

namespace App\Upstream;

use RuntimeException;

/**
 * Thrown from the download progress callback to abort a body larger than max_body_bytes.
 * Guzzle wraps it in a ConnectionException; UpstreamClient unwraps it again.
 */
class ResponseTooLarge extends RuntimeException {}
