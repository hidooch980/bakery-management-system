<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\DieselAllocation;
use App\Models\DieselDelivery;
use App\Models\FlourAllocation;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fuel used to be an expense category and nothing more, so nobody could say
 * how many litres the shop still had a right to. An oven that runs dry
 * mid-bake loses the batch in it and the one behind it.
 */
class DieselQuotaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function quota(float $litres, float $carryover = 0): DieselAllocation
    {
        return DieselAllocation::create([
            'month_start' => Jalali::currentMonthRange()[0],
            'total_litres' => $litres,
            'carryover_litres' => $carryover,
        ]);
    }

    private function delivery(float $litres, ?float $amount = null): DieselDelivery
    {
        return DieselDelivery::create([
            'user_id' => $this->admin->id,
            'received_on' => now(),
            'litres' => $litres,
            'amount' => $amount,
        ]);
    }

    public function test_the_month_label_follows_the_date_rather_than_being_typed(): void
    {
        // Two fields that can disagree are two fields that will.
        $quota = $this->quota(2000);

        $this->assertNotEmpty($quota->month_label);
    }

    public function test_carryover_adds_to_what_may_be_drawn(): void
    {
        $quota = $this->quota(2000, 300);

        $this->assertEquals(2300.0, $quota->available_litres);
    }

    public function test_deliveries_come_off_the_quota(): void
    {
        $quota = $this->quota(2000);
        $this->delivery(500);
        $this->delivery(300);

        $this->assertEquals(800.0, $quota->fresh()->delivered_litres);
        $this->assertEquals(1200.0, $quota->fresh()->remaining_litres);
    }

    public function test_a_delivery_in_another_month_is_not_counted(): void
    {
        $quota = $this->quota(2000);

        $delivery = $this->delivery(500);
        $delivery->forceFill(['received_on' => now()->subMonths(2)])->save();

        $this->assertEquals(0.0, $quota->fresh()->delivered_litres);
    }

    public function test_a_subsidised_delivery_carries_no_invoice(): void
    {
        $free = $this->delivery(500);
        $paid = $this->delivery(500, 3_000_000);

        $this->assertFalse($free->was_paid_for);
        $this->assertTrue($paid->was_paid_for);
        $this->assertSame('سهمیه‌ای', $free->amount_formatted);
    }

    public function test_drawing_more_than_the_quota_reads_as_overdrawn(): void
    {
        $quota = $this->quota(1000);
        $this->delivery(1200);

        $this->assertTrue($quota->fresh()->is_overdrawn);
        $this->assertEquals(-200.0, $quota->fresh()->remaining_litres);
        // The bar stops at full rather than claiming 120% of a journey.
        $this->assertEquals(100.0, $quota->fresh()->used_percent);
    }

    public function test_nearly_out_is_raised_while_there_is_time_to_order(): void
    {
        $this->quota(1000);
        $this->delivery(850);

        $issue = (new IssueScanner)->scan()->firstWhere('key', 'diesel-running-out');

        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue->severity);
    }

    public function test_running_out_entirely_is_critical(): void
    {
        $this->quota(1000);
        $this->delivery(1100);

        $issue = (new IssueScanner)->scan()->firstWhere('key', 'diesel-running-out');

        $this->assertNotNull($issue);
        $this->assertSame('critical', $issue->severity);
    }

    public function test_a_comfortable_month_says_nothing(): void
    {
        // Crying wolf at half a tank teaches the admin to ignore the page.
        $this->quota(1000);
        $this->delivery(400);

        $this->assertNull((new IssueScanner)->scan()->firstWhere('key', 'diesel-running-out'));
    }

    public function test_no_quota_registered_raises_nothing(): void
    {
        $this->delivery(400);

        $this->assertNull((new IssueScanner)->scan()->firstWhere('key', 'diesel-running-out'));
    }

    public function test_the_quota_follows_the_flour_allocation(): void
    {
        // Five litres a sack is the rate the depot works to, so a hundred
        // sacks is five hundred litres and nobody types either figure.
        FlourAllocation::create([
            'month_start' => Jalali::currentMonthRange()[0],
            'month_label' => 'تست',
            'total_bags' => 100,
        ]);

        $quota = DieselAllocation::create([
            'month_start' => Jalali::currentMonthRange()[0],
        ]);

        $this->assertEquals(500.0, (float) $quota->total_litres);
    }

    public function test_the_rate_is_a_setting_rather_than_a_constant(): void
    {
        Bakery::first()->update(['diesel_litres_per_bag' => 7]);

        FlourAllocation::create([
            'month_start' => Jalali::currentMonthRange()[0],
            'month_label' => 'تست',
            'total_bags' => 10,
        ]);

        $quota = DieselAllocation::create([
            'month_start' => Jalali::currentMonthRange()[0],
        ]);

        $this->assertEquals(70.0, (float) $quota->total_litres);
    }

    public function test_a_typed_figure_wins_over_the_formula(): void
    {
        // The depot occasionally issues something other than the formula,
        // and the docket is the truth.
        FlourAllocation::create([
            'month_start' => Jalali::currentMonthRange()[0],
            'month_label' => 'تست',
            'total_bags' => 100,
        ]);

        $quota = DieselAllocation::create([
            'month_start' => Jalali::currentMonthRange()[0],
            'total_litres' => 420,
        ]);

        $this->assertEquals(420.0, (float) $quota->total_litres);
    }
}
