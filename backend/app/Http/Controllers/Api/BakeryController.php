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
        ]);

        $bakery = Bakery::firstOrNew(['id' => 1]);
        $bakery->fill($data)->save();

        // Both formatters cache their setting, so drop them after a change.
        Money::forgetCache();
        AppCalendar::forgetCache();

        return $this->success($bakery, 'اطلاعات نانوایی به‌روزرسانی شد.');
    }
}
