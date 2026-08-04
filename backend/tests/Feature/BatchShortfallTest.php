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
 * A batch sold across more than one transaction.
 *
 * The shortfall is worked out for the shop rather than typed in, so it has
 * to hold however the batch is sold — in one go at the end of the morning,
 * or a few loaves at a time all day.
 */
class BatchShortfallTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private ChaneEntry $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::query()->first()->update(['bread_price' => 1000, 'currency' => 'toman']);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $chaneGir = User::factory()->create(['is_active' => true]);
        $chaneGir->assignRole('chane_gir');

        $dough = DoughEntry::create(['user_id' => $chaneGir->id, 'bag_count' => 2]);

        $this->batch = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $chaneGir->id,
            'chane_count' => 100,
            'normal_weight_kg' => 43,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);
    }

    /**
     * Records a sale the way the panel does — straight through the shared
     * recorder. The API refuses a second sale of the same batch outright;
     * the panel does not, and that is the path a batch has actually been
     * sold twice down.
     */
    private function sell(int $breadCount): array
    {
        return SaleRecorder::record($this->batch->fresh(), [[
            'payment_type' => 'cash',
            'bread_count' => $breadCount,
            'amount' => $breadCount * 1000.0,
            'customer_id' => null,
            'note' => null,
        ]], $this->seller->id);
    }

    private function refusalFor(int $breadCount): ?string
    {
        return SaleRecorder::problemWith($this->batch->fresh(), [[
            'payment_type' => 'cash',
            'bread_count' => $breadCount,
            'amount' => $breadCount * 1000.0,
            'customer_id' => null,
            'note' => null,
        ]]);
    }

    private function totalShortfall(): int
    {
        return (int) Sale::where('chane_entry_id', $this->batch->id)->sum('shortfall_count');
    }

    public function test_a_batch_sold_in_one_go_owes_only_what_was_left(): void
    {
        $this->sell(80);

        // Twenty loaves of the hundred never sold.
        $this->assertSame(20, $this->totalShortfall());
    }

    public function test_a_batch_sold_in_two_goes_owes_nothing(): void
    {
        $this->sell(60);
        $this->sell(40);

        // The whole batch sold, just not all at once. Charging the seller
        // for the forty that had not sold yet at midday, and then again for
        // the sixty at the second sale, would invent a debt of a hundred
        // loaves out of a batch that sold out.
        $this->assertSame(0, $this->totalShortfall());
    }

    public function test_a_batch_part_sold_twice_owes_only_the_remainder(): void
    {
        $this->sell(50);
        $this->sell(30);

        $this->assertSame(20, $this->totalShortfall());
    }

    public function test_the_batch_cannot_be_oversold_across_transactions(): void
    {
        $this->sell(60);

        // Sixty and sixty is a hundred and twenty loaves out of a hundred
        // chane. Checking each sale against the batch on its own let this
        // through.
        $this->assertNotNull($this->refusalFor(60));
        $this->assertStringContainsString('باقی‌مانده', $this->refusalFor(60));

        // And forty still fits.
        $this->assertNull($this->refusalFor(40));
    }

    public function test_a_settled_shortfall_is_left_alone(): void
    {
        $this->sell(60);

        // The admin settled the forty before the rest of the batch sold.
        Sale::where('chane_entry_id', $this->batch->id)
            ->whereNotNull('shortfall_count')
            ->update(['shortfall_settled_on' => now()]);

        $this->sell(40);

        // Money that already changed hands is not rewritten by a later
        // sale; the record stands and the admin can see both.
        $this->assertSame(40, $this->totalShortfall());
    }
}
