<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\Sale;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    use ApiResponse;

    public const PAYMENT_TYPES = ['cash', 'card', 'credit', 'home', 'schools', 'other'];

    /**
     * Seller records the sale of a pending chane batch.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'chane_entry_id' => ['required', 'exists:chane_entries,id'],
            'payment_type' => ['required', 'in:'.implode(',', self::PAYMENT_TYPES)],
            'bread_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Sales to schools or offices should name the buyer.
        if (in_array($data['payment_type'], ['schools', 'credit'], true)
            && empty($data['customer_id'])) {
            return $this->error('برای این نوع پرداخت، انتخاب مشتری الزامی است.', 422);
        }

        $chane = ChaneEntry::find($data['chane_entry_id']);

        if ($chane->status !== 'pending') {
            return $this->error('این چانه قبلاً فروخته شده است.', 409);
        }

        $sale = DB::transaction(function () use ($data, $chane, $request) {
            // Default to the batch size when the seller did not split it.
            $breadCount = $data['bread_count'] ?? $chane->chane_count;

            // Whatever the batch held beyond what this sale accounts for is
            // a temporary debt against the seller — computed from the
            // batch's own count, never from client input, so it can't be
            // typed away.
            $shortfallCount = max(0, $chane->chane_count - $breadCount);
            $breadPrice = (float) (Bakery::first()->bread_price ?? 0);

            $sale = Sale::create([
                'chane_entry_id' => $chane->id,
                'user_id' => $request->user()->id,
                'payment_type' => $data['payment_type'],
                'bread_count' => $breadCount,
                'shortfall_count' => $shortfallCount ?: null,
                'shortfall_amount' => $shortfallCount > 0 ? round($shortfallCount * $breadPrice, 2) : null,
                'customer_id' => $data['customer_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            $chane->update(['status' => 'sold']);

            return $sale;
        });

        return $this->success($sale, 'فروش ثبت شد.', 201);
    }

    /**
     * The seller's sales for the current day.
     */
    public function today(Request $request): JsonResponse
    {
        $sales = Sale::where('user_id', $request->user()->id)
            ->whereDate('created_at', now()->toDateString())
            ->with(['chaneEntry:id,chane_count', 'customer:id,name,type'])
            ->latest()
            ->get();

        return $this->success([
            'sales' => $sales,
            'summary' => [
                'count' => $sales->count(),
                'bread_count' => (int) $sales->sum('bread_count'),
                'total_amount' => round((float) $sales->sum('amount'), 2),
                'total_amount_formatted' => \App\Support\Money::format($sales->sum('amount')),
                'currency' => \App\Support\Money::currency(),
                'currency_label' => \App\Support\Money::label(),
                'by_payment_type' => $sales->groupBy('payment_type')->map(fn ($g) => [
                    'count' => $g->count(),
                    'bread_count' => (int) $g->sum('bread_count'),
                    'amount' => round((float) $g->sum('amount'), 2),
                ]),
            ],
        ]);
    }

    public function paymentTypes(): JsonResponse
    {
        return $this->success(self::PAYMENT_TYPES);
    }
}
