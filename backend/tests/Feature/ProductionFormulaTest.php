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

    public function test_only_normal_chane_is_deducted_from_dough_stock(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 2])
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

        // 100 normal chane at 0.85kg. The 50 nanino chane are a display
        // figure and must not touch stock.
        $expected = round($doughBefore - 85.0, 3);

        $this->assertSame($expected, InventoryItem::ofKey(InventoryItem::DOUGH)->balance);
    }

    public function test_reported_weight_excludes_nanino(): void
    {
        $dough = $this->userWithRole('dough_maker');
        $chane = $this->userWithRole('chane_gir');

        $this->actingAs($dough, 'sanctum')->postJson('/api/v1/dough-entries', ['bag_count' => 2]);

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

        $this->actingAs($dough, 'sanctum')->postJson('/api/v1/dough-entries', ['bag_count' => 2]);
        $this->actingAs($chane, 'sanctum')->postJson('/api/v1/chane-entries', [
            'dough_entry_id' => DoughEntry::first()->id,
            'chane_count' => 100,
            'nanino_chane_count' => 50,
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
