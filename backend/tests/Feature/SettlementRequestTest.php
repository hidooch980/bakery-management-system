<?php

namespace Tests\Feature;

use App\Filament\Widgets\SettlementRequestsTable;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\SettlementRequest;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settling up runs in two halves: the seller says the money changed hands,
 * the admin agrees it did. A seller clearing their own debt would undo the
 * point of recording it, so the account only moves on confirmation.
 */
class SettlementRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['bread_price' => 5000, 'currency' => 'toman']);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function sale(array $attributes): Sale
    {
        $dough = DoughEntry::create(['user_id' => $this->seller->id, 'bag_count' => 1]);
        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);

        return Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $this->seller->id,
            'bread_count' => 100,
            ...$attributes,
        ]);
    }

    private function request(): TestResponse
    {
        return $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['note' => 'تحویل شد']);
    }

    private function asAdminPanel(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_seller_asks_to_settle_what_they_hold(): void
    {
        $this->sale(['payment_type' => 'cash', 'amount' => 500_000, 'amount_difference' => 0]);

        $this->request()->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertEquals(500_000.0, (float) SettlementRequest::first()->amount);
    }

    public function test_asking_does_not_clear_the_account_by_itself(): void
    {
        $this->sale(['payment_type' => 'cash', 'amount' => 500_000, 'amount_difference' => 0]);

        $this->request()->assertCreated();

        // Until the admin agrees, the seller still owes it.
        $this->assertSame(1, Sale::query()->sellerAccountOutstanding()->count());
        $this->assertNull(Sale::first()->cash_settled_on);
    }

    public function test_confirming_clears_the_account(): void
    {
        $this->sale(['payment_type' => 'cash', 'amount' => 500_000, 'amount_difference' => 0]);
        $this->request()->assertCreated();

        $this->asAdminPanel();

        Livewire::test(SettlementRequestsTable::class)
            ->callTableAction('confirm', SettlementRequest::first());

        $this->assertNotNull(SettlementRequest::first()->confirmed_at);
        $this->assertSame(0, Sale::query()->sellerAccountOutstanding()->count());
    }

    public function test_confirming_records_who_agreed_to_it(): void
    {
        $this->sale(['payment_type' => 'cash', 'amount' => 500_000, 'amount_difference' => 0]);
        $this->request()->assertCreated();

        $this->asAdminPanel();
        Livewire::test(SettlementRequestsTable::class)
            ->callTableAction('confirm', SettlementRequest::first());

        $this->assertSame($this->admin->id, SettlementRequest::first()->confirmed_by);
    }

    public function test_rejecting_leaves_the_debt_exactly_where_it_was(): void
    {
        $this->sale(['payment_type' => 'cash', 'amount' => 500_000, 'amount_difference' => 0]);
        $this->request()->assertCreated();

        $this->asAdminPanel();
        Livewire::test(SettlementRequestsTable::class)
            ->callTableAction('reject', SettlementRequest::first(), data: [
                'reason' => 'مبلغ تحویلی کمتر بود',
            ]);

        $this->assertTrue(SettlementRequest::first()->is_rejected);
        $this->assertSame(1, Sale::query()->sellerAccountOutstanding()->count());
    }

    public function test_the_seller_is_told_why_it_was_rejected(): void
    {
        $this->sale(['payment_type' => 'cash', 'amount' => 500_000, 'amount_difference' => 0]);
        $this->request()->assertCreated();

        $this->asAdminPanel();
        Livewire::test(SettlementRequestsTable::class)
            ->callTableAction('reject', SettlementRequest::first(), data: [
                'reason' => 'مبلغ تحویلی کمتر بود',
            ]);

        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/settlement-requests')
            ->assertOk()
            ->assertJsonPath('data.history.0.rejection_reason', 'مبلغ تحویلی کمتر بود');
    }

    public function test_two_pending_requests_at_once_are_refused(): void
    {
        $this->sale(['payment_type' => 'cash', 'amount' => 500_000, 'amount_difference' => 0]);

        $this->request()->assertCreated();
        $this->request()->assertStatus(409);

        $this->assertSame(1, SettlementRequest::count());
    }

    public function test_asking_with_nothing_owed_is_refused(): void
    {
        $this->request()->assertStatus(422);

        $this->assertSame(0, SettlementRequest::count());
    }

    public function test_credit_alone_cannot_be_settled_by_the_seller(): void
    {
        $customer = Customer::create(['name' => 'دبستان', 'type' => 'school']);

        $this->sale([
            'payment_type' => 'credit',
            'customer_id' => $customer->id,
            'amount' => 500_000,
            'amount_difference' => 0,
        ]);

        // The money is still with the customer, so there is nothing for the
        // seller to hand over — even though their account shows the debt.
        $this->request()->assertStatus(422);
    }

    public function test_the_snapshot_holds_even_if_a_sale_lands_afterwards(): void
    {
        $this->sale(['payment_type' => 'cash', 'amount' => 500_000, 'amount_difference' => 0]);
        $this->request()->assertCreated();

        // A later sale must not change the figure the two of them agreed on.
        $this->sale(['payment_type' => 'cash', 'amount' => 300_000, 'amount_difference' => 0]);

        $this->assertEquals(500_000.0, (float) SettlementRequest::first()->amount);
    }

    public function test_a_seller_only_sees_their_own_requests(): void
    {
        $this->sale(['payment_type' => 'cash', 'amount' => 500_000, 'amount_difference' => 0]);
        $this->request()->assertCreated();

        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('seller');

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/settlement-requests')
            ->assertOk()
            ->assertJsonPath('data.pending', null)
            ->assertJsonCount(0, 'data.history');
    }
}
