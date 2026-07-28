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
 * A batch of dough yields a known weight and no more.
 *
 * A count typed one digit too long used to deduct more dough than the batch
 * ever held, quietly eating into the next batch — and the shop only found
 * out when the next entry was refused for stock that should have been there.
 */
class ChaneOvershootTest extends TestCase
{
    use RefreshDatabase;

    private User $chaneGir;

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
        ]);

        $this->chaneGir = User::factory()->create();
        $this->chaneGir->assignRole('chane_gir');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 100, 'purchase');
    }

    private function batch(int $bags = 10): DoughEntry
    {
        $maker = User::factory()->create();
        $maker->assignRole('dough_maker');

        $this->actingAs($maker, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => $bags])
            ->assertCreated();

        return DoughEntry::latest('id')->first();
    }

    public function test_a_count_larger_than_the_batch_is_refused(): void
    {
        $dough = $this->batch();

        // Ten bags make 646kg. 797 chane at 0.85kg is 677kg — more dough
        // than this batch ever held.
        $this->actingAs($this->chaneGir, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'chane_count' => 797,
                'spray_flour_kg' => 0,
            ])
            ->assertStatus(422);
    }

    public function test_an_overshoot_does_not_touch_the_dough_stock(): void
    {
        $dough = $this->batch();
        $before = InventoryItem::ofKey(InventoryItem::DOUGH)->balance;

        $this->actingAs($this->chaneGir, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'chane_count' => 797,
                'spray_flour_kg' => 0,
            ])
            ->assertStatus(422);

        $this->assertSame($before, InventoryItem::ofKey(InventoryItem::DOUGH)->balance);
        $this->assertSame('pending', $dough->fresh()->status);
    }

    /**
     * The next batch's dough is what the overshoot used to be taken from,
     * so its own entry must still go through afterwards.
     */
    public function test_the_next_batch_can_still_be_shaped(): void
    {
        $first = $this->batch();

        $this->actingAs($this->chaneGir, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $first->id,
                'chane_count' => 797,
                'spray_flour_kg' => 0,
            ])
            ->assertStatus(422);

        $this->actingAs($this->chaneGir, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $first->id,
                'chane_count' => 760,
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();

        $second = $this->batch();

        $this->actingAs($this->chaneGir, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $second->id,
                'chane_count' => 760,
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();
    }

    public function test_a_count_that_fits_the_batch_is_accepted(): void
    {
        $dough = $this->batch();

        // 760 chane at 0.85kg is 646kg — exactly what ten bags make.
        $this->actingAs($this->chaneGir, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'chane_count' => 760,
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();

        $this->assertSame(0.0, InventoryItem::ofKey(InventoryItem::DOUGH)->balance);
    }

    /**
     * The panel calls the same recorder, so without its own check the
     * admin would meet a Server Error page rather than being told the
     * count is too high.
     */
    public function test_the_panel_refuses_an_overshoot_as_a_message(): void
    {
        $dough = $this->batch();
        $before = InventoryItem::ofKey(InventoryItem::DOUGH)->balance;

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(\App\Filament\Resources\ChaneEntryResource\Pages\CreateChaneEntry::class)
            ->fillForm([
                'dough_entry_id' => $dough->id,
                'user_id' => $this->chaneGir->id,
                'spray_flour_kg' => 0,
                'trays' => [['count' => 797]],
            ])
            ->call('create');

        // Refused, and nothing was written or spent.
        $this->assertSame(0, \App\Models\ChaneEntry::count());
        $this->assertSame($before, InventoryItem::ofKey(InventoryItem::DOUGH)->balance);
        $this->assertSame('pending', $dough->fresh()->status);
    }

    public function test_nanino_counts_against_the_same_batch(): void
    {
        $dough = $this->batch();

        Bakery::first()->update(['nanino_chane_weight_kg' => 0.5]);

        // 700 normal (595kg) plus 200 nanino (100kg) is 695kg — over the
        // 646kg the batch holds, even though neither figure is silly alone.
        $this->actingAs($this->chaneGir, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'chane_count' => 700,
                'nanino_chane_count' => 200,
                'spray_flour_kg' => 0,
            ])
            ->assertStatus(422);
    }
}
