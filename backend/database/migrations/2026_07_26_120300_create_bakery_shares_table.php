<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who owns a share ("دنگ") of the bakery.
        Schema::create('bakery_shares', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 20)->nullable();

            // Traditionally the whole is six dang, but any denominator works
            // because each holder's cut is their share over the total.
            $table->decimal('dang', 8, 3);

            $table->boolean('is_active')->default(true);
            $table->string('note', 500)->nullable();

            $table->timestamps();
        });

        // A settlement pays a holder their cut of one period's profit.
        Schema::create('share_settlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bakery_share_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();

            $table->date('period_start');
            $table->date('period_end');
            $table->string('period_label')->nullable();

            // All three are snapshots of what the books said when the payout
            // was agreed, so a later correction cannot silently rewrite it.
            $table->decimal('period_profit', 14, 2)->default(0);
            $table->decimal('dang', 8, 3);
            $table->decimal('amount', 14, 2)->default(0);

            $table->date('paid_on')->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['bakery_share_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_settlements');
        Schema::dropIfExists('bakery_shares');
    }
};
