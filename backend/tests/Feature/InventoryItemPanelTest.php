<?php

namespace Tests\Feature;

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

    public function test_the_bag_count_is_visible_next_to_the_weight(): void
    {
        InventoryItem::ofKey('flour')->move('in', 120, 'purchase');

        $html = Livewire::test(
            \App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems::class
        )->html();

        // 120kg at the bakery's 40kg sack is 3 sacks.
        $this->assertStringContainsString('3.00 کیسه', $html);
    }

    public function test_the_bag_count_comes_before_the_weight(): void
    {
        InventoryItem::ofKey('flour')->move('in', 120, 'purchase');

        $html = Livewire::test(
            \App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems::class
        )->html();

        $bagsPosition = strpos($html, '3.00 کیسه');
        $weightPosition = strpos($html, '120.000 کیلوگرم');

        $this->assertNotFalse($bagsPosition);
        $this->assertNotFalse($weightPosition);
        $this->assertLessThan(
            $weightPosition,
            $bagsPosition,
            'the bag count should be shown before the weight, not after it'
        );
    }

    public function test_recording_stock_in_bags_creates_the_right_movement(): void
    {
        $flour = InventoryItem::ofKey('flour');

        Livewire::test(
            \App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems::class
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
            \App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems::class
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
            \App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems::class
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
            \App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems::class
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
            \App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems::class
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
