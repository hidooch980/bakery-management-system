<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when an "out" movement would push an item's balance below zero. */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly string $itemName,
        public readonly float $available,
        public readonly float $requested,
        public readonly string $unit = 'kg',
    ) {
        parent::__construct(sprintf(
            'موجودی %s کافی نیست: %s %s در انبار موجود است، %s %s درخواست شده.',
            $itemName,
            number_format($available, 3),
            $unit,
            number_format($requested, 3),
            $unit,
        ));
    }
}
