<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ties every record to a bakery, so one installation can run more than one.
 *
 * Everything that exists belongs to the shop that has been running all
 * along, so it is all stamped with the first bakery rather than being left
 * to a nullable column nobody would ever fill in. The column is not null
 * afterwards: a record with no shop would be visible to every shop.
 */
return new class extends Migration
{
    /**
     * Written as a literal list rather than read from the schema: a
     * migration has to keep meaning what it meant the day it ran, and the
     * table list moves on.
     */
    private const TABLES = [
        'users',
        'attendances',
        'bakery_shares',
        'bank_accounts',
        'bank_transactions',
        'chane_entries',
        'consignment_flours',
        'customer_interactions',
        'customers',
        'dough_entries',
        'expenses',
        'flour_allocation_periods',
        'flour_allocations',
        'flour_sales',
        'flour_stock_movements',
        'holidays',
        'incomes',
        'inventory_items',
        'inventory_movements',
        'salary_advance_recoveries',
        'salary_payments',
        'sales',
        'settlement_requests',
        'share_settlements',
        'staff_advances',
        'work_starts',
    ];

    public function up(): void
    {
        // The shop that has been running all along. Nothing exists that does
        // not belong to it, so there is nothing to decide between.
        $firstBakeryId = DB::table('bakeries')->orderBy('id')->value('id');

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'bakery_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('bakery_id')->nullable()->index();
            });

            if ($firstBakeryId !== null) {
                DB::table($table)->update(['bakery_id' => $firstBakeryId]);
            }
        }

        // Only once every row has one: a column made non-null while rows
        // are still empty would refuse the change outright.
        if ($firstBakeryId === null) {
            return;
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('bakery_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'bakery_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('bakery_id');
                });
            }
        }
    }
};
