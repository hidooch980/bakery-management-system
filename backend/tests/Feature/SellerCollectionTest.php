<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\SettlementRequest;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the schools, offices and dormitories owe the seller who delivers to
 * them, and the money they hand back.
 */
class SellerCollectionTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private ChaneEntry $chane;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'currency' => 'toman',
            'flour_bag_weight_kg' => 40,
            'bread_price' => 5000,
        ]);
        \App\Support\Money::forgetCache();

        $this->seller = User::factory()->create();
        $this->seller->assignRole('seller');

        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 1,
            'status' => 'shaped',
        ]);

        $this->chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);
    }

    private function buyer(string $name, string $type = 'school'): Customer
    {
        return Customer::create(['name' => $name, 'type' => $type]);
    }

    private function credit(Customer $customer, float $amount, ?User $seller = null): Sale
    {
        return Sale::create([
            'user_id' => ($seller ?? $this->seller)->id,
            'chane_entry_id' => $this->chane->id,
            'payment_type' => 'schools',
            'customer_id' => $customer->id,
            'bread_count' => 10,
            'amount' => $amount,
        ]);
    }

    public function test_a_dormitory_is_a_buyer_like_a_school(): void
    {
        $this->assertContains('dormitory', Customer::BUYER_TYPES);
        $this->assertSame('خوابگاه', Customer::TYPES['dormitory']);

        $dorm = $this->buyer('خوابگاه دانشجویی', 'dormitory');

        $this->assertSame('dormitory', $dorm->fresh()->type);
    }

    public function test_the_seller_sees_what_each_buyer_owes_them(): void
    {
        $school = $this->buyer('دبستان');
        $this->credit($school, 50000);
        $this->credit($school, 30000);

        $data = $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/my-collections')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['customers']);
        $this->assertEqualsWithDelta(80000, $data['customers'][0]['owed'], 0.01);
        $this->assertSame(2, $data['customers'][0]['open_count']);
    }

    public function test_dormitories_and_offices_are_listed_too(): void
    {
        $this->credit($this->buyer('خوابگاه', 'dormitory'), 20000);
        $this->credit($this->buyer('اداره', 'office'), 30000);

        $names = collect(
            $this->actingAs($this->seller, 'sanctum')
                ->getJson('/api/v1/my-collections')->json('data.customers')
        )->pluck('name');

        $this->assertTrue($names->contains('خوابگاه'));
        $this->assertTrue($names->contains('اداره'));
    }

    /** Another seller's credit is not this seller's to collect. */
    public function test_only_this_sellers_own_credit_is_shown(): void
    {
        $other = User::factory()->create();
        $other->assignRole('seller');

        $this->credit($this->buyer('مال دیگری'), 50000, seller: $other);

        $this->assertSame([], $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/my-collections')->json('data.customers'));
    }

    public function test_money_handed_back_clears_the_oldest_invoice_first(): void
    {
        $school = $this->buyer('دبستان');
        $old = $this->credit($school, 50000);
        $old->forceFill(['created_at' => now()->subMonth()])->save();
        $recent = $this->credit($school, 30000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/my-collections/{$school->id}/collect", ['amount' => 50000])
            ->assertOk();

        $this->assertNotNull($old->fresh()->settled_on);
        $this->assertNull($recent->fresh()->settled_on);
    }

    public function test_collecting_more_than_is_owed_is_refused(): void
    {
        $school = $this->buyer('دبستان');
        $this->credit($school, 50000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/my-collections/{$school->id}/collect", ['amount' => 90000])
            ->assertStatus(422);
    }

    public function test_what_has_already_come_back_is_shown(): void
    {
        $school = $this->buyer('دبستان');
        $this->credit($school, 50000)->update(['settled_on' => now()]);
        $this->credit($school, 30000);

        $row = $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/my-collections')->json('data.customers.0');

        $this->assertStringContainsString('50,000', $row['collected_formatted']);
        $this->assertEqualsWithDelta(30000, $row['owed'], 0.01);
    }

    // ------------------------------------ settlement, type by type

    public function test_a_settlement_request_can_name_an_amount_per_type(): void
    {
        Sale::create([
            'user_id' => $this->seller->id,
            'chane_entry_id' => $this->chane->id,
            'payment_type' => 'cash',
            'bread_count' => 20,
            'amount' => 100000,
        ]);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', [
                'payments' => [
                    ['payment_type' => 'cash', 'amount' => 60000],
                    ['payment_type' => 'card', 'amount' => 30000],
                    ['payment_type' => 'home', 'amount' => 10000],
                ],
            ])
            ->assertCreated();

        $breakdown = SettlementRequest::first()->paid_breakdown;

        $this->assertEqualsWithDelta(60000, $breakdown['cash'], 0.01);
        $this->assertEqualsWithDelta(30000, $breakdown['card'], 0.01);
        $this->assertEqualsWithDelta(10000, $breakdown['home'], 0.01);
    }

    /** The card share still gets its own column, since it reaches the bank. */
    public function test_the_card_share_is_taken_from_the_breakdown(): void
    {
        Sale::create([
            'user_id' => $this->seller->id,
            'chane_entry_id' => $this->chane->id,
            'payment_type' => 'cash',
            'bread_count' => 20,
            'amount' => 100000,
        ]);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', [
                'payments' => [
                    ['payment_type' => 'card', 'amount' => 40000],
                ],
            ])
            ->assertCreated();

        $this->assertEqualsWithDelta(40000, (float) SettlementRequest::first()->paid_card, 0.01);
    }

    public function test_the_breakdown_comes_back_labelled(): void
    {
        Sale::create([
            'user_id' => $this->seller->id,
            'chane_entry_id' => $this->chane->id,
            'payment_type' => 'cash',
            'bread_count' => 20,
            'amount' => 100000,
        ]);

        $lines = $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', [
                'payments' => [['payment_type' => 'cash', 'amount' => 100000]],
            ])
            ->assertCreated()
            ->json('data.paid_breakdown');

        $this->assertSame('نقد', $lines[0]['label']);
    }
}
