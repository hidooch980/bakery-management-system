<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed a figure, when, and what it was before.
 *
 * This shop has carried four ten-times errors — the wages, two advance
 * postings, and the loan instalment. Every one of them survived because
 * nothing anywhere recorded what the number had been. Each was found
 * months later by arithmetic that refused to agree, and each cost a
 * session of guesswork to attribute. «تاریخچهٔ مالی overwrite نشود» is the
 * shop's own first rule and there was no table backing it.
 *
 * Deliberately append-only and deliberately narrow.
 *
 *   - **Append-only.** The model refuses updates and deletes. An audit
 *     trail that can be edited answers a different question from the one
 *     it was built for, and answers it wrongly.
 *   - **Narrow.** It records the records that move money or goods, not
 *     every row in the database. A log that catches everything is read by
 *     nobody, and the point is to be read on the day a figure is disputed.
 *
 * `before` and `after` hold only the fields that actually changed. Storing
 * whole rows would bury the one number that moved under thirty that did
 * not — and the disputed figure is always one number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // created / updated / deleted.
            $table->string('event', 16);

            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');

            // Kept as plain text beside the ids: a deleted payslip leaves a
            // log row pointing at a record nobody can load any more, and
            // «فیش حقوقی عبدالله» is the whole of what the reader needs.
            $table->string('subject', 160)->nullable();

            // Nullable on purpose. A console command, the scheduler, or a
            // data migration all change figures with nobody signed in, and
            // recording that honestly is better than attributing it to
            // whoever happens to be first in the users table.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 100)->nullable();

            // Only the fields that moved.
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('ip', 45)->nullable();

            $table->foreignId('bakery_id')->nullable()->constrained()->cascadeOnDelete();

            $table->timestamps();

            // The two questions this table gets asked: «what happened to
            // this record» and «what happened on this day».
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['created_at']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
