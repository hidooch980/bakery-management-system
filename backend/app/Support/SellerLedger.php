<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\SellerSettlementRecord;
use App\Models\SettlementRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One seller's account as a ledger: every debt the seller took on and every
 * handover, in date order, with the balance running beside them.
 *
 * The ledger is the independent check. The figure on the screens is worked
 * out from which sales are still open, and a bug in how payments close
 * sales can drift it from the truth without anything looking wrong. The
 * ledger never asks which sale a payment closed — only what was owed and
 * what was paid — so when the two disagree, the screens are wrong.
 *
 * Customer credit (schools, نسیه) is not here: that money is with the
 * customer, not the seller.
 */
class SellerLedger
{
    /** Kinds of handover record that are bookkeeping, not money handed over. */
    public const NOT_MONEY = ['correction', 'backfill'];

    /** @return Collection<int, array{at: \Illuminate\Support\Carbon, kind: string, label: string, debit: float, credit: float, ref: ?int}> */
    public static function entries(User $seller): Collection
    {
        $debts = Sale::query()
            ->where('user_id', $seller->id)
            ->where(function ($q) {
                $q->whereIn('payment_type', Sale::CASH_TYPES)
                    ->orWhere('shortfall_count', '>', 0)
                    ->orWhere('amount_difference', '!=', 0);
            })
            ->get()
            ->map(function (Sale $s) {
                $cash = in_array($s->payment_type, Sale::CASH_TYPES, true) ? (float) $s->amount : 0.0;
                $short = (int) $s->shortfall_count > 0 ? (float) $s->shortfall_amount : 0.0;
                $diff = (float) $s->amount_difference;
                $parts = array_filter([
                    $cash > 0 ? 'فروش نقدی' : null,
                    $short > 0 ? 'کسری نان ('.self::fa((int) $s->shortfall_count).' عدد)' : null,
                    abs($diff) > 0.001 ? 'اختلاف صندوق' : null,
                ]);

                return [
                    'at' => $s->created_at,
                    'kind' => 'debt',
                    'label' => implode(' + ', $parts),
                    'debit' => round($cash + $short - $diff, 2),
                    'credit' => 0.0,
                    'ref' => $s->id,
                ];
            })
            ->filter(fn ($e) => abs($e['debit']) > 0.001);

        $reversedRequests = SellerSettlementRecord::query()
            ->where('user_id', $seller->id)
            ->whereNotNull('reversed_at')
            ->whereNotNull('settlement_request_id')
            ->pluck('settlement_request_id')
            ->all();

        $requests = SettlementRequest::query()
            ->where('user_id', $seller->id)
            ->whereNotNull('confirmed_at')
            ->whereNotIn('id', $reversedRequests)
            ->get()
            ->map(fn (SettlementRequest $r) => [
                'at' => $r->confirmed_at,
                'kind' => 'payment',
                'label' => 'تسویه #'.self::fa($r->id),
                'debit' => 0.0,
                'credit' => self::paidOn($r),
                'ref' => $r->id,
            ]);

        // Handovers the manager recorded directly, without a request.
        $direct = SellerSettlementRecord::query()
            ->where('user_id', $seller->id)
            ->whereNull('settlement_request_id')
            ->whereNull('reversed_at')
            ->whereNotIn('kind', self::NOT_MONEY)
            ->get()
            ->map(fn (SellerSettlementRecord $r) => [
                'at' => $r->created_at,
                'kind' => 'payment',
                'label' => 'تسویهٔ مستقیم',
                'debit' => 0.0,
                'credit' => round((float) $r->paid_cash + (float) $r->paid_card, 2),
                'ref' => null,
            ]);

        $running = 0.0;

        return $debts->concat($requests)->concat($direct)
            ->sortBy(fn ($e) => [$e['at']?->timestamp ?? 0, $e['kind'] === 'payment' ? 1 : 0])
            ->values()
            ->map(function ($e) use (&$running) {
                $running = round($running + $e['debit'] - $e['credit'], 2);
                $e['balance'] = $running;

                return $e;
            });
    }

    public static function paidOn(SettlementRequest $r): float
    {
        $paid = round((float) $r->paid_cash + (float) $r->paid_card, 2);

        return $paid > 0.001 ? $paid : round((float) $r->amount, 2);
    }

    /**
     * Ledger against screen.
     *
     * @return array{ledger: float, shown: float, difference: float, debts: float, paid: float, ok: bool}
     */
    public static function reconcile(User $seller): array
    {
        $entries = self::entries($seller);
        $debts = round($entries->sum('debit'), 2);
        $paid = round($entries->sum('credit'), 2);
        $ledger = round($debts - $paid, 2);
        $shown = SellerSettlement::balanceFor($seller)['balance'];
        $difference = round($shown - $ledger, 2);

        return [
            'ledger' => $ledger,
            'shown' => $shown,
            'difference' => $difference,
            'debts' => $debts,
            'paid' => $paid,
            // Rounding on bread prices is in whole Toman; anything under
            // one is not a discrepancy anyone can act on.
            'ok' => abs($difference) < 1,
        ];
    }

    /** The warning sentence, or null when the two agree. */
    public static function warning(array $r): ?string
    {
        if ($r['ok']) {
            return null;
        }

        return 'مغایرت حساب: ماندهٔ نمایش‌داده‌شده '.Money::format($r['shown'])
            .' است ولی جمع بدهی‌ها منهای جمع تسویه‌ها '.Money::format($r['ledger'])
            .' می‌شود (اختلاف '.Money::format(abs($r['difference'])).').';
    }

    /**
     * What a repair would change for one seller, without changing it.
     *
     * @return array{before: float, after: float, ledger: float, difference: float, missing_history: int, fixable: bool}
     */
    public static function plan(User $seller): array
    {
        $r = self::reconcile($seller);

        return [
            'before' => $r['shown'],
            // Only an account shown too high is closed down to the ledger.
            // One shown too low would mean reopening sales already handed
            // over, and that is a conversation, not a command.
            'after' => $r['difference'] >= 1 ? $r['ledger'] : $r['shown'],
            'ledger' => $r['ledger'],
            'difference' => $r['difference'],
            'missing_history' => self::requestsWithNoRecord($seller)->count(),
            'fixable' => $r['difference'] >= 1,
        ];
    }

    /** Confirmed handovers that left no row in the history. */
    public static function requestsWithNoRecord(User $seller)
    {
        return SettlementRequest::query()
            ->where('user_id', $seller->id)
            ->whereNotNull('confirmed_at')
            ->whereNotIn('id', SellerSettlementRecord::query()
                ->whereNotNull('settlement_request_id')
                ->select('settlement_request_id'));
    }

    /**
     * Bring the account down to what the ledger says and write the missing
     * history. Moves no money: the cash was banked when it was handed over.
     * Safe to run twice — the second run finds nothing to do.
     */
    public static function repair(User $seller, ?User $by = null): array
    {
        $plan = self::plan($seller);

        DB::transaction(function () use ($seller, $by, $plan) {
            foreach (self::requestsWithNoRecord($seller)->orderBy('confirmed_at')->get() as $r) {
                $row = new SellerSettlementRecord([
                    'user_id' => $seller->id,
                    'settled_by' => $r->confirmed_by ?? $by?->id,
                    'settlement_request_id' => $r->id,
                    'kind' => 'backfill',
                    'paid_cash' => (float) $r->paid_cash,
                    'paid_card' => (float) $r->paid_card,
                    'amount' => self::paidOn($r),
                    'sale_ids' => [],
                    'credit_ids' => [],
                    'note' => 'سابقهٔ تسویهٔ تأییدشده که در تاریخچه ثبت نشده بود',
                ]);
                $row->created_at = $r->confirmed_at;
                $row->updated_at = now();
                $row->save();
            }

            if ($plan['fixable']) {
                $applied = SellerSettlement::applyPayment($seller, $plan['difference']);
                SellerSettlementRecord::create([
                    'user_id' => $seller->id,
                    'settled_by' => $by?->id,
                    'kind' => 'correction',
                    'paid_cash' => 0,
                    'paid_card' => 0,
                    'amount' => $plan['difference'],
                    'sale_ids' => $applied['settled'],
                    'credit_ids' => $applied['credit_ids'],
                    'note' => 'اصلاح حساب: تسویه‌هایی که از بدهی کم نشده بودند',
                ]);
            }
        });

        return $plan;
    }

    public static function fa(int|string $n): string
    {
        return strtr((string) $n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }
}
