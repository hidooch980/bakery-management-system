<?php

namespace App\Models\Concerns;

use App\Models\Bakery;
use App\Support\CurrentBakery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ties a record to the shop it belongs to, and keeps it there.
 *
 * Every query is narrowed to the signed-in user's bakery and every new
 * record is stamped with it, so no screen, report or export has to remember
 * to filter — forgetting once would show one shop another's takings.
 *
 * The scope stands down when there is no bakery to scope to: a fresh
 * install, a console command that has not said which shop it means, or the
 * seeder building the first one. A shop that has only ever had one bakery
 * therefore behaves exactly as it did before any of this existed.
 */
trait BelongsToBakery
{
    protected static function bootBelongsToBakery(): void
    {
        static::addGlobalScope('bakery', function (Builder $query) {
            $bakeryId = CurrentBakery::id();

            if ($bakeryId !== null) {
                $query->where($query->getModel()->getTable().'.bakery_id', $bakeryId);
            }
        });

        static::creating(function ($model) {
            $model->bakery_id ??= CurrentBakery::id();
        });
    }

    public function bakery()
    {
        return $this->belongsTo(Bakery::class);
    }

    /** Deliberately crosses shops — for the console and for reports that own the choice. */
    public function scopeAcrossBakeries(Builder $query): Builder
    {
        return $query->withoutGlobalScope('bakery');
    }
}
