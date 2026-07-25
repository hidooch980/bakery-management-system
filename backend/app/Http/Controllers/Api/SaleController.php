<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            'amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $chane = ChaneEntry::find($data['chane_entry_id']);

        if ($chane->status !== 'pending') {
            return $this->error('این چانه قبلاً فروخته شده است.', 409);
        }

        $sale = DB::transaction(function () use ($data, $chane, $request) {
            $sale = Sale::create([
                'chane_entry_id' => $chane->id,
                'user_id' => $request->user()->id,
                'payment_type' => $data['payment_type'],
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
            ->with('chaneEntry:id,chane_count')
            ->latest()
            ->get();

        return $this->success([
            'sales' => $sales,
            'summary' => [
                'count' => $sales->count(),
                'total_amount' => round((float) $sales->sum('amount'), 2),
                'by_payment_type' => $sales->groupBy('payment_type')->map->count(),
            ],
        ]);
    }

    public function paymentTypes(): JsonResponse
    {
        return $this->success(self::PAYMENT_TYPES);
    }
}
