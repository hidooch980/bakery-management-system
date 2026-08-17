<?php

use App\Models\InventoryItem;
use Illuminate\Database\Migrations\Migration;

/**
 * «هر کیسه خمیر ۱۰ کیلو هست، هر کیسه نمک ۲۵» — the owner, 2026-08-17.
 *
 * Salt and yeast were treated as loose goods that only ever read in
 * kilograms, on the belief that they are weighed rather than counted. They
 * are weighed *into the dough*. They arrive in sacks like everything else,
 * and the owner reads his store in sacks.
 *
 * This moves no stock. It records the size of one sack, so 8.52 kg of
 * yeast can be read as what it actually is — less than one bag left.
 */
return new class extends Migration
{
    private const SACKS = [
        InventoryItem::SALT => 25.0,
        InventoryItem::YEAST_DRY => 10.0,
    ];

    public function up(): void
    {
        foreach (self::SACKS as $key => $kg) {
            // Only where nothing is set. A shop that has already answered
            // this for itself is not overruled by a default.
            InventoryItem::withoutGlobalScopes()
                ->where('key', $key)
                ->whereNull('bag_weight_kg')
                ->update(['bag_weight_kg' => $kg]);
        }
    }

    public function down(): void
    {
        foreach (self::SACKS as $key => $kg) {
            InventoryItem::withoutGlobalScopes()
                ->where('key', $key)
                ->where('bag_weight_kg', $kg)
                ->update(['bag_weight_kg' => null]);
        }
    }
};
