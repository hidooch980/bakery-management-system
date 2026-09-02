<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\FlourAllocation;
use App\Models\InventoryItem;
use Illuminate\Support\Collection;

/**
 * The sentence the shop opens with, and the short list under it.
 *
 * The panel says it and the phone says it, so it is written once. The
 * alternative — the same sentence composed in a Blade view and again in a
 * controller — is how «سالم» ends up on one screen while the other says
 * something else, and the owner learns to trust neither.
 *
 * Two halves, deliberately. How the *system* is, then how much is the
 * *owner's*. A sound system and a busy shop are not in tension: reading
 * «مغازه امروز سالم است» beside «سه چیز کار شماست» is the correct reading
 * of a shop that owes money and keeps honest books. Conflating them would
 * cry wolf about a debt he already knows about, or go quiet about a real
 * fault because nobody happens to owe anything.
 *
 * When the records *do* contradict each other, the second half stops
 * reporting the shop's business and says the figures cannot be trusted —
 * because at that point they cannot.
 */
class TodayAnswer
{
    private function __construct(
        public readonly ShopHealth $health,
        /** @var Collection<int, SystemIssue> */
        public readonly Collection $needs,
    ) {}

    /** @param  Collection<int, SystemIssue>  $needs */
    public static function from(ShopHealth $health, Collection $needs): self
    {
        return new self($health, $needs);
    }

    public static function now(): self
    {
        return new self(ShopHealth::inspect(), app(IssueScanner::class)->scan());
    }

    /** @return array{tone: string, system: string, yours: string} */
    public function sentence(): array
    {
        if (! $this->health->isSound()) {
            return [
                'tone' => 'fail',
                'system' => 'سیستم با خودش نمی‌خواند.',
                'yours' => 'تا این درست نشود به عددهای پایین اعتماد نکنید.',
            ];
        }

        $open = $this->needs->count();

        return [
            'tone' => $open === 0 ? 'clear' : 'sound',
            'system' => 'مغازه امروز سالم است.',
            'yours' => match (true) {
                $open === 0 => 'هیچ چیز کار شما نیست.',
                $open === 1 => 'یک چیز کار شماست.',
                default => self::digits($open).' چیز کار شماست.',
            },
        ];
    }

    /**
     * How many cycles were checked, for the line under the sentence.
     *
     * Counted rather than written down, so adding a cycle cannot leave
     * either screen claiming the old number.
     */
    public function cycleCount(): int
    {
        return count($this->health->cycles());
    }

    /**
     * The figures, last and quiet.
     *
     * One line, no cards. They are here because the owner will sometimes
     * want them, not because they are the answer — the moment they are
     * laid out as a grid they become the page again, which is the
     * dashboard this replaces.
     *
     * @return list<array{label: string, value: string}>
     */
    public function figures(): array
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $bags = $flour->balance_bags;

        $rows = [[
            'label' => 'آرد',
            'value' => $bags === null
                ? self::digits(number_format($flour->balance, 0)).' کیلو'
                : self::sacks($bags),
        ]];

        foreach (BankAccount::all() as $account) {
            $rows[] = [
                'label' => $account->title,
                'value' => Money::format((float) $account->balance),
            ];
        }

        $allocation = FlourAllocation::with('periods')->orderByDesc('month_start')->first();
        $period = $allocation?->periodFor(now());

        if ($period) {
            $rows[] = ['label' => 'سهمیه', 'value' => self::digits($period->usage_percent).'٪'];
        }

        $rows[] = [
            'label' => 'چانهٔ امروز',
            'value' => self::digits((int) ChaneEntry::whereDate('created_at', now())->sum('chane_count')),
        ];

        return $rows;
    }

    /** «۶۵٫۲ کیسه» — one decimal, because nobody counts a tenth of a sack. */
    private static function sacks(float $bags): string
    {
        return self::digits(rtrim(rtrim(number_format($bags, 1), '0'), '.')).' کیسه';
    }

    /** Persian digits, because every other figure on these screens is. */
    public static function digits(int|float|string $value): string
    {
        return strtr((string) $value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', '.' => '٫',
        ]);
    }
}
