<?php

namespace Tests\Feature;

use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\DoughFormula;
use App\Support\ProductionRecorder;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «خمیرمایهٔ تر پاک شود».
 *
 * The shop was set up to stock both kinds. Every one of the first
 * thirty-one batches used dry, so the fresh tub was a choice nobody made,
 * a row in the warehouse nobody read, and a line on a chart that was zero
 * for ever.
 */
class TheFreshYeastTubIsGoneTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('admin');

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 4000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 200, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
    }

    public function test_the_warehouse_no_longer_carries_it(): void
    {
        $this->assertDatabaseMissing('inventory_items', ['key' => 'yeast_wet']);
    }

    public function test_it_is_not_among_the_items_the_shop_counts(): void
    {
        $this->assertArrayNotHasKey('yeast_wet', InventoryItem::DEFAULTS);
    }

    public function test_kneading_draws_from_the_dry_tub(): void
    {
        $before = InventoryItem::ofKey(InventoryItem::YEAST_DRY)->balance;

        ProductionRecorder::dough(10, $this->baker->id);

        $after = InventoryItem::ofKey(InventoryItem::YEAST_DRY)->fresh()->balance;

        $this->assertLessThan($before, $after, 'خمیر باید از مایهٔ خشک بردارد.');
    }

    public function test_a_batch_records_dry_without_being_asked(): void
    {
        $entry = ProductionRecorder::dough(5, $this->baker->id);

        $this->assertSame(DoughFormula::DRY, $entry->yeast_type);
    }

    public function test_a_phone_on_an_older_build_can_still_record_a_batch(): void
    {
        // It will send `yeast_type: wet` because its form still offers the
        // choice. Refusing the field would stop that handset working at
        // all, for a tub that no longer exists either way.
        $this->actingAs($this->baker)
            ->postJson('/api/v1/dough-entries', [
                'bag_count' => 3,
                'yeast_type' => 'wet',
            ])
            ->assertSuccessful();

        $this->assertSame(DoughFormula::DRY, DoughEntry::latest('id')->first()->yeast_type);
    }

    public function test_a_batch_recorded_before_the_change_still_reads_as_fresh(): void
    {
        $entry = ProductionRecorder::dough(2, $this->baker->id);
        DB::table('dough_entries')->where('id', $entry->id)->update(['yeast_type' => 'wet']);

        // Reading an old «wet» back as «خشک» would be the system telling a
        // story about its own past that is not true.
        $this->assertSame('خمیرمایه تر', $entry->fresh()->yeast_type_label);
    }
}
