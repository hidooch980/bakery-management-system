<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «هر کیسه خمیر ۱۰ کیلو هست، هر کیسه نمک ۲۵» — the owner, 2026-08-17.
 *
 * Salt and yeast were barred from ever reading as sacks by a list in the
 * code, on the belief that they are weighed rather than counted. They are
 * weighed into the dough; they arrive in sacks like everything else. The
 * owner reads his store in sacks, and 8.52 kg of yeast means nothing to
 * him until it is said as less than one bag left.
 *
 * Whether a good has a fixed package is a fact about the good, so it is
 * the shop's answer now, not the code's.
 */
class SaltAndYeastComeInSacksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['flour_bag_weight_kg' => 40]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function item(string $key): InventoryItem
    {
        return InventoryItem::ofKey($key);
    }

    private function stock(string $key, float $kg): InventoryItem
    {
        $item = $this->item($key);
        $item->move('in', $kg, 'purchase', $this->admin->id);

        return $item->fresh();
    }

    public function test_salt_reads_in_sacks_of_twenty_five(): void
    {
        $this->item(InventoryItem::SALT)->update(['bag_weight_kg' => 25]);

        $salt = $this->stock(InventoryItem::SALT, 189.76);

        // 189.76 / 25 = 7.59 sacks.
        $this->assertEqualsWithDelta(7.59, $salt->balance_bags, 0.01);
    }

    public function test_yeast_reads_in_sacks_of_ten(): void
    {
        $this->item(InventoryItem::YEAST_DRY)->update(['bag_weight_kg' => 10]);

        $yeast = $this->stock(InventoryItem::YEAST_DRY, 8.52);

        // Under one bag, which is the whole point of saying it in bags.
        $this->assertEqualsWithDelta(0.85, $yeast->balance_bags, 0.01);
        $this->assertLessThan(1, $yeast->balance_bags);
    }

    public function test_flour_still_takes_its_size_from_the_formula(): void
    {
        $flour = $this->stock(InventoryItem::FLOUR, 2374);

        // Not from the column, which is empty for flour. That setting
        // predates the column and every install already has it.
        $this->assertNull($flour->bag_weight_kg);
        $this->assertEqualsWithDelta(59.35, $flour->balance_bags, 0.01);
    }

    public function test_a_good_with_no_package_size_still_reads_in_kilos_only(): void
    {
        // Every good the shop stocks is sized now, so the missing setting
        // is made rather than borrowed. The rule under test is about the
        // setting being absent, not about which good is being weighed.
        $this->item(InventoryItem::YEAST_DRY)->update(['bag_weight_kg' => null]);

        $unsized = $this->stock(InventoryItem::YEAST_DRY, 12);

        // Null, not zero and not a made-up bag count. Nobody has said what
        // a sack of this weighs, so nothing can be said in sacks.
        $this->assertNull($unsized->balance_bags);
    }

    public function test_stock_can_be_taken_in_by_the_sack(): void
    {
        $this->item(InventoryItem::SALT)->update(['bag_weight_kg' => 25]);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/inventory/movements', [
            'item' => InventoryItem::SALT,
            'direction' => 'in',
            'bags' => 4,
            'reason' => 'purchase',
        ])->assertSuccessful();

        $this->assertEqualsWithDelta(100, $this->item(InventoryItem::SALT)->balance, 0.01);
    }

    public function test_a_good_with_no_package_size_refuses_a_sack_count(): void
    {
        $this->item(InventoryItem::YEAST_DRY)->update(['bag_weight_kg' => null]);

        Sanctum::actingAs($this->admin);

        // And says why, rather than «این فقط به کیلوگرم ثبت می‌شود» — which
        // read as a rule about the good when it is really a missing setting.
        $this->postJson('/api/v1/inventory/movements', [
            'item' => InventoryItem::YEAST_DRY,
            'direction' => 'in',
            'bags' => 2,
            'reason' => 'purchase',
        ])->assertStatus(422);
    }
}
