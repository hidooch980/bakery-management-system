<?php

namespace Tests\Feature;

use App\Filament\Widgets\SettlementRequestsTable;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
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
 * A seller who cannot hand over the whole account today settles the part
 * they can. The rest has to stay owed — a partial handover that quietly
 * cleared everything would lose the shop money.
 */
class PartialSettlementTest extends TestCase
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

    public function test_the_seller_is_offered_each_open_sale_to_choose_from(): void
    {
        $this->sale(300_000);
        $this->sale(200_000);

        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/settlement-requests/settleable')
            ->assertOk()
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.total', 500_000);
    }

    public function test_settling_a_chosen_sale_asks_for_that_much_only(): void
    {
        $first = $this->sale(300_000);
        $this->sale(200_000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['sale_ids' => [$first->id]])
            ->assertCreated()
            ->assertJsonPath('data.amount', 300_000);
    }

    public function test_confirming_a_partial_settlement_leaves_the_rest_owed(): void
    {
        $first = $this->sale(300_000);
        $second = $this->sale(200_000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['sale_ids' => [$first->id]])
            ->assertCreated();

        $this->confirm(SettlementRequest::first());

        // The one they handed over is closed; the one they did not is not.
        $this->assertNotNull($first->fresh()->cash_settled_on);
        $this->assertNull($second->fresh()->cash_settled_on);
        $this->assertSame(1, Sale::query()->sellerAccountOutstanding()->count());
    }

    public function test_the_remainder_can_be_settled_afterwards(): void
    {
        $first = $this->sale(300_000);
        $second = $this->sale(200_000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['sale_ids' => [$first->id]])
            ->assertCreated();
        $this->confirm(SettlementRequest::first());

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['sale_ids' => [$second->id]])
            ->assertCreated()
            ->assertJsonPath('data.amount', 200_000);

        $this->confirm(SettlementRequest::latest('id')->first());

        $this->assertSame(0, Sale::query()->sellerAccountOutstanding()->count());
    }

    public function test_a_settlement_naming_nothing_still_clears_the_account(): void
    {
        // What an older copy of the app sends, and what the admin's own
        // settle action means.
        $this->sale(300_000);
        $this->sale(200_000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['note' => 'همه'])
            ->assertCreated()
            ->assertJsonPath('data.amount', 500_000);

        $this->confirm(SettlementRequest::first());

        $this->assertSame(0, Sale::query()->sellerAccountOutstanding()->count());
    }

    public function test_a_seller_cannot_settle_another_sellers_sale(): void
    {
        $mine = $this->sale(300_000);

        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('seller');
        $theirs = Sale::create([
            'chane_entry_id' => $mine->chane_entry_id,
            'user_id' => $other->id,
            'bread_count' => 10,
            'payment_type' => 'cash',
            'amount' => 50_000,
            'amount_difference' => 0,
        ]);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['sale_ids' => [$mine->id, $theirs->id]])
            ->assertStatus(422);

        $this->assertNull($theirs->fresh()->cash_settled_on);
    }

    public function test_the_panel_shows_the_admin_it_is_only_part_of_the_account(): void
    {
        $first = $this->sale(300_000);
        $this->sale(200_000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['sale_ids' => [$first->id]])
            ->assertCreated();

        // Confirming reads as "the account is clear" unless the row says
        // otherwise, and the rest of the debt would go unnoticed.
        $this->assertAuthenticatedAs($this->seller, 'sanctum');
        $this->assertSame([$first->id], SettlementRequest::first()->sale_ids);
    }

    public function test_a_sale_already_settled_cannot_be_settled_again(): void
    {
        $sale = $this->sale(300_000);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['sale_ids' => [$sale->id]])
            ->assertCreated();
        $this->confirm(SettlementRequest::first());

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/settlement-requests', ['sale_ids' => [$sale->id]])
            ->assertStatus(422);
    }
}
