<?php

namespace Tests\Feature;

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\FlourAllocation;
use App\Models\Sale;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shop's record of card sales, checked against the reader itself.
 *
 * «اختلاف با کارتخوان» has always compared the quota against
 * `card_bread_count`, which is the shop's *own* record — the loaves a
 * seller marked as paid by card. Nothing compared that with what the
 * reader actually registered, and the two are different authorities:
 * next month's flour follows the national system, so a card sale entered
 * as cash costs flour and leaves no trace until the allocation arrives
 * short.
 *
 * Reading it automatically was the nanino link, removed 1405/06/12
 * because their gateway refuses server-to-server calls. So it is typed.
 */
class TheReaderIsCheckedAgainstOurRecordTest extends TestCase
{
    use RefreshDatabase;

    private FlourAllocation $allocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->allocation = FlourAllocation::create([
            'month_start' => Jalali::currentMonthRange()[0],
            'month_label' => 'تست',
            'total_bags' => 75,
        ]);
        $this->allocation->syncPeriods();
    }

    /** A batch baked inside the period, and card sales against it. */
    private function sellOnCard(int $loaves): void
    {
        $period = $this->currentPeriod();
        $at = $period->starts_on->copy()->addHours(9);

        $dough = DoughEntry::create([
            'user_id' => User::first()->id,
            'bag_count' => 10,
            'created_at' => $at,
        ]);

        $batch = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => User::first()->id,
            'chane_count' => $loaves,
            'normal_weight_kg' => $loaves * 0.85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 5,
            'created_at' => $at,
        ]);

        Sale::create([
            'chane_entry_id' => $batch->id,
            'user_id' => User::first()->id,
            'payment_type' => 'card',
            'bread_count' => $loaves,
            'amount' => $loaves * 10_000,
            'created_at' => $at,
        ]);
    }

    /**
     * Always re-read. `periodFor()` walks the loaded `periods` relation,
     * so holding on to it hands back the values as they were before the
     * last update — which is exactly the figure these tests change.
     */
    private function currentPeriod()
    {
        return $this->allocation->fresh('periods')->periodFor(now());
    }

    private function issue(): ?SystemIssue
    {
        $period = $this->currentPeriod();

        return app(IssueScanner::class)->scan()
            ->firstWhere('key', "reader-gap-{$period->id}");
    }

    public function test_a_period_nobody_has_checked_reads_as_unchecked(): void
    {
        $period = $this->currentPeriod();

        // Not zero, and not agreement. Those are three different claims
        // and the blank one must not be mistaken for the other two.
        $this->assertNull($period->system_bread_count);
        $this->assertNull($period->system_gap);
        $this->assertFalse($period->is_checked_against_reader);
        $this->assertNull($this->issue());
    }

    public function test_the_reader_seeing_fewer_loaves_is_reported(): void
    {
        $period = $this->currentPeriod();
        $period->update(['system_bread_count' => 900]);

        // Nothing recorded, so our own count is zero and the reader saw
        // more — the harmless direction.
        $this->assertSame(900, $period->fresh()->system_gap);
        $this->assertNull($this->issue(), 'more is not a problem');

        // Now the shop claims more than the reader registered.
        $period->update(['system_bread_count' => 10]);
        $this->assertSame(10, $period->fresh()->system_gap);
        $this->assertNull($this->issue(), 'still no card sales recorded');
    }

    public function test_loaves_we_recorded_that_the_reader_never_saw(): void
    {
        $period = $this->currentPeriod();

        $this->sellOnCard(500);

        $period = $period->fresh();
        $this->assertSame(500, $period->card_bread_count);

        // The reader registered 380 of them. The other 120 earn nothing
        // towards next month's flour.
        $period->update(['system_bread_count' => 380]);

        $issue = $this->issue();

        $this->assertNotNull($issue);
        $this->assertSame(SystemIssue::WARNING, $issue->severity);
        $this->assertSame(-120, $period->fresh()->system_gap);
        $this->assertStringContainsString('۱۲۰', $this->faDigits($issue->detail));
        $this->assertSame(120.0, $issue->magnitude);
    }

    public function test_agreement_says_nothing(): void
    {
        $period = $this->currentPeriod();

        $this->sellOnCard(500);

        $period->fresh()->update(['system_bread_count' => 500]);

        $this->assertSame(0, $this->currentPeriod()->system_gap);
        $this->assertNull($this->issue());
    }

    public function test_re_syncing_the_periods_does_not_lose_the_figure(): void
    {
        $period = $this->currentPeriod();
        $period->update(['system_bread_count' => 640]);

        // Editing the month's total re-derives the periods; a figure typed
        // in by hand must survive that.
        $this->allocation->update(['total_bags' => 90]);
        $this->allocation->syncPeriods();

        $this->assertSame(640, $this->currentPeriod()->system_bread_count);
    }

    private function faDigits(string $text): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            $text,
        );
    }
}
