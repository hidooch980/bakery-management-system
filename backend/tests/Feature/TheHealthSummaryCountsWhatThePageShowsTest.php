<?php

namespace Tests\Feature;

use App\Filament\Pages\IssueCenter;
use App\Models\Bakery;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\IssueAcknowledgement;
use App\Models\User;
use App\Support\DecidedIssues;
use App\Support\IssueScanner;
use App\Support\Money;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `shop:health`'s summary and the issue centre count the same things.
 *
 * The line names that page — «صفحهٔ مشکلات: N مورد» — and printed the raw
 * scan, so on 2026-09-03 it said seven while the page showed four. Five
 * had been answered, two of them weeks before. It was read aloud twice
 * that morning as though all seven were waiting, and two settled matters
 * were handed back to the owner as work.
 *
 * A summary that points at a screen and disagrees with it teaches the
 * reader to trust neither.
 */
class TheHealthSummaryCountsWhatThePageShowsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
            'bread_price' => 5000,
            'currency' => 'toman',
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 10_000, 'purchase');
    }

    /** A lending old enough to be chased, so there is a real issue to answer. */
    private function anOpenIssue(string $partner, float $bags): SystemIssue
    {
        $customer = Customer::create(['name' => $partner, 'type' => 'partner']);

        ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'customer_id' => $customer->id,
            'direction' => 'lent',
            'bags' => $bags,
            'occurred_on' => now()->subDays(30),
        ]);

        return (new IssueScanner)->scan()
            ->firstWhere('key', 'consignment-open-'.$customer->id);
    }

    private function answer(SystemIssue $issue, ?float $magnitude = null): void
    {
        IssueAcknowledgement::create([
            'issue_key' => $issue->key,
            'title' => $issue->title,
            'severity' => $issue->severity,
            'magnitude' => $magnitude ?? $issue->magnitude,
            'note' => 'تصمیم گرفته شد.',
        ]);
    }

    private function summary(): string
    {
        Artisan::call('shop:health');

        return Artisan::output();
    }

    public function test_an_answered_issue_is_not_counted_as_open(): void
    {
        $issue = $this->anOpenIssue('نانوایی هیدوچ', 56);

        $this->assertStringContainsString('1 مورد باز', $this->summary());

        $this->answer($issue);

        $this->assertStringContainsString('0 مورد باز', $this->summary());
    }

    public function test_the_answered_ones_are_still_said_on_their_own_line(): void
    {
        $this->answer($this->anOpenIssue('نانوایی هیدوچ', 56));

        // Not nothing: an answer covers a problem at the size it was, so a
        // long decided list is a list that will come back one at a time.
        $this->assertStringContainsString('1 مورد که پاسخ داده‌اید', $this->summary());
    }

    public function test_nothing_answered_means_no_second_line_at_all(): void
    {
        $this->anOpenIssue('نانوایی هیدوچ', 56);

        $this->assertStringNotContainsString('پاسخ داده‌اید', $this->summary());
    }

    /**
     * The whole point of the line. Whatever it says, the page must show
     * the same.
     */
    public function test_the_summary_and_the_page_never_disagree(): void
    {
        $this->answer($this->anOpenIssue('نانوایی هیدوچ', 56));
        $this->anOpenIssue('نانوایی پدگان', 20);
        $this->anOpenIssue('نانوایی کنت', 30);

        $page = new IssueCenter;

        $this->assertSame(2, $page->getOpenIssues()->count());
        $this->assertSame(1, $page->getAnsweredIssues()->count());

        $summary = $this->summary();
        $this->assertStringContainsString('2 مورد باز', $summary);
        $this->assertStringContainsString('1 مورد که پاسخ داده‌اید', $summary);
    }

    /**
     * An answer covers the problem at the size it was. One that has grown
     * past that is a different problem wearing the same key, and belongs
     * back in the open count.
     */
    public function test_an_issue_that_outgrew_its_answer_counts_as_open_again(): void
    {
        $issue = $this->anOpenIssue('نانوایی هیدوچ', 56);

        // Answered when it was ten sacks; it is fifty-six now.
        $this->answer($issue, magnitude: 10);

        $this->assertStringContainsString('1 مورد باز', $this->summary());
        $this->assertStringNotContainsString('پاسخ داده‌اید', $this->summary());
    }

    /**
     * The critical count follows the open list too. A decided critical
     * issue reprinted as critical every morning is the reason nobody
     * reads the line.
     */
    public function test_the_critical_count_is_of_open_issues_only(): void
    {
        // A balance below zero: the shop's oldest critical, and one this
        // test can arrange rather than hope for. Written straight to the
        // ledger because the model would refuse to take out what is not
        // there — which is the whole reason a negative balance is an
        // issue when it does appear.
        InventoryMovement::create([
            'inventory_item_id' => InventoryItem::ofKey(InventoryItem::FLOUR)->id,
            'direction' => 'out',
            'quantity' => 20_000,
            'reason' => 'production',
        ]);

        $critical = (new IssueScanner)->scan()
            ->firstWhere('severity', SystemIssue::CRITICAL);

        $this->assertNotNull($critical, 'موجودی منفی باید بحرانی گزارش شود.');

        $this->assertStringContainsString('(1 بحرانی)', $this->summary());

        $this->answer($critical);

        // A decided critical reprinted as critical every morning is the
        // reason nobody reads the line.
        $this->assertStringContainsString('(0 بحرانی)', $this->summary());
    }

    public function test_the_split_is_one_rule_shared_by_both(): void
    {
        $answered = $this->anOpenIssue('نانوایی هیدوچ', 56);
        $open = $this->anOpenIssue('نانوایی پدگان', 20);

        $this->answer($answered);

        $decided = DecidedIssues::load();

        $this->assertFalse($decided->isOpen($answered));
        $this->assertTrue($decided->isOpen($open));
        $this->assertNotNull($decided->answerFor($answered));
        $this->assertNull($decided->answerFor($open));
    }
}
