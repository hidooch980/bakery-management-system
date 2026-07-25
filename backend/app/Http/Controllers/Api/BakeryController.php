<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bakery;
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
        return $this->success(Bakery::first());
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
        ]);

        $bakery = Bakery::firstOrNew(['id' => 1]);
        $bakery->fill($data)->save();

        return $this->success($bakery, 'اطلاعات نانوایی به‌روزرسانی شد.');
    }
}
