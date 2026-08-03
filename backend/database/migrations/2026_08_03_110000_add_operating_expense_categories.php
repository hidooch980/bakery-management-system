<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The costs a bakery actually pays that had nowhere to go.
 *
 * Freight, unloading, salt, bought-in dough and insurance were all being
 * filed under "other", which hides them from the expense breakdown — the
 * one report that is supposed to say where the money went.
 */
return new class extends Migration
{
    private const CATEGORIES = [
        'flour', 'fuel', 'utilities', 'rent', 'maintenance', 'salary',
        'freight', 'unloading', 'salt', 'dough', 'insurance', 'tax',
        'equipment', 'packaging', 'other',
    ];

    private const PREVIOUS = [
        'flour', 'fuel', 'utilities', 'rent', 'maintenance', 'salary', 'other',
    ];

    public function up(): void
    {
        $this->setEnum(self::CATEGORIES);
    }

    public function down(): void
    {
        // Anything filed under a category that is going away becomes "other",
        // or the column change would refuse the rows still using it.
        DB::table('expenses')
            ->whereNotIn('category', self::PREVIOUS)
            ->update(['category' => 'other']);

        $this->setEnum(self::PREVIOUS);
    }

    private function setEnum(array $values): void
    {
        $list = collect($values)->map(fn ($v) => "'".$v."'")->implode(',');

        DB::statement("ALTER TABLE expenses MODIFY category ENUM({$list}) NOT NULL");
    }
};
