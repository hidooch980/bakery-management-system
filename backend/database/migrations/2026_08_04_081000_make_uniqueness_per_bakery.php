<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narrows the uniqueness that was written for one shop.
 *
 * A single bakery has one flour item, one quota for Mordad, one holiday on
 * the fifteenth. Two bakeries each have their own, and a rule that says
 * "only one in the whole database" would let the first shop to record
 * something stop the second from recording it at all.
 *
 * Left alone deliberately: a login must be unique across the whole system,
 * since someone signing in names only themselves, not their shop. And the
 * pairs already hung off a user or an allocation are narrow enough — those
 * parents belong to one bakery, so their children cannot stray.
 */
return new class extends Migration
{
    /** table => [old index name, columns that make a row unique within a shop] */
    private const INDEXES = [
        'inventory_items' => ['inventory_items_key_unique', ['key']],
        'flour_allocations' => ['flour_allocations_month_start_unique', ['month_start']],
        'holidays' => ['holidays_date_unique', ['date']],
        'work_starts' => ['work_start_unique', ['type', 'date']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => [$oldIndex, $columns]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'bakery_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($oldIndex, $columns) {
                $blueprint->dropUnique($oldIndex);
                $blueprint->unique(['bakery_id', ...$columns]);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [$oldIndex, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $oldIndex, $columns) {
                $blueprint->dropUnique($table.'_bakery_id_'.implode('_', $columns).'_unique');
                $blueprint->unique($columns, $oldIndex);
            });
        }
    }
};
