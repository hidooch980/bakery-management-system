<?php

namespace Tests\Feature;

use App\Filament\Widgets\SettlementRequestsTable;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\SellerAccountCredit;
use App\Models\SettlementRequest;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A seller hands over what they have, not a sum that happens to close whole
 * sales. The account carries one figure they can pay against; the shop does
 * the arithmetic of which debts that covers.
 */
class SellerRunningAccountTest extends TestCase
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

    protected function tearDown(): void
    {
        Money::forgetCache();
        parent::tearDown();
    }

    private function sale(float $amount): Sale
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
            'payment_type' => 'cash',
            'amount' => $amount,
            'amount_difference' => 0,
        ]);
    }

    private function confirm(SettlementRequest $settlement): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SettlementRequestsTable::class)
            ->callTableAction('confirm', $settlement);
    }

    public function test_the_account_is_one_figure_the_seller_can_read(): void
    {
        $this->sale(300_000);
        $this->sale(500_000);

        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/settlement-requests/account')
            ->assertOk()
            ->assertJsonPath('data.debt', 800_000)
            ->assertJsonPath('data.credit', 0)
            ->assertJsonPath('data.balance', 800_000);
    }

    public function test_a_payment_smaller_than_the_debt_is_accepted(): void
    {
        $this->sale(300_000);
        $this->sale(500_000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['amount' => 500_000])
            ->assertCreated()
            ->assertJsonPath('data.amount', 500_000);
    }

    public function test_it_closes_the_oldest_debt_first_and_holds_the_rest(): void
    {
        $first = $this->sale(300_000);
        $second = $this->sale(500_000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['amount' => 500_000])
            ->assertCreated();

        $this->confirm(SettlementRequest::first());

        // 300,000 closes the oldest sale; the 200,000 left over is the
        // shop's to hold, not a sale it can half close.
        $this->assertNotNull($first->fresh()->cash_settled_on);
        $this->assertNull($second->fresh()->cash_settled_on);
        $this->assertEquals(200_000.0, SellerAccountCredit::balanceFor($this->seller->id));
    }

    public function test_the_balance_reads_what_the_seller_would_say(): void
    {
        $this->sale(300_000);
        $this->sale(500_000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['amount' => 500_000])
            ->assertCreated();
        $this->confirm(SettlementRequest::first());

        // Paid 500,000 of 800,000, so 300,000 is owed — whatever shape the
        // underlying sales happen to be.
        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/settlement-requests/account')
            ->assertOk()
            ->assertJsonPath('data.balance', 300_000);
    }

    public function test_the_held_credit_is_spent_before_new_money_is_asked_for(): void
    {
        $this->sale(300_000);
        $second = $this->sale(500_000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['amount' => 500_000])
            ->assertCreated();
        $this->confirm(SettlementRequest::first());

        // 200,000 is already held, so 300,000 more clears the 500,000 sale.
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['amount' => 300_000])
            ->assertCreated();
        $this->confirm(SettlementRequest::latest('id')->first());

        $this->assertNotNull($second->fresh()->cash_settled_on);
        $this->assertSame(0, Sale::query()->sellerAccountOutstanding()->count());
    }

    public function test_paying_more_than_is_owed_is_refused(): void
    {
        $this->sale(300_000);

        // Otherwise the shop quietly holds money against debts that do not
        // exist, and the seller has no way to see it went missing.
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['amount' => 900_000])
            ->assertStatus(422);
    }

    public function test_naming_no_amount_still_settles_the_whole_account(): void
    {
        $this->sale(300_000);
        $this->sale(500_000);

        // What an older copy of the app sends.
        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['note' => 'همه'])
            ->assertCreated()
            ->assertJsonPath('data.amount', 800_000);

        $this->confirm(SettlementRequest::first());

        $this->assertSame(0, Sale::query()->sellerAccountOutstanding()->count());
    }
}
