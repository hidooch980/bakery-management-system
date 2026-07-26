<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\FlourAllocation;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\AppCalendar;
use App\Support\DoughFormula;
use App\Support\Jalali;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // 2 bags = 80kg flour, +48kg water, +1.2kg salt.
        $this->assertSame(80.0, $formula->flourKg(2));
        $this->assertSame(48.0, $formula->waterKg(2));
        $this->assertSame(1.2, $formula->saltKg(2));
        $this->assertSame(129.2, $formula->doughKg(2));
    }

    public function test_formula_applies_the_handling_loss(): void
    {
        Bakery::first()->update(['dough_loss_ratio' => 0.1]);

        // 129.2kg less 10% handling loss.
        $this->assertSame(116.28, DoughFormula::fromBakery()->doughKg(2));
    }

    public function test_formula_counts_chane_by_weight(): void
    {
        $formula = DoughFormula::fromBakery();

        // 129.2kg of dough at 0.85kg per chane rounds down to whole chane.
        $this->assertSame(152, $formula->normalChaneCount(2));
        $this->assertSame(129, $formula->naninoChaneCount(2));
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

        $this->actingAs($dough, 'sanctum')
            // 3 bags to cover both the normal and nanino weight shaped below.
            ->postJson('/api/v1/dough-entries', ['bag_count' => 3])
            ->assertCreated();

        $doughBefore = InventoryItem::ofKey(InventoryItem::DOUGH)->balance;

        $this->actingAs($chane, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => DoughEntry::first()->id,
                'chane_count' => 100,
                'nanino_chane_count' => 50,
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();

        // Nanino is a display figure for sales and reports, but the dough
        // shaped into it is physically gone — 100 normal at 0.85kg plus 50
        // nanino at 1.0kg both come out of stock.
        $expected = round($doughBefore - 85.0 - 50.0, 3);

        $this->assertSame($expected, InventoryItem::ofKey(InventoryItem::DOUGH)->balance);
    }

    public function test_a_batch_shaped_almost_entirely_into_nanino_still_consumes_its_dough(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 5])
            ->assertCreated();

        $doughBefore = InventoryItem::ofKey(InventoryItem::DOUGH)->balance;

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

        $expected = round($doughBefore - 0.85 - 64.0, 3);

        $this->assertSame($expected, InventoryItem::ofKey(InventoryItem::DOUGH)->balance);
    }

    public function test_sales_and_report_figures_still_ignore_nanino(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

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

        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 2])
            ->assertCreated()
            ->assertJsonPath('data.expected.dough_kg', 129.2);

        // 500 - 80 flour, 50 - 1.2 salt, and the dough it produced.
        $this->assertSame(420.0, InventoryItem::ofKey(InventoryItem::FLOUR)->balance);
        $this->assertSame(48.8, InventoryItem::ofKey(InventoryItem::SALT)->balance);
        $this->assertSame(129.2, InventoryItem::ofKey(InventoryItem::DOUGH)->balance);
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
        \App\Models\Holiday::create([
            'date' => Jalali::parse('1405/05/15'),
            'title' => 'تعطیل ماهانه',
            'type' => 'shop',
        ]);
        \App\Models\Holiday::create([
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
        \App\Models\Holiday::create([
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
        \App\Models\Holiday::create([
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
        \Illuminate\Support\Carbon::setTestNow(Jalali::parse('1405/05/04'));

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

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_the_dashboard_explains_the_gap_rather_than_calling_it_undefined(): void
    {
        \Illuminate\Support\Carbon::setTestNow(Jalali::parse('1405/05/04'));

        FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_kg' => 3000,
        ])->syncPeriods();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        \Filament\Facades\Filament::setCurrentPanel(
            \Filament\Facades\Filament::getPanel('admin')
        );

        $html = \Livewire\Livewire::actingAs($admin)->test(
            \App\Filament\Widgets\FlourQuotaOverview::class
        )->html();

        $this->assertStringContainsString('سهمیه این ماه ثبت شده', $html);
        $this->assertStringNotContainsString('در بخش انبار ثبت نشده است', $html);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_no_allocation_at_all_is_still_reported_as_undefined(): void
    {
        \Illuminate\Support\Carbon::setTestNow(Jalali::parse('1405/05/04'));

        $this->assertNull(FlourAllocation::forDate(now()));
        $this->assertNull(FlourAllocation::forJalaliMonthOf(now()));

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_the_panel_shows_working_days_for_each_period(): void
    {
        \App\Models\Holiday::create([
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

        \Filament\Facades\Filament::setCurrentPanel(
            \Filament\Facades\Filament::getPanel('admin')
        );

        $html = \Livewire\Livewire::actingAs($admin)->test(
            \App\Filament\Resources\FlourAllocationResource\Pages\ListFlourAllocations::class
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
        \Illuminate\Support\Facades\DB::table('inventory_movements')
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

    // ------------------------------- quota reconciled against nanino output

    public function test_period_reports_the_flour_nanino_accounts_for(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);
        $allocation->syncPeriods();
        $period = $allocation->periods()->first();

        // One bag yields 129.2/2 = 64.6kg of dough, so at 1kg per nanino
        // chane a bag is worth about 64.6 nanino chane.
        $dough = DoughEntry::create(['user_id' => $this->userWithRole('admin')->id, 'bag_count' => 1]);
        $entry = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $dough->user_id,
            'chane_count' => 10,
            'normal_weight_kg' => 8.5,
            // 64.6kg of nanino chane is exactly one bag's worth of dough.
            'nanino_weight_kg' => 64.6,
            'spray_flour_kg' => 0,
        ]);

        \Illuminate\Support\Facades\DB::table('chane_entries')
            ->where('id', $entry->id)
            ->update(['created_at' => $period->starts_on->copy()->addDay()]);

        $period->refresh();

        // 64.6kg of nanino dough came from one 40kg bag.
        $this->assertEqualsWithDelta(40.0, $period->nanino_flour_kg, 0.5);
    }

    public function test_period_balances_its_allocation_against_nanino(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);
        $allocation->syncPeriods();
        $period = $allocation->periods()->first();

        // With no nanino output the whole allocation is still unaccounted for.
        $this->assertSame((float) $period->allocated_kg, $period->nanino_balance_kg);
    }

    public function test_nanino_reconciliation_is_zero_without_a_nanino_weight(): void
    {
        Bakery::first()->update(['nanino_chane_weight_kg' => null]);

        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);
        $allocation->syncPeriods();

        $period = $allocation->periods()->first();

        $this->assertSame(0, $period->nanino_chane_count);
        $this->assertSame(0.0, $period->nanino_flour_kg);
    }

    // ------------------------- production measured against flour consumed

    /**
     * Sets up a period, burns $usedBags of flour inside it, and records
     * $naninoLoaves of nanino output on the same day.
     */
    private function periodWithUsageAndOutput(float $usedBags, int $naninoLoaves): \App\Models\FlourAllocationPeriod
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
        $flour->move('in', 10000, 'purchase');
        $movement = $flour->move('out', $usedBags * 40, 'production');
        \Illuminate\Support\Facades\DB::table('inventory_movements')
            ->where('id', $movement->id)->update(['created_at' => $inside]);

        $user = $this->userWithRole('chane_gir');
        $dough = DoughEntry::create(['user_id' => $user->id, 'bag_count' => 1]);
        $entry = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $user->id,
            'chane_count' => 0,
            'normal_weight_kg' => 0,
            // Nanino weight is 1.0kg, so the weight is the loaf count.
            'nanino_weight_kg' => $naninoLoaves * 1.0,
            'spray_flour_kg' => 0,
        ]);
        \Illuminate\Support\Facades\DB::table('chane_entries')
            ->where('id', $entry->id)->update(['created_at' => $inside]);

        return $period->refresh();
    }

    public function test_the_period_expects_nanino_output_from_the_flour_it_consumed(): void
    {
        // One bag yields 64.6kg of dough, so 64 nanino loaves at 1.0kg.
        $period = $this->periodWithUsageAndOutput(usedBags: 1, naninoLoaves: 64);

        $this->assertSame(64, $period->expected_nanino_count);
        $this->assertSame(64, $period->nanino_chane_count);
        $this->assertSame(0, $period->nanino_production_gap);
        $this->assertSame('ok', $period->nanino_production_status);
    }

    public function test_producing_less_than_the_flour_accounts_for_is_an_error(): void
    {
        // A bag of flour went out, but only 10 loaves came back.
        $period = $this->periodWithUsageAndOutput(usedBags: 1, naninoLoaves: 10);

        $this->assertSame(64, $period->expected_nanino_count);
        $this->assertSame(-54, $period->nanino_production_gap);
        $this->assertSame('short', $period->nanino_production_status);
    }

    public function test_a_small_overshoot_is_not_treated_as_an_error(): void
    {
        // 10 loaves over is well inside one bag's 64, so it is tolerated.
        $period = $this->periodWithUsageAndOutput(usedBags: 1, naninoLoaves: 74);

        $this->assertSame(10, $period->nanino_production_gap);
        $this->assertSame('ok', $period->nanino_production_status);
    }

    public function test_producing_more_than_a_bag_over_is_an_error(): void
    {
        // 65 loaves over is more than one bag's worth.
        $period = $this->periodWithUsageAndOutput(usedBags: 1, naninoLoaves: 129);

        $this->assertSame(65, $period->nanino_production_gap);
        $this->assertGreaterThan(1, $period->nanino_production_gap_bags);
        $this->assertSame('over', $period->nanino_production_status);
    }

    public function test_the_comparison_is_unknown_when_no_flour_was_consumed(): void
    {
        $allocation = FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/01'),
            'month_label' => 'مرداد 1405',
            'total_bags' => 75,
        ]);
        $allocation->syncPeriods();

        $period = $allocation->periods()->first();

        $this->assertSame(0, $period->expected_nanino_count);
        $this->assertSame('unknown', $period->nanino_production_status);
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
        \Filament\Facades\Filament::setCurrentPanel(
            \Filament\Facades\Filament::getPanel('admin')
        );

        $expense = \App\Models\Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 1000,
            'spent_on' => '2026-07-25',
        ]);

        // Opening the form shows the stored Gregorian date in Jalali, and
        // saving converts it straight back — no drift either way.
        \Livewire\Livewire::test(
            \App\Filament\Resources\ExpenseResource\Pages\EditExpense::class,
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
        $date = \Illuminate\Support\Carbon::parse('2026-07-25');

        $this->assertSame('1405/05/03', AppCalendar::date($date, AppCalendar::JALALI));
        $this->assertSame('1448/02/09', AppCalendar::date($date, AppCalendar::HIJRI));
        $this->assertSame('2026/07/25', AppCalendar::date($date, AppCalendar::GREGORIAN));
    }

    public function test_calendar_follows_the_bakery_setting(): void
    {
        Bakery::first()->update(['calendar' => AppCalendar::HIJRI]);
        AppCalendar::forgetCache();

        $this->assertSame('1448/02/09', AppCalendar::date(\Illuminate\Support\Carbon::parse('2026-07-25')));
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
            ->assertJsonPath('data.dough_today.dough_kg', 129.2)
            ->assertJsonPath('data.dough_today.as_nanino_count', 129)
            ->assertJsonPath(
                'data.dough_today.as_nanino_announcement',
                'خمیر امروز (2 کیسه) معادل 129 نان نانینو است.'
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
            ->assertJsonPath('data.dough_today.as_nanino_count', 129);
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

    public function test_salt_and_dough_have_their_configured_bag_sizes(): void
    {
        $this->assertEquals(25.0, (float) InventoryItem::ofKey(InventoryItem::SALT)->bag_weight_kg);
        $this->assertEquals(10.0, (float) InventoryItem::ofKey(InventoryItem::DOUGH)->bag_weight_kg);
    }

    public function test_salt_balance_is_reported_in_sacks(): void
    {
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 75, 'purchase');

        // 75kg at 25kg a sack is 3 sacks.
        $this->assertEquals(3.0, InventoryItem::ofKey(InventoryItem::SALT)->fresh()->balance_bags);
    }

    public function test_dough_balance_is_reported_in_its_own_units(): void
    {
        InventoryItem::ofKey(InventoryItem::DOUGH)->move('in', 25, 'production');

        // 25kg at 10kg a unit is 2.5 units.
        $this->assertEquals(2.5, InventoryItem::ofKey(InventoryItem::DOUGH)->fresh()->balance_bags);
    }

    public function test_flour_still_reads_its_bag_size_from_the_formula(): void
    {
        // Flour predates the per-item column and must keep using the
        // bakery-wide setting, not a null column value.
        $this->assertNull(InventoryItem::ofKey(InventoryItem::FLOUR)->bag_weight_kg);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 80, 'purchase');

        $this->assertEquals(2.0, InventoryItem::ofKey(InventoryItem::FLOUR)->fresh()->balance_bags);
    }
}
