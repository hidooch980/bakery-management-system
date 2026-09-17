<?php

namespace App\Http\Controllers\Api;

use App\Filament\Resources\SaleResource;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SettlementRequest;
use App\Support\AppCalendar;
use App\Support\Money;
use App\Support\SellerSettlement;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The seller's side of settling up. They say they have handed the money
 * over; the admin confirms it in the panel and the account clears then.
 */
class SettlementRequestController extends Controller
{
    use ApiResponse;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            // Cash and card settle the same debt but land in different
            // places, so the seller says how much went by each.
            'paid_cash' => ['nullable', 'numeric', 'min:0'],
            'paid_card' => ['nullable', 'numeric', 'min:0'],

            // The shop settles in more ways than two, so the seller may
            // send an amount per payment type. Cash and card are still
            // kept apart on their own columns, since one lands at the
            // bank and the other in the admin's hand.
            'payments' => ['nullable', 'array'],
            'payments.*.payment_type' => ['required', 'in:'.implode(',', SaleController::PAYMENT_TYPES)],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],

            // A seller settling only part of what they owe names the debts
            // the money covers. Omitted means the whole account, which is
            // what every older copy of the app sends.
            'sale_ids' => ['nullable', 'array'],
            'sale_ids.*' => ['integer'],

            // A seller paying against their running balance names an
            // amount instead of picking sales. The two are exclusive:
            // one says how much, the other says which.
            'amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $seller = $request->user();

        if (SettlementRequest::where('user_id', $seller->id)->pending()->exists()) {
            return $this->error('یک درخواست تسویه در انتظار تأیید دارید.', 409);
        }

        $chosen = $data['sale_ids'] ?? null;

        if ($chosen !== null) {
            // Only the seller's own open sales survive this, so an id
            // belonging to someone else — or already settled — is rejected
            // rather than silently ignored.
            $valid = SellerSettlement::outstandingSales($seller, $chosen)
                ->pluck('id')
                ->all();

            if (count($valid) !== count(array_unique($chosen))) {
                return $this->error('برخی از موارد انتخابی قابل تسویه نیستند.', 422);
            }

            $chosen = $valid;
        }

        $owed = SellerSettlement::outstandingFor($seller, $chosen);

        if ($owed['total'] <= 0) {
            return $this->error('مبلغی برای تسویه وجود ندارد.', 422);
        }

        $breakdown = $this->breakdownFrom($data);

        // A named amount is what the seller is actually handing over. It
        // pays down the running account oldest debt first, so it does not
        // have to match any particular sale.
        //
        // With no amount named, what is being settled is whatever the
        // seller said they were handing over — and only when they said
        // nothing at all is it the whole account.
        //
        // It used to be the whole account whenever `amount` was absent,
        // however little the seller named beside it. A handover of ۴۰۰
        // against a debt of ۴۵۰ therefore closed the account for ۴۵۰,
        // banked the ۴۰۰, and forgave the ۵۰ — no credit, no remaining
        // debt, nothing on any page to say it had happened. The seller
        // was simply fifty thousand better off and the shop could not
        // have found it.
        //
        // «مبلغ تسویه باید بیشتر از صفر باشد» below still catches a
        // handover that names only zeroes, so this cannot settle an
        // account for nothing.
        $named = round(
            (isset($data['paid_cash']) ? Money::toToman($data['paid_cash']) : 0)
                + (isset($data['paid_card']) ? Money::toToman($data['paid_card']) : 0)
                + array_sum($breakdown),
            2,
        );

        // `paid_cash`/`paid_card` and `payments` are two ways of saying
        // the same thing, so a caller sending both would be counted twice.
        // The app sends `payments`; older copies send the pair.
        if ($breakdown !== [] && (isset($data['paid_cash']) || isset($data['paid_card']))) {
            return $this->error(
                'هم مبلغ نقد و کارت فرستاده شده و هم ریز پرداخت‌ها. یکی را بفرستید.',
                422,
            );
        }

        $paying = match (true) {
            isset($data['amount']) => Money::toToman($data['amount']),
            $named > 0 => $named,
            default => $owed['total'],
        };

        if ($paying <= 0) {
            return $this->error('مبلغ تسویه باید بیشتر از صفر باشد.', 422);
        }

        if ($paying > $owed['total'] + 0.01) {
            return $this->error('مبلغ واردشده از بدهی شما بیشتر است.', 422);
        }

        // How much of the handover was money, and of what kind.
        //
        // Only `cash` and `card` are money that reaches the shop. The other
        // lines a seller can name — منزل، مدارس، خیرات — clear the debt
        // without anything changing hands, so they settle the account and
        // must not be posted to any of it.
        //
        // The default below used to be the whole amount as cash whenever
        // `paid_cash` was absent, which is exactly what the app sends: it
        // names the split under `payments` and never sends `paid_cash`. So
        // a handover of ۴۰۰ نقد و ۲۰۰ کارت was stored as ۶۰۰ cash plus ۲۰۰
        // card, and the drawer and the bank between them recorded ۸۰۰ for
        // ۶۰۰ handed over — the till reading high by the card share on
        // every split settlement the shop has ever made.
        //
        // With no breakdown at all the whole amount is still cash, which is
        // what an older copy of the app means by sending neither.
        $paidCash = isset($data['paid_cash'])
            ? Money::toToman($data['paid_cash'])
            : ($breakdown === [] ? $paying : ($breakdown['cash'] ?? 0));

        $paidCard = isset($data['paid_card'])
            ? Money::toToman($data['paid_card'])
            : ($breakdown['card'] ?? 0);

        // Never more than the debt being cleared. Anything over that is
        // money the ledger would gain that the account never lost, and a
        // seller cannot hand over more than they owe — the check above
        // already refuses that for the amount itself.
        if ($paidCash + $paidCard > $paying + 0.01) {
            return $this->error(
                'مبلغ نقد و کارت روی هم از مبلغ تسویه بیشتر است.',
                422,
            );
        }

        // The figures are captured now rather than read back at
        // confirmation, so a sale recorded in between cannot quietly
        // change what the two of them agreed on.
        $settlement = SettlementRequest::create([
            'user_id' => $seller->id,
            'amount' => $paying,
            'cash_amount' => $owed['cash'],
            'difference_amount' => $owed['difference'],
            'shortfall_amount' => $owed['shortfall'],
            'note' => $data['note'] ?? null,
            'paid_cash' => $paidCash,
            'paid_card' => $paidCard,
            'paid_breakdown' => $breakdown ?: null,
            'sale_ids' => $chosen,
        ]);

        return $this->success(
            $this->present($settlement),
            'درخواست تسویه ثبت شد و در انتظار تأیید مدیر است.',
            201
        );
    }

    /**
     * The per-type amounts, in Toman, keyed by payment type.
     *
     * @return array<string, float>
     */
    private function breakdownFrom(array $data): array
    {
        $breakdown = [];

        foreach ($data['payments'] ?? [] as $line) {
            $amount = Money::toToman((float) $line['amount']);

            if ($amount <= 0) {
                continue;
            }

            // A type sent twice is added up rather than overwritten.
            $breakdown[$line['payment_type']] =
                round(($breakdown[$line['payment_type']] ?? 0) + $amount, 2);
        }

        return $breakdown;
    }

    /** The seller's own requests, newest first. */
    public function index(Request $request): JsonResponse
    {
        $requests = SettlementRequest::where('user_id', $request->user()->id)
            ->with('confirmedBy:id,name')
            ->latest()
            ->limit(20)
            ->get();

        return $this->success([
            'pending' => $requests->firstWhere('is_pending', true)
                ? $this->present($requests->firstWhere('is_pending', true))
                : null,
            'history' => $requests->map(fn (SettlementRequest $r) => $this->present($r))->values(),
        ]);
    }

    private function present(SettlementRequest $request): array
    {
        return [
            'id' => $request->id,
            'amount' => Money::convert((float) $request->amount),
            'amount_formatted' => $request->amount_formatted,
            'status' => match (true) {
                $request->is_confirmed => 'confirmed',
                $request->is_rejected => 'rejected',
                default => 'pending',
            },
            'status_label' => $request->status_label,
            'paid_cash' => Money::convert((float) $request->paid_cash),
            'paid_cash_formatted' => Money::format((float) $request->paid_cash),
            'paid_card' => Money::convert((float) $request->paid_card),
            'paid_card_formatted' => Money::format((float) $request->paid_card),
            'paid_breakdown' => collect($request->paid_breakdown ?? [])
                ->map(fn ($amount, $type) => [
                    'payment_type' => $type,
                    'label' => SaleResource::PAYMENT_LABELS[$type] ?? $type,
                    'amount' => Money::convert((float) $amount),
                    'amount_formatted' => Money::format((float) $amount),
                ])->values(),
            'note' => $request->note,
            'rejection_reason' => $request->rejection_reason,
            'requested_on_display' => $request->requested_on_display,
            'confirmed_by' => $request->confirmedBy?->name,
        ];
    }

    /**
     * The sales the seller could hand over today, one line each, so they can
     * pick the ones this money covers instead of settling the lot.
     */
    public function settleable(Request $request): JsonResponse
    {
        $seller = $request->user();

        $lines = SellerSettlement::outstandingSales($seller)
            ->get()
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'amount' => Money::convert($sale->seller_account_amount),
                'amount_formatted' => Money::format($sale->seller_account_amount),
                'payment_type' => $sale->payment_type,
                'payment_label' => SaleResource::PAYMENT_LABELS[$sale->payment_type] ?? $sale->payment_type,
                'sold_on_display' => AppCalendar::dateTime($sale->created_at),
                'customer' => $sale->customer?->name,
                // What the line is made of, so the seller can tell a cash
                // sale apart from bread nobody paid for.
                'cash_held' => Money::convert($sale->cash_held),
                'open_credit' => Money::convert($sale->open_credit),
                'open_shortfall' => Money::convert($sale->open_shortfall),
            ])
            // A sale can sit in the outstanding set and still owe nothing —
            // a difference that cancels the cash, say. Offering it would let
            // a seller submit a settlement worth zero.
            ->filter(fn (array $line) => $line['amount'] > 0)
            ->values();

        return $this->success([
            'lines' => $lines,
            'total' => Money::convert((float) $lines->sum('amount')),
            'total_formatted' => Money::format((float) $lines->sum('amount')),
        ]);
    }

    /**
     * The seller's running account: one figure they can pay against.
     */
    public function account(Request $request): JsonResponse
    {
        $account = SellerSettlement::runningBalanceFor($request->user());

        return $this->success([
            'debt' => Money::convert($account['debt']),
            'debt_formatted' => Money::format($account['debt']),
            'credit' => Money::convert($account['credit']),
            'credit_formatted' => Money::format($account['credit']),
            'balance' => Money::convert($account['balance']),
            'balance_formatted' => Money::format($account['balance']),
            'cash' => Money::convert($account['components']['cash']),
            'shortfall' => Money::convert($account['components']['shortfall']),
            'difference' => Money::convert($account['components']['difference']),
            'uncollected_credit' => Money::convert($account['components']['credit']),
            'uncollected_credit_formatted' => Money::format($account['components']['credit']),
            // The shop counts this debt in loaves — "I have accounted for
            // five hundred" — so the count travels beside the money.
            'loaves' => $account['components']['loaves'],
            'cash_loaves' => $account['components']['cash_loaves'],
            'shortfall_loaves' => $account['components']['shortfall_loaves'],
            'loaf_price' => Money::convert(SellerSettlement::loafPrice()),
        ]);
    }
}
