<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every automatic message the shop can send gets its own switch, and every
 * one starts off. Nobody should get an SMS because a deploy happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->constrained()->cascadeOnDelete();

            // SMS — each kind separately, all off.
            $table->boolean('sms_partner_weekly')->default(false);
            $table->boolean('sms_staff_penalty')->default(false);

            // Seller owes more than this (Toman) → the manager hears on
            // Bale and the seller sees it in the app. Null: no alert.
            $table->decimal('seller_debt_threshold', 14, 2)->nullable();

            // School/customer credit older than this many days is
            // reminded to the manager.
            $table->boolean('credit_reminder_enabled')->default(false);
            $table->unsignedSmallInteger('credit_reminder_days')->default(30);

            // Card sales against what the card reader deposited.
            $table->boolean('card_reader_check_enabled')->default(false);
            $table->decimal('card_reader_tolerance', 14, 2)->default(10000);

            $table->timestamps();
            $table->unique('bakery_id');
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('recipient_name')->nullable();
            // Never the whole number: the log is read by more people than
            // need to dial it.
            $table->string('phone_masked', 20)->nullable();
            $table->text('message');
            $table->string('channel', 20)->default('sms');
            $table->string('status', 20);
            $table->string('error')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['bakery_id', 'created_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // How much credit this customer may run up (Toman). Null: no limit.
            $table->decimal('credit_limit', 14, 2)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('credit_limit'));
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('notification_settings');
    }
};
