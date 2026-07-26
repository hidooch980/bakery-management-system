<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bakery;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BakeryController extends Controller
{
    use ApiResponse;

    /**
     * Bakery profile — readable by any authenticated user so the app can
     * display the shop name and logo.
     */
    public function show(): JsonResponse
    {
        $bakery = Bakery::first();

        return $this->success([
            ...($bakery?->toArray() ?? []),
            // Everything the app needs to compute chane figures locally,
            // so the two sides can never disagree about the formula.
            'formula' => DoughFormula::fromBakery($bakery)->toArray(),
            'calendar_label' => AppCalendar::label($bakery?->calendar),
            'currency_label' => Money::label($bakery?->currency),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:20'],
            'logo' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'normal_chane_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'nanino_chane_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bread_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'currency' => ['nullable', 'in:toman,rial'],
            'calendar' => ['nullable', 'in:jalali,hijri,gregorian'],
            'flour_bag_weight_kg' => ['nullable', 'numeric', 'min:0.1', 'max:1000'],
            'water_ratio' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'salt_ratio' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'dough_loss_ratio' => ['nullable', 'numeric', 'min:0', 'max:0.9'],
            'flour_price_per_kg' => ['nullable', 'numeric', 'min:0'],
            'flour_price_per_bag' => ['nullable', 'numeric', 'min:0'],
            'flour_purchase_price_per_kg' => ['nullable', 'numeric', 'min:0'],
            'flour_transport_by_factory' => ['nullable', 'boolean'],
            'chane_start_deadline' => ['nullable', 'date_format:H:i'],
            'baking_start_deadline' => ['nullable', 'date_format:H:i'],
            'late_free_days' => ['nullable', 'integer', 'min:0'],
            'late_tier1_last_day' => ['nullable', 'integer', 'min:1'],
            'late_tier1_amount' => ['nullable', 'numeric', 'min:0'],
            'late_tier2_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $bakery = Bakery::firstOrNew(['id' => 1]);
        $bakery->fill($data)->save();

        // Both formatters cache their setting, so drop them after a change.
        Money::forgetCache();
        AppCalendar::forgetCache();

        return $this->success($bakery, 'اطلاعات نانوایی به‌روزرسانی شد.');
    }
}
