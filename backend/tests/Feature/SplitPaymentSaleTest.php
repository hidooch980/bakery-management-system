<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A batch is often paid for in more than one way, so the seller sends a
 * bread count per payment type and the whole lot is written together.
 */
class SplitPaymentSaleTest extends TestCase
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
            'bread_price' => 5000,
            'currency' => 'toman',
        ]);
        \App\Support\Money::forgetCache();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function batchOf(int $count): ChaneEntry
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 500, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 50, 'purchase');

        $dough = $this->userWithRole('dough_maker');
        $chaneGir = $this->userWithRole('chane_gir');

        $this->actingAs($dough, 'sanctum')->postJson('/api/v1/dough-entries', ['bag_count' => 5]);
        $this->actingAs($chaneGir, 'sanctum')->postJson('/api/v1/chane-entries', [
            'dough_entry_id' => DoughEntry::latest('id')->first()->id,
            'chane_count' => $count,
            'spray_flour_kg' => 0,
        ]);

        return ChaneEntry::latest('id')->first();
    }

    public function test_one_batch_can_be_paid_for_in_two_ways(): void
    {
        $seller = $this->userWithRole('seller');
        $chane = $this->batchOf(100);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payments' => [
                    ['payment_type' => 'cash', 'bread_count' => 60, 'amount' => 300_000],
                    ['payment_type' => 'card', 'bread_count' => 40, 'amount' => 200_000],
                ],
            ])
            ->assertCreated();

        $this->assertSame(2, Sale::count());
        $this->assertSame(100, (int) Sale::sum('bread_count'));
        $this->assertSame('sold', $chane->fresh()->status);

        // Each line keeps its own payment type, so every report that groups
        // by type still sees exactly what it expects.
        $this->assertSame(60, (int) Sale::where('payment_type', 'cash')->sum('bread_count'));
        $this->assertSame(40, (int) Sale::where('payment_type', 'card')->sum('bread_count'));
    }

    public function test_the_batch_shortfall_is_counted_once_not_once_per_line(): void
    {
        $seller = $this->userWithRole('seller');
        $chane = $this->batchOf(100);

        // 90 of the 100 accounted for, so 10 loaves are short — once.
        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payments' => [
                    ['payment_type' => 'cash', 'bread_count' => 50, 'amount' => 250_000],
                    ['payment_type' => 'card', 'bread_count' => 40, 'amount' => 200_000],
                ],
            ])
            ->assertCreated();

        $this->assertSame(10, (int) Sale::sum('shortfall_count'));
        $this->assertEquals(50_000.0, (float) Sale::sum('shortfall_amount'));
    }

    public function test_selling_the_whole_batch_across_lines_leaves_no_shortfall(): void
    {
        $seller = $this->userWithRole('seller');
        $chane = $this->batchOf(100);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payments' => [
                    ['payment_type' => 'cash', 'bread_count' => 60, 'amount' => 300_000],
                    ['payment_type' => 'card', 'bread_count' => 40, 'amount' => 200_000],
                ],
            ])
            ->assertCreated();

        $this->assertSame(0, (int) Sale::sum('shortfall_count'));
    }

    public function test_more_bread_than_the_batch_holds_is_rejected(): void
    {
        $seller = $this->userWithRole('seller');
        $chane = $this->batchOf(100);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payments' => [
                    ['payment_type' => 'cash', 'bread_count' => 80, 'amount' => 400_000],
                    ['payment_type' => 'card', 'bread_count' => 40, 'amount' => 200_000],
                ],
            ])
            ->assertStatus(422);

        // Nothing was written, and the batch is still there to sell.
        $this->assertSame(0, Sale::count());
        $this->assertSame('pending', $chane->fresh()->status);
    }

    public function test_each_line_carries_its_own_money_difference(): void
    {
        $seller = $this->userWithRole('seller');
        $chane = $this->batchOf(100);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payments' => [
                    // 60 loaves are worth 300,000 but only 280,000 was taken.
                    ['payment_type' => 'cash', 'bread_count' => 60, 'amount' => 280_000],
                    ['payment_type' => 'card', 'bread_count' => 40, 'amount' => 200_000],
                ],
            ])
            ->assertCreated();

        $cash = Sale::where('payment_type', 'cash')->first();
        $card = Sale::where('payment_type', 'card')->first();

        $this->assertEquals(-20_000.0, (float) $cash->amount_difference);
        $this->assertEquals(0.0, (float) $card->amount_difference);
    }

    public function test_a_credit_line_still_needs_a_named_buyer(): void
    {
        $seller = $this->userWithRole('seller');
        $chane = $this->batchOf(100);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payments' => [
                    ['payment_type' => 'cash', 'bread_count' => 60, 'amount' => 300_000],
                    ['payment_type' => 'credit', 'bread_count' => 40, 'amount' => 200_000],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(0, Sale::count());
    }

    public function test_a_credit_line_may_name_its_own_buyer(): void
    {
        $seller = $this->userWithRole('seller');
        $customer = Customer::create(['name' => 'دبستان', 'type' => 'school']);
        $chane = $this->batchOf(100);

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payments' => [
                    ['payment_type' => 'cash', 'bread_count' => 60, 'amount' => 300_000],
                    [
                        'payment_type' => 'credit',
                        'bread_count' => 40,
                        'amount' => 200_000,
                        'customer_id' => $customer->id,
                    ],
                ],
            ])
            ->assertCreated();

        $this->assertSame(
            $customer->id,
            Sale::where('payment_type', 'credit')->first()->customer_id
        );
        // The cash line is a walk-in, so it names nobody.
        $this->assertNull(Sale::where('payment_type', 'cash')->first()->customer_id);
    }

    public function test_the_old_single_payment_request_still_works(): void
    {
        $seller = $this->userWithRole('seller');
        $chane = $this->batchOf(100);

        // An older copy of the app must keep working after a server update.
        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payment_type' => 'cash',
                'bread_count' => 100,
                'amount' => 500_000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment_type', 'cash');

        $this->assertSame(1, Sale::count());
        $this->assertSame('sold', $chane->fresh()->status);
    }

    public function test_a_sold_batch_cannot_be_sold_again(): void
    {
        $seller = $this->userWithRole('seller');
        $chane = $this->batchOf(100);

        $payload = [
            'chane_entry_id' => $chane->id,
            'payments' => [['payment_type' => 'cash', 'bread_count' => 100, 'amount' => 500_000]],
        ];

        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/sales', $payload)->assertCreated();
        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/sales', $payload)->assertStatus(409);
    }
}
