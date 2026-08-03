<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\Customer;
use App\Models\FlourSale;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlourSaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'flour_price_per_kg' => 30_000,
            'currency' => 'toman',
        ]);
        Money::forgetCache();

        // Stock the warehouse, or every sale is refused for lack of flour.
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 1000, 'purchase');
    }

    private function seller(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('seller');

        return $user;
    }

    // ------------------------------------------------------------- by kilo

    public function test_seller_sells_flour_by_the_kilo(): void
    {
        $this->actingAs($this->seller(), 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'kg',
                'quantity' => 12.5,
                'payment_type' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('data.unit', 'kg')
            ->assertJsonPath('data.unit_label', 'کیلوگرم');

        $sale = FlourSale::first();

        $this->assertEquals(12.5, (float) $sale->weight_kg);
        $this->assertEquals(12.5 * 30000, (float) $sale->amount);
        // A kilo sale has no sack weight to record.
        $this->assertNull($sale->bag_weight_kg);
    }

    // -------------------------------------------------------------- by bag

    public function test_seller_sells_flour_by_the_sack(): void
    {
        $this->actingAs($this->seller(), 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'bag',
                'quantity' => 3,
                'payment_type' => 'cash',
            ])
            ->assertCreated();

        $sale = FlourSale::first();

        // Three sacks of 40kg.
        $this->assertEquals(120.0, (float) $sale->weight_kg);
        $this->assertEquals(40.0, (float) $sale->bag_weight_kg);
    }

    public function test_sack_price_falls_back_to_the_kilo_rate(): void
    {
        // No explicit sack price is set, so it is 40kg × 30,000.
        $this->assertEquals(1_200_000.0, FlourSale::defaultUnitPrice('bag'));
    }

    public function test_explicit_sack_price_wins_over_the_kilo_rate(): void
    {
        Bakery::first()->update(['flour_price_per_bag' => 1_150_000]);

        $this->assertEquals(1_150_000.0, FlourSale::defaultUnitPrice('bag'));
    }

    // ---------------------------------------------- purchase cost settings

    public function test_the_purchase_price_is_distinct_from_the_resale_price(): void
    {
        Bakery::first()->update([
            'flour_price_per_kg' => 30_000,
            'flour_purchase_price_per_kg' => 22_000,
        ]);

        // What the mill charges must never be confused with what the
        // bakery charges a customer reselling flour out of its warehouse.
        $this->assertEquals(30_000.0, FlourSale::defaultUnitPrice('kg'));
        $this->assertEquals(
            22_000.0,
            (float) Bakery::first()->flour_purchase_price_per_kg
        );
    }

    public function test_transport_by_factory_defaults_to_true(): void
    {
        $this->assertTrue((bool) Bakery::first()->flour_transport_by_factory);
    }

    public function test_transport_by_factory_can_be_turned_off(): void
    {
        Bakery::first()->update(['flour_transport_by_factory' => false]);

        $this->assertFalse((bool) Bakery::first()->fresh()->flour_transport_by_factory);
    }

    public function test_admin_updates_the_purchase_cost_settings_through_the_api(): void
    {
        $this->actingAs($this->seller(), 'sanctum');
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/bakery', [
                'name' => 'نانوایی من',
                'flour_purchase_price_per_kg' => 21_500,
                'flour_transport_by_factory' => false,
            ])
            ->assertOk();

        $bakery = Bakery::first();
        $this->assertEquals(21_500.0, (float) $bakery->flour_purchase_price_per_kg);
        $this->assertFalse((bool) $bakery->flour_transport_by_factory);
    }

    public function test_sack_weight_is_frozen_at_sale_time(): void
    {
        $this->actingAs($this->seller(), 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'bag',
                'quantity' => 2,
                'payment_type' => 'cash',
            ])->assertCreated();

        // Changing the setting afterwards must not rewrite a past sale.
        Bakery::first()->update(['flour_bag_weight_kg' => 50]);

        $this->assertEquals(80.0, (float) FlourSale::first()->weight_kg);
    }

    // ----------------------------------------------------------- warehouse

    public function test_selling_flour_deducts_it_from_the_warehouse(): void
    {
        $before = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;

        $this->actingAs($this->seller(), 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'bag',
                'quantity' => 2,
                'payment_type' => 'cash',
            ])->assertCreated();

        $this->assertEquals(
            $before - 80,
            InventoryItem::ofKey(InventoryItem::FLOUR)->fresh()->balance
        );
    }

    public function test_deleting_a_sale_returns_the_flour_to_the_warehouse(): void
    {
        $before = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;

        $this->actingAs($this->seller(), 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'kg',
                'quantity' => 30,
                'payment_type' => 'cash',
            ])->assertCreated();

        FlourSale::first()->delete();

        $this->assertEquals(
            $before,
            InventoryItem::ofKey(InventoryItem::FLOUR)->fresh()->balance
        );
    }

    public function test_selling_more_flour_than_is_in_stock_is_refused(): void
    {
        $this->actingAs($this->seller(), 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'kg',
                'quantity' => 5000,
                'payment_type' => 'cash',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('flour_sales', 0);
    }

    // ------------------------------------------------------------ payments

    public function test_credit_sale_must_name_the_buyer(): void
    {
        $this->actingAs($this->seller(), 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'kg',
                'quantity' => 10,
                'payment_type' => 'credit',
            ])
            ->assertStatus(422);
    }

    public function test_credit_sale_is_accepted_with_a_buyer(): void
    {
        $customer = Customer::create(['name' => 'نانوایی مرکزی', 'type' => 'partner']);

        $this->actingAs($this->seller(), 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'kg',
                'quantity' => 10,
                'payment_type' => 'credit',
                'customer_id' => $customer->id,
            ])
            ->assertCreated();

        $this->assertTrue(FlourSale::first()->is_debt);
    }

    // -------------------------------------------------------------- prices

    public function test_the_seller_may_override_the_price_at_the_counter(): void
    {
        $this->actingAs($this->seller(), 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'kg',
                'quantity' => 10,
                'unit_price' => 25_000,
                'payment_type' => 'cash',
            ])->assertCreated();

        $this->assertEquals(250_000.0, (float) FlourSale::first()->amount);
    }

    // ------------------------------------------------------------ endpoints

    public function test_options_report_what_is_available_to_sell(): void
    {
        $this->actingAs($this->seller(), 'sanctum')
            ->getJson('/api/v1/flour-sales/options')
            ->assertOk()
            ->assertJsonPath('data.bag_weight_kg', 40)
            ->assertJsonPath('data.available_kg', 1000)
            ->assertJsonPath('data.available_bags', 25);
    }

    public function test_today_summarises_the_sellers_flour_sales(): void
    {
        $seller = $this->seller();

        foreach ([['kg', 10], ['bag', 2]] as [$unit, $quantity]) {
            $this->actingAs($seller, 'sanctum')
                ->postJson('/api/v1/flour-sales', [
                    'unit' => $unit,
                    'quantity' => $quantity,
                    'payment_type' => 'cash',
                ])->assertCreated();
        }

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/flour-sales/today')
            ->assertOk()
            ->assertJsonPath('data.summary.count', 2)
            // 10kg loose plus two 40kg sacks.
            ->assertJsonPath('data.summary.total_weight_kg', 90)
            ->assertJsonPath('data.summary.bag_count', 2);
    }

    public function test_a_seller_sees_only_their_own_sales(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();

        $this->actingAs($theirs, 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'kg', 'quantity' => 5, 'payment_type' => 'cash',
            ])->assertCreated();

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/v1/flour-sales/today')
            ->assertOk()
            ->assertJsonPath('data.summary.count', 0);
    }

    public function test_an_admin_sees_every_sellers_sales(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'kg', 'quantity' => 5, 'payment_type' => 'cash',
            ])->assertCreated();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        // The warehouse view is shop-wide, not per seller.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/flour-sales/today')
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1);
    }

    public function test_a_dough_maker_cannot_sell_flour(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('dough_maker');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'kg',
                'quantity' => 1,
                'payment_type' => 'cash',
            ])
            ->assertForbidden();
    }

    // ------------------------------------------------------------ currency

    public function test_amounts_are_reported_in_the_configured_unit(): void
    {
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->actingAs($this->seller(), 'sanctum')
            ->postJson('/api/v1/flour-sales', [
                'unit' => 'kg',
                'quantity' => 10,
                // Typed in Rial, so 300,000 Rial is 30,000 Toman a kilo.
                'unit_price' => 300_000,
                'payment_type' => 'cash',
            ])->assertCreated();

        // 300,000 Rial a kilo is 30,000 Toman, and ten kilos of it is
        // 300,000 Toman stored — regardless of the display unit.
        $this->assertEquals(300_000.0, (float) FlourSale::first()->amount);

        $this->actingAs($this->seller(), 'sanctum')
            ->getJson('/api/v1/flour-sales/today')
            ->assertOk()
            ->assertJsonPath('data.summary.currency', 'rial');
    }
}
