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

        // The figures are captured now rather than read back at
        // confirmation, so a sale recorded in between cannot quietly
        // change what the two of them agreed on.
        $settlement = SettlementRequest::create([
            'user_id' => $seller->id,
            'amount' => $owed['total'],
            'cash_amount' => $owed['cash'],
            'difference_amount' => $owed['difference'],
            'shortfall_amount' => $owed['shortfall'],
            'note' => $data['note'] ?? null,
            // Defaults to all of it in cash, which is the common case and
            // what an older copy of the app sends.
            'paid_cash' => isset($data['paid_cash'])
                ? Money::toToman($data['paid_cash'])
                : ($data['paid_card'] ?? null ? 0 : $owed['total']),
            'paid_card' => isset($data['paid_card'])
                ? Money::toToman($data['paid_card'])
                : ($breakdown['card'] ?? 0),
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
                'sold_on_display' => AppCalendar::dateTime($sale->sold_at),
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
}
