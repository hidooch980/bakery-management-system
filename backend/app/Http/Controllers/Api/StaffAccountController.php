<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SalaryPayment;
use App\Models\StaffAdvance;
use App\Models\StaffAdjustment;
use App\Models\User;
use App\Support\SameBakery;
use App\Support\Money;
use App\Support\AppCalendar;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** پرونده مالی کارکنان برای مدیر، با پیوند هر بدهی به فیش تسویه‌کننده. */
class StaffAccountController extends Controller
{
    use ApiResponse;

    public function show(User $person)
    {
        SameBakery::or404($person);
        $salaryController = app(SalaryController::class);
        $slips = SalaryPayment::where('user_id', $person->id)
            ->with(['recoveries', 'breadRecoveries'])->latest('period_start')->limit(100)->get();
        $advances = StaffAdvance::where('user_id', $person->id)->with('recoveries')->latest('paid_on')->limit(100)->get();
        $bread = Sale::where('consumed_by_user_id', $person->id)->with('breadRecoveries')->latest()->limit(100)->get();
        $adjustments = StaffAdjustment::where('user_id', $person->id)->latest('occurred_on')->limit(100)->get();
        return $this->success([
            'person' => ['id' => $person->id, 'name' => $person->name],
            'currency_label' => Money::label(),
            'summary' => [
                'advance_outstanding' => Money::convert(StaffAdvance::outstandingFor($person->id)),
                'bread_outstanding' => Money::convert(Sale::staffBreadOutstandingFor($person->id)),
                'unpaid' => Money::format(SalaryPayment::where('user_id', $person->id)->unpaid()->sum('net_amount')),
                'advances' => Money::format(StaffAdvance::outstandingFor($person->id)),
                'bread' => Money::format(Sale::staffBreadOutstandingFor($person->id)),
            ],
            'payslips' => $slips->map(fn ($p) => array_merge($salaryController->payload($p), [
                'advance_links' => $p->recoveries->map(fn ($r) => ['id' => $r->staff_advance_id, 'amount' => Money::format($r->amount)]),
                'bread_links' => $p->breadRecoveries->map(fn ($r) => ['id' => $r->sale_id, 'amount' => Money::format($r->amount)]),
            ])),
            'advances' => $advances->map(fn ($a) => [
                'id' => $a->id, 'amount' => Money::convert($a->amount), 'amount_formatted' => Money::format($a->amount),
                'outstanding_formatted' => Money::format($a->outstanding), 'date' => AppCalendar::date($a->paid_on),
                'note' => $a->note, 'editable' => $a->recoveries->isEmpty(),
                'salary_ids' => $a->recoveries->pluck('salary_payment_id')->unique()->values(),
            ]),
            'bread' => $bread->map(fn ($s) => [
                'id' => $s->id, 'bread_count' => $s->bread_count, 'amount_formatted' => Money::format($s->consumed_amount ?? 0),
                'outstanding_formatted' => Money::format($s->consumed_outstanding), 'date' => AppCalendar::date($s->created_at),
                'note' => $s->note, 'editable' => $s->breadRecoveries->isEmpty() && $s->shortfall_settled_on === null,
                'salary_ids' => $s->breadRecoveries->pluck('salary_payment_id')->unique()->values(),
            ]),
            'adjustments' => $adjustments->map(fn ($a) => [
                'id' => $a->id, 'kind' => $a->kind, 'amount' => Money::convert($a->value),
                'amount_formatted' => Money::format($a->value), 'note' => $a->reason,
                'date' => AppCalendar::date($a->occurred_on), 'waived' => $a->isWaived(),
                'automatic' => $a->isAutomatic(), 'editable' => !$a->isAutomatic() && $a->salary_payment_id === null,
                'salary_ids' => $a->salary_payment_id ? [$a->salary_payment_id] : [],
            ]),
            'detail_limit' => 100,
        ]);
    }

    /** رکورد تسویه‌شده فقط از راه اصلاح فیش تغییر می‌کند. */
    public function update(Request $request, string $kind, int $id)
    {
        abort_unless(in_array($kind, ['advance', 'bread', 'adjustment'], true), 404);
        $data = $request->validate([
            'amount' => [$kind === 'bread' ? 'nullable' : 'required', 'numeric', 'min:0'],
            'bread_count' => [$kind === 'bread' ? 'required' : 'nullable', 'integer', 'min:1', 'max:1000000'],
            'note' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        return DB::transaction(function () use ($kind, $id, $data) {
            $class = match ($kind) { 'advance' => StaffAdvance::class, 'bread' => Sale::class, default => StaffAdjustment::class };
            $record = $class::lockForUpdate()->findOrFail($id);
            if ($kind === 'advance') {
                if ($record->recoveries()->exists()) return $this->error('این مساعده در فیش تسویه شده؛ ابتدا فیش مرتبط را اصلاح کنید.', 409);
                $record->update(['amount' => Money::toToman($data['amount']), 'note' => $data['note']]);
            } elseif ($kind === 'bread') {
                abort_unless($record->payment_type === Sale::HOME_TYPE && $record->consumed_by_user_id !== null, 404);
                if ($record->breadRecoveries()->exists() || $record->shortfall_settled_on !== null) return $this->error('این نان تسویه شده؛ ابتدا فیش مرتبط را اصلاح کنید.', 409);
                $batch = $record->chaneEntry()->lockForUpdate()->firstOrFail();
                $other = Sale::where('chane_entry_id', $batch->id)->where('id', '!=', $record->id)->sum('bread_count');
                if ($other + $data['bread_count'] > $batch->chane_count) return $this->error('مجموع نان از تعداد چانه بیشتر می‌شود.', 422);
                $record->update(['bread_count' => $data['bread_count'], 'note' => $data['note']]);
            } else {
                if ($record->salary_payment_id !== null || $record->isAutomatic()) return $this->error('مورد خودکار یا لحاظ‌شده در فیش، از اینجا ویرایش نمی‌شود.', 409);
                $record->update(['basis' => StaffAdjustment::BY_AMOUNT, 'amount' => Money::toToman($data['amount']), 'days' => null, 'reason' => $data['note']]);
            }
            return $this->success(null, 'رکورد اصلی اصلاح شد.');
        });
    }
}
