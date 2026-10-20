<?php

namespace App\Upstream;

use RuntimeException;

/**
 * No HTTP response at all: connect/read timeout or connection failure.
 */
class UpstreamTimeout extends RuntimeException {}
