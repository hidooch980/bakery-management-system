<?php

namespace App\Console\Concerns;

use App\Models\Bakery;
use App\Support\CurrentBakery;

trait ForEachBakery
{
    /** Run $work once inside every shop, each seeing only its own rows. */
    protected function forEachBakery(callable $work): void
    {
        foreach (Bakery::query()->orderBy('id')->get() as $bakery) {
            try {
                CurrentBakery::for($bakery->id, fn () => $work($bakery));
            } catch (\Throwable $e) {
                // One shop failing must not stop the others hearing.
                report($e);
                $this->error($bakery->name.': '.$e->getMessage());
            }
        }
    }
}
