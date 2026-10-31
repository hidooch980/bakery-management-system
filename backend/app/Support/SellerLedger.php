<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\SellerSettlementRecord;
use App\Models\SettlementRequest;
use App\Models\User;
use Illuminate\Support\Collection;

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

    public static function fa(int|string $n): string
    {
        return strtr((string) $n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }
}
