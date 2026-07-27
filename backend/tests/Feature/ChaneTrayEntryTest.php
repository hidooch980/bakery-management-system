<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chane is counted out a tray at a time, so the app sends one count per
 * tray and the batch total is worked out from them here.
 */
class ChaneTrayEntryTest extends TestCase
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
            'chane_per_tray' => 30,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function pendingDough(int $bags = 5): DoughEntry
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 1000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 100, 'purchase');

        $this->actingAs($this->userWithRole('dough_maker'), 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => $bags]);

        return DoughEntry::latest('id')->first();
    }

    public function test_the_batch_total_is_the_sum_of_its_trays(): void
    {
        $dough = $this->pendingDough();

        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'trays' => [30, 30, 18],
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();

        $entry = ChaneEntry::first();

        $this->assertSame(78, $entry->chane_count);
        $this->assertSame(3, $entry->tray_count);
        $this->assertSame([30, 30, 18], $entry->tray_counts);
    }

    public function test_the_weight_follows_the_summed_count(): void
    {
        $dough = $this->pendingDough();

        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'trays' => [30, 30, 18],
                'spray_flour_kg' => 0,
            ])
            ->assertCreated()
            // 78 chane at the configured 0.85kg each.
            ->assertJsonPath('data.total_weight_kg', 66.3);
    }

    public function test_a_count_sent_alongside_trays_cannot_contradict_them(): void
    {
        $dough = $this->pendingDough();

        // A client claiming 500 while sending trays that add to 78 must not
        // be able to inflate the batch.
        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'chane_count' => 500,
                'trays' => [30, 30, 18],
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();

        $this->assertSame(78, ChaneEntry::first()->chane_count);
    }

    public function test_a_single_tray_is_a_valid_batch(): void
    {
        $dough = $this->pendingDough();

        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'trays' => [30],
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();

        $this->assertSame(30, ChaneEntry::first()->chane_count);
        $this->assertSame(1, ChaneEntry::first()->tray_count);
    }

    public function test_an_empty_tray_is_rejected(): void
    {
        $dough = $this->pendingDough();

        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'trays' => [30, 0],
                'spray_flour_kg' => 0,
            ])
            ->assertStatus(422);

        $this->assertSame(0, ChaneEntry::count());
    }

    public function test_the_older_single_count_request_still_works(): void
    {
        $dough = $this->pendingDough();

        // A copy of the app that has not updated yet must keep working.
        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'chane_count' => 100,
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();

        $entry = ChaneEntry::first();

        $this->assertSame(100, $entry->chane_count);
        // Nothing is invented about trays that were never counted.
        $this->assertNull($entry->tray_count);
        $this->assertNull($entry->tray_counts);
    }

    public function test_a_request_with_neither_trays_nor_a_count_is_rejected(): void
    {
        $dough = $this->pendingDough();

        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'spray_flour_kg' => 0,
            ])
            ->assertStatus(422);
    }

    public function test_the_panel_shows_how_the_batch_was_counted_out(): void
    {
        $dough = $this->pendingDough();

        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'trays' => [30, 30, 18],
                'spray_flour_kg' => 0,
            ]);

        $this->assertSame('30 + 30 + 18', ChaneEntry::first()->tray_breakdown);
    }

    public function test_a_batch_recorded_without_trays_has_no_breakdown(): void
    {
        $dough = $this->pendingDough();

        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'chane_count' => 100,
                'spray_flour_kg' => 0,
            ]);

        $this->assertNull(ChaneEntry::first()->tray_breakdown);
    }

    public function test_the_app_is_told_the_shops_tray_size(): void
    {
        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->getJson('/api/v1/bakery')
            ->assertOk()
            ->assertJsonPath('data.chane_per_tray', 30);
    }
}
