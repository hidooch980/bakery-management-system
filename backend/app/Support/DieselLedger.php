<?php

namespace App\Support;

use App\Models\DieselAllocation;
use App\Models\DieselDelivery;
use App\Models\DoughEntry;
use App\Models\FlourAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** گردش پیوستهٔ سهمیه و باک؛ انتقال مانده با محاسبه است و تراکنش تازه ایجاد نمی‌کند. */
class DieselLedger
{
    public static function quotaOpening(Carbon $from, ?DieselAllocation $current = null): float
    {
        // عدد مثبتِ انتقال دستی، ماندهٔ اصلاح‌شدهٔ ابتدای همین ماه است، نه یک سهمیهٔ تازه.
        if ($current && (float) $current->carryover_litres > 0) {
            return (float) $current->carryover_litres;
        }

        $prior = DieselAllocation::query()->where('month_start', '<', $from->toDateString())
            ->orderBy('month_start')->orderBy('id')->get()
            ->filter(fn ($q) => $q->quotaRange()[0]->lt($from));
        $anchor = $prior->last(fn ($q) => (float) $q->carryover_litres > 0);
        $since = $anchor?->quotaRange()[0];
        $opening = $anchor ? (float) $anchor->carryover_litres : 0.0;
        foreach ($prior as $quota) {
            if ($since === null || $quota->quotaRange()[0]->gte($since)) {
                $opening += (float) $quota->total_litres;
            }
        }
        $delivered = DieselDelivery::query()->where('received_on', '<', $from->toDateString())
            ->when($since, fn ($q) => $q->where('received_on', '>=', $since->toDateString()))
            ->sum('litres');

        return round($opening - (float) $delivered, 2);
    }

    public static function tankOpening(Carbon $from): array
    {
        $rates = DieselAllocation::query()->orderBy('month_start')->orderBy('id')->get()
            ->mapWithKeys(fn ($q) => [Jalali::format($q->month_start, 'Y/m') => $q->litres_per_bag]);
        $days = DoughEntry::query()->where('created_at', '<', $from)
            ->selectRaw('DATE(created_at) as day, SUM(bag_count) as bags')->groupByRaw('DATE(created_at)')->get();
        $months = [];
        foreach ($days as $day) {
            $key = Jalali::format(FlourAllocation::monthStartFor(Carbon::parse($day->day)), 'Y/m');
            $months[$key] = ($months[$key] ?? 0) + (float) $day->bags;
        }
        $consumed = 0.0;
        $fallback = false;
        $defaultRate = DieselAllocation::rateInForce();
        foreach ($months as $key => $bags) {
            $rate = $rates[$key] ?? null;
            $fallback = $fallback || $rate === null;
            $consumed += round($bags * (float) ($rate ?? $defaultRate), 2);
        }
        $delivered = (float) DieselDelivery::query()->where('received_on', '<', $from->toDateString())->sum('litres');

        return ['litres' => round($delivered - $consumed, 2), 'uses_default_rate' => $fallback];
    }

    public static function month(?string $text = null): array
    {
        if ($text === null) {
            $month = FlourAllocation::monthStartFor(today());
        } else {
            $value = trim(Jalali::toLatinDigits($text));
            if (preg_match('/^\d{4}[\/-]\d{1,2}$/', $value)) {
                $value .= str_contains($value, '/') ? '/01' : '-01';
            }
            $date = Jalali::parseFlexible($value);
            if ($date === null) {
                throw ValidationException::withMessages(['month' => 'ماه معتبر وارد کنید؛ مانند ۱۴۰۵/۰۸.']);
            }
            $month = Jalali::parse(Jalali::format($date, 'Y/m').'/01');
        }
        if ($month->gt(FlourAllocation::monthStartFor(today()))) {
            throw ValidationException::withMessages(['month' => 'ماه آینده هنوز گردش قطعی ندارد.']);
        }
        [$from] = FlourAllocation::periodRange($month, 1);
        [, $to] = FlourAllocation::periodRange($month, 3);
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $quotas = DieselAllocation::query()->orderBy('month_start')->orderBy('id')->get()
            ->filter(fn ($q) => $q->quotaRange()[0]->isSameDay($from));
        $quota = $quotas->last();
        $quotaOpening = self::quotaOpening($from, $quota);
        $tankOpening = self::tankOpening($from);
        $deliveryQuery = DieselDelivery::query()->whereBetween('received_on', [$from->toDateString(), $to->toDateString()]);
        $doughQuery = DoughEntry::query()->whereBetween('created_at', [$from, $to]);
        $delivered = round((float) (clone $deliveryQuery)->sum('litres'), 2);
        $bags = (float) (clone $doughQuery)->sum('bag_count');
        $rate = (float) ($quota?->litres_per_bag ?? DieselAllocation::rateInForce());
        $consumed = round($bags * $rate, 2);
        $added = round((float) $quotas->sum('total_litres'), 2);
        $lines = collect();
        foreach ((clone $deliveryQuery)->with('user:id,name')->orderByDesc('received_on')->orderByDesc('id')->limit(201)->get() as $d) {
            $lines->push([
                'id' => $d->id, 'type' => 'delivery', 'label' => 'تحویل سوخت',
                'date_label' => AppCalendar::date($d->received_on), 'litres' => (float) $d->litres,
                'detail' => trim(($d->docket_number ? 'حواله '.$d->docket_number.'؛ ' : '').$d->amount_formatted),
                'user' => $d->user?->name, 'sort' => $d->received_on->toDateString().' 00:00:00 '.sprintf('%012d', $d->id),
            ]);
        }
        foreach ((clone $doughQuery)->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id')->limit(201)->get() as $d) {
            $lines->push([
                'id' => $d->id, 'type' => 'consumption', 'label' => 'مصرف تخمینی پخت',
                'date_label' => AppCalendar::date($d->created_at).' '.$d->created_at->format('H:i'),
                'litres' => round((float) $d->bag_count * $rate, 4),
                'detail' => $d->bag_count.' کیسه × '.$rate.' لیتر', 'user' => $d->user?->name,
                'sort' => $d->created_at->format('Y-m-d H:i:s').' '.sprintf('%012d', $d->id),
            ]);
        }
        $next = Jalali::parse(Jalali::format($month->copy()->addDays(32), 'Y/m').'/01');

        return [
            'month' => Jalali::format($month, 'Y/m'), 'month_label' => Jalali::monthLabel($month),
            'from_label' => AppCalendar::date($from), 'to_label' => AppCalendar::date($to),
            'previous_month' => Jalali::format($month->copy()->subDay(), 'Y/m'),
            'next_month' => $next->lte(FlourAllocation::monthStartFor(today())) ? Jalali::format($next, 'Y/m') : null,
            'quota_recorded' => $quotas->isNotEmpty(), 'quota_records_count' => $quotas->count(),
            'quota_opening_litres' => $quotaOpening, 'quota_litres' => $added,
            'available_litres' => round($quotaOpening + $added, 2), 'delivered_litres' => $delivered,
            'quota_closing_litres' => round($quotaOpening + $added - $delivered, 2),
            'tank_opening_litres' => $tankOpening['litres'], 'consumed_litres' => $consumed,
            'tank_closing_litres' => round($tankOpening['litres'] + $delivered - $consumed, 2),
            'bags_baked' => round($bags, 2), 'litres_per_bag' => $rate,
            'uses_default_rate' => $tankOpening['uses_default_rate'] || ($bags > 0 && $quota?->litres_per_bag === null),
            'quota_opening_source' => $quota && (float) $quota->carryover_litres > 0 ? 'اصلاح دستی مانده ابتدای ماه' : 'انتقال خودکار از گردش قبل',
            'note' => 'ماندهٔ انتهای ماه به ابتدای ماه بعد منتقل می‌شود. سهمیهٔ قابل دریافت و سوخت داخل باک دو ماندهٔ جدا هستند. مصرف و ماندهٔ باک، برآورد از خمیر ثبت‌شده‌اند؛ اندازه‌گیری واقعی باک نیستند.',
            'lines' => $lines->sortByDesc('sort')->take(200)->map(fn ($r) => collect($r)->except('sort')->all())->values()->all(),
            'lines_truncated' => (clone $deliveryQuery)->count() + (clone $doughQuery)->count() > 200,
        ];
    }
}
