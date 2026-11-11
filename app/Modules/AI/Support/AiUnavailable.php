<?php

namespace App\Modules\AI\Support;

use RuntimeException;

/**
 * The provider could not answer (not configured, rate-limited, down, or the answer was not valid).
 */
class AiUnavailable extends RuntimeException {}
