<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the thing being acted on was taken by somebody else first.
 *
 * Distinct from a validation failure: nothing the caller sent was wrong,
 * and sending it again will not help. Somebody was simply ahead of them,
 * and the honest answer is to say so in the shop's own words.
 */
class AlreadyClaimedException extends RuntimeException {}
