<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Support\ProductionRecorder;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The chane-entry panel form must derive weight from the count the same
 * way the mobile app's API does — an admin typing a number in kilograms
 * directly, contradicting the production formula, was the original bug.
 */
class ChaneEntryPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // Shaping spends real dough now, whichever screen records it, so
        // the batch has to exist in the warehouse for the panel to draw on.
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 200, 'purchase');

        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_WET)->move('in', 50, 'purchase');
        $this->dough = ProductionRecorder::dough(10, $admin->id);
    }

    private DoughEntry $dough;

    public function test_weight_is_derived_from_the_count_not_typed(): void
    {
        $admin = User::first();

        Livewire::test(\App\Filament\Resources\ChaneEntryResource\Pages\CreateChaneEntry::class)
            ->fillForm([
                'dough_entry_id' => $this->dough->id,
                'user_id' => $admin->id,
                // Counted out as two full trays and a part one.
                'trays' => [['count' => 40], ['count' => 40], ['count' => 20]],
                'nanino_chane_count' => 40,
                'spray_flour_kg' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = ChaneEntry::first();

        // 100 × 0.85 = 85, 40 × 1.0 = 40 — never the raw form input.
        $this->assertEquals(85.0, (float) $entry->normal_weight_kg);
        $this->assertEquals(40.0, (float) $entry->nanino_weight_kg);
    }

    public function test_the_form_has_no_way_to_type_a_raw_weight(): void
    {
        $html = Livewire::test(\App\Filament\Resources\ChaneEntryResource\Pages\CreateChaneEntry::class)->html();

        // The old inputs named normal_weight_kg/nanino_weight_kg must be
        // gone; only the count fields and the computed placeholders remain.
        $this->assertStringNotContainsString('wire:model="data.normal_weight_kg"', $html);
        $this->assertStringNotContainsString('wire:model="data.nanino_weight_kg"', $html);
    }

    public function test_editing_reverses_the_nanino_count_from_the_stored_weight(): void
    {
        $admin = User::first();

        $entry = ChaneEntry::create([
            'dough_entry_id' => $this->dough->id,
            'user_id' => $admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 40,
            'spray_flour_kg' => 2,
        ]);

        Livewire::test(\App\Filament\Resources\ChaneEntryResource\Pages\EditChaneEntry::class, [
            'record' => $entry->getRouteKey(),
        ])->assertFormSet(['nanino_chane_count' => 40]);
    }

    public function test_saving_an_edit_recomputes_the_weight_from_the_new_count(): void
    {
        $admin = User::first();

        $entry = ChaneEntry::create([
            'dough_entry_id' => $this->dough->id,
            'user_id' => $admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 40,
            'spray_flour_kg' => 2,
        ]);

        Livewire::test(\App\Filament\Resources\ChaneEntryResource\Pages\EditChaneEntry::class, [
            'record' => $entry->getRouteKey(),
        ])
            ->fillForm([
                'trays' => [['count' => 200]],
                'nanino_chane_count' => 10,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $entry->refresh();

        $this->assertEquals(170.0, (float) $entry->normal_weight_kg);
        $this->assertEquals(10.0, (float) $entry->nanino_weight_kg);
    }

    public function test_the_panel_stores_how_the_batch_was_counted_out(): void
    {
        Livewire::test(\App\Filament\Resources\ChaneEntryResource\Pages\CreateChaneEntry::class)
            ->fillForm([
                'dough_entry_id' => $this->dough->id,
                'user_id' => $this->dough->user_id,
                'trays' => [['count' => 30], ['count' => 30], ['count' => 18]],
                'spray_flour_kg' => 0,
                'status' => 'pending',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = ChaneEntry::first();

        // The total is the sum of the trays, never a figure typed on its own.
        $this->assertSame(78, $entry->chane_count);
        $this->assertSame(3, $entry->tray_count);
        $this->assertSame([30, 30, 18], $entry->tray_counts);
    }

    public function test_a_batch_recorded_before_trays_opens_as_one_tray(): void
    {
        $entry = ChaneEntry::create([
            'dough_entry_id' => $this->dough->id,
            'user_id' => $this->dough->user_id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);

        // An older record has no trays stored; the form must not open
        // empty, so it shows the whole count as a single tray. Repeater
        // state is keyed by generated ids, so read the counts out of it.
        $state = Livewire::test(
            \App\Filament\Resources\ChaneEntryResource\Pages\EditChaneEntry::class,
            ['record' => $entry->id]
        )->get('data.trays');

        $counts = collect($state)->pluck('count')->values()->all();

        $this->assertSame([100], array_map('intval', $counts));
    }
}
