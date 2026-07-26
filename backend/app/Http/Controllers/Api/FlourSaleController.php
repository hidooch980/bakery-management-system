<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlourSale;
use App\Models\InventoryItem;
use App\Support\DoughFormula;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlourSaleController extends Controller
{
    use ApiResponse;

    /**
     * Seller sells flour out of the warehouse, by the kilo or by the sack.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit' => ['required', 'in:'.implode(',', array_keys(FlourSale::UNITS))],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:100000'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'payment_type' => ['required', 'in:'.implode(',', SaleController::PAYMENT_TYPES)],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Credit sales must name the buyer, or the debt cannot be chased.
        if (in_array($data['payment_type'], FlourSale::DEBT_TYPES, true)
            && empty($data['customer_id'])) {
            return $this->error('برای این نوع پرداخت، انتخاب مشتری الزامی است.', 422);
        }

        $bagWeight = DoughFormula::fromBakery()->bagWeightKg;

        $weightKg = $data['unit'] === FlourSale::BAG
            ? round((float) $data['quantity'] * $bagWeight, 3)
            : round((float) $data['quantity'], 3);

        // Selling flour the warehouse does not have would push the balance
        // negative and silently corrupt every quota figure downstream.
        $balance = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;

        if ($weightKg > $balance) {
            return $this->error(
                'موجودی آرد کافی نیست. موجودی فعلی: '
                    .number_format($balance, 2).' کیلوگرم',
                422
            );
        }

        $sale = DB::transaction(fn () => FlourSale::create([
            'user_id' => $request->user()->id,
            'customer_id' => $data['customer_id'] ?? null,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'unit' => $data['unit'],
            'quantity' => $data['quantity'],
            'bag_weight_kg' => $data['unit'] === FlourSale::BAG ? $bagWeight : null,
            // An overridden price arrives in the display unit and is stored
            // as Toman; the configured fallback is already in Toman.
            'unit_price' => isset($data['unit_price'])
                ? Money::toToman($data['unit_price'])
                : FlourSale::defaultUnitPrice($data['unit']),
            'payment_type' => $data['payment_type'],
            'sold_on' => now()->toDateString(),
            'note' => $data['note'] ?? null,
        ]));

        return $this->success($this->present($sale), 'فروش آرد ثبت شد.', 201);
    }

    /**
     * Today's flour sales: the seller's own, or every seller's for someone
     * who can see the whole shop's figures.
     */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();

        $sales = FlourSale::query()
            ->whereDate('sold_on', now()->toDateString())
            ->unless(
                $user->can('view-all-reports'),
                fn ($q) => $q->where('user_id', $user->id)
            )
            ->with(['customer:id,name,type', 'user:id,name'])
            ->latest()
            ->get();

        return $this->success([
            'sales' => $sales->map(fn ($sale) => $this->present($sale))->values(),
            'summary' => [
                'count' => $sales->count(),
                'total_weight_kg' => round((float) $sales->sum('weight_kg'), 3),
                'bag_count' => round(
                    (float) $sales->where('unit', FlourSale::BAG)->sum('quantity'),
                    2
                ),
                'kg_quantity' => round(
                    (float) $sales->where('unit', FlourSale::KG)->sum('quantity'),
                    2
                ),
                'total_amount' => round((float) $sales->sum('amount'), 2),
                'total_amount_formatted' => Money::format($sales->sum('amount')),
                'currency' => Money::currency(),
                'currency_label' => Money::label(),
            ],
        ]);
    }

    /**
     * What the seller needs before writing a sale: the units, the going
     * rates and how much flour is actually left.
     */
    public function options(): JsonResponse
    {
        $bagWeight = DoughFormula::fromBakery()->bagWeightKg;
        $balance = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;

        return $this->success([
            'units' => collect(FlourSale::UNITS)
                ->map(fn ($label, $key) => [
                    'key' => $key,
                    'label' => $label,
                    'unit_price' => Money::convert(FlourSale::defaultUnitPrice($key)),
                    'unit_price_formatted' => Money::format(FlourSale::defaultUnitPrice($key)),
                ])
                ->values(),
            'bag_weight_kg' => $bagWeight,
            'available_kg' => $balance,
            'available_bags' => $bagWeight > 0 ? round($balance / $bagWeight, 2) : null,
            'payment_types' => SaleController::PAYMENT_TYPES,
            'currency' => Money::currency(),
            'currency_label' => Money::label(),
        ]);
    }

    /**
     * Amounts cross the wire in the configured display unit, like every
     * other money field in the API.
     */
    private function present(FlourSale $sale): array
    {
        return array_merge($sale->toArray(), [
            'unit_label' => $sale->unit_label,
            'quantity_label' => $sale->quantity_label,
            'weight_label' => $sale->weight_label,
            'unit_price' => Money::convert($sale->unit_price),
            'amount' => Money::convert($sale->amount),
            'amount_formatted' => $sale->amount_formatted,
            'sold_on_display' => $sale->sold_on_display,
        ]);
    }
}
