<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\SellerSettlementRecord;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «اصلاح تسویهٔ اشتباه» and «سابقهٔ تسویه‌های فروشنده».
 *
 * Neither had an answer, for the same reason: a handover left no record
 * of itself. A seller's own request left a row, but the owner settling
 * somebody at the counter — the common case, and the one people argue
 * about — left nothing but a bank movement with a note on it.
 *
 * So there was no list to show, and nothing to put back: the only remedy
 * for a settlement against the wrong seller was the panel's sales table
 * and a guess about which rows to reopen.
 */
class AWrongSettlementCanBePutBackTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private BankAccount $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['bread_price' => 5000, 'currency' => 'toman']);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->till = BankAccount::create([
            'title' => 'صندوق',
            'is_cash_box' => true,
            'is_active' => true,
        ]);
    }

    public function test_settling_writes_down_what_it_closed(): void
    {
        $sale = $this->sell(100, 500_000);

        $this->settle(500_000);

        $record = SellerSettlementRecord::first();

        $this->assertNotNull($record);
        $this->assertSame($this->seller->id, $record->user_id);
        $this->assertSame($this->admin->id, $record->settled_by);
        $this->assertSame('settlement', $record->kind);
        $this->assertEqualsWithDelta(500_000.0, (float) $record->paid_cash, 0.01);

        // The ids, not «the sales that look settled around then» — a
        // second handover the same afternoon would be caught by that.
        $this->assertSame([$sale->id], $record->sale_ids);
    }

    public function test_the_history_lists_the_handovers(): void
    {
        $this->sell(100, 500_000);
        $this->settle(500_000);

        $this->sell(50, 250_000);
        $this->settle(250_000);

        $rows = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/seller-accounts/{$this->seller->id}/history")
            ->assertOk()
            ->json('data.data');

        $this->assertCount(2, $rows);
        $this->assertSame($this->admin->name, $rows[0]['settled_by_name']);
        $this->assertFalse($rows[0]['is_reversed']);
    }

    public function test_putting_a_settlement_back_reopens_the_debt(): void
    {
        $sale = $this->sell(100, 500_000);
        $this->settle(500_000);

        $this->assertNotNull($sale->fresh()->cash_settled_on);

        $record = SellerSettlementRecord::first();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-settlements/{$record->id}/reverse", [
                'reason' => 'به اشتباه پای این فروشنده زده شد',
            ])
            ->assertOk();

        $this->assertNull($sale->fresh()->cash_settled_on);
    }

    public function test_the_money_goes_back_out_of_the_drawer_it_went_into(): void
    {
        $this->sell(100, 500_000);
        $this->settle(500_000);

        $this->assertEqualsWithDelta(500_000.0, (float) $this->till->fresh()->balance, 0.01);

        $record = SellerSettlementRecord::first();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-settlements/{$record->id}/reverse", ['reason' => 'اشتباه'])
            ->assertOk();

        // Otherwise the debt is open again *and* the shop still counts
        // the money — the seller owes it twice.
        $this->assertEqualsWithDelta(0.0, (float) $this->till->fresh()->balance, 0.01);
    }

    public function test_a_reversal_is_kept_rather_than_the_row_deleted(): void
    {
        $this->sell(100, 500_000);
        $this->settle(500_000);

        $record = SellerSettlementRecord::first();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-settlements/{$record->id}/reverse", [
                'reason' => 'فروشندهٔ اشتباه',
            ])
            ->assertOk();

        $row = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/seller-accounts/{$this->seller->id}/history")
            ->assertOk()
            ->json('data.data.0');

        // A history that quietly drops what was undone tells a tidier
        // story than the truth.
        $this->assertTrue($row['is_reversed']);
        $this->assertSame('فروشندهٔ اشتباه', $row['reversal_reason']);
        $this->assertSame($this->admin->name, $row['reversed_by_name']);
    }

    public function test_the_same_settlement_is_not_put_back_twice(): void
    {
        $this->sell(100, 500_000);
        $this->settle(500_000);

        $record = SellerSettlementRecord::first();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-settlements/{$record->id}/reverse", ['reason' => 'یک'])
            ->assertOk();

        // Two admins a moment apart would take the money out twice, and
        // the drawer would end up short by the whole handover.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-settlements/{$record->id}/reverse", ['reason' => 'دو'])
            ->assertStatus(409);

        $this->assertEqualsWithDelta(0.0, (float) $this->till->fresh()->balance, 0.01);
    }

    public function test_a_correction_without_a_reason_is_refused(): void
    {
        $this->sell(100, 500_000);
        $this->settle(500_000);

        $record = SellerSettlementRecord::first();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-settlements/{$record->id}/reverse", [])
            ->assertStatus(422);
    }

    public function test_a_seller_cannot_put_their_own_settlement_back(): void
    {
        $this->sell(100, 500_000);
        $this->settle(500_000);

        $record = SellerSettlementRecord::first();

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/seller-settlements/{$record->id}/reverse", ['reason' => 'نه'])
            ->assertForbidden();
    }

    // ---------------------------------------------------------- helpers

    private function settle(float $cash): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [
                'paid_cash' => $cash,
            ])
            ->assertOk();
    }

    private function sell(int $breadCount, float $amount): Sale
    {
        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 1,
            'status' => 'processed',
        ]);

        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => $breadCount,
            'normal_weight_kg' => 0,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);

        return Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $this->seller->id,
            'payment_type' => 'cash',
            'bread_count' => $breadCount,
            'amount' => $amount,
        ]);
    }
}
