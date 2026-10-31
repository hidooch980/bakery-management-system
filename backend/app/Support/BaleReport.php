<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Sale;
use Illuminate\Support\Carbon;

/**
 * The nightly, weekly and monthly Bale reports, built from the same
 * figures the panel and the phone show.
 *
 * These lived in a script on the server, outside the repository, and had
 * drifted from the app: bread sales were stored toman printed as «ریال»
 * (a tenth of the real figure), a deleted batch's flour was listed as
 * flour «arriving», and dough and dusting flour were one line.
 */
class BaleReport
{
    public static function nightly(Carbon $day): string
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $journey = ReportSeries::itemJourney(InventoryItem::ofKey(InventoryItem::FLOUR), $from, $to);
        $flour = Ledger::flourSplit($from, $to);
        [$amount, $bread] = self::sales($from, $to);

        $lines = [
            '📋 گزارش شبانهٔ '.self::shop(),
            '📅 '.Jalali::date($day),
            '',
            '🌾 گردش آرد امروز (کیسه):',
            '• موجودی اول روز: '.self::bags($journey['opening_bags']),
            '• ورودی: '.self::bags($journey['in_bags']),
        ];
        foreach ($journey['in'] as $row) {
            $lines[] = '   – '.$row['label'].': '.self::bags($row['bags']);
        }
        $lines[] = '• آرد خمیرگیری: '.self::bags($flour['dough_bags']);
        $lines[] = '• آرد پاششی: '.self::bags($flour['spray_bags']);
        $lines[] = '• کل خروجی: '.self::bags($journey['out_bags']);
        foreach ($journey['out'] as $row) {
            $lines[] = '   – '.$row['label'].': '.self::bags($row['bags']);
        }
        $lines[] = '• موجودی آخر روز: '.self::bags($journey['closing_bags']);
        $lines[] = '';
        $lines[] = '💰 فروش نان امروز: '.Money::format($amount).' ('.number_format($bread).' عدد نان)';
        $lines[] = '';
        $lines[] = '🤝 حساب همکاران (آرد امانی، کیسه):';
        foreach (Customer::query()->partners()->orderBy('name')->get() as $partner) {
            $lines[] = '• '.$partner->name.': '.self::balance(PartnerStatement::balanceOf($partner));
        }
        $lines = array_merge($lines, self::partnerTotals());

        return implode("\n", $lines);
    }

    /** @param 'weekly'|'monthly' $kind */
    public static function period(string $kind, Carbon $now): string
    {
        if ($kind === 'weekly') {
            $from = $now->copy()->startOfDay()->subDays(($now->dayOfWeek + 1) % 7);
            $to = $from->copy()->addDays(6)->endOfDay();
            $previous = [$from->copy()->subDays(7), $to->copy()->subDays(7)];
            $title = '📊 گزارش هفتگی';
            $previousName = 'هفتهٔ قبل';
        } else {
            [$from, $to] = Jalali::monthRangeFor($now);
            $previous = Jalali::monthRangeFor($from->copy()->subDay());
            $title = '📊 گزارش ماهانه ('.Jalali::monthLabel($from).')';
            $previousName = 'ماه قبل';
        }

        $now = self::stats($from, $to);
        $before = self::stats(...$previous);
        $money = fn (float $v) => Money::format($v);
        $count = fn (float $v) => number_format($v);
        $bags = fn (float $v) => self::bags($v).' کیسه';

        return implode("\n", array_merge([
            $title.' '.self::shop(),
            '📅 '.Jalali::date($from).' تا '.Jalali::date($to),
            '(مقایسه با '.$previousName.': '.Jalali::date($previous[0]).' تا '.Jalali::date($previous[1]).')',
            '',
            '💰 فروش نان:',
            self::compare('مبلغ', $now['amount'], $before['amount'], $money),
            self::compare('تعداد نان', $now['bread'], $before['bread'], $count),
            '',
            '🌾 آرد (کیسه):',
            self::compare('ورودی', $now['in'], $before['in'], $bags),
            self::compare('آرد خمیرگیری', $now['dough'], $before['dough'], $bags),
            self::compare('آرد پاششی', $now['spray'], $before['spray'], $bags),
            self::compare('فروش آرد', $now['sold'], $before['sold'], $bags),
        ], self::partnerTotals()));
    }

    /** Whether today is the last day of its Jalali month. */
    public static function isMonthEnd(Carbon $day): bool
    {
        return Jalali::monthRangeFor($day)[1]->isSameDay($day);
    }

    private static function stats(Carbon $from, Carbon $to): array
    {
        $journey = ReportSeries::itemJourney(InventoryItem::ofKey(InventoryItem::FLOUR), $from, $to);
        $flour = Ledger::flourSplit($from, $to);
        [$amount, $bread] = self::sales($from, $to);
        $out = collect($journey['out'])->keyBy('reason');

        return [
            'amount' => $amount,
            'bread' => $bread,
            // Arrivals net of anything undone the same period: a batch
            // cancelled is not a delivery.
            'in' => (float) ($journey['in_bags'] ?? 0),
            'dough' => $flour['dough_bags'],
            'spray' => $flour['spray_bags'],
            'sold' => (float) ($out->get('flour_sale')['bags'] ?? 0),
        ];
    }

    /** @return array{0: float, 1: float} bread sold for money: amount (toman) and loaves */
    private static function sales(Carbon $from, Carbon $to): array
    {
        $sales = Sale::query()->whereBetween('created_at', [$from, $to])
            ->whereNotIn('payment_type', Sale::GIVEAWAY_TYPES);

        return [(float) (clone $sales)->sum('amount'), (float) (clone $sales)->sum('bread_count')];
    }

    private static function partnerTotals(): array
    {
        $totals = PartnerStatement::totals();

        return [
            '',
            'جمع طلب ما: '.self::bags($totals['owed_to_us']).' کیسه ('.$totals['partners_owing'].' نفر)',
            'جمع بدهی ما: '.self::bags($totals['we_owe']).' کیسه ('.$totals['partners_owed'].' نفر)',
            'خالص: '.self::balance($totals['net']).($totals['net'] == 0.0 ? '' : ' کیسه'),
        ];
    }

    private static function balance(float $bags): string
    {
        return $bags > 0.001 ? 'طلب ما '.self::bags($bags)
            : ($bags < -0.001 ? 'بدهی ما '.self::bags(abs($bags)) : 'تسویه');
    }

    private static function compare(string $label, float $now, float $before, callable $format): string
    {
        $change = $now - $before;
        $percent = abs($before) > 0.001
            ? ' ('.($change >= 0 ? '+' : '−').number_format(abs($change) / abs($before) * 100, 1).'٪)'
            : '';
        $trend = abs($change) < 0.001 ? 'بدون تغییر' : (($change > 0 ? '▲ ' : '▼ ').$format(abs($change)).$percent);

        return '• '.$label.': '.$format($now).' | قبل: '.$format($before).' | '.$trend;
    }

    private static function bags(?float $value): string
    {
        return PartnerStatement::bags((float) $value);
    }

    private static function shop(): string
    {
        return (string) (CurrentBakery::get()?->name ?? '');
    }
}
