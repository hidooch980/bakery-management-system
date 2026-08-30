<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageBakery;
use App\Filament\Resources\ExpenseResource\Pages\EditExpense;
use App\Filament\Resources\FlourAllocationResource\Pages\ListFlourAllocations;
use App\Filament\Widgets\FlourQuotaOverview;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\FlourAllocation;
use App\Models\Holiday;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Support\Jalali;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionFormulaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'water_ratio' => 0.6,
            'salt_ratio' => 0.015,
            'dough_loss_ratio' => 0,
            // Proving is measured in ProofGainTest; here the
            // formula's own arithmetic is what is under test.
            'proof_gain_ratio' => 0,
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
        ]);

        AppCalendar::forgetCache();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    // --------------------------------------------------------- the formula

    public function test_formula_derives_dough_from_bags(): void
    {
        $formula = DoughFormula::fromBakery();

        // 2 bags = 80kg flour, +48kg water, +1.2kg salt, +0.4kg yeast.
        $this->assertSame(80.0, $formula->flourKg(2));
        $this->assertSame(48.0, $formula->waterKg(2));
        $this->assertSame(1.2, $formula->saltKg(2));
        $this->assertSame(0.4, $formula->yeastKg(2));
        $this->assertSame(129.6, $formula->doughKg(2));
    }

    public function test_formula_applies_the_handling_loss(): void
    {
        Bakery::first()->update(['dough_loss_ratio' => 0.1]);

        // 129.6kg less 10% handling loss.
        $this->assertSame(116.64, DoughFormula::fromBakery()->doughKg(2));
    }

    public function test_the_nanino_rate_is_a_setting_the_shop_can_change(): void
    {
        // Sixty-four is what the samane works to today, but it is a rule the
        // authority sets - the shop must be able to follow a change without
        // waiting for a new build.
        Bakery::first()->update(['nanino_per_bag' => 70]);

        $this->assertSame(140, DoughFormula::fromBakery(Bakery::first())->naninoChaneCount(2));
    }

    public function test_formula_counts_chane_by_weight(): void
    {
        $formula = DoughFormula::fromBakery();

        // 129.2kg of dough at 0.85kg per chane rounds down to whole chane.
        $this->assertSame(152, $formula->normalChaneCount(2));

        // Nanino is not weighed out the same way: the sāmāne counts 64 to
        // the sack whatever the bench yields, so two sacks are 128.
        $this->assertSame(128, $formula->naninoChaneCount(2));
    }

    public function test_formula_returns_null_when_no_chane_weight_is_set(): void
    {
        Bakery::first()->update([
            'normal_chane_weight_kg' => null,
            'nanino_chane_weight_kg' => null,
        ]);

        $formula = DoughFormula::fromBakery();

        $this->assertNull($formula->normalChaneCount(2));
        $this->assertNull($formula->weightForNormalChane(100));
    }

    // ------------------------------------------------- chane weight is derived

    public function test_chane_weight_comes_from_the_formula_not_the_client(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 2])
            ->assertCreated();

        $entry = DoughEntry::first();

        // The client sends a count only; any weight it tried to send is ignored.
        $this->actingAs($chane, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $entry->id,
                'chane_count' => 100,
                'normal_weight_kg' => 99999,
                'spray_flour_kg' => 1,
            ])
            ->assertCreated()
            // 100 chane at 0.85kg each.
            ->assertJsonPath('data.entry.normal_weight_kg', 85)
            ->assertJsonPath('data.derived_from_formula', true);
    }

    public function test_chane_is_rejected_when_the_formula_is_incomplete(): void
    {
        Bakery::first()->update(['normal_chane_weight_kg' => null]);

        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        $this->actingAs($dough, 'sanctum')->postJson('/api/v1/dough-entries', ['bag_count' => 1]);

        $this->actingAs($chane, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => DoughEntry::first()->id,
                'chane_count' => 10,
                'spray_flour_kg' => 0,
            ])
            ->assertStatus(422);
    }

    // --------------------------------------- nanino is display-only

    public function test_both_normal_and_nanino_weight_are_deducted_from_dough_stock(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        $this->actingAs($dough, 'sanctum')
            // 3 bags to cover both the normal and nanino weight shaped below.
            ->postJson('/api/v1/dough-entries', ['bag_count' => 3])
            ->assertCreated();

        $this->actingAs($chane, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => DoughEntry::first()->id,
                'chane_count' => 100,
                'nanino_chane_count' => 50,
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();

        // Nanino is a display figure for sales and reports, but the dough
        // shaped into it was really used — so both weights are recorded,
        // 100 normal at 0.85kg and 50 nanino at 1.0kg.
        $entry = ChaneEntry::first();

        $this->assertSame('85.00', $entry->normal_weight_kg);
        $this->assertSame('50.00', $entry->nanino_weight_kg);
    }

    public function test_a_batch_shaped_almost_entirely_into_nanino_still_consumes_its_dough(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 5])
            ->assertCreated();

        // 64 nanino loaves at the configured 1.0kg is 64kg of dough — that
        // dough must leave stock even though nanino itself is never sold.
        $this->actingAs($chane, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => DoughEntry::first()->id,
                'chane_count' => 1,
                'nanino_chane_count' => 64,
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();

        // Almost all of it shaped as nanino: the weight is still recorded,
        // so the batch cannot read as though nothing was made.
        $entry = ChaneEntry::first();

        $this->assertSame('0.85', $entry->normal_weight_kg);
        $this->assertSame('64.00', $entry->nanino_weight_kg);
    }

    public function test_sales_and_report_figures_still_ignore_nanino(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 3])
            ->assertCreated();

        $this->actingAs($chane, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => DoughEntry::first()->id,
                'chane_count' => 100,
                'nanino_chane_count' => 50,
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();

        // Only the dough-stock deduction changed. The figure that drives
        // sales, weight_kg and every report is still normal-only.
        $entry = ChaneEntry::first();
        $this->assertSame(85.0, (float) $entry->weight_kg);
    }

    public function test_reported_weight_excludes_nanino(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        $this->actingAs($dough, 'sanctum')->postJson('/api/v1/dough-entries', ['bag_count' => 3]);

        $this->actingAs($chane, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => DoughEntry::first()->id,
                'chane_count' => 100,
                'nanino_chane_count' => 50,
                'spray_flour_kg' => 0,
            ])
            ->assertCreated()
            // The authoritative weight is the normal chane alone...
            ->assertJsonPath('data.total_weight_kg', 85)
            // ...with nanino reported separately for comparison.
            ->assertJsonPath('data.nanino_weight_kg', 50);
    }

    public function test_efficiency_report_ignores_nanino(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');
        $admin = $this->userWithRole('admin');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        $this->actingAs($dough, 'sanctum')->postJson('/api/v1/dough-entries', ['bag_count' => 2]);
        // Kept low enough (with the 100 normal chane) to fit the 129.2kg of
        // dough 2 bags actually yield — bag_count stays at 2 here because
        // weight_per_bag_kg below is asserted against it.
        $this->actingAs($chane, 'sanctum')->postJson('/api/v1/chane-entries', [
            'dough_entry_id' => DoughEntry::first()->id,
            'chane_count' => 100,
            'nanino_chane_count' => 20,
            'spray_flour_kg' => 0,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/efficiency')
            ->assertOk()
            // 85kg over 2 bags, with nanino excluded.
            ->assertJsonPath('data.total_weight_kg', 85)
            ->assertJsonPath('data.weight_per_bag_kg', 42.5);
    }

    // ------------------------------------------------------------ warehouse

    public function test_recording_dough_moves_stock_per_the_formula(): void
    {
        $dough = $this->userWithRole('dough_maker');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 2])
            ->assertCreated()
            ->assertJsonPath('data.expected.dough_kg', 129.6);

        // 500 - 80 flour, 50 - 1.2 salt, and the dough it produced.
        $this->assertSame(420.0, InventoryItem::ofKey(InventoryItem::FLOUR)->balance);
        $this->assertSame(48.8, InventoryItem::ofKey(InventoryItem::SALT)->balance);
    }

    public function test_inventory_balance_is_derived_from_movements(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);

        $flour->move('in', 100, 'purchase');
        $flour->move('out', 30, 'production');
        $flour->move('in', 5.5, 'purchase');

        $this->assertSame(75.5, $flour->fresh()->balance);
    }

    public function test_low_stock_is_flagged_against_the_threshold(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->update(['low_threshold' => 50]);
        $flour->move('in', 40, 'purchase');

        $this->assertTrue($flour->fresh()->is_low);

        $flour->move('in', 20, 'purchase');
        $this->assertFalse($flour->fresh()->is_low);
    }

    // ------------------------------------------------- flour quota periods

    public function test_quota_splits_into_the_three_delivery_periods(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_kg' => 3000,
        ]);

        $allocation->syncPeriods();
        $periods = $allocation->periods()->get();

        $this->assertCount(3, $periods);
        // The parts must always sum to the whole.
        $this->assertSame(3000.0, round((float) $periods->sum('allocated_kg'), 3));

        $this->assertSame('1405/05/05', Jalali::date($periods[0]->starts_on));
        $this->assertSame('1405/05/14', Jalali::date($periods[0]->ends_on));
        $this->assertSame('1405/05/15', Jalali::date($periods[1]->starts_on));
        $this->assertSame('1405/05/24', Jalali::date($periods[1]->ends_on));
        $this->assertSame('1405/05/25', Jalali::date($periods[2]->starts_on));
        // The third period wraps into the following Jalali month.
        $this->assertSame('1405/06/04', Jalali::date($periods[2]->ends_on));
    }

    public function test_a_period_with_no_registered_holiday_is_all_working_days(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_kg' => 3000,
        ]);
        $allocation->syncPeriods();

        $first = $allocation->periods()->first();

        // 5th to the 14th, inclusive, is 10 calendar days.
        $this->assertSame(10, $first->total_days);
        $this->assertSame(0, $first->holiday_days);
        $this->assertSame(10, $first->working_days);
    }

    public function test_registered_holidays_are_subtracted_from_working_days(): void
    {
        // There is no standing "every Friday" closure — only dates someone
        // actually registered, such as a monthly-recurring 15th and 25th.
        Holiday::create([
            'date' => Jalali::parse('1405/05/15'),
            'title' => 'تعطیل ماهانه',
            'type' => 'shop',
        ]);
        Holiday::create([
            'date' => Jalali::parse('1405/05/20'),
            'title' => 'تعطیل رسمی',
            'type' => 'official',
        ]);

        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_kg' => 3000,
        ]);
        $allocation->syncPeriods();

        // Second period is the 15th to the 24th: 10 days, 2 registered.
        $second = $allocation->periods()->get()[1];

        $this->assertSame(10, $second->total_days);
        $this->assertSame(2, $second->holiday_days);
        $this->assertSame(8, $second->working_days);
    }

    public function test_a_holiday_outside_the_period_does_not_count_against_it(): void
    {
        Holiday::create([
            'date' => Jalali::parse('1405/05/01'),
            'title' => 'تعطیل خارج از دوره',
            'type' => 'shop',
        ]);

        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_kg' => 3000,
        ]);
        $allocation->syncPeriods();

        $first = $allocation->periods()->first();

        $this->assertSame(0, $first->holiday_days);
        $this->assertSame(10, $first->working_days);
    }

    public function test_the_daily_pace_is_based_on_working_days_not_calendar_days(): void
    {
        Holiday::create([
            'date' => Jalali::parse('1405/05/10'),
            'title' => 'تعطیل',
            'type' => 'shop',
        ]);

        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_kg' => 900,
        ]);
        $allocation->syncPeriods();

        $first = $allocation->periods()->first();

        // 300kg over 9 working days (one of the 10 calendar days is closed).
        $this->assertSame(9, $first->working_days);
        $this->assertEqualsWithDelta(300.0 / 9, $first->daily_pace_kg, 0.01);
    }

    public function test_a_registered_month_with_no_active_period_yet_is_reported_correctly(): void
    {
        // Days 1–4 of a Jalali month fall outside all three delivery
        // periods (5–14, 15–24, 25–next 4) unless the previous month's
        // allocation was also entered — a fresh install's first month has
        // no such predecessor, so this is the expected state on day 4.
        Carbon::setTestNow(Jalali::parse('1405/05/04'));

        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_kg' => 3000,
        ]);
        $allocation->syncPeriods();

        // forDate correctly finds nothing — today has no active period.
        $this->assertNull(FlourAllocation::forDate(now()));

        // But the month's own allocation must still be found, so the
        // dashboard can say "registered, starts on the 5th" instead of
        // "not registered at all".
        $found = FlourAllocation::forJalaliMonthOf(now());
        $this->assertNotNull($found);
        $this->assertSame($allocation->id, $found->id);

        Carbon::setTestNow();
    }

    public function test_the_dashboard_explains_the_gap_rather_than_calling_it_undefined(): void
    {
        Carbon::setTestNow(Jalali::parse('1405/05/04'));

        FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_kg' => 3000,
        ])->syncPeriods();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(
            Filament::getPanel('admin')
        );

        $html = Livewire::actingAs($admin)->test(
            FlourQuotaOverview::class
        )->html();

        $this->assertStringContainsString('سهمیه این ماه ثبت شده', $html);
        $this->assertStringNotContainsString('در بخش انبار ثبت نشده است', $html);

        Carbon::setTestNow();
    }

    public function test_no_allocation_at_all_is_still_reported_as_undefined(): void
    {
        Carbon::setTestNow(Jalali::parse('1405/05/04'));

        $this->assertNull(FlourAllocation::forDate(now()));
        $this->assertNull(FlourAllocation::forJalaliMonthOf(now()));

        Carbon::setTestNow();
    }

    public function test_the_panel_shows_working_days_for_each_period(): void
    {
        Holiday::create([
            'date' => Jalali::parse('1405/05/15'),
            'title' => 'تعطیل ماهانه',
            'type' => 'shop',
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_kg' => 3000,
        ])->syncPeriods();

        Filament::setCurrentPanel(
            Filament::getPanel('admin')
        );

        $html = Livewire::actingAs($admin)->test(
            ListFlourAllocations::class
        )->html();

        $this->assertStringContainsString('روز کاری', $html);
    }

    public function test_quota_remainder_lands_on_the_last_period(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_kg' => 1000,
        ]);

        $allocation->syncPeriods();

        $this->assertSame(1000.0, round((float) $allocation->periods()->sum('allocated_kg'), 3));
    }

    public function test_period_reports_usage_against_its_allocation(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_kg' => 300,
        ]);
        $allocation->syncPeriods();

        $period = $allocation->periods()->first();

        // Consume flour inside the first period's window.
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 100, 'purchase');
        $movement = $flour->move('out', 40, 'production');
        DB::table('inventory_movements')
            ->where('id', $movement->id)
            ->update(['created_at' => $period->starts_on->copy()->addDay()]);

        $period->refresh();

        $this->assertSame(40.0, $period->used_kg);
        $this->assertSame(60.0, $period->remaining_kg);
        $this->assertFalse($period->is_over);
    }

    // ------------------------------------------------ quota entered in bags

    public function test_quota_weight_is_derived_from_the_bag_count(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);

        // 75 sacks at the configured 40kg bag weight.
        $this->assertSame('3000.000', $allocation->fresh()->total_kg);
    }

    public function test_quota_weight_follows_a_change_to_the_bag_weight(): void
    {
        Bakery::first()->update(['flour_bag_weight_kg' => 50]);

        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);

        $this->assertSame('3750.000', $allocation->fresh()->total_kg);
    }

    public function test_period_allocation_is_reported_in_bags_too(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);
        $allocation->syncPeriods();

        $period = $allocation->periods()->first();

        // 1000kg of the 3000kg quota, at 40kg per sack.
        $this->assertSame(25.0, $allocation->bagsForPeriod($period));
    }

    public function test_admin_creates_a_quota_by_bag_count(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson('/api/v1/flour-allocations', [
                'month_start' => '1405/05/01',
                'total_bags' => 75,
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_bags', 75)
            ->assertJsonPath('data.total_kg', 3000)
            ->assertJsonPath('data.bag_weight_kg', 40);
    }

    // ------------------------- quota reconciled against the card reader

    public function test_the_period_states_its_quota_in_nanino_loaves(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);
        $allocation->syncPeriods();

        $period = $allocation->periods()->first();

        // A third of 75 bags is 25, and one bag yields 64 nanino loaves.
        $this->assertSame(25 * 64, $period->allocated_bread_count);
    }

    public function test_flour_sold_on_is_not_counted_as_consumed(): void
    {
        // A bakery only eats flour two ways: what the dough maker kneads
        // and what is thrown on the bench. Flour sold to someone else was
        // never baked, so it must not spend the period's quota.
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);
        $allocation->syncPeriods();

        $period = $allocation->periods()->first();
        $inside = $period->starts_on->copy()->addDay();

        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 10000, 'purchase');

        foreach ([['production', 400.0], ['spray', 5.0], ['flour_sale', 120.0]] as [$reason, $qty]) {
            $movement = $flour->move('out', $qty, $reason);
            DB::table('inventory_movements')
                ->where('id', $movement->id)->update(['created_at' => $inside]);
        }

        // The kneaded batch and the bench flour, and nothing else.
        $this->assertSame(405.0, $period->refresh()->used_kg);
    }

    // -------------------------------------------- carry-over (سنوات)

    public function test_carryover_weight_is_derived_from_its_bag_count(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
            'carryover_bags' => 20,
        ]);

        $allocation->refresh();

        $this->assertSame('3000.000', $allocation->total_kg);
        $this->assertSame('800.000', $allocation->carryover_kg);
    }

    public function test_available_total_adds_carryover_to_the_month_quota(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
            'carryover_bags' => 20,
        ]);

        $this->assertSame(95.0, $allocation->available_bags);
        $this->assertSame(3800.0, $allocation->available_kg);
    }

    public function test_carryover_is_not_split_across_the_periods(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
            'carryover_bags' => 30,
        ]);
        $allocation->syncPeriods();

        // The periods ration the month's own quota only; carry-over is a
        // reserve drawn on whenever it is needed.
        $this->assertSame(3000.0, round((float) $allocation->periods()->sum('allocated_kg'), 3));
    }

    public function test_admin_records_carryover_through_the_api(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson('/api/v1/flour-allocations', [
                'month_start' => '1405/05/01',
                'total_bags' => 75,
                'carryover_bags' => 20,
                'carryover_note' => 'مانده سنوات ۱۴۰۴',
            ])
            ->assertCreated()
            ->assertJsonPath('data.carryover_bags', 20)
            ->assertJsonPath('data.carryover_kg', 800)
            ->assertJsonPath('data.available_bags', 95)
            ->assertJsonPath('data.available_kg', 3800);
    }

    public function test_carryover_defaults_to_zero(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);

        $this->assertSame(0.0, (float) $allocation->fresh()->carryover_bags);
        $this->assertSame(75.0, $allocation->available_bags);
    }

    // ------------------------------------------------- jalali date input

    public function test_panel_date_form_stores_a_jalali_date_as_gregorian(): void
    {
        $this->actingAs($this->userWithRole('admin'));
        Filament::setCurrentPanel(
            Filament::getPanel('admin')
        );

        $expense = Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 1000,
            'spent_on' => '2026-07-25',
        ]);

        // Opening the form shows the stored Gregorian date in Jalali, and
        // saving converts it straight back — no drift either way.
        Livewire::test(
            EditExpense::class,
            ['record' => $expense->getRouteKey()]
        )
            ->assertFormSet(['spent_on' => '1405/05/03'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-07-25', $expense->fresh()->spent_on->toDateString());
    }

    // -------------------------------------------------------- multi calendar

    public function test_calendar_setting_switches_the_displayed_date(): void
    {
        $date = Carbon::parse('2026-07-25');

        $this->assertSame('1405/05/03', AppCalendar::date($date, AppCalendar::JALALI));
        $this->assertSame('1448/02/09', AppCalendar::date($date, AppCalendar::HIJRI));
        $this->assertSame('2026/07/25', AppCalendar::date($date, AppCalendar::GREGORIAN));
    }

    public function test_calendar_follows_the_bakery_setting(): void
    {
        Bakery::first()->update(['calendar' => AppCalendar::HIJRI]);
        AppCalendar::forgetCache();

        $this->assertSame('1448/02/09', AppCalendar::date(Carbon::parse('2026-07-25')));
    }

    // ------------------------------------------------- nanino equivalence

    public function test_normal_count_converts_to_a_nanino_equivalent(): void
    {
        // 100 normal chane at 0.85kg is 85kg of dough — 85 nanino loaves at
        // 1.0kg each.
        $formula = DoughFormula::fromBakery();

        $this->assertSame(85, $formula->naninoEquivalentForNormalCount(100));
    }

    public function test_the_equivalent_floors_a_partial_loaf(): void
    {
        Bakery::first()->update(['nanino_chane_weight_kg' => 0.9]);

        // 100 × 0.85 = 85kg; 85 ÷ 0.9 = 94.44 → 94, not 95.
        $formula = DoughFormula::fromBakery();
        $this->assertSame(94, $formula->naninoEquivalentForNormalCount(100));
    }

    public function test_the_equivalent_is_null_without_both_weights(): void
    {
        Bakery::first()->update(['nanino_chane_weight_kg' => null]);

        $formula = DoughFormula::fromBakery();
        $this->assertNull($formula->naninoEquivalentForNormalCount(100));
    }

    public function test_the_chane_board_announces_the_equivalent(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('seller');

        $dough = DoughEntry::create(['user_id' => $user->id, 'bag_count' => 10]);
        ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $user->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/chane-board')
            ->assertOk()
            ->assertJsonPath('data.today.normal_as_nanino_equivalent', 85)
            ->assertJsonPath(
                'data.today.normal_as_nanino_announcement',
                'چانه‌های عادی امروز (100 عدد) معادل 85 نان نانینو است.'
            );
    }

    public function test_the_chane_board_restates_todays_dough_as_nanino(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('seller');

        // 2 bags yield 129.2kg of dough; at 1.0kg a nanino loaf that is 129.
        DoughEntry::create(['user_id' => $user->id, 'bag_count' => 2]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/chane-board')
            ->assertOk()
            ->assertJsonPath('data.dough_today.bags', 2)
            ->assertJsonPath('data.dough_today.dough_kg', 129.6)
            // Two sacks at the sāmāne's fixed 64 to the sack.
            ->assertJsonPath('data.dough_today.as_nanino_count', 128)
            ->assertJsonPath(
                'data.dough_today.as_nanino_announcement',
                'خمیر امروز (2 کیسه) معادل 128 نان نانینو است.'
            );
    }

    public function test_the_dough_restatement_counts_dough_not_yet_shaped(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('seller');

        // Kneaded but never shaped into chane: still counted, because this
        // figure is about the day's raw material, not its output.
        DoughEntry::create(['user_id' => $user->id, 'bag_count' => 2]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/chane-board')
            ->assertOk()
            ->assertJsonPath('data.today.normal_count', 0)
            ->assertJsonPath('data.dough_today.as_nanino_count', 128);
    }

    public function test_the_dough_restatement_is_null_without_a_nanino_weight(): void
    {
        Bakery::first()->update(['nanino_chane_weight_kg' => null]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('seller');

        DoughEntry::create(['user_id' => $user->id, 'bag_count' => 2]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/chane-board')
            ->assertOk()
            ->assertJsonPath('data.dough_today.as_nanino_count', null)
            ->assertJsonPath('data.dough_today.as_nanino_announcement', null);
    }

    public function test_the_admin_dashboard_announces_the_equivalent(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $dough = DoughEntry::create(['user_id' => $admin->id, 'bag_count' => 10]);
        ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/dashboard')
            ->assertOk()
            ->assertJsonPath('data.today.normal_as_nanino_equivalent', 85);
    }

    // -------------------------------------------------- real nanino count

    public function test_nanino_count_is_derived_from_its_recorded_weight(): void
    {
        // 40kg of nanino output at 1.0kg each is 40 loaves.
        $this->assertSame(40, DoughFormula::fromBakery()->naninoCountForWeight(40));
    }

    public function test_nanino_count_is_zero_without_a_configured_weight(): void
    {
        Bakery::first()->update(['nanino_chane_weight_kg' => null]);

        $this->assertSame(0, DoughFormula::fromBakery()->naninoCountForWeight(40));
    }

    public function test_the_production_report_includes_the_period_nanino_count(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $dough = DoughEntry::create(['user_id' => $admin->id, 'bag_count' => 10]);
        ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 40,
            'spray_flour_kg' => 0,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/production')
            ->assertOk()
            ->assertJsonPath('data.total_nanino_count', 40);
    }

    public function test_the_production_report_breaks_dough_down_by_day(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        DoughEntry::create(['user_id' => $admin->id, 'bag_count' => 3]);
        DoughEntry::create(['user_id' => $admin->id, 'bag_count' => 2]);

        $today = now()->toDateString();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/production')
            ->assertOk();

        $daily = collect($response->json('data.daily'));
        $todayRow = $daily->firstWhere('date', $today);

        $this->assertNotNull($todayRow, 'today should appear in the daily breakdown');
        $this->assertSame(2, $todayRow['dough_entries']);
        $this->assertSame(5, $todayRow['dough_bags']);
    }

    public function test_the_daily_breakdown_counts_the_bread_baked_including_nanino(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $dough = DoughEntry::create(['user_id' => $admin->id, 'bag_count' => 3]);
        ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            // Nanino weight is 1.0kg a loaf, so 40kg is 40 loaves.
            'nanino_weight_kg' => 40,
            'spray_flour_kg' => 0,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/production')
            ->assertOk();

        $todayRow = collect($response->json('data.daily'))
            ->firstWhere('date', now()->toDateString());

        $this->assertSame(100, $todayRow['normal_chane_count']);
        $this->assertSame(40, $todayRow['nanino_chane_count']);
        $this->assertSame(140, $todayRow['total_bread_count']);
    }

    public function test_the_daily_bread_count_is_normal_only_without_a_nanino_weight(): void
    {
        Bakery::first()->update(['nanino_chane_weight_kg' => null]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $dough = DoughEntry::create(['user_id' => $admin->id, 'bag_count' => 3]);
        ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 40,
            'spray_flour_kg' => 0,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/production')
            ->assertOk();

        $todayRow = collect($response->json('data.daily'))
            ->firstWhere('date', now()->toDateString());

        // Without a configured weight the nanino weight cannot be turned
        // into a loaf count, so it is left out rather than guessed at.
        $this->assertSame(0, $todayRow['nanino_chane_count']);
        $this->assertSame(100, $todayRow['total_bread_count']);
    }

    public function test_a_day_with_no_dough_still_appears_with_zero_counts(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/reports/production?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonCount(3, 'data.daily');
    }

    // --------------------------------------------------- salt and dough bags

    /**
     * Salt arrives in sacks of no fixed size and dough is never bagged at
     * all, so counting either in bags invented a number nobody weighs.
     * Both are kept in kilograms only.
     */
    public function test_salt_and_dough_are_kept_in_kilograms_only(): void
    {
        $this->assertNull(InventoryItem::ofKey(InventoryItem::SALT)->bag_weight_kg);
    }

    /** Until the shop says what a sack of it weighs, nothing is in sacks. */
    public function test_salt_balance_is_not_reported_in_sacks(): void
    {
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 75, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        $salt = InventoryItem::ofKey(InventoryItem::SALT)->fresh();

        $this->assertSame(75.0, $salt->balance);
        $this->assertNull($salt->balance_bags);
    }

    public function test_yeast_balance_is_not_reported_in_bags(): void
    {
        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 25, 'purchase');

        $yeast = InventoryItem::ofKey(InventoryItem::YEAST_DRY)->fresh();

        $this->assertSame(25.0, $yeast->balance);
        $this->assertNull($yeast->balance_bags);
    }

    /**
     * A bag weight set on salt is honoured.
     *
     * This test used to assert the opposite, on the reasoning that salt and
     * yeast are weighed goods «by nature, not by setting». Half of that was
     * right: they are weighed into the dough. They arrive in sacks like
     * everything else, and on 2026-08-17 the owner said what those sacks
     * weigh — «هر کیسه نمک ۲۵، هر کیسه خمیر ۱۰». A store he reads in sacks
     * was reporting his yeast as 8.52 kilograms rather than as under one
     * bag left.
     *
     * So whether a good has a package is a fact about the good, and the
     * shop is the one who knows it. The two tests above still hold: with
     * nothing set, nothing is said in sacks.
     */
    public function test_a_bag_weight_set_on_salt_is_used(): void
    {
        $salt = InventoryItem::ofKey(InventoryItem::SALT);
        $salt->update(['bag_weight_kg' => 25]);
        $salt->move('in', 75, 'purchase');

        $this->assertSame(3.0, $salt->fresh()->balance_bags);
    }

    /** With no sack weight recorded, a sack count has nothing to convert at. */
    public function test_recording_salt_in_bags_is_refused(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/inventory/movements', [
                'item' => 'salt',
                'direction' => 'in',
                'bags' => 3,
                'reason' => 'purchase',
            ])
            ->assertStatus(422);
    }

    /**
     * Shaping spends the dough it was given, so the formula preview says
     * what each chane costs and what the batch is used for — the figures
     * the warehouse is actually deducted by.
     */
    public function test_the_formula_preview_shows_what_the_dough_is_spent_on(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = preg_replace('/\s+/u', ' ', strip_tags(
            Livewire::test(ManageBakery::class)->html(),
            '<br>'
        ));

        $this->assertStringContainsString('مصرف خمیر', $html);
        // One bag of 40kg makes 64.6kg, which is 76 chane of 0.85kg.
        $this->assertStringContainsString('هر چانه 0.850 کیلوگرم', $html);
        $this->assertStringContainsString('76 چانه = 64.60 کیلوگرم', $html);
    }

    public function test_flour_still_reads_its_bag_size_from_the_formula(): void
    {
        // Flour predates the per-item column and must keep using the
        // bakery-wide setting, not a null column value.
        $this->assertNull(InventoryItem::ofKey(InventoryItem::FLOUR)->bag_weight_kg);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 80, 'purchase');

        $this->assertEquals(2.0, InventoryItem::ofKey(InventoryItem::FLOUR)->fresh()->balance_bags);
    }

    public function test_flour_given_back_by_a_deletion_is_not_counted_as_used(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);
        $allocation->syncPeriods();

        $period = $allocation->periods()->first();
        $inside = $period->starts_on->copy()->addDay();

        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 1000, 'purchase');

        $used = $flour->move('out', 100, 'production');
        $back = $flour->move('in', 40, 'production_reversal');

        foreach ([$used, $back] as $movement) {
            DB::table('inventory_movements')
                ->where('id', $movement->id)->update(['created_at' => $inside]);
        }

        // 100 went out but 40 came back when an entry was deleted, so the
        // period consumed 60 — counting the full 100 would push it towards
        // its quota for work that no longer exists.
        $this->assertSame(60.0, $period->refresh()->used_kg);
    }

    public function test_a_purchase_does_not_reduce_the_periods_usage(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);
        $allocation->syncPeriods();

        $period = $allocation->periods()->first();
        $inside = $period->starts_on->copy()->addDay();

        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 1000, 'purchase');

        $used = $flour->move('out', 100, 'production');
        $bought = $flour->move('in', 500, 'purchase');

        foreach ([$used, $bought] as $movement) {
            DB::table('inventory_movements')
                ->where('id', $movement->id)->update(['created_at' => $inside]);
        }

        // Buying more flour is not a refund of what was already consumed.
        $this->assertSame(100.0, $period->refresh()->used_kg);
    }
}
