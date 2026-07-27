<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SettlementRequest;
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
        ]);

        $seller = $request->user();

        if (SettlementRequest::where('user_id', $seller->id)->pending()->exists()) {
            return $this->error('یک درخواست تسویه در انتظار تأیید دارید.', 409);
        }

        $owed = SellerSettlement::outstandingFor($seller);

        if ($owed['total'] <= 0) {
            return $this->error('مبلغی برای تسویه وجود ندارد.', 422);
        }

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
                : 0,
        ]);

        return $this->success(
            $this->present($settlement),
            'درخواست تسویه ثبت شد و در انتظار تأیید مدیر است.',
            201
        );
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
            'note' => $request->note,
            'rejection_reason' => $request->rejection_reason,
            'requested_on_display' => $request->requested_on_display,
            'confirmed_by' => $request->confirmedBy?->name,
        ];
    }
}
