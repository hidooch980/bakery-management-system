<?php

namespace Tests\Feature;

use App\Filament\Pages\IssueCenter;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\IssueAcknowledgement;
use App\Models\Sale;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Answering an issue instead of fixing it.
 *
 * Some of what the scanner reports is not a mistake. This shop pays no
 * wages through the system and keeps rent at zero on purpose, so those two
 * are reported every time the page is opened and always will be — one of
 * them as «بحرانی». An alarm that cannot be answered gets ignored, and then
 * nobody looks the day a real one sounds.
 *
 * So the owner can answer one. What that must not become is a mute button:
 * the answer covers the problem at the size it was, and a problem that
 * grows past that comes back on its own.
 */
class AnsweringAnIssueTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'negative-stock-flour';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
            'bread_price' => 5000,
            'currency' => 'toman',
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Flour below zero — an issue with a size, which is what makes it the
     * right one to test answers against. move() will not take a balance
     * under zero, so the movement is written straight in.
     */
    private function short(float $kg): void
    {
        InventoryMovement::create([
            'inventory_item_id' => InventoryItem::ofKey(InventoryItem::FLOUR)->id,
            'direction' => 'out',
            'quantity' => $kg,
            'reason' => 'production',
        ]);
    }

    private function answer(string $note = 'می‌دانم.'): void
    {
        Livewire::test(IssueCenter::class)
            ->callAction('acknowledge', data: ['note' => $note], arguments: ['key' => self::KEY]);
    }

    private function page(): IssueCenter
    {
        // A fresh instance each time: the page holds its scan for one
        // request, which is the point of it, and a stale one here would
        // make these tests pass for the wrong reason.
        return app(IssueCenter::class);
    }

    public function test_an_unanswered_issue_is_open(): void
    {
        $this->short(5);

        $this->assertSame(1, $this->page()->getOpenIssues()->count());
        $this->assertSame(0, $this->page()->getAnsweredIssues()->count());
    }

    public function test_answering_moves_it_out_of_the_open_list(): void
    {
        $this->short(5);

        $this->answer();

        $this->assertSame(0, $this->page()->getOpenIssues()->count());
        $this->assertSame(1, $this->page()->getAnsweredIssues()->count());
    }

    public function test_the_issue_is_still_on_the_page(): void
    {
        $this->short(5);
        $this->answer();

        // Answered, not hidden. The distinction is the whole design: a
        // dismissal that deleted the finding would leave the shop with no
        // record that the flour is short.
        $this->assertSame(
            self::KEY,
            $this->page()->getAnsweredIssues()->first()->key,
        );
    }

    public function test_the_answer_records_who_and_why(): void
    {
        $this->short(5);

        $this->answer('حقوق بیرون از سامانه پرداخت می‌شود.');

        $answer = IssueAcknowledgement::where('issue_key', self::KEY)->first();

        $this->assertNotNull($answer);
        $this->assertSame($this->admin->id, $answer->acknowledged_by);
        $this->assertSame('حقوق بیرون از سامانه پرداخت می‌شود.', $answer->note);
        $this->assertEqualsWithDelta(5.0, $answer->magnitude, 0.001);
    }

    public function test_a_problem_that_grows_a_little_stays_answered(): void
    {
        $this->short(5);
        $this->answer();

        // 5 → 5.5, a tenth worse. Under the threshold on purpose: a
        // balance drifting by a day's trading must not reopen a decision
        // every morning.
        $this->short(0.5);

        $this->assertSame(0, $this->page()->getOpenIssues()->count());
    }

    public function test_a_problem_that_grows_a_lot_comes_back(): void
    {
        $this->short(5);
        $this->answer();

        // 5 → 10. This is not the problem that was decided about.
        $this->short(5);

        $this->assertSame(1, $this->page()->getOpenIssues()->count());
        $this->assertSame(0, $this->page()->getAnsweredIssues()->count());
    }

    public function test_an_issue_that_came_back_says_how_much_worse_it_got(): void
    {
        $this->short(5);
        $this->answer();
        $this->short(5);

        $page = $this->page();
        $issue = $page->getOpenIssues()->first();

        $this->assertSame(100, $page->growthFor($issue));
    }

    public function test_asking_for_it_back_reopens_it(): void
    {
        $this->short(5);
        $this->answer();

        Livewire::test(IssueCenter::class)
            ->callAction('reopen', arguments: ['key' => self::KEY]);

        $this->assertSame(1, $this->page()->getOpenIssues()->count());
        $this->assertSame(0, IssueAcknowledgement::count());
    }

    public function test_the_badge_counts_only_open_issues(): void
    {
        $this->short(5);
        $this->assertSame('1', IssueCenter::getNavigationBadge());

        $this->answer();

        // Nothing left to act on, so nothing on the badge. A badge that
        // never clears is a badge nobody reads.
        $this->assertNull(IssueCenter::getNavigationBadge());
    }

    public function test_the_badge_is_red_only_while_something_critical_is_open(): void
    {
        $this->short(5);
        $this->assertSame('danger', IssueCenter::getNavigationBadgeColor());

        $this->answer();

        $this->assertNull(IssueCenter::getNavigationBadgeColor());
    }

    public function test_fixing_the_data_removes_it_from_both_lists(): void
    {
        $this->short(5);
        $this->answer();

        // An answer is about a problem, and the problem is gone. Nothing
        // derives it any more, so neither list has anywhere to show it —
        // and the answer left behind is harmless, matching no issue.
        // Six, not five. Five lands the balance on exactly zero, which is
        // its own issue now — a bakery with no flour has not had its data
        // fixed. See test_flour_brought_back_to_exactly_zero_is_still_an_issue.
        InventoryItem::ofKey(InventoryItem::FLOUR)
            ->move('in', 6, 'purchase', $this->admin->id);

        $page = $this->page();
        $this->assertSame(0, $page->getOpenIssues()->count());
        $this->assertSame(0, $page->getAnsweredIssues()->count());
    }

    public function test_flour_brought_back_to_exactly_zero_is_still_an_issue(): void
    {
        $this->short(5);
        $this->answer();

        // The negative is gone, so the answered issue no longer matches
        // anything — but nothing has been bought either. The shop still
        // cannot bake, and the page has to keep saying so.
        InventoryItem::ofKey(InventoryItem::FLOUR)
            ->move('in', 5, 'purchase', $this->admin->id);

        $open = $this->page()->getOpenIssues();

        $this->assertSame(1, $open->count());
        $this->assertSame('empty-stock-flour', $open->first()->key);
    }

    /**
     * The wages issue has no magnitude on purpose.
     *
     * The obvious candidate was the month's sale count — it is in the
     * message — but that climbs every day the shop opens, so an answer
     * given on the 5th would have reopened itself by the 10th. The one
     * issue the owner is most certain to answer would have been the one
     * that would not stay answered.
     */
    public function test_answering_the_wages_issue_survives_another_days_trading(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $sell = function (int $loaves) use ($seller) {
            $dough = DoughEntry::create(['user_id' => $seller->id, 'bag_count' => 1]);
            $chane = ChaneEntry::create([
                'dough_entry_id' => $dough->id,
                'user_id' => $seller->id,
                'chane_count' => $loaves,
                'normal_weight_kg' => $loaves * 0.85,
                'nanino_weight_kg' => 0,
                'spray_flour_kg' => 0,
                'status' => 'sold',
            ]);
            Sale::create([
                'chane_entry_id' => $chane->id,
                'user_id' => $seller->id,
                'bread_count' => $loaves,
                'payment_type' => 'cash',
                'amount' => $loaves * 5000,
                'amount_difference' => 0,
            ]);
        };

        // A month of trading, then past its end — which is when wages fall
        // due and therefore the only moment the issue exists to be
        // answered at all.
        [$monthStart, $monthEnd] = Jalali::currentMonthRange();
        $key = 'monthly-wages-'.$monthStart->format('Y-m');

        $sell(100);

        Carbon::setTestNow($monthEnd->copy()->addDay()->setTime(9, 0));

        Livewire::test(IssueCenter::class)->callAction(
            'acknowledge',
            data: ['note' => 'حقوق بیرون از سامانه پرداخت می‌شود.'],
            arguments: ['key' => $key],
        );

        // About the wages issue specifically, not about the page being
        // empty: a cash sale leaves money with the seller, and that is its
        // own issue with its own life.
        $this->assertFalse($this->isOpen($key));

        // Five more days of ordinary trading in the new month.
        for ($i = 0; $i < 5; $i++) {
            $sell(100);
        }

        $this->assertFalse($this->isOpen($key));
        $this->assertTrue(
            $this->page()->getAnsweredIssues()->contains(fn ($i) => $i->key === $key)
        );
    }

    private function isOpen(string $key): bool
    {
        return $this->page()->getOpenIssues()->contains(fn ($i) => $i->key === $key);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_page_renders_with_an_answered_issue(): void
    {
        $this->short(5);
        $this->answer('چون تصمیم گرفتم.');

        Livewire::test(IssueCenter::class)
            ->assertOk()
            ->assertSee('تصمیم گرفته‌شده')
            ->assertSee('چون تصمیم گرفتم.');
    }
}
