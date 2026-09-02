<?php

namespace App\Services\Sending;

use RuntimeException;

/**
 * The transport gave up waiting. The message may or may not have left, so
 * the caller must record `unknown` and never retry automatically.
 */
class SendTimeoutException extends RuntimeException {}
