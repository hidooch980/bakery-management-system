<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every dealing with a customer that is not a sale: the call about an
 * unpaid invoice, the visit that agreed next month's order, the complaint
 * about a late delivery.
 *
 * Without this the only record of a customer is what they bought, so the
 * promise someone made on the phone lives in one person's memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();

            $table->string('type', 20);
            $table->text('summary');

            // What has to happen next, and when. A follow-up that is due
            // and not done is what the call list is built from.
            $table->date('follow_up_on')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['follow_up_on', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_interactions');
    }
};
