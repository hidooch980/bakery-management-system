<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes the fresh-yeast tub from the warehouse.
 *
 * The shop was set up to stock both kinds, on the idea that fresh proves
 * faster and is what winter calls for. Every one of the first thirty-one
 * batches was mixed with dry. The owner asked for it to go rather than
 * stay as a choice nobody makes and a row nobody reads.
 *
 * It refuses rather than deletes if any batch on this install actually
 * used it. This shop is not the only one running this code, and a
 * migration that quietly threw away another bakery's production history
 * would be worse than one that stops and says why.
 */
return new class extends Migration
{
    public function up(): void
    {
        $used = DB::table('dough_entries')->where('yeast_type', 'wet')->count();

        if ($used > 0) {
            throw new RuntimeException(
                "این نصب {$used} خمیر با خمیرمایهٔ تر ثبت کرده است. "
                .'حذف قلم، تاریخچهٔ آن خمیرها را بی‌صاحب می‌کند. '
                .'ابتدا تکلیف آن ثبت‌ها روشن شود.'
            );
        }

        $items = DB::table('inventory_items')->where('key', 'yeast_wet')->get();

        foreach ($items as $item) {
            // The movements go with it. Left behind they would be stock
            // history pointing at an item that no longer exists, and
            // `stock:audit` checks exactly that — every movement has an
            // owner or has been reversed.
            DB::table('inventory_movements')
                ->where('inventory_item_id', $item->id)
                ->delete();

            DB::table('inventory_items')->where('id', $item->id)->delete();
        }
    }

    public function down(): void
    {
        // Put the tub back, empty. The five kilograms that were in it were
        // a manual opening figure, not a purchase, so there is nothing to
        // restore and inventing a movement would be worse than an empty
        // shelf.
        foreach (DB::table('bakeries')->pluck('id') as $bakeryId) {
            $exists = DB::table('inventory_items')
                ->where('key', 'yeast_wet')
                ->where('bakery_id', $bakeryId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('inventory_items')->insert([
                'key' => 'yeast_wet',
                'name' => 'خمیرمایه تر',
                'unit' => 'کیلوگرم',
                'bakery_id' => $bakeryId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
