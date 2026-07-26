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
        InventoryItem::ofKey('salt')->move('in', 75, 'purchase');

        $html = Livewire::test(
            \App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems::class
        )->html();

        // 75kg at the configured 25kg salt sack is 3 sacks.
        $this->assertStringContainsString('3.00 کیسه', $html);
    }

    public function test_recording_stock_in_bags_creates_the_right_movement(): void
    {
        $salt = InventoryItem::ofKey('salt');

        Livewire::test(
            \App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems::class
        )
            ->callTableAction('recordStock', $salt, data: [
                'direction' => 'in',
                'bags' => 2,
                'reason' => 'purchase',
            ])
            ->assertHasNoTableActionErrors();

        // 2 sacks at 25kg is 50kg — never entered by hand.
        $this->assertEquals(50.0, $salt->fresh()->balance);
        $this->assertEquals(2.0, $salt->fresh()->balance_bags);
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
        $dough = InventoryItem::ofKey('dough');
        $dough->move('in', 100, 'production');

        Livewire::test(
            \App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems::class
        )
            ->callTableAction('recordStock', $dough, data: [
                'direction' => 'out',
                'bags' => 3,
                'reason' => 'shaping',
            ])
            ->assertHasNoTableActionErrors();

        // 100kg in, 3 units of 10kg out: 70kg left.
        $this->assertEquals(70.0, $dough->fresh()->balance);
    }
}
