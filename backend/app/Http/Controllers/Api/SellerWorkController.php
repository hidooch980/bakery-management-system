<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\ChaneEntry;
use App\Models\Sale;
use App\Models\SettlementRequest;
use App\Models\User;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\SameBakery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Traits\ApiResponse;

/** اصلاح کارکرد واقعی فروشنده؛ مبلغ و مدت گزارش‌ها از همان رکوردها خوانده می‌شوند. */
class SellerWorkController extends Controller
{
    use ApiResponse;

    private function seller(User $seller): User
    {
        SameBakery::or404($seller);
        abort_unless($seller->hasRole('seller'), 404);

        return $seller;
    }

    private function version(Model $model): string
    {
        return hash('sha256', json_encode($model->getRawOriginal(), JSON_THROW_ON_ERROR));
    }

    public function show(Request $request, User $seller)
    {
        $this->seller($seller);
        $request->validate(['sales_page' => ['sometimes', 'integer', 'min:1'], 'attendance_page' => ['sometimes', 'integer', 'min:1']]);
        $sales = Sale::where('user_id', $seller->id)->latest('created_at')->latest('id')
            ->paginate(25, ['*'], 'sales_page');
        $attendance = Attendance::where('user_id', $seller->id)->latest('date')->latest('id')
            ->paginate(25, ['*'], 'attendance_page');

        return $this->success([
            'seller' => ['id' => $seller->id, 'name' => $seller->name],
            'currency_label' => Money::label(),
            'sales' => $sales->through(fn ($sale) => $this->salePayload($sale)),
            'attendance' => $attendance->through(fn ($row) => $this->attendancePayload($row)),
        ]);
    }

    private function salePayload(Sale $sale): array
    {
        return [
            'id' => $sale->id, 'date_label' => AppCalendar::date($sale->created_at),
            'payment_label' => Sale::PAYMENT_LABELS[$sale->payment_type] ?? $sale->payment_type,
            'bread_count' => (int) $sale->bread_count, 'amount' => Money::convert($sale->amount ?? 0),
            'amount_formatted' => Money::format($sale->amount ?? 0), 'note' => $sale->note,
            'takes_money' => ! in_array($sale->payment_type, [...Sale::GIVEAWAY_TYPES, ...Sale::SHORTFALL_TYPES], true),
            'version' => $this->version($sale),
        ];
    }

    private function attendancePayload(Attendance $row): array
    {
        $reason = AuditLog::where('auditable_type', Attendance::class)->where('auditable_id', $row->id)
            ->latest('id')->first()?->after['correction_reason'] ?? null;

        return array_merge($row->toApi(), [
            'date_label' => AppCalendar::date($row->date),
            'in_time' => $row->checked_in_at?->format('H:i'),
            'out_time' => $row->checked_out_at?->format('H:i'),
            'out_next_day' => $row->checked_out_at?->toDateString() > $row->date?->toDateString(),
            'correction_reason' => $reason, 'version' => $this->version($row),
        ]);
    }

    private function changes(Request $request, array $rules): array
    {
        return $request->validate(array_merge($rules, [
            'reason' => ['required', 'string', 'min:3', 'max:250'],
            'version' => ['required', 'string', 'size:64'],
        ]));
    }

    private function unchanged(Model $row, array $data): bool
    {
        return hash_equals($this->version($row), $data['version']);
    }

    public function sale(Request $request, User $seller, Sale $sale)
    {
        $this->seller($seller);
        abort_unless($sale->user_id === $seller->id, 404);
        $data = $this->changes($request, [
            'bread_count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency_label' => ['required', 'string'],
        ]);

        return DB::transaction(function () use ($request, $sale, $seller, $data) {
            // قفل دسته با مسیر ثبت فروش مشترک است؛ تعداد از همان نسخه تازه سنجیده می‌شود.
            $batch = ChaneEntry::lockForUpdate()->findOrFail($sale->chane_entry_id);
            $rows = Sale::where('chane_entry_id', $batch->id)->orderBy('id')->lockForUpdate()->get();
            $fresh = $rows->firstWhere('id', $sale->id);
            abort_unless($fresh && $fresh->user_id === $seller->id, 404);
            if (! $this->unchanged($fresh, $data) || $data['currency_label'] !== Money::label()) {
                return $this->error('رکورد یا واحد پول تغییر کرده است؛ صفحه را تازه کنید.', 409);
            }
            if ($rows->contains(fn ($row) => $row->settled_on || $row->cash_settled_on || $row->shortfall_settled_on || $row->breadRecoveries()->exists())) {
                return $this->error('این دسته تسویه مرتبط دارد؛ ابتدا تسویه یا فیش مرتبط را اصلاح کنید.', 409);
            }
            if (SettlementRequest::whereIn('user_id', $rows->pluck('user_id'))->pending()->exists()) {
                return $this->error('درخواست تسویه باز است؛ ابتدا آن را بررسی کنید.', 409);
            }
            if ($rows->where('id', '!=', $fresh->id)->sum('bread_count') + $data['bread_count'] > $batch->chane_count) {
                throw ValidationException::withMessages(['bread_count' => ['مجموع نان از تعداد چانه این دسته بیشتر می‌شود.']]);
            }

            $takesMoney = ! in_array($fresh->payment_type, [...Sale::GIVEAWAY_TYPES, ...Sale::SHORTFALL_TYPES], true);
            if (! $takesMoney && (float) $data['amount'] !== 0.0) {
                throw ValidationException::withMessages(['amount' => ['این نوع خروج نان مبلغ دریافتی ندارد.']]);
            }
            $price = null;
            if ((int) $fresh->bread_count > 0 && $fresh->amount_difference !== null) {
                $price = ((float) $fresh->amount - (float) $fresh->amount_difference) / $fresh->bread_count;
            }
            $carrier = $rows->first(fn ($row) => (int) $row->shortfall_count > 0 && (float) $row->shortfall_amount > 0);
            $batchPrice = $carrier ? (float) $carrier->shortfall_amount / $carrier->shortfall_count : $price;
            if ((int) $data['bread_count'] !== (int) $fresh->bread_count && ($batchPrice === null || ($takesMoney && $price === null))) {
                return $this->error('قیمت تاریخی این رکورد مشخص نیست؛ تغییر تعداد از این مسیر ممکن نیست.', 409);
            }
            $amount = $takesMoney ? Money::toToman($data['amount']) : null;
            $fresh->fill([
                'bread_count' => $data['bread_count'], 'amount' => $amount,
                'amount_difference' => $takesMoney && $price !== null ? round($amount - $data['bread_count'] * $price, 2) : $fresh->amount_difference,
                'note' => trim(($fresh->note ? $fresh->note."\n" : '').'اصلاح: '.$data['reason']),
            ]);
            if (in_array($fresh->payment_type, Sale::SHORTFALL_TYPES, true)) {
                $fresh->shortfall_count = $data['bread_count'];
                $fresh->shortfall_amount = round($data['bread_count'] * $batchPrice, 2);
            }
            $fresh->save();
            // رویداد مدل تعداد کسری را تازه می‌کند؛ بهای تاریخی دسته ثابت می‌ماند.
            if ($batchPrice !== null) {
                foreach (Sale::where('chane_entry_id', $batch->id)->whereNotIn('payment_type', Sale::SHORTFALL_TYPES)->get() as $row) {
                    if ($row->shortfall_settled_on === null && $row->shortfall_count > 0) {
                        $row->update(['shortfall_amount' => round($row->shortfall_count * $batchPrice, 2)]);
                    }
                }
            }

            return $this->success($this->salePayload($fresh->fresh()), 'فروش و حساب مرتبط اصلاح شد.');
        });
    }

    private function attendanceData(Request $request): array
    {
        return $request->validate([
            'date' => ['required', 'string', 'max:20'],
            'in_time' => ['required', 'date_format:H:i'],
            'out_time' => ['nullable', 'date_format:H:i'],
            'out_next_day' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:250'],
            'version' => ['sometimes', 'string', 'size:64'],
        ]);
    }

    private function times(array $data, $fixedDate = null): array
    {
        $date = $fixedDate?->copy() ?? Jalali::parseFlexible($data['date']);
        if (! $date) {
            throw ValidationException::withMessages(['date' => ['تاریخ نامعتبر است.']]);
        }
        $in = $date->copy()->setTimeFromTimeString($data['in_time']);
        $out = empty($data['out_time']) ? null : $date->copy()->setTimeFromTimeString($data['out_time']);
        if ($out && $data['out_next_day']) {
            $out->addDay();
        }
        if ($in->isFuture() || ($out && ($out->lt($in) || $out->isFuture() || $in->diffInHours($out) > 24))) {
            throw ValidationException::withMessages(['in_time' => ['ورود و خروج باید در گذشته، به ترتیب و با فاصله حداکثر ۲۴ ساعت باشند.']]);
        }

        return ['date' => $date->toDateString(), 'checked_in_at' => $in, 'checked_out_at' => $out];
    }

    private function audit(Request $request, Attendance $row, ?array $before, string $reason): void
    {
        AuditLog::create([
            'event' => $before === null ? AuditLog::CREATED : AuditLog::UPDATED,
            'auditable_type' => Attendance::class, 'auditable_id' => $row->id,
            'subject' => 'اصلاح حضور فروشنده', 'user_id' => $request->user()->id,
            'user_name' => $request->user()->name, 'ip' => $request->ip(), 'before' => $before,
            'after' => array_merge($row->toApi(), ['correction_reason' => $reason]),
        ]);
    }

    public function addAttendance(Request $request, User $seller)
    {
        $this->seller($seller);
        $data = $this->attendanceData($request);
        $times = $this->times($data);

        return DB::transaction(function () use ($request, $seller, $data, $times) {
            User::lockForUpdate()->findOrFail($seller->id);
            if (Attendance::where('user_id', $seller->id)->whereDate('date', $times['date'])->exists()) {
                return $this->error('این روز حضور دارد؛ همان رکورد را ویرایش کنید.', 409);
            }
            $row = Attendance::create(array_merge($times, ['user_id' => $seller->id, 'recorded_by' => $request->user()->id]));
            $this->audit($request, $row, null, $data['reason']);

            return $this->success($this->attendancePayload($row), 'حضور جامانده ثبت شد.', 201);
        });
    }

    public function attendance(Request $request, User $seller, Attendance $attendance)
    {
        $this->seller($seller);
        abort_unless($attendance->user_id === $seller->id, 404);
        $request->validate(['version' => ['required', 'string', 'size:64']]);
        $data = $this->attendanceData($request);

        return DB::transaction(function () use ($request, $seller, $attendance, $data) {
            $row = Attendance::where('user_id', $seller->id)->lockForUpdate()->findOrFail($attendance->id);
            if (! $this->unchanged($row, $data)) {
                return $this->error('حضور تغییر کرده است؛ صفحه را تازه کنید.', 409);
            }
            $before = $row->toApi();
            $row->update(array_merge($this->times($data, $row->date), ['recorded_by' => $request->user()->id]));
            $this->audit($request, $row, $before, $data['reason']);

            return $this->success($this->attendancePayload($row), 'کارکرد حضور اصلاح شد.');
        });
    }
}
