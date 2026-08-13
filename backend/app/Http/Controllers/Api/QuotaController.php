<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bakery;
use App\Models\DieselAllocation;
use App\Models\DieselDelivery;
use App\Models\FlourAllocation;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The diesel quota and the tankers drawn against it.
 *
 * The flour quota this derives from has had an API since it was built;
 * diesel had none, so the litres off a docket had to be carried back to a
 * desk to be entered, and by then the docket was in somebody's pocket.
 */
class QuotaController extends Controller
{
    use ApiResponse;

    // ------------------------------------------------------------ diesel

    /** What the depot allows this month, and what is left of it. */
    public function dieselQuota(): JsonResponse
    {
        $quota = DieselAllocation::current();

        return $this->success([
            'allocation' => $this->dieselPayload($quota),
            'deliveries' => DieselDelivery::with('user:id,name')
                ->orderByDesc('received_on')->orderByDesc('id')->limit(20)->get()
                ->map(fn (DieselDelivery $d) => $this->deliveryPayload($d)),
        ]);
    }

    public function storeDieselDelivery(Request $request): JsonResponse
    {
        $data = $request->validate([
            'litres' => ['required', 'numeric', 'min:0.1', 'max:100000'],
            // Null when the tanker came off quota and carried no invoice.
            'amount' => ['nullable', 'numeric', 'min:0'],
            'received_on' => ['nullable', 'string', 'max:20'],
            'docket_number' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $receivedOn = isset($data['received_on'])
            ? Jalali::parseFlexible($data['received_on'])
            : now();

        if ($receivedOn === null) {
            throw ValidationException::withMessages([
                'received_on' => ['تاریخ نامعتبر است. قالب درست: ۱۴۰۵/۰۵/۰۱'],
            ]);
        }

        $delivery = DieselDelivery::create([
            'user_id' => $request->user()->id,
            'litres' => $data['litres'],
            // Typed in the shop's display unit and stored in Toman, like
            // every other amount on the way in.
            'amount' => isset($data['amount']) ? Money::toToman($data['amount']) : null,
            'received_on' => $receivedOn,
            'docket_number' => $data['docket_number'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        $quota = DieselAllocation::current();

        return $this->success([
            'delivery' => $this->deliveryPayload($delivery->fresh('user')),
            'allocation' => $this->dieselPayload($quota),
            // Said at the moment of recording: a tanker that puts the month
            // over quota is worth knowing about before the next one is
            // ordered, not at the end of the month.
            'warning' => $quota && $quota->is_overdrawn
                ? 'این تحویل سهمیه‌ی ماه را رد کرده است.'
                : null,
        ], 'تحویل گازوئیل ثبت شد.', 201);
    }

    /**
     * Amend this month's quota: the rate a sack earns, the litres allowed,
     * or both.
     *
     * The depot allows more some months and less others, and its own figure
     * does not always match the arithmetic — 343 sacks at 6.5 works out at
     * 2,229.5 where the docket said 2,230. Whichever is given wins for this
     * month; a new rate also becomes the default the next month starts
     * from, since a rate told to us once should not have to be told again.
     */
    public function updateDieselQuota(Request $request): JsonResponse
    {
        $data = $request->validate([
            'litres_per_bag' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'total_litres' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'carryover_litres' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $quota = DieselAllocation::current();

        if (! $quota) {
            return $this->error(
                'برای این ماه سهمیه‌ای ثبت نشده است. اول سهمیه آرد ماه را وارد کنید.',
                404,
            );
        }

        if (isset($data['litres_per_bag'])) {
            $quota->litres_per_bag = $data['litres_per_bag'];

            Bakery::query()->update(['diesel_litres_per_bag' => $data['litres_per_bag']]);

            // Recompute from the new rate unless the depot's own figure is
            // being given alongside it.
            if (! isset($data['total_litres'])) {
                $flour = FlourAllocation::query()
                    ->whereDate('month_start', $quota->month_start->toDateString())
                    ->first();

                if ($flour && $flour->total_bags !== null) {
                    $quota->total_litres = round(
                        (float) $flour->total_bags * (float) $data['litres_per_bag']
                    );
                }
            }
        }

        foreach (['total_litres', 'carryover_litres', 'note'] as $field) {
            if (isset($data[$field])) {
                $quota->$field = $data[$field];
            }
        }

        $quota->save();

        return $this->success(
            ['allocation' => $this->dieselPayload($quota->fresh())],
            'سهمیه گازوئیل این ماه به‌روزرسانی شد.',
        );
    }

    public function destroyDieselDelivery(DieselDelivery $delivery): JsonResponse
    {
        $delivery->delete();

        return $this->success(
            ['allocation' => $this->dieselPayload(DieselAllocation::current())],
            'تحویل حذف شد.',
        );
    }

    // ----------------------------------------------------------- helpers

    private function dieselPayload(?DieselAllocation $d): ?array
    {
        if (! $d) {
            return null;
        }

        return [
            'id' => $d->id,
            'month_label' => $d->month_label,
            'total_litres' => (float) $d->total_litres,
            'carryover_litres' => (float) $d->carryover_litres,
            'available_litres' => $d->available_litres,
            'delivered_litres' => $d->delivered_litres,
            'remaining_litres' => $d->remaining_litres,
            // What was burned baking, and what that leaves in the tank —
            // a different question from what the depot will still issue.
            'consumed_litres' => $d->consumed_litres,
            'bags_baked' => $d->bags_baked,
            'in_tank_litres' => $d->in_tank_litres,
            'is_tank_empty' => $d->is_tank_empty,
            'litres_per_bag' => $d->litres_per_bag === null ? null : (float) $d->litres_per_bag,
            'derivation_label' => $d->derivation_label,
            'used_percent' => $d->used_percent,
            'is_overdrawn' => $d->is_overdrawn,
            // The fuel is issued against flour that arrives in three lots,
            // so it belongs to those same three periods even when the shop
            // draws the whole month in one go.
            'periods' => $d->periods(),
        ];
    }

    private function deliveryPayload(DieselDelivery $d): array
    {
        return [
            'id' => $d->id,
            'litres' => (float) $d->litres,
            'amount' => $d->amount === null ? null : (float) $d->amount,
            'amount_formatted' => $d->amount_formatted,
            'was_paid_for' => $d->was_paid_for,
            'received_on' => $d->received_on?->toDateString(),
            'received_on_label' => AppCalendar::date($d->received_on),
            'docket_number' => $d->docket_number,
            'recorded_by' => $d->user?->name,
            'note' => $d->note,
        ];
    }
}
