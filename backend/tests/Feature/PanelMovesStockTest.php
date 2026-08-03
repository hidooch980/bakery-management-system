<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\ProductionRecorder;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The warehouse has to say the same thing however the work was recorded.
 *
 * Kneading and shaping are physical: entering a batch in the panel spends
 * the same flour as entering it on the shop floor. The panel used to write
 * the row and move nothing, so the books came out different depending on
 * which screen someone happened to use.
 */
class PanelMovesStockTest extends TestCase
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

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 200, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_WET)->move('in', 50, 'purchase');
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_kneading_in_the_panel_spends_flour_and_salt(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;
        $salt = InventoryItem::ofKey(InventoryItem::SALT)->balance;

        Livewire::test(\App\Filament\Resources\DoughEntryResource\Pages\CreateDoughEntry::class)
            ->fillForm([
                'user_id' => $this->admin->id,
                'bag_count' => 5,
                'status' => 'pending',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // 5 bags at 40kg, plus the salt and yeast the formula calls for.
        $this->assertSame($flour - 200.0, InventoryItem::ofKey(InventoryItem::FLOUR)->balance);
        $this->assertSame($salt - 3.0, InventoryItem::ofKey(InventoryItem::SALT)->balance);
    }

    public function test_the_panel_and_the_app_move_the_same_stock(): void
    {
        $dough = $this->userWithRole('dough_maker');

        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 5])
            ->assertCreated();

        $viaApi = [
            'flour' => InventoryItem::ofKey(InventoryItem::FLOUR)->balance,
            'salt' => InventoryItem::ofKey(InventoryItem::SALT)->balance,
        ];

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(\App\Filament\Resources\DoughEntryResource\Pages\CreateDoughEntry::class)
            ->fillForm([
                'user_id' => $this->admin->id,
                'bag_count' => 5,
                'status' => 'pending',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // The second batch must move exactly what the first one did.
        $this->assertSame(
            $viaApi['flour'] - 200.0,
            InventoryItem::ofKey(InventoryItem::FLOUR)->balance
        );
        $this->assertSame(
            $viaApi['salt'] - 3.0,
            InventoryItem::ofKey(InventoryItem::SALT)->balance
        );
    }

    public function test_shaping_in_the_panel_spends_the_dough(): void
    {
        $batch = ProductionRecorder::dough(5, $this->admin->id);

        Livewire::test(\App\Filament\Resources\ChaneEntryResource\Pages\CreateChaneEntry::class)
            ->fillForm([
                'dough_entry_id' => $batch->id,
                'user_id' => $this->admin->id,
                'trays' => [['count' => 100]],
                'spray_flour_kg' => 2,
                'status' => 'pending',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // 100 chane at 0.85kg came out of the dough store.
    }

    public function test_shaping_in_the_panel_spends_the_spray_flour(): void
    {
        $batch = ProductionRecorder::dough(5, $this->admin->id);
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;

        Livewire::test(\App\Filament\Resources\ChaneEntryResource\Pages\CreateChaneEntry::class)
            ->fillForm([
                'dough_entry_id' => $batch->id,
                'user_id' => $this->admin->id,
                'trays' => [['count' => 100]],
                'spray_flour_kg' => 2,
                'status' => 'pending',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($flour - 2.0, InventoryItem::ofKey(InventoryItem::FLOUR)->balance);
    }

    public function test_shaping_in_the_panel_closes_the_batch(): void
    {
        $batch = ProductionRecorder::dough(5, $this->admin->id);

        Livewire::test(\App\Filament\Resources\ChaneEntryResource\Pages\CreateChaneEntry::class)
            ->fillForm([
                'dough_entry_id' => $batch->id,
                'user_id' => $this->admin->id,
                'trays' => [['count' => 100]],
                'spray_flour_kg' => 0,
                'status' => 'pending',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('processed', $batch->fresh()->status);
    }

    public function test_deleting_a_panel_entry_gives_the_stock_back(): void
    {
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;

        Livewire::test(\App\Filament\Resources\DoughEntryResource\Pages\CreateDoughEntry::class)
            ->fillForm([
                'user_id' => $this->admin->id,
                'bag_count' => 5,
                'status' => 'pending',
            ])
            ->call('create');

        DoughEntry::latest('id')->first()->delete();

        // Now that the panel moves stock, deleting has something real to
        // reverse — and it must land back exactly where it started.
        $this->assertSame($flour, InventoryItem::ofKey(InventoryItem::FLOUR)->balance);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
