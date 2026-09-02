<?php

namespace App\Services\Ai;

use RuntimeException;

/** Rate limit / overloaded / network: the lead goes back to pending untouched. */
class AiTransientException extends RuntimeException {}
