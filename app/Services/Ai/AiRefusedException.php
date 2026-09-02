<?php

namespace App\Services\Ai;

use RuntimeException;

/** The model declined (safety) or produced no usable draft. Counts as an attempt. */
class AiRefusedException extends RuntimeException {}
