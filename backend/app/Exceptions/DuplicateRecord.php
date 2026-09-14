<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A record that matches one already filed the same day.
 *
 * Thrown from inside the transaction that wrote it, so the write is rolled
 * back whole — no half-filed invoice, no stock moved, no money posted.
 */
class DuplicateRecord extends RuntimeException
{
    public function __construct(public readonly Model $twin)
    {
        parent::__construct('duplicate of '.$twin::class.' #'.$twin->getKey());
    }
}
