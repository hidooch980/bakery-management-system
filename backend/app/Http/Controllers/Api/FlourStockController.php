<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlourStockMovement;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlourStockController extends Controller
{
    use ApiResponse;

    /**
     * Current flour balance derived from all in/out movements.
     */
    public function balance(): JsonResponse
    {
        $in = (float) FlourStockMovement::where('type', 'in')->sum('amount_kg');
        $out = (float) FlourStockMovement::where('type', 'out')->sum('amount_kg');

        return $this->success([
            'total_in_kg' => round($in, 2),
            'total_out_kg' => round($out, 2),
            'balance_kg' => round($in - $out, 2),
        ]);
    }

    public function index(): JsonResponse
    {
        $movements = FlourStockMovement::with('user:id,name')
            ->latest()
            ->paginate(20);

        return $this->success($movements);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:in,out'],
            'amount_kg' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $movement = FlourStockMovement::create([
            'user_id' => $request->user()->id,
            ...$data,
        ]);

        return $this->success($movement, 'تراکنش آرد ثبت شد.', 201);
    }
}
