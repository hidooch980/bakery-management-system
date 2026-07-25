<?php

namespace Tests\Feature;

use App\Models\Bakery;
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
}
