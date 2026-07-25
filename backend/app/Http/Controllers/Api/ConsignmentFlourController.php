<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsignmentFlour;
use App\Models\InventoryItem;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsignmentFlourController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $records = ConsignmentFlour::with('user:id,name')
            ->when($request->query('direction'), fn ($q, $d) => $q->where('direction', $d))
            ->when($request->boolean('outstanding_only'), fn ($q) => $q->outstanding())
            ->latest('occurred_on')
            ->paginate(20)
            ->through(fn (ConsignmentFlour $c) => $this->payload($c));

        return $this->success($records);
    }

    /** Net position: how much flour we owe partners, and they owe us. */
    public function balance(): JsonResponse
    {
        $borrowed = (float) ConsignmentFlour::outstanding()->where('direction', 'borrowed')->sum('amount_kg');
        $lent = (float) ConsignmentFlour::outstanding()->where('direction', 'lent')->sum('amount_kg');

        return $this->success([
            'borrowed_kg' => round($borrowed, 3),
            'lent_kg' => round($lent, 3),
            // Positive means partners owe us; negative means we owe them.
            'net_kg' => round($lent - $borrowed, 3),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'partner_name' => ['required', 'string', 'max:255'],
            'partner_phone' => ['nullable', 'string', 'max:20'],
            'direction' => ['required', 'in:borrowed,lent'],
            'amount_kg' => ['required', 'numeric', 'min:0.001'],
            'occurred_on' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $record = DB::transaction(function () use ($data, $request) {
            $record = ConsignmentFlour::create([
                'user_id' => $request->user()->id,
                'partner_name' => $data['partner_name'],
                'partner_phone' => $data['partner_phone'] ?? null,
                'direction' => $data['direction'],
                'amount_kg' => $data['amount_kg'],
                'occurred_on' => Jalali::parseFlexible($data['occurred_on'] ?? null) ?? now(),
                'note' => $data['note'] ?? null,
            ]);

            // Consignment flour physically moves, so the warehouse follows it.
            InventoryItem::ofKey(InventoryItem::FLOUR)->move(
                $data['direction'] === 'borrowed' ? 'in' : 'out',
                (float) $data['amount_kg'],
                $data['direction'] === 'borrowed' ? 'consignment_in' : 'consignment_out',
                $request->user()->id,
                $record,
                $data['partner_name'],
            );

            return $record;
        });

        return $this->success($this->payload($record), 'آرد امانی ثبت شد.', 201);
    }

    public function settle(ConsignmentFlour $consignment): JsonResponse
    {
        if ($consignment->is_settled) {
            return $this->error('این مورد قبلاً تسویه شده است.', 409);
        }

        $consignment->update(['settled_on' => now()]);

        return $this->success($this->payload($consignment->fresh()), 'تسویه ثبت شد.');
    }

    public function destroy(ConsignmentFlour $consignment): JsonResponse
    {
        $consignment->delete();

        return $this->success(null, 'رکورد حذف شد.');
    }

    private function payload(ConsignmentFlour $record): array
    {
        return [
            'id' => $record->id,
            'partner_name' => $record->partner_name,
            'partner_phone' => $record->partner_phone,
            'direction' => $record->direction,
            'direction_label' => $record->direction_label,
            'amount_kg' => (float) $record->amount_kg,
            'occurred_on' => $record->occurred_on?->toDateString(),
            'occurred_on_display' => AppCalendar::date($record->occurred_on),
            'settled_on_display' => AppCalendar::date($record->settled_on),
            'is_settled' => $record->is_settled,
            'note' => $record->note,
        ];
    }
}
