<?php

use App\Models\BankTransaction;
use App\Models\StaffAdvance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The owner's own withdrawals are not pay brought forward.
 *
 * Five of them — «خودم» twice, «کار منزل», «خرید سنگ» and the 702,926,025
 * Rial «اختلاف» — were moved off the expense book yesterday and onto the
 * advance ledger, which was half right. Taking them out of expenses was
 * correct: they are not what it costs to run a bakery, and leaving them
 * there had the shop reading as though eighteen days of trading lost money.
 *
 * Calling them advances was not. An advance is wages paid early, and the
 * next payslip recovers it — so 967,426,025 Rial of them against a monthly
 * wage of 530,000,000 would have taken the owner's pay to nothing for two
 * months running and told him so on his own phone. He confirmed on
 * 2026-08-15 that this is his own money, not a draw against his wages.
 *
 * So they become what they always were: withdrawals from the account with a
 * reason written on them. Entered by hand rather than posted by a record,
 * which also means he can correct or remove any of them from the bank
 * statement screen — a posted row is rebuilt from its record and refuses to
 * be edited there.
 *
 * The amounts, dates and account do not move, so the balance does not
 * either. Only the claim about what the money was.
 *
 * 'manual' rather than 'share': 'share' belongs to ShareSettlement, and
 * borrowing it would put five rows that are not partner settlements into
 * every report that counts them.
 */
return new class extends Migration
{
    /** Written by the migration that moved these off the expense book. */
    private const MOVED_MARKER = 'از دفتر هزینه منتقل شد';

    private const OWNER_ID = 1;

    public function up(): void
    {
        DB::transaction(function () {
            $advances = StaffAdvance::withoutGlobalScopes()
                ->where('user_id', self::OWNER_ID)
                ->where('note', 'like', '%'.self::MOVED_MARKER)
                ->get();

            foreach ($advances as $advance) {
                BankTransaction::create([
                    'bank_account_id' => $advance->bank_account_id,
                    'user_id' => $advance->recorded_by,
                    'direction' => 'out',
                    'amount' => $advance->amount,
                    'reason' => 'manual',
                    'occurred_on' => $advance->paid_on,
                    'note' => self::describe((string) $advance->note),
                ]);

                // Clears the posting this advance had made, so the money
                // leaves the account once rather than twice.
                $advance->delete();
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $rows = BankTransaction::whereNull('source_type')
                ->where('note', 'like', 'برداشت شخصی%')
                ->get();

            foreach ($rows as $row) {
                StaffAdvance::withoutGlobalScopes()->create([
                    'user_id' => self::OWNER_ID,
                    'recorded_by' => $row->user_id,
                    'amount' => $row->amount,
                    'paid_on' => $row->occurred_on,
                    'bank_account_id' => $row->bank_account_id,
                    'note' => self::undescribe((string) $row->note),
                ]);

                $row->delete();
            }
        });
    }

    /** Keeps what the money was actually for, which is the useful part. */
    private static function describe(string $note): string
    {
        return match (true) {
            str_contains($note, 'کار منزل') => 'برداشت شخصی: کار منزل',
            str_contains($note, 'خرید سنگ') => 'برداشت شخصی: خرید سنگ',
            str_contains($note, 'برداشت شخصی') => 'برداشت شخصی: خودم',
            default => 'برداشت شخصی: اختلاف',
        };
    }

    private static function undescribe(string $note): string
    {
        return match (true) {
            str_contains($note, 'کار منزل') => 'شخصی: کار منزل — '.self::MOVED_MARKER,
            str_contains($note, 'خرید سنگ') => 'شخصی: خرید سنگ — '.self::MOVED_MARKER,
            str_contains($note, 'خودم') => 'برداشت شخصی — '.self::MOVED_MARKER,
            default => 'شخصی — '.self::MOVED_MARKER,
        };
    }
};
