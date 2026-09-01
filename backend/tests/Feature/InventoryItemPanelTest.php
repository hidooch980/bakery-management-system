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

    public function test_salt_reads_in_sacks_too_now_that_its_size_is_known(): void
    {
        InventoryItem::ofKey('salt')->move('in', 75, 'purchase');

        $html = Livewire::test(
            ListInventoryItems::class
        )->html();

        // Three sacks of 25. The same rule as flour, for the same reason:
        // the shop counts sacks, so «کیلو در انبار معنی نداره».
        $this->assertStringContainsString('3.00 کیسه', $html);
        $this->assertStringNotContainsString('75.000 کیلوگرم', $html);
    }

    public function test_a_good_with_no_sack_size_keeps_its_weight(): void
    {
        // Made rather than borrowed: every good the shop stocks is sized
        // now, and the rule under test is about the missing setting.
        InventoryItem::ofKey('yeast_dry')->update(['bag_weight_kg' => null]);
        InventoryItem::ofKey('yeast_dry')->move('in', 8.5, 'purchase');

        $html = Livewire::test(
            ListInventoryItems::class
        )->html();

        // Nobody has said what a sack of dry yeast weighs. Hiding the
        // weight here would leave the row saying nothing at all, and
        // inventing a sack count would be worse than either.
        $this->assertStringContainsString('8.500 کیلوگرم', $html);
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
     * Salt arrives in sacks of 25 — «هر کیسه نمک ۲۵» — so its entry form
     * asks for a sack count and does the weighing itself.
     */
    public function test_salt_is_recorded_in_sacks(): void
    {
        $salt = InventoryItem::ofKey('salt');

        Livewire::test(
            ListInventoryItems::class
        )
            ->callTableAction('recordStock', $salt, data: [
                'direction' => 'in',
                'bags' => 3,
                'reason' => 'purchase',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(75.0, $salt->fresh()->balance);
        $this->assertEquals(3.0, $salt->fresh()->balance_bags);
    }

    /**
     * And a good whose sack size nobody has given still reads in
     * kilograms, rather than having one invented for it.
     */
    public function test_dry_yeast_is_recorded_in_sacks_of_ten(): void
    {
        $yeast = InventoryItem::ofKey('yeast_dry');

        Livewire::test(
            ListInventoryItems::class
        )
            ->callTableAction('recordStock', $yeast, data: [
                'direction' => 'in',
                'bags' => 3,
                'reason' => 'purchase',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(30.0, $yeast->fresh()->balance);
        $this->assertEquals(3.0, $yeast->fresh()->balance_bags);
    }

    /** And an unsized good still asks for, and keeps, kilograms. */
    public function test_an_unsized_good_is_recorded_in_kilograms(): void
    {
        $yeast = InventoryItem::ofKey('yeast_dry');
        $yeast->update(['bag_weight_kg' => null]);

        Livewire::test(
            ListInventoryItems::class
        )
            ->callTableAction('recordStock', $yeast, data: [
                'direction' => 'in',
                'quantity' => 8.5,
                'reason' => 'purchase',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(8.5, $yeast->fresh()->balance);
        $this->assertNull($yeast->fresh()->balance_bags);
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
                'bags' => 1.2,
                'reason' => 'production',
            ])
            ->assertHasNoTableActionErrors();

        // 100kg in, 1.2 sacks of 25 out: 70kg left.
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
