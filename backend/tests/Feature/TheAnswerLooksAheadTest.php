<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\FlourAllocation;
use App\Models\Income;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\Outlook;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «امروز» says what the next few days look like, from what the last few did.
 *
 * Three rules, each with a test that fails without it: the basis travels
 * with every number, a history too thin to average produces no line rather
 * than a guess, and the arithmetic is the plain run-rate kind the owner
 * can check against the store.
 *
 * Time is pinned to the 14th of Shahrivar 1405 — ten days into the shop's
 * own month, which opens on the 5th — so «how many days are left» has one
 * answer on every machine this runs on.
 */
class TheAnswerLooksAheadTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00:00'));

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first();

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A delivery well before the window, so nothing here runs the store dry. */
    private function stock(float $kg): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)
            ->move('in', $kg, 'purchase')
            ->forceFill(['created_at' => now()->subDays(40)])
            ->save();
    }

    /** [$kg] into production on each of the last [$days] days, today included. */
    private function bake(float $kg, int $days): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);

        for ($ago = 0; $ago < $days; $ago++) {
            $flour->move('out', $kg, 'production')
                ->forceFill(['created_at' => now()->subDays($ago)->setTime(9, 0)])
                ->save();
        }
    }

    private function line(string $key): ?array
    {
        return Outlook::now()->firstWhere('key', $key);
    }

    public function test_it_says_how_many_days_the_flour_lasts_and_on_what_basis(): void
    {
        $this->stock(3000);
        $this->bake(100, 14);

        // 3000 in, 1400 out, 100 a day: sixteen days.
        $line = $this->line('flour-days');

        $this->assertNotNull($line);
        $this->assertSame('calm', $line['tone']);
        $this->assertStringContainsString('۱۶ روز', $line['title']);
        $this->assertStringContainsString('۱۰۰ کیلو در روز', $line['basis']);
        $this->assertStringContainsString('۱۴ روز پخت', $line['basis']);
    }

    public function test_a_store_about_to_run_out_is_said_with_attention(): void
    {
        $this->stock(1650);
        $this->bake(100, 14);

        // 250 left at 100 a day.
        $line = $this->line('flour-days');

        $this->assertSame('attention', $line['tone']);
        $this->assertStringContainsString('۲ روز', $line['title']);
    }

    /**
     * Three days of records is not a run rate, and a forecast built on it
     * would be wrong with a straight face. No line at all.
     */
    public function test_a_history_too_thin_to_average_says_nothing(): void
    {
        $this->stock(3000);
        $this->bake(100, Outlook::MIN_DAYS - 1);

        $this->assertNull($this->line('flour-days'));
        $this->assertNull($this->line('quota-lasts'));
        $this->assertNull($this->line('quota-short'));
    }

    /**
     * The period opened nine days ago and runs 31 days, so today is its
     * tenth day and 22 remain, today included — today's bake has not
     * happened yet at ten in the morning. Ten days at 100 spent 1000 of
     * the 5000; the 4000 left covers forty days against those 22.
     */
    public function test_a_quota_that_will_last_is_said_calmly(): void
    {
        $this->stock(3000);
        $this->bake(100, 14);
        $this->period(allocatedKg: 5000, startedDaysAgo: 10, length: 31);

        $line = $this->line('quota-lasts');

        $this->assertNotNull($line);
        $this->assertSame('calm', $line['tone']);
        $this->assertStringContainsString('۲۲ روز از دوره مانده', $line['title']);
        $this->assertNull($this->line('quota-short'));
    }

    /** 1500 allocated, 1000 spent: five days of flour against the 22 left. */
    public function test_a_quota_that_will_not_last_says_by_how_much(): void
    {
        $this->stock(3000);
        $this->bake(100, 14);
        $this->period(allocatedKg: 1500, startedDaysAgo: 10, length: 31);

        $line = $this->line('quota-short');

        $this->assertNotNull($line);
        $this->assertSame('attention', $line['tone']);
        $this->assertStringContainsString('۵ روز دیگر تمام', $line['title']);
        $this->assertStringContainsString('۲۲ روز از دوره مانده', $line['title']);
    }

    /**
     * The 14th is ten days into the period that opened on the 5th, so the
     * projection is allowed. On the 6th it is not: one delivery and no
     * bread, projected forward, says the shop is ruined every month on
     * the second.
     */
    public function test_the_profit_projection_waits_for_a_working_week_of_the_period(): void
    {
        $this->assertNotNull($this->line('period-profit'));

        Carbon::setTestNow(Carbon::parse('2026-08-28 10:00:00')); // 1405/06/06

        $this->assertNull($this->line('period-profit'));
    }

    /**
     * Ten days into a 31-day period with a million in and nothing out:
     * 1,000,000 × 31 / 10. The figure is checked through `Money::format`
     * rather than typed, so a change to the shop's currency label cannot
     * fail this for the wrong reason.
     */
    public function test_the_profit_projection_is_plain_run_rate_arithmetic(): void
    {
        $account = BankAccount::create(['title' => 'صندوق', 'opening_balance' => 0]);

        Income::create([
            'category' => 'rent',
            'title' => 'اجاره',
            'amount' => 1_000_000,
            'received_on' => now(),
            'bank_account_id' => $account->id,
        ]);

        $line = $this->line('period-profit');

        $this->assertSame('calm', $line['tone']);
        $this->assertStringContainsString(Money::format(3_100_000.0), $line['title']);
        $this->assertStringContainsString(Money::format(1_000_000.0), $line['basis']);
        $this->assertStringContainsString('۱۰ روز از ۳۱', $line['basis']);
    }

    public function test_the_outlook_reaches_the_handset(): void
    {
        $this->stock(3000);
        $this->bake(100, 14);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/today')
            ->assertOk()
            ->assertJsonStructure(['data' => ['outlook' => [['key', 'tone', 'title', 'basis']]]])
            ->assertJsonPath('data.outlook.0.key', 'flour-days');
    }

    private function period(float $allocatedKg, int $startedDaysAgo, int $length): void
    {
        [$monthStart] = Jalali::currentMonthRange();

        $allocation = FlourAllocation::firstOrCreate(
            ['month_start' => $monthStart],
            ['month_label' => Jalali::monthLabel($monthStart) ?? '', 'total_bags' => 0, 'carryover_bags' => 0],
        );

        $starts = now()->copy()->subDays($startedDaysAgo - 1)->startOfDay();

        $allocation->periods()->create([
            'period_number' => 1,
            'label' => 'دورهٔ آزمایشی',
            'starts_on' => $starts,
            'ends_on' => $starts->copy()->addDays($length - 1),
            'allocated_kg' => $allocatedKg,
        ]);
    }
}
