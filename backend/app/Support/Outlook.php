<?php

namespace App\Support;

use App\Models\FlourAllocation;
use App\Models\FlourAllocationPeriod;
use App\Models\InventoryItem;
use Illuminate\Support\Collection;

/**
 * What the next few days look like, from what the last few did.
 *
 * Everything on «امروز» so far says what *is*: the flour in the store, the
 * balance in the bank, what needs the owner. None of it says what is
 * about to happen — and «آرد برای چند روز کافی است؟» is the question he
 * actually asks in the morning, with a delivery to order or not.
 *
 * Three lines, and the rules they all keep:
 *
 *   - **The basis is printed with the number.** «حدود ۶ روز» on its own is
 *     an oracle; «حدود ۶ روز، با میانگین ۱۴ روز اخیر» is arithmetic he can
 *     check against the store and disagree with.
 *   - **Not enough history means no line, not a guess.** A shop three days
 *     into its records gets nothing here, rather than a forecast built on
 *     three points that would be wrong with a straight face.
 *   - **Derived only.** Nothing is stored and nothing is asked of the
 *     staff. It reads the ledger the shop already keeps.
 *
 * This is run-rate arithmetic, not a model. It is called what it is.
 */
class Outlook
{
    /** Days of history the run rate is averaged over. */
    public const WINDOW_DAYS = 14;

    /**
     * Fewer days than this with any movement, and there is no run rate
     * worth the name. Five is one working week.
     */
    public const MIN_DAYS = 5;

    /** Days of flour at which the line stops being calm. */
    public const FLOUR_ATTENTION_DAYS = 3;

    /**
     * @return Collection<int, array{key: string, tone: string, title: string, basis: string}>
     */
    /**
     * @param  FlourAllocationPeriod|null  $period  the period in progress when the
     *                                              caller has already loaded it — `TodayAnswer` has, for the figures
     *                                              line — so it is not loaded twice for one page. Called bare, it is
     *                                              looked up here.
     */
    public static function now(?FlourAllocationPeriod $period = null, bool $periodGiven = false): Collection
    {
        $burn = self::flourBurnPerDay();

        if (! $periodGiven && $period === null) {
            $allocation = FlourAllocation::with('periods')->orderByDesc('month_start')->first();
            $period = $allocation?->periodFor(now());
        }

        return collect([
            ...self::flour($burn),
            ...self::quota($burn, $period),
            ...self::periodProfit(),
        ]);
    }

    /**
     * Kilograms of flour that go into production on an average day, over
     * the window — or null when the history is too thin to average.
     *
     * Calendar days, not working days, and on purpose: the question is
     * «how many days until the store is empty», and days the oven is cold
     * are days all the same. A shop that closes two days a week burns
     * less per calendar day, and that is exactly what makes its flour
     * last longer.
     *
     * @return array{perDay: float, activeDays: int}|null
     */
    private static function flourBurnPerDay(): ?array
    {
        $to = now()->endOfDay();
        $from = now()->subDays(self::WINDOW_DAYS - 1)->startOfDay();

        $daily = Ledger::dailyStockOut(
            InventoryItem::ofKey(InventoryItem::FLOUR),
            ['production'],
            $from,
            $to,
        );

        $activeDays = count(array_filter($daily, fn (float $kg) => $kg > 0));

        if ($activeDays < self::MIN_DAYS) {
            return null;
        }

        return [
            'perDay' => Ledger::sumDays($daily, $from, $to) / self::WINDOW_DAYS,
            'activeDays' => $activeDays,
        ];
    }

    /** «آرد با این روند حدود ۶ روز کافی است.» */
    private static function flour(?array $burn): array
    {
        if ($burn === null || $burn['perDay'] <= 0) {
            return [];
        }

        $balance = (float) InventoryItem::ofKey(InventoryItem::FLOUR)->balance;

        // Below zero is a fault the scanner already reports; a forecast
        // over a negative store would be nonsense dressed as a number.
        if ($balance <= 0) {
            return [];
        }

        $days = (int) floor($balance / $burn['perDay']);

        return [[
            'key' => 'flour-days',
            'tone' => $days <= self::FLOUR_ATTENTION_DAYS ? 'attention' : 'calm',
            'title' => match (true) {
                $days === 0 => 'آرد با این روند تا فردا نمی‌رسد.',
                $days === 1 => 'آرد با این روند فقط برای فردا کافی است.',
                default => 'آرد با این روند حدود '.TodayAnswer::digits($days).' روز کافی است.',
            },
            'basis' => self::basis($burn),
        ]];
    }

    /**
     * Whether the quota period in progress will last to its end.
     *
     * The period is the shop's own month, the 5th to the 4th, because that
     * is the cycle the flour is issued on. `usage_percent` on the figures
     * line says how much is used; this says whether the rest is enough
     * for the days that are left, which is the half that decides anything.
     */
    private static function quota(?array $burn, ?FlourAllocationPeriod $period): array
    {
        if ($burn === null || $burn['perDay'] <= 0) {
            return [];
        }

        if ($period === null) {
            return [];
        }

        $remaining = (float) $period->remaining_kg;

        // Overdrawn is an issue on the scanner, not a forecast.
        if ($remaining <= 0) {
            return [];
        }

        // Today included on both sides: the bake that spends today's flour
        // has usually not happened at the hour this is read, so today is a
        // day of consumption still to come — for the flour that is left
        // and for the days that are left alike. The wording says «مانده»
        // rather than «دیگر پایان می‌یابد» because the count includes
        // today, and on the last day «۱ روز مانده» is true where «۱ روز
        // دیگر» would not be.
        $daysLeft = (int) now()->startOfDay()->diffInDays($period->ends_on->copy()->startOfDay()) + 1;
        $daysCovered = (int) floor($remaining / $burn['perDay']);

        if ($daysCovered >= $daysLeft) {
            return [[
                'key' => 'quota-lasts',
                'tone' => 'calm',
                'title' => 'با این روند، سهمیه تا پایان دوره می‌رسد ('
                    .TodayAnswer::digits($daysLeft).' روز از دوره مانده).',
                'basis' => self::basis($burn),
            ]];
        }

        return [[
            'key' => 'quota-short',
            'tone' => 'attention',
            'title' => 'سهمیهٔ این دوره حدود '.TodayAnswer::digits($daysCovered)
                .' روز دیگر تمام می‌شود، ولی '.TodayAnswer::digits($daysLeft)
                .' روز از دوره مانده.',
            'basis' => self::basis($burn),
        ]];
    }

    /**
     * Where the period's profit lands if the rest of it goes like the
     * start.
     *
     * The same window `tradingAtALoss` judges, so «چقدر سود می‌کنیم» and
     * «داریم ضرر می‌کنیم» cannot be about two different months. Nothing
     * before the fifth day: a period two days old has one delivery in it
     * and no bread yet, and projecting that forward says the shop is
     * ruined every month on the second.
     */
    private static function periodProfit(): array
    {
        [$from, $to] = Jalali::currentQuotaPeriod();

        $elapsed = (int) $from->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1;
        $total = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;

        if ($elapsed < self::MIN_DAYS || $total <= 0) {
            return [];
        }

        $soFar = Ledger::profit($from, now());
        $projected = round($soFar * $total / $elapsed, 2);

        $word = $projected >= 0 ? 'سود' : 'زیان';

        return [[
            'key' => 'period-profit',
            'tone' => $projected < 0 ? 'attention' : 'calm',
            'title' => 'با روند این دوره، '.$word.' پایان دوره حدود '
                .Money::format(abs($projected)).'.',
            'basis' => 'تا امروز '.($soFar >= 0 ? 'سود' : 'زیان').' '
                .Money::format(abs($soFar)).' در '
                .TodayAnswer::digits($elapsed).' روز از '.TodayAnswer::digits($total).'.',
        ]];
    }

    /** «میانگین ۱۴ روز اخیر: ۱۱۲ کیلو در روز (۱۲ روز پخت)». */
    private static function basis(array $burn): string
    {
        return 'میانگین '.TodayAnswer::digits(self::WINDOW_DAYS).' روز اخیر: '
            .TodayAnswer::digits(number_format($burn['perDay'], 0)).' کیلو در روز ('
            .TodayAnswer::digits($burn['activeDays']).' روز پخت).';
    }
}
