<?php

use App\Models\BankAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks which account is the drawer rather than a bank.
 *
 * Cash collected against a debt has to land somewhere, and the code that
 * banks it needs to find the till without being told its name. Matching on
 * "صندوق نقد" would work until someone renames it on a screen that invites
 * renaming, and then cash would quietly stop being recorded again.
 *
 * One at a time, like is_default: two tills is a question with no answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->boolean('is_cash_box')->default(false)->after('is_default');
        });

        BankAccount::where('title', 'صندوق نقد')
            ->orderBy('id')
            ->limit(1)
            ->update(['is_cash_box' => true]);
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn('is_cash_box');
        });
    }
};
