<?php

namespace App\Exceptions;

use App\Models\Purchase;
use RuntimeException;

/** An invoice that matches one already filed today. Carries the twin. */
class DuplicatePurchase extends RuntimeException
{
    public function __construct(public readonly Purchase $twin)
    {
        parent::__construct('duplicate purchase of #'.$twin->id);
    }
}
