<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Money;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two findings on the owner's «امروز» page that nothing had ever made
 * fire.
 *
 * The error detector was the same shape and could not fire at all: it
 * opened a filename this server does not use, returned nothing, and its
 * seven tests all passed because each one created that filename itself.
 * Silence from a detector is read as good news, so an untested one is a
 * claim nobody has checked.
 *
 * These two are reachable — checked, not assumed — and now there is
 * something that says so.
 */
class TwoDetectorsNobodyHadFiredTest extends TestCase
{
    use RefreshDatabase;

    private User $chaneGir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::query()->first()->update(['bread_price' => 1000, 'currency' => 'toman']);
        Money::forgetCache();

        $this->chaneGir = User::factory()->create(['is_active' => true]);
        $this->chaneGir->assignRole('chane_gir');
    }

    private function issue(string $key): ?SystemIssue
    {
        return (new IssueScanner)->scan()->firstWhere('key', $key);
    }

    private function batch(int $chaneCount = 100): ChaneEntry
    {
        $dough = DoughEntry::create([
            'user_id' => $this->chaneGir->id,
            'bag_count' => 2,
        ]);

        return ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->chaneGir->id,
            'chane_count' => $chaneCount,
            'normal_weight_kg' => 43,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);
    }

    public function test_chane_waiting_since_yesterday_is_reported(): void
    {
        // Chane is perishable. A batch still «در انتظار فروش» a day later
        // usually means the sale went unrecorded, which makes the day's
        // takings look smaller than they were.
        $batch = $this->batch();
        $batch->forceFill(['created_at' => now()->subDays(2)])->save();

        $issue = $this->issue('stale-chane');

        $this->assertNotNull($issue, 'چانهٔ مانده گزارش نشد.');
        $this->assertSame(1.0, $issue->magnitude);
    }

    public function test_chane_from_this_morning_is_not_reported(): void
    {
        // A batch shaped an hour ago is the ordinary state of the shop.
        $this->batch();

        $this->assertNull($this->issue('stale-chane'));
    }

    public function test_chane_that_was_sold_is_not_reported(): void
    {
        $batch = $this->batch();
        $batch->forceFill([
            'created_at' => now()->subDays(2),
            'status' => 'sold',
        ])->save();

        $this->assertNull($this->issue('stale-chane'));
    }

    public function test_an_unsettled_shortfall_is_reported(): void
    {
        $sale = Sale::create([
            'user_id' => $this->chaneGir->id,
            'chane_entry_id' => $this->batch()->id,
            'bread_count' => 90,
            'payment_type' => 'cash',
            'sold_on' => now()->toDateString(),
            'shortfall_count' => 10,
            'shortfall_amount' => 10_000,
        ]);

        $issue = $this->issue('unsettled-shortfalls');

        $this->assertNotNull($issue, 'کسری تسویه‌نشده گزارش نشد.');
        $this->assertSame(10.0, $issue->magnitude);
        $this->assertNotNull($sale->id);
    }

    public function test_a_settled_shortfall_is_not_reported(): void
    {
        // Settling is the answer to it. Once answered it is history, and
        // an issue that stays after it is dealt with teaches people to
        // stop reading the page.
        Sale::create([
            'user_id' => $this->chaneGir->id,
            'chane_entry_id' => $this->batch()->id,
            'bread_count' => 90,
            'payment_type' => 'cash',
            'sold_on' => now()->toDateString(),
            'shortfall_count' => 10,
            'shortfall_amount' => 10_000,
            'shortfall_settled_on' => now()->toDateString(),
        ]);

        $this->assertNull($this->issue('unsettled-shortfalls'));
    }

    public function test_a_sale_with_no_shortfall_is_not_reported(): void
    {
        Sale::create([
            'user_id' => $this->chaneGir->id,
            'chane_entry_id' => $this->batch()->id,
            'bread_count' => 100,
            'payment_type' => 'cash',
            'sold_on' => now()->toDateString(),
        ]);

        $this->assertNull($this->issue('unsettled-shortfalls'));
    }
}
