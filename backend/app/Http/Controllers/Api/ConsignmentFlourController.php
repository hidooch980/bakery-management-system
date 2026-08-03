<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsignmentFlour;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
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
        $records = ConsignmentFlour::with(['user:id,name', 'partner:id,name'])
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

        $bagWeight = DoughFormula::fromBakery()->bagWeightKg;
        $inBags = fn (float $kg) => $bagWeight > 0 ? round($kg / $bagWeight, 2) : 0.0;

        return $this->success([
            'borrowed_kg' => round($borrowed, 3),
            'lent_kg' => round($lent, 3),
            // Positive means partners owe us; negative means we owe them.
            'net_kg' => round($lent - $borrowed, 3),
            'borrowed_bags' => $inBags($borrowed),
            'lent_bags' => $inBags($lent),
            'net_bags' => $inBags($lent - $borrowed),
            'bag_weight_kg' => $bagWeight,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Either a defined partner, or a one-off name.
            'customer_id' => ['nullable', 'exists:customers,id'],
            'partner_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'partner_phone' => ['nullable', 'string', 'max:20'],
            'direction' => ['required', 'in:borrowed,lent'],
            // Sacks are what changes hands. A weight is still accepted for
            // anything that was genuinely weighed out rather than counted.
            'bags' => ['required_without:amount_kg', 'nullable', 'numeric', 'min:0.01'],
            'amount_kg' => ['required_without:bags', 'nullable', 'numeric', 'min:0.001'],
            'occurred_on' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $record = DB::transaction(function () use ($data, $request) {
            $record = ConsignmentFlour::create([
                'user_id' => $request->user()->id,
                'customer_id' => $data['customer_id'] ?? null,
                'partner_name' => $data['partner_name'] ?? null,
                'partner_phone' => $data['partner_phone'] ?? null,
                'direction' => $data['direction'],
                'bags' => $data['bags'] ?? null,
                'amount_kg' => $data['amount_kg'] ?? 0,
                'occurred_on' => Jalali::parseFlexible($data['occurred_on'] ?? null) ?? now(),
                'note' => $data['note'] ?? null,
            ]);

            // The warehouse movement is the model's own doing, so the panel
            // and any other caller get it too — see ConsignmentFlour::booted().

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
            'partner_id' => $record->customer_id,
            'partner_name' => $record->partner_label,
            'partner_phone' => $record->partner_phone,
            'direction' => $record->direction,
            'direction_label' => $record->direction_label,
            'bags' => (float) $record->bags,
            'amount_kg' => (float) $record->amount_kg,
            'quantity_label' => $record->quantity_label,
            'occurred_on' => $record->occurred_on?->toDateString(),
            'occurred_on_display' => AppCalendar::date($record->occurred_on),
            'settled_on_display' => AppCalendar::date($record->settled_on),
            'is_settled' => $record->is_settled,
            'note' => $record->note,
        ];
    }
}
