<?php

use App\Models\SellerWarningRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rules that turn a seller's own records into a warning for the manager
 * to approve or reject, through the same penalty requests a seller files.
 *
 * Every rule starts switched off. The nanino-sourced ones are named after
 * the complaint list in nanino's buyer guide; the shop decides the
 * thresholds and the wording before turning any of them on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_warning_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bakery_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('condition', 32);
            $table->decimal('threshold', 14, 2)->nullable();
            $table->string('reason', 32)->default('rule');
            $table->text('description')->nullable();
            $table->string('source', 32)->nullable();
            $table->string('source_url')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['bakery_id', 'condition']);
        });

        Schema::table('staff_penalty_requests', function (Blueprint $table) {
            // A rule files the request, not a person.
            $table->foreignId('requested_by')->nullable()->change();
            $table->foreignId('seller_warning_rule_id')->nullable()->after('requested_by')
                ->constrained('seller_warning_rules')->nullOnDelete();
            $table->unique(['seller_warning_rule_id', 'user_id', 'occurred_on'], 'penalty_rule_once_a_day');
        });

        $now = now();
        foreach (DB::table('bakeries')->pluck('id') as $bakeryId) {
            foreach (SellerWarningRule::DEFAULTS as $condition => $rule) {
                DB::table('seller_warning_rules')->insertOrIgnore($rule + [
                    'bakery_id' => $bakeryId,
                    'condition' => $condition,
                    'is_active' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('staff_penalty_requests', function (Blueprint $table) {
            $table->dropUnique('penalty_rule_once_a_day');
            $table->dropConstrainedForeignId('seller_warning_rule_id');
        });
        Schema::dropIfExists('seller_warning_rules');
    }
};
