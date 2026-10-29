<?php

namespace App\Support;

use App\Models\ChaneEntry;
use App\Models\FlourAllocationPeriod;
use App\Models\Holiday;
use App\Models\Sale;
use App\Models\SellerAccountCredit;
use App\Models\SellerSettlementRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** ریز حساب فروشنده، با زمینهٔ سهمیه و تولید نانوایی؛ بدون ساختن بدهی از سهمیه. */
class SellerDailyReport
{
    public static function build(User $seller, ?string $fromText = null, ?string $toText = null): array
    {
        $seller = SameBakery::or404($seller);
        abort_unless($seller->hasRole('seller'), 404);
        [$defaultFrom, $defaultTo] = Jalali::currentQuotaPeriod();
        $from = $fromText === null ? $defaultFrom : Jalali::parseFlexible($fromText);
        $to = $toText === null ? min($defaultTo, today()) : Jalali::parseFlexible($toText);
        if ($from === null || $to === null || $from->gt($to) || $from->diffInDays($to) > 92) {
            throw ValidationException::withMessages(['from' => 'بازهٔ معتبر و حداکثر ۹۳روزه انتخاب کنید.']);
        }
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $window = [$from, $to];

        $sales = Sale::query()->where('user_id', $seller->id)->whereBetween('created_at', $window)
            ->with('customer:id,name')->orderByDesc('created_at')->orderByDesc('id')->get();
        $byDay = $sales->groupBy(fn (Sale $s) => $s->created_at->toDateString());
        $open = SellerSettlement::outstandingSales($seller)->with('customer:id,name')->get();
        $owed = SellerSettlement::outstandingFor($seller);
        $onAccount = SellerAccountCredit::balanceFor($seller->id);

        // هر چانه فقط یک بار از جدول تولید شمرده می‌شود، نه به تعداد ردیف‌های فروشش.
        $chane = ChaneEntry::query()->whereBetween('created_at', $window)
            ->selectRaw('DATE(created_at) as day, SUM(chane_count) as total')->groupByRaw('DATE(created_at)')
            ->pluck('total', 'day');
        $bakeryCards = Sale::query()->whereIn('payment_type', Sale::BANKED_TYPES)
            ->whereBetween('created_at', $window)
            ->selectRaw('DATE(created_at) as day, SUM(bread_count) as total')->groupByRaw('DATE(created_at)')
            ->pluck('total', 'day');
        $periods = FlourAllocationPeriod::query()->where('starts_on', '<=', $to->toDateString())
            ->where('ends_on', '>=', $from->toDateString())->get();
        $paces = $periods->mapWithKeys(fn ($p) => [$p->id => $p->daily_pace_kg]);
        $holidays = Holiday::query()->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();
        $formula = DoughFormula::fromBakery();

        $handovers = SellerSettlementRecord::query()->where('user_id', $seller->id)
            ->whereBetween('created_at', $window)->orderByDesc('created_at')->orderByDesc('id')->get();
        $handoversByDay = $handovers->whereNull('reversed_at')->groupBy(fn ($r) => $r->created_at->toDateString());
        $days = [];
        for ($date = $to->copy()->startOfDay(); $date->gte($from); $date->subDay()) {
            $key = $date->toDateString();
            $daySales = $byDay->get($key, collect());
            $card = $daySales->whereIn('payment_type', Sale::BANKED_TYPES);
            $matching = $periods->filter(fn ($p) => $p->starts_on->lte($date) && $p->ends_on->gte($date));
            $holiday = $holidays->has($key);
            $bags = null;
            $status = $matching->count() > 1 ? 'سهمیه‌های هم‌پوشان؛ نیاز به بررسی' : 'سهمیه ثبت نشده است';
            if ($matching->count() === 1 && $formula->bagWeightKg > 0) {
                $period = $matching->first();
                $bags = $holiday ? 0.0 : round($paces[$period->id] / $formula->bagWeightKg, 2);
                $status = $holiday ? 'تعطیلی ثبت‌شده' : 'سهم روز کاری نانوایی';
            }
            $days[] = [
                'date' => $key,
                'date_label' => AppCalendar::date($date),
                'quota_bags' => $bags,
                'quota_status' => $status,
                'is_holiday' => $holiday,
                'quota_card_target' => $bags === null ? null : (int) round($bags * $formula->naninoPerBag),
                'bakery_chane_count' => (int) ($chane[$key] ?? 0),
                'bakery_card_bread_count' => (int) ($bakeryCards[$key] ?? 0),
                'seller_card_bread_count' => (int) $card->sum('bread_count'),
                'seller_card_amount_formatted' => Money::format($card->sum('amount')),
                'home_bread_count' => (int) $daySales->where('payment_type', 'home')->sum('bread_count'),
                'credit_bread_count' => (int) $daySales->whereIn('payment_type', Sale::DEBT_TYPES)->sum('bread_count'),
                'waste_bread_count' => (int) $daySales->where('payment_type', 'waste')->sum('bread_count'),
                'handed_over_formatted' => Money::format($handoversByDay->get($key, collect())->sum('amount')),
                'has_sales' => $daySales->isNotEmpty(),
                'lines' => $daySales->map(fn (Sale $s) => self::line($s))->values()->all(),
            ];
        }

        return [
            'seller' => ['id' => $seller->id, 'name' => $seller->name],
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'from_label' => AppCalendar::date($from), 'to_label' => AppCalendar::date($to),
            'card_source' => 'فروش کارتخوان ثبت‌شده داخل اپ؛ دریافت مستقیم از دستگاه یا نانینو نیست.',
            'scope_note' => 'سهمیه و چانه مربوط به کل نانوایی‌اند؛ سهم هر فروشنده از آن‌ها تعیین نشده است.',
            'quota_note' => 'معادل کارتخوان برآورد با ضریب فعلی '.$formula->naninoPerBag.' نان در کیسه است؛ اختلاف سهمیه، بدهی مالی فروشنده نیست.',
            'summary' => [
                'cash_formatted' => Money::format($owed['cash']),
                'shortfall_formatted' => Money::format($owed['shortfall']),
                'difference_formatted' => Money::format($owed['difference']),
                'total_formatted' => Money::format($owed['total']),
                'on_account_formatted' => Money::format($onAccount),
                'still_owed' => Money::convert(max(0, $owed['total'] - $onAccount)),
                'still_owed_formatted' => Money::format(max(0, $owed['total'] - $onAccount)),
                'credit_formatted' => Money::format($owed['credit']),
            ],
            // بدهی قدیمی از گزارش حذف نمی‌شود؛ این بخش همهٔ تاریخ‌ها را پوشش می‌دهد.
            'open_days' => $open->groupBy(fn (Sale $s) => $s->created_at->toDateString())
                ->sortKeysDesc()->map(fn (Collection $items, string $date) => [
                    'date' => $date, 'date_label' => AppCalendar::date($items->first()->created_at),
                    'total_formatted' => Money::format($items->sum(fn ($s) => $s->cash_held + $s->open_shortfall - $s->open_difference)),
                    'credit_formatted' => Money::format($items->sum(fn ($s) => $s->open_credit)),
                    'lines' => $items->map(fn (Sale $s) => self::line($s))->values()->all(),
                ])->values()->all(),
            'days' => $days,
            'handover_note' => 'تحویل وجه در تاریخ پرداخت نمایش داده می‌شود؛ ماندهٔ علی‌الحساب فقط یک بار از جمع بدهی کم شده است.',
            'handovers' => $handovers->take(100)->map(fn ($r) => [
                'id' => $r->id, 'date_label' => AppCalendar::date($r->created_at),
                'amount_formatted' => Money::format((float) $r->amount),
                'is_reversed' => $r->is_reversed, 'note' => $r->note,
            ])->values()->all(),
            'handovers_truncated' => $handovers->count() > 100,
        ];
    }

    private static function line(Sale $sale): array
    {
        return [
            'id' => $sale->id, 'chane_entry_id' => $sale->chane_entry_id,
            'time' => $sale->created_at->format('H:i'),
            'payment_type' => $sale->payment_type,
            'payment_label' => Sale::PAYMENT_LABELS[$sale->payment_type] ?? $sale->payment_type,
            'bread_count' => (int) $sale->bread_count,
            'amount_formatted' => Money::format((float) $sale->amount),
            'cash_formatted' => Money::format($sale->cash_held),
            'shortfall_formatted' => Money::format($sale->open_shortfall),
            'difference_formatted' => Money::format($sale->open_difference),
            'open_debt_formatted' => Money::format($sale->cash_held + $sale->open_shortfall - $sale->open_difference),
            'customer' => $sale->customer?->name,
        ];
    }
}
