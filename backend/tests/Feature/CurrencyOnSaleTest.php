<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use App\Support\SaleRecorder;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop that displays Rial still keeps its books in Toman.
 *
 * The money gap on a seller's account is the difference between what they
 * took and what the bread was worth. Both sides of that subtraction have to
 * be in the same unit — measure one in Rial and the other in Toman and the
 * gap comes out at nine times the sale, which reads as a seller who has
 * pocketed a fortune when they have done nothing wrong.
 */
class CurrencyOnSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'currency' => 'rial',
            'bread_price' => 10_000,      // stored Toman
            'normal_chane_weight_kg' => 0.85,
        ]);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');
    }

    private function batch(int $chaneCount): ChaneEntry
    {
        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 10,
            'status' => 'processed',
        ]);

        return ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => $chaneCount,
            'normal_weight_kg' => 0,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'pending',
        ]);
    }

    public function test_an_amount_in_toman_leaves_no_gap(): void
    {
        $chane = $this->batch(740);

        // What the app sends: the count times the stored Toman price.
        SaleRecorder::record($chane, [[
            'payment_type' => 'card',
            'bread_count' => 740,
            'amount' => 7_400_000,
            'customer_id' => null,
            'note' => null,
        ]], $this->seller->id);

        $sale = Sale::first();

        $this->assertSame('7400000.00', $sale->amount);
        $this->assertSame('0.00', $sale->amount_difference);
    }

    public function test_a_rial_amount_is_taken_for_what_it_is(): void
    {
        $chane = $this->batch(740);

        // The same sale typed in the display unit. Whatever the recorder
        // does with it, the two figures it stores must agree with each
        // other: a gap of exactly nine times the sale is the signature of
        // one side converted and the other not.
        SaleRecorder::record($chane, [[
            'payment_type' => 'card',
            'bread_count' => 740,
            'amount' => Money::toToman(74_000_000),
            'customer_id' => null,
            'note' => null,
        ]], $this->seller->id);

        $sale = Sale::first();

        $this->assertSame('7400000.00', $sale->amount);
        $this->assertSame('0.00', $sale->amount_difference);
    }

    public function test_the_gap_is_real_when_the_money_really_is_short(): void
    {
        $chane = $this->batch(100);

        // 100 loaves worth 1,000,000 Toman, but only 900,000 handed over.
        SaleRecorder::record($chane, [[
            'payment_type' => 'cash',
            'bread_count' => 100,
            'amount' => 900_000,
            'customer_id' => null,
            'note' => null,
        ]], $this->seller->id);

        $sale = Sale::first();

        $this->assertSame('-100000.00', $sale->amount_difference);
    }
}
