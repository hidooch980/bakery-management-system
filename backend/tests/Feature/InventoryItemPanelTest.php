<?php

namespace Tests\Feature;

use App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems;
use App\Models\Bakery;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "ثبت موجودی (کیسه‌ای)" action on /admin/inventory-items: the admin
 * types a bag count, not a raw kilogram figure, and the stock movement is
 * created with the computed weight.
 */
class InventoryItemPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['flour_bag_weight_kg' => 40]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_bag_count_is_what_the_warehouse_shows(): void
    {
        InventoryItem::ofKey('flour')->move('in', 120, 'purchase');

        $html = Livewire::test(
            ListInventoryItems::class
        )->html();

        // 120kg at the bakery's 40kg sack is 3 sacks.
        $this->assertStringContainsString('3.00 کیسه', $html);
    }

    public function test_flour_shows_no_weight_beside_the_sacks(): void
    {
        InventoryItem::ofKey('flour')->move('in', 120, 'purchase');

        $html = Livewire::test(
            ListInventoryItems::class
        )->html();

        // «کیلو در انبار معنی نداره، فقط کیسه بیاد». The weight next to the
        // sack count said the same thing twice, in the unit the shop does
        // not use for flour. This test used to assert the opposite — that
        // the count came *before* the weight — which was the right rule
        // while both were shown.
        $this->assertStringContainsString('3.00 کیسه', $html);
        $this->assertStringNotContainsString('120.000 کیلوگرم', $html);
    }

    public function test_salt_keeps_its_weight_because_it_has_no_sack(): void
    {
        InventoryItem::ofKey('salt')->move('in', 25, 'purchase');

        $html = Livewire::test(
            ListInventoryItems::class
        )->html();

        // Salt arrives in sacks of no set size, so there is no bag count
        // to show instead. Hiding the weight here would leave the row
        // saying nothing at all.
        $this->assertStringContainsString('25.000 کیلوگرم', $html);
    }

    public function test_recording_stock_in_bags_creates_the_right_movement(): void
    {
        $flour = InventoryItem::ofKey('flour');

        Livewire::test(
            ListInventoryItems::class
        )
            ->callTableAction('recordStock', $flour, data: [
                'direction' => 'in',
                'bags' => 2,
                'reason' => 'purchase',
            ])
            ->assertHasNoTableActionErrors();

        // 2 sacks at the bakery's 40kg is 80kg — never entered by hand.
        $this->assertEquals(80.0, $flour->fresh()->balance);
        $this->assertEquals(2.0, $flour->fresh()->balance_bags);
    }

    /**
     * Salt and dough are weighed rather than bagged, so their entry form
     * asks for kilograms directly instead of a sack count.
     */
    public function test_salt_is_recorded_in_kilograms(): void
    {
        $salt = InventoryItem::ofKey('salt');

        Livewire::test(
            ListInventoryItems::class
        )
            ->callTableAction('recordStock', $salt, data: [
                'direction' => 'in',
                'quantity' => 75,
                'reason' => 'purchase',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(75.0, $salt->fresh()->balance);
        $this->assertNull($salt->fresh()->balance_bags);
    }

    public function test_recording_stock_for_flour_uses_the_formula_bag_weight(): void
    {
        $flour = InventoryItem::ofKey('flour');

        Livewire::test(
            ListInventoryItems::class
        )
            ->callTableAction('recordStock', $flour, data: [
                'direction' => 'in',
                'bags' => 5,
                'reason' => 'purchase',
            ])
            ->assertHasNoTableActionErrors();

        // 5 sacks at the bakery's configured 40kg is 200kg.
        $this->assertEquals(200.0, $flour->fresh()->balance);
    }

    public function test_an_outbound_entry_deducts_stock(): void
    {
        $salt = InventoryItem::ofKey('salt');
        $salt->move('in', 100, 'purchase');

        Livewire::test(
            ListInventoryItems::class
        )
            ->callTableAction('recordStock', $salt, data: [
                'direction' => 'out',
                'quantity' => 30,
                'reason' => 'production',
            ])
            ->assertHasNoTableActionErrors();

        // 100kg in, 30kg out: 70kg left.
        $this->assertEquals(70.0, $salt->fresh()->balance);
    }

    public function test_recording_more_stock_out_than_available_is_rejected(): void
    {
        $flour = InventoryItem::ofKey('flour');
        $flour->move('in', 40, 'purchase');

        Livewire::test(
            ListInventoryItems::class
        )
            ->callTableAction('recordStock', $flour, data: [
                'direction' => 'out',
                'bags' => 5, // 5 * 40kg = 200kg, far more than the 40kg on hand.
                'reason' => 'shaping',
            ]);

        // The balance must stay untouched — no partial or negative movement.
        $this->assertEquals(40.0, $flour->fresh()->balance);
    }
}
