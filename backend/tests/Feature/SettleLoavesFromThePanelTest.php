<?php

namespace Tests\Feature;

use App\Filament\Widgets\SellerAccountsTable;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use App\Support\SellerSettlement;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settling a seller by the loaf, from the panel and from the app.
 *
 * The arithmetic lives in one place either way — a count typed on a phone
 * and a count typed at a desk have to clear the same sales, or the two
 * screens will disagree about what is owed.
 */
class SettleLoavesFromThePanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private ChaneEntry $chane;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman', 'bread_price' => 10_000]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 4,
            'status' => 'shaped',
        ]);

        $this->chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => 400,
            'normal_weight_kg' => 340,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);
    }

    private function cashSale(int $loaves): Sale
    {
        return Sale::create([
            'user_id' => $this->seller->id,
            'chane_entry_id' => $this->chane->id,
            'payment_type' => 'cash',
            'bread_count' => $loaves,
            'amount' => $loaves * 10_000,
        ]);
    }

    // ------------------------------------------------------------- api

    public function test_the_admin_settles_a_loaf_count_over_the_api(): void
    {
        $this->cashSale(300);
        $this->cashSale(200);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle-loaves", [
                'loaves' => 300,
            ])
            ->assertOk();

        $this->assertEquals(200, $response->json('data.loaves_left'));
        $this->assertSame(200, SellerSettlement::outstandingFor($this->seller)['loaves']);
    }

    public function test_settling_more_loaves_than_are_owed_is_refused(): void
    {
        $this->cashSale(300);

        // Otherwise the surplus quietly becomes credit on an account that
        // never had it, and the seller appears to be owed money.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle-loaves", [
                'loaves' => 400,
            ])
            ->assertStatus(422);

        $this->assertSame(300, SellerSettlement::outstandingFor($this->seller)['loaves']);
    }

    public function test_settling_a_clear_account_is_refused(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle-loaves", [
                'loaves' => 10,
            ])
            ->assertStatus(422);
    }

    public function test_a_seller_cannot_settle_their_own_account(): void
    {
        $this->cashSale(300);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle-loaves", [
                'loaves' => 300,
            ])
            ->assertForbidden();

        $this->assertSame(300, SellerSettlement::outstandingFor($this->seller)['loaves']);
    }

    public function test_the_sellers_own_account_screen_carries_the_count(): void
    {
        $this->cashSale(250);

        $response = $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/settlement-requests/account')
            ->assertOk();

        $this->assertEquals(250, $response->json('data.loaves'));
        $this->assertEquals(250, $response->json('data.cash_loaves'));
        // In the shop's display unit, like every other figure it sends.
        $this->assertEquals(10_000, $response->json('data.loaf_price'));
    }

    public function test_the_count_is_reported_in_the_shops_display_unit(): void
    {
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->cashSale(250);

        $response = $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/settlement-requests/account')
            ->assertOk();

        // Loaves are loaves in any currency; only the price converts.
        $this->assertEquals(250, $response->json('data.loaves'));
        $this->assertEquals(100_000, $response->json('data.loaf_price'));
    }

    // ----------------------------------------------------------- panel

    public function test_the_panel_settles_a_loaf_count(): void
    {
        $this->cashSale(300);
        $this->cashSale(200);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SellerAccountsTable::class)
            ->callTableAction('settleLoaves', $this->seller, data: ['loaves' => 300]);

        $this->assertSame(200, SellerSettlement::outstandingFor($this->seller)['loaves']);
    }

    public function test_the_panel_action_is_hidden_when_nothing_is_owed(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // The widget only lists sellers with something open, so a clear
        // account has no row to act on in the first place.
        $this->assertSame(0, SellerSettlement::outstandingFor($this->seller)['loaves']);
    }

    public function test_the_panel_and_the_api_clear_the_same_sales(): void
    {
        $first = $this->cashSale(300);
        $this->cashSale(200);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SellerAccountsTable::class)
            ->callTableAction('settleLoaves', $this->seller, data: ['loaves' => 300]);

        // Oldest first, exactly as the API path does it.
        $this->assertNotNull($first->fresh()->cash_settled_on);
    }
}
