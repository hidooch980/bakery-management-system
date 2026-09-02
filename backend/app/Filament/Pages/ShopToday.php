<?php

namespace App\Filament\Pages;

use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\FlourAllocation;
use App\Models\InventoryItem;
use App\Support\IssueScanner;
use App\Support\Money;
use App\Support\ShopHealth;
use App\Support\SystemIssue;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * One answer: whether the shop is sound, and what is the owner's to do.
 *
 * The dashboard has twenty widgets on it and the owner reads none of them.
 * He asks «چک کن» instead, and I run the checks and tell him in a
 * sentence — which worked, and is exactly the problem: the answer existed
 * and the software would not give it to him.
 *
 * On 1405/06/07 a batch entered as ten sacks was corrected to twenty and
 * the flour for the other ten was baked and sold without leaving the
 * ledger. **Four days passed with every screen he had showing green**,
 * because a missing deduction is invisible to «ورود منهای خروج» and the
 * page that would have said so was a command over SSH. He found it by
 * feel: «چرخه‌ها یک جایی مشکل دارد».
 *
 * So this page leads with a sentence, then lists only what needs him, and
 * puts the figures last in one quiet line. It is the owner's half of the
 * «یک کار» redesign the production roles got in Mordad — they are asked
 * one question; he is given one answer.
 *
 * Three things it does that the dashboard does not:
 *
 *   - **Says when it looked.** A green screen with no timestamp is what
 *     those four days looked like.
 *   - **Says nothing about what is fine.** Twenty widgets that are all
 *     correct hide the one that is not.
 *   - **Separates «the system is wrong» from «the shop owes money».**
 *     `ShopHealth` answers the first, `IssueScanner` the second, and
 *     conflating them is how a real fault ends up filed beside a debt
 *     the owner already knows about.
 *
 * Nothing is removed: the old dashboard is still there for anyone who
 * wants the grid.
 */
class ShopToday extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sun';

    protected static ?string $navigationLabel = 'امروز';

    protected static ?string $title = 'امروز';

    // Above everything, including the dashboard: this is the first screen
    // the shop should meet.
    protected static ?int $navigationSort = -100;

    protected static string $view = 'filament.pages.shop-today';

    private ?ShopHealth $health = null;

    /** @var Collection<int, SystemIssue>|null */
    private ?Collection $issues = null;

    public function health(): ShopHealth
    {
        return $this->health ??= ShopHealth::inspect();
    }

    /** @return Collection<int, SystemIssue> */
    public function issues(): Collection
    {
        return $this->issues ??= app(IssueScanner::class)->scan();
    }

    /**
     * The sentence the page opens with.
     *
     * Deliberately in two halves: how the *system* is, then how much is
     * the *owner's*. Reading «سالم» beside «سه چیز کار شماست» is the whole
     * point — a sound system and a busy shop are not in tension, and a
     * page that mixed them would cry wolf about a debt or stay silent
     * about a fault.
     *
     * @return array{tone: string, system: string, yours: string}
     */
    public function answer(): array
    {
        $health = $this->health();
        $open = $this->issues()->count();

        $yours = match (true) {
            $open === 0 => 'هیچ چیز کار شما نیست.',
            $open === 1 => 'یک چیز کار شماست.',
            default => "{$this->digits($open)} چیز کار شماست.",
        };

        if (! $health->isSound()) {
            return [
                'tone' => 'fail',
                'system' => 'سیستم با خودش نمی‌خواند.',
                'yours' => 'تا این درست نشود به عددهای پایین اعتماد نکنید.',
            ];
        }

        return [
            'tone' => $open === 0 ? 'clear' : 'sound',
            'system' => 'مغازه امروز سالم است.',
            'yours' => $yours,
        ];
    }

    /**
     * How many cycles were checked, for the line under the sentence.
     *
     * Counted rather than written as a constant, so adding a cycle cannot
     * leave the page claiming the old number.
     */
    public function cycleCount(): int
    {
        return count($this->health()->cycles());
    }

    public function cycleCountLabel(): string
    {
        return $this->digits($this->cycleCount());
    }

    /**
     * The figures, last and quiet.
     *
     * One line, no cards. They are here because the owner will sometimes
     * want them, not because they are the answer — the moment they are
     * laid out as a grid they become the page again.
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
                ? number_format($flour->balance, 0).' کیلو'
                : $this->trim($bags).' کیسه',
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
            $rows[] = ['label' => 'سهمیه', 'value' => $this->digits($period->usage_percent).'٪'];
        }

        $rows[] = [
            'label' => 'چانهٔ امروز',
            'value' => $this->digits((int) ChaneEntry::whereDate('created_at', now())->sum('chane_count')),
        ];

        return $rows;
    }

    /** Persian digits, because every other figure on this screen is. */
    private function digits(int|float|string $value): string
    {
        return strtr((string) $value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', '.' => '٫']);
    }

    /** «۶۵٫۲» rather than «۶۵٫۱۵» — a fraction of a sack nobody counts. */
    private function trim(float $bags): string
    {
        return $this->digits(rtrim(rtrim(number_format($bags, 1), '0'), '.'));
    }
}
