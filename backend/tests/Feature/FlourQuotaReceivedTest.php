<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Item 11: the month's flour quota against what was actually delivered.
 * Flour bought in during the quota's Jalali month is the quota received; a
 * purchase undone comes off it, and partner flour is not quota.
 */
class FlourQuotaReceivedTest extends TestCase
{
    use RefreshDatabase;

    public function test_received_and_still_to_receive_are_reported_in_bags(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->travelTo(Carbon::parse('2026-10-31 12:00')); // 1405/08/09

        $quota = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/flour-allocations', [
            'month_start' => '1405/08/01', 'total_bags' => 100,
        ])->assertCreated()->json('data');
        $bag = (float) $quota['bag_weight_kg'];

        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->move('in', 60 * $bag, 'purchase');
        $flour->move('out', 5 * $bag, 'purchase_reversal');
        $flour->move('in', 20 * $bag, 'consignment_in');
        $this->travelTo(Carbon::parse('2026-10-20 12:00')); // the month before (1405/07/28)
        $flour->move('in', 30 * $bag, 'purchase');
        $this->travelTo(Carbon::parse('2026-10-31 12:00'));

        $this->getJson('/api/v1/flour-allocations/current')->assertOk()
            ->assertJsonPath('data.receipt.received_bags', 55)
            ->assertJsonPath('data.receipt.to_receive_bags', 45)
            ->assertJsonPath('data.receipt.received_percent', 55);
    }
}
