<?php

namespace App\Filament\Pages;

use App\Support\ShopHealth;
use App\Support\SystemIssue;
use App\Support\TodayAnswer;
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
 *
 * The sentence itself lives in `TodayAnswer`, because the phone shows it
 * too — the same words on both screens or the owner learns to trust
 * neither.
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

    private ?TodayAnswer $answer = null;

    private function answerer(): TodayAnswer
    {
        return $this->answer ??= TodayAnswer::now();
    }

    public function health(): ShopHealth
    {
        return $this->answerer()->health;
    }

    /** @return Collection<int, SystemIssue> */
    public function issues(): Collection
    {
        return $this->answerer()->needs;
    }

    /** @return array{tone: string, system: string, yours: string} */
    public function answer(): array
    {
        return $this->answerer()->sentence();
    }

    public function cycleCountLabel(): string
    {
        return TodayAnswer::digits($this->answerer()->cycleCount());
    }

    /** @return list<array{key: string, tone: string, title: string, basis: string}> */
    public function outlook(): array
    {
        return $this->answerer()->outlook();
    }

    /** @return list<array{label: string, value: string}> */
    public function figures(): array
    {
        return $this->answerer()->figures();
    }
}
