<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «کارکنان نان اگه بدون پول بردن، در فیش حقوقشان پایان ماه حساب بشه و کسر
 * بشه» — the owner, 1405/06/10. «فروشنده انتخاب می‌کنه» — the seller names
 * who took it, at the moment it goes.
 *
 * Bread that leaves without money is already recorded, as payment type
 * «منزل», and it is deliberately charged to nobody: it is kept out of the
 * money-gap check and off the seller's account. That was right — the
 * seller handed it over, they did not eat it — but it left the shop with
 * no way to say *which* employee took it.
 *
 * `sales.user_id` is the seller. Reading it as the consumer is exactly the
 * mistake that nearly charged محمد حنیف 7,100,000 Rial of somebody else's
 * shortfall out of his own pocket, so the consumer gets its own column
 * rather than borrowing one that means something else.
 *
 * The value is frozen when the bread goes, like a shortfall is, so a later
 * change to the bread price cannot rewrite what somebody already owed.
 *
 * Recovery mirrors staff advances rather than a settled-on stamp: a
 * payslip absorbs what it can and the rest waits for next month, which
 * needs a row per part-payment. A stamp would have had to take each sale
 * whole or leave it, and a single sale worth more than the available pay
 * would then have waited for ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('consumed_by_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->decimal('consumed_amount', 14, 2)
                ->nullable()
                ->after('shortfall_amount');
        });

        Schema::create('salary_bread_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            // Every record belongs to a shop. A table created after the
            // migration that added this column everywhere has to carry it
            // itself, or BelongsToBakery filters on a column that is not
            // there the first time a second shop is opened.
            $table->foreignId('bakery_id')->nullable()->index();
            $table->timestamps();

            // One payslip can take from one sale once. Without this a
            // re-save that failed halfway could double the recovery, which
            // is the shape of every ten-times error this shop has carried.
            $table->unique(['salary_payment_id', 'sale_id'], 'salary_bread_unique');
        });

        Schema::table('salary_payments', function (Blueprint $table) {
            // Kept apart from `deduction`, which is the hand-typed
            // «تنبیهی و کسورات», for the same reason `advance_deduction`
            // is: a derived figure added into a typed one cannot be
            // recomputed on a re-save without doubling.
            $table->decimal('bread_deduction', 14, 2)
                ->default(0)
                ->after('advance_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->dropColumn('bread_deduction');
        });

        Schema::dropIfExists('salary_bread_recoveries');

        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['consumed_by_user_id']);
            $table->dropColumn(['consumed_by_user_id', 'consumed_amount']);
        });
    }
};
