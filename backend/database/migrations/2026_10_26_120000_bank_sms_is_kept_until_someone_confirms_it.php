<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «پیامک‌های بانکی»: هر پیامک بانک خام نگه داشته می‌شود تا کسی تأییدش کند.
 *
 * یک پیامک به حداکثر یک تراکنش بانکی وصل می‌شود و هر تراکنش به حداکثر یک
 * پیامک (یکتا بودن bank_transaction_id) — همین است که نمی‌گذارد حقوقی که
 * دستی ثبت شده، با رسیدن پیامکش دوباره ثبت شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_sms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sender', 40);
            $table->string('bank', 40)->nullable();
            $table->text('body');
            $table->dateTime('received_at');
            $table->char('hash', 64);
            $table->enum('direction', ['in', 'out', 'unknown'])->default('unknown');
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('balance', 16, 2)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('suggested_category', 32)->nullable();
            $table->enum('status', ['pending', 'ambiguous', 'matched', 'confirmed', 'ignored'])->default('pending');
            $table->foreignId('bank_transaction_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['bakery_id', 'hash']);
            $table->index(['bakery_id', 'status']);
        });

        Schema::create('bank_sms_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->nullable()->constrained()->nullOnDelete();
            $table->string('card_last4', 4);
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['bakery_id', 'card_last4']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_sms_cards');
        Schema::dropIfExists('bank_sms');
    }
};
