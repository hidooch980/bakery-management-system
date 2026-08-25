<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard reported «موجودی کافی ✅» beside «خمیرمایهٔ تر ۰٫۰ کیلوگرم».
 *
 * Sufficiency was read entirely through `low_threshold`, and a null
 * threshold made every quantity sufficient — zero included. Most items in
 * this shop have never had a threshold set, because setting one is a
 * judgement somebody has to sit down and make. Emptiness is not a
 * judgement; the ledger already knows it.
 *
 * It mattered on the day it was found: that was the wet yeast, and the
 * dough had stopped for want of it while the dashboard said all was well.
 */
class EmptyIsNotSufficientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
    }

    private function itemAt(float $balance, ?float $threshold): InventoryItem
    {
        $item = InventoryItem::ofKey(InventoryItem::SALT);
        $item->update(['low_threshold' => $threshold]);

        // Through the ledger, not the column — the balance is derived from
        // movements, and writing it directly would test nothing the app does.
        $current = $item->fresh()->balance;
        if ($balance > $current) {
            $item->move('in', $balance - $current, 'test');
        } elseif ($balance < $current) {
            $item->move('out', $current - $balance, 'test');
        }

        return $item->fresh();
    }

    public function test_empty_with_no_threshold_set_is_not_sufficient(): void
    {
        $item = $this->itemAt(0.0, null);

        $this->assertTrue($item->is_empty);
        $this->assertTrue($item->is_low, 'صفر بدون حد هشدار، «کافی» گزارش می‌شد.');
    }

    public function test_a_stocked_item_with_no_threshold_is_still_sufficient(): void
    {
        // The other half: adding emptiness must not turn every
        // threshold-less item into a standing warning.
        $item = $this->itemAt(40.0, null);

        $this->assertFalse($item->is_empty);
        $this->assertFalse($item->is_low);
    }

    public function test_below_a_threshold_is_low_but_not_empty(): void
    {
        $item = $this->itemAt(3.0, 10.0);

        $this->assertFalse($item->is_empty, 'کم بودن با تمام شدن یکی نیست.');
        $this->assertTrue($item->is_low);
    }

    public function test_above_the_threshold_is_neither(): void
    {
        $item = $this->itemAt(50.0, 10.0);

        $this->assertFalse($item->is_empty);
        $this->assertFalse($item->is_low);
    }
}
