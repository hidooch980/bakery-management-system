<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\SameBakery;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** اصلاح اصل جابه‌جایی؛ رویداد مدل انبار را با اختلاف مقدار هم‌حساب می‌کند. */
class PartnerFlourCorrectionController extends Controller
{
    use ApiResponse;

    private function payload(ConsignmentFlour $row): array
    {
        return [
            'id' => $row->id, 'customer_id' => $row->customer_id,
            'partner_name' => $row->partner_label, 'bags' => (float) $row->bags,
            'direction' => $row->direction, 'date' => $row->occurred_on?->toDateString(),
            'date_label' => AppCalendar::date($row->occurred_on), 'returned_bags' => $row->returnedBags(),
            'note' => $row->note, 'version' => hash('sha256', json_encode($row->getRawOriginal(), JSON_THROW_ON_ERROR)),
        ];
    }

    public function show(ConsignmentFlour $consignment)
    {
        return $this->success($this->payload($consignment));
    }

    public function update(Request $request, ConsignmentFlour $consignment)
    {
        $data = $request->validate([
            'bags' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'direction' => ['required', 'in:borrowed,lent'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'partner_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'date' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:3', 'max:250'],
            'version' => ['required', 'string', 'size:64'],
        ]);
        if (! empty($data['customer_id'])) {
            $partner = SameBakery::or404(Customer::findOrFail($data['customer_id']));
            abort_unless($partner->type === Customer::PARTNER_TYPE, 404);
        }
        $date = Jalali::parseFlexible($data['date']);
        if (! $date || $date->isFuture()) {
            return $this->error('تاریخ معتبر و غیرآینده وارد کنید.', 422);
        }

        return DB::transaction(function () use ($consignment, $data, $date) {
            $row = ConsignmentFlour::lockForUpdate()->findOrFail($consignment->id);
            if (! hash_equals($this->payload($row)['version'], $data['version'])) {
                return $this->error('این جابه‌جایی تغییر کرده است؛ دوباره بازش کنید.', 409);
            }
            if ($row->settled_on !== null) {
                return $this->error('ردیف با روش قدیمی تسویه شده است؛ ابتدا تسویه مرتبط را بررسی کنید.', 409);
            }
            $returned = $row->returnedBags();
            if ($data['bags'] < $returned) {
                return $this->error('مقدار از کیسه‌های برگشت‌شده کمتر نمی‌شود.', 422);
            }
            if ($returned > 0 && ($data['direction'] !== $row->direction || ($data['customer_id'] ?? null) != $row->customer_id || ($row->customer_id === null && $data['partner_name'] !== $row->partner_name))) {
                return $this->error('این ردیف برگشت دارد؛ تغییر همکار یا جهت به بررسی برگشت مرتبط نیاز دارد.', 409);
            }
            $row->update([
                'bags' => round($data['bags'], 2), 'direction' => $data['direction'],
                'customer_id' => $data['customer_id'] ?? null,
                'partner_name' => empty($data['customer_id']) ? $data['partner_name'] : null,
                'occurred_on' => $date,
                'note' => trim(($row->note ? $row->note."\n" : '').'اصلاح: '.$data['reason']),
            ]);

            return $this->success($this->payload($row->fresh()), 'آرد همکار، موجودی و مانده حساب اصلاح شد.');
        });
    }
}
