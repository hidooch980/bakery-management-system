<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ConsignmentFlour;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BalanceSheet;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A store full of flour that the balance sheet never mentioned.
 *
 * The sheet counted the bank, what customers owe, what sellers hold, staff
 * advances and the oven — and said nothing at all about the hundred sacks
 * in the store. A bakery's largest ordinary asset was missing from the one
 * page that exists to say what it owns.
 *
 * Valued at what the shop last paid for it, which is a fact on a purchase
 * line rather than a figure anybody guessed. A good that has never been
 * bought through the system has no price on record and is left out of the
 * total — and said so in the note, because silently valuing it at zero is
 * how a full store reads as an empty one.
 *
 * Consignment cuts both ways and the sheet showed neither side. Flour
 * borrowed from a colleague sits in the store and is owed back, so the
 * stock figure counts it as owned when it is not. Flour lent out has left
 * the store entirely, so it vanished from the sheet altogether — value the
 * shop is owed and nothing anywhere said so.
 */
class TheStoreIsOnTheBalanceSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman', 'flour_bag_weight_kg' => 40]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    /** A purchase is what puts both the stock and its price on record. */
    private function buy(string $key, float $kg, float $pricePerKg): void
    {
        // An invoice belongs to a mill. The table says so, and a fixture
        // that skips it is a purchase this shop could never have made.
        $supplier = Supplier::firstOrCreate(['name' => 'آسیاب مرکزی']);

        $purchase = Purchase::create([
            'user_id' => $this->admin->id,
            'supplier_id' => $supplier->id,
            'purchased_on' => now(),
            'total_amount' => $kg * $pricePerKg,
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'inventory_item_id' => InventoryItem::ofKey($key)->id,
            'title' => $key,
            'quantity_kg' => $kg,
            'unit_price' => $pricePerKg,
        ]);
    }

    private function line(string $side, string $key): ?array
    {
        return collect(BalanceSheet::build()[$side])->firstWhere('key', $key);
    }

    public function test_the_store_is_counted_as_an_asset(): void
    {
        $this->buy(InventoryItem::FLOUR, 400, 25_000);

        $stock = $this->line('assets', 'stock');

        $this->assertNotNull($stock, 'a bakery owns its flour');
        $this->assertEqualsWithDelta(400 * 25_000, $stock['amount'], 1);
    }

    public function test_it_is_valued_at_the_most_recent_price(): void
    {
        // Flour bought at two prices is worth what it costs to replace,
        // not an average of what it has ever cost.
        $this->buy(InventoryItem::FLOUR, 100, 20_000);
        $this->buy(InventoryItem::FLOUR, 100, 30_000);

        $this->assertEqualsWithDelta(
            200 * 30_000,
            $this->line('assets', 'stock')['amount'],
            1,
        );
    }

    public function test_flour_baked_since_leaves_the_figure(): void
    {
        $this->buy(InventoryItem::FLOUR, 400, 25_000);

        InventoryItem::ofKey(InventoryItem::FLOUR)
            ->move('out', 100, 'production', $this->admin->id);

        $this->assertEqualsWithDelta(
            300 * 25_000,
            $this->line('assets', 'stock')['amount'],
            1,
        );
    }

    public function test_a_good_never_bought_is_named_rather_than_valued_at_zero(): void
    {
        // Silently pricing it at nothing is how a full store reads as an
        // empty one.
        $this->buy(InventoryItem::FLOUR, 400, 25_000);

        InventoryItem::ofKey(InventoryItem::SALT)
            ->move('in', 50, 'correction', $this->admin->id);

        $stock = $this->line('assets', 'stock');

        $this->assertEqualsWithDelta(400 * 25_000, $stock['amount'], 1);
        $this->assertStringContainsString('نمک', (string) $stock['note']);
    }

    public function test_an_empty_store_shows_no_line_at_all(): void
    {
        $this->assertNull($this->line('assets', 'stock'));
    }

    // ------------------------------------------------------- consignment

    public function test_flour_borrowed_from_a_colleague_is_a_liability(): void
    {
        // It is in the store, so the stock figure counts it. It is owed
        // back, so the sheet must say so or the shop looks richer for
        // holding somebody else's sacks.
        $this->buy(InventoryItem::FLOUR, 400, 25_000);

        ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'partner_name' => 'نانوایی مرکزی',
            'direction' => 'borrowed',
            'bags' => 2,
            'occurred_on' => now(),
        ]);

        $owed = $this->line('liabilities', 'consignment_owed');

        $this->assertNotNull($owed);
        $this->assertEqualsWithDelta(80 * 25_000, $owed['amount'], 1);
    }

    public function test_flour_lent_out_is_still_the_shops(): void
    {
        // It left the store, so it is not in the stock figure — and
        // without this row it was on the sheet nowhere at all.
        $this->buy(InventoryItem::FLOUR, 400, 25_000);

        ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'partner_name' => 'نانوایی مرکزی',
            'direction' => 'lent',
            'bags' => 2,
            'occurred_on' => now(),
        ]);

        $due = $this->line('assets', 'consignment_due');

        $this->assertNotNull($due);
        $this->assertEqualsWithDelta(80 * 25_000, $due['amount'], 1);
    }

    public function test_a_settled_consignment_is_off_the_sheet(): void
    {
        $this->buy(InventoryItem::FLOUR, 400, 25_000);

        ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'partner_name' => 'نانوایی مرکزی',
            'direction' => 'borrowed',
            'bags' => 2,
            'occurred_on' => now(),
            'settled_on' => now(),
        ]);

        $this->assertNull($this->line('liabilities', 'consignment_owed'));
    }

    public function test_the_totals_take_the_new_lines_in(): void
    {
        $this->buy(InventoryItem::FLOUR, 400, 25_000);

        $sheet = BalanceSheet::build();

        $this->assertGreaterThanOrEqual(400 * 25_000, $sheet['asset_total']);
        $this->assertSame(
            round($sheet['asset_total'] - $sheet['liability_total'], 2),
            $sheet['equity'],
        );
    }
}
