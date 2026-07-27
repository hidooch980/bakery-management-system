<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Running out of stock is an ordinary thing that happens on a shop floor,
 * so the panel has to say so in words. Letting the guard's exception reach
 * Laravel unhandled turns a routine mistake into a Server Error page with
 * nothing on it to act on.
 */
class PanelStockGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_guard_reads_as_a_message_rather_than_a_server_error(): void
    {
        InventoryItem::ofKey(InventoryItem::DOUGH)->move('in', 80, 'production');

        // The quick stock action is the panel's own way of moving stock,
        // and it is where an admin meets the guard.
        Livewire::test(
            \App\Filament\Resources\InventoryItemResource\Pages\ListInventoryItems::class
        )
            ->callTableAction('recordStock', InventoryItem::ofKey(InventoryItem::DOUGH), data: [
                'direction' => 'out',
                'bags' => 100,
                'reason' => 'production',
            ]);

        // Refused, and the balance is untouched — no Server Error page.
        $this->assertSame(80.0, InventoryItem::ofKey(InventoryItem::DOUGH)->balance);
    }

    public function test_the_message_names_the_item_and_both_figures(): void
    {
        InventoryItem::ofKey(InventoryItem::DOUGH)->move('in', 80, 'production');

        try {
            InventoryItem::ofKey(InventoryItem::DOUGH)->move('out', 646, 'production');
            $this->fail('the guard should have refused this');
        } catch (\App\Exceptions\InsufficientStockException $e) {
            // Whoever reads it needs to know what ran out and by how much.
            $this->assertStringContainsString('خمیر', $e->getMessage());
            $this->assertStringContainsString('80.000', $e->getMessage());
            $this->assertStringContainsString('646.000', $e->getMessage());
        }
    }

    public function test_a_batch_within_stock_still_saves(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 1000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 100, 'purchase');

        Livewire::test(\App\Filament\Resources\DoughEntryResource\Pages\CreateDoughEntry::class)
            ->fillForm([
                'user_id' => $this->admin->id,
                'bag_count' => 5,
                'status' => 'pending',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, DoughEntry::count());
    }
}
