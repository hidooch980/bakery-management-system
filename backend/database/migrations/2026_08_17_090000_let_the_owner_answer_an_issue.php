<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A place to record that the owner has seen an issue and decided about it.
 *
 * The issue centre derives everything it shows from the current records, so
 * a problem disappears the moment the data is put right. That is correct
 * for mistakes. It is wrong for decisions: the shop pays no wages through
 * the system, keeps utilities and rent at zero, and carries a seller's
 * balance on purpose. Those three are reported every time the page is
 * opened — one of them as «بحرانی» — and they never will be otherwise.
 *
 * An alarm that cannot be answered gets ignored, and then the day a real
 * one sounds nobody looks. So the owner can answer an issue: it moves out
 * of the open list into a decided one, with who decided, when, and why.
 *
 * Nothing is deleted and nothing is hidden. The decided list stays on the
 * page, and `magnitude` is what stops an answer from becoming a mute
 * button: the size of the problem at the moment it was answered. If a
 * seller's 31,900,000 becomes 90,000,000, the issue is not the one that
 * was decided about, and it comes back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->constrained()->cascadeOnDelete();

            // The scanner's own key — 'wages-never-recorded',
            // 'seller-account-5'. Stable across scans by construction:
            // keys carry ids, never amounts.
            $table->string('issue_key');

            $table->string('title');
            $table->string('severity', 16);
            $table->text('note')->nullable();

            // Null where the issue has no natural quantity — a missing
            // setting is present or it is not. Those stay answered until
            // the owner asks for them back.
            $table->decimal('magnitude', 20, 4)->nullable();

            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bakery_id', 'issue_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_acknowledgements');
    }
};
