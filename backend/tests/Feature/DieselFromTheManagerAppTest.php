<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\DieselAllocation;
use App\Models\DieselDelivery;
use App\Models\DoughEntry;
use App\Models\FlourAllocation;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The diesel quota, and tankers recorded from the manager's phone.
 *
 * The flour quota it derives from has had an API since it was built.
 * Diesel had none, so litres read off a docket had to be carried back to a
 * desk to be entered, and by then the docket was in somebody's pocket.
 */
class DieselFromTheManagerAppTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'currency' => 'toman',
            'flour_bag_weight_kg' => 40,
            'diesel_litres_per_bag' => 6.5,
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('shater');
    }

    /** The month's flour quota, which the diesel quota follows. */
    private function flourQuota(float $bags = 343): FlourAllocation
    {
        $monthStart = Jalali::currentMonthRange()[0];

        return FlourAllocation::create([
            'month_start' => $monthStart,
            'month_label' => Jalali::monthLabel($monthStart),
            'total_bags' => $bags,
        ]);
    }

    /** Sacks into dough — what the consumption estimate rests on. */
    private function mixDough(int $bags): void
    {
        DoughEntry::create([
            'user_id' => $this->admin->id,
            'bag_count' => $bags,
            'status' => 'shaped',
        ]);
    }

    public function test_registering_flour_registers_the_diesel_that_follows_it(): void
    {
        $this->flourQuota(343);

        // 343 sacks at 6.5 litres. Nobody types this: it is not negotiated
        // separately, and left to be created by hand it simply was not —
        // the shop ran a whole month with no diesel quota on file.
        $this->assertSame(1, DieselAllocation::count());
        $this->assertEquals(2230, (float) DieselAllocation::first()->total_litres);
    }

    public function test_a_quota_already_on_file_is_not_restated(): void
    {
        $allocation = $this->flourQuota(343);

        // The depot's own figure, already drawn against.
        DieselAllocation::first()->update(['total_litres' => 2230]);

        $allocation->update(['total_bags' => 350]);

        $this->assertSame(1, DieselAllocation::count());
        $this->assertEquals(2230.0, (float) DieselAllocation::first()->total_litres);
    }

    public function test_a_flour_quota_of_zero_derives_nothing_to_draw(): void
    {
        $this->flourQuota(0);

        $this->assertEquals(0.0, (float) DieselAllocation::first()->total_litres);
    }

    public function test_the_app_reads_this_months_quota(): void
    {
        $this->flourQuota(343);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/diesel/quota')
            ->assertOk();

        $this->assertEquals(2230, $response->json('data.allocation.total_litres'));
        $this->assertEquals(0, $response->json('data.allocation.delivered_litres'));
        $this->assertSame([], $response->json('data.deliveries'));
    }

    public function test_a_month_with_no_quota_says_so_rather_than_guessing(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/diesel/quota')
            ->assertOk()
            ->assertJsonPath('data.allocation', null);
    }

    public function test_a_delivery_comes_off_the_quota(): void
    {
        $this->flourQuota(343);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/diesel/deliveries', [
                'litres' => 500,
                'docket_number' => 'A-1024',
            ])
            ->assertCreated();

        $this->assertEquals(500, $response->json('data.delivery.litres'));
        $this->assertEquals(500, $response->json('data.allocation.delivered_litres'));
        $this->assertEquals(1730, $response->json('data.allocation.remaining_litres'));
        $this->assertNull($response->json('data.warning'));
        $this->assertEquals('A-1024', $response->json('data.delivery.docket_number'));
    }

    public function test_going_over_quota_is_said_when_it_happens(): void
    {
        $this->flourQuota(343);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/diesel/deliveries', ['litres' => 2500])
            ->assertCreated();

        // Worth knowing before the next tanker is ordered, not at the end
        // of the month.
        $this->assertTrue($response->json('data.allocation.is_overdrawn'));
        $this->assertNotNull($response->json('data.warning'));
    }

    public function test_a_quota_delivery_carries_no_invoice(): void
    {
        $this->flourQuota(343);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/diesel/deliveries', ['litres' => 200])
            ->assertCreated();

        $this->assertFalse($response->json('data.delivery.was_paid_for'));
        $this->assertEquals('سهمیه‌ای', $response->json('data.delivery.amount_formatted'));
    }

    public function test_a_paid_delivery_is_priced_in_the_shops_display_unit(): void
    {
        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();
        $this->flourQuota(343);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/diesel/deliveries', [
                'litres' => 200,
                'amount' => 50_000_000, // Rial
            ])
            ->assertCreated();

        // Stored in Toman, a tenth of what was typed.
        $this->assertEquals(5_000_000.0, (float) DieselDelivery::first()->amount);
    }

    public function test_it_records_who_took_the_delivery(): void
    {
        $this->flourQuota(343);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/diesel/deliveries', ['litres' => 100])
            ->assertCreated()
            ->assertJsonPath('data.delivery.recorded_by', $this->admin->name);

        $this->assertSame($this->admin->id, DieselDelivery::first()->user_id);
    }

    public function test_a_mistaken_delivery_can_be_removed(): void
    {
        $this->flourQuota(343);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/diesel/deliveries', ['litres' => 100])
            ->assertCreated();

        $id = DieselDelivery::first()->id;

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/diesel/deliveries/{$id}")
            ->assertOk();

        $this->assertEquals(0, $response->json('data.allocation.delivered_litres'));
        $this->assertSame(0, DieselDelivery::count());
    }

    public function test_a_delivery_can_be_dated(): void
    {
        $this->flourQuota(343);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/diesel/deliveries', [
                'litres' => 100,
                'received_on' => now()->subDay()->toDateString(),
            ])
            ->assertCreated();

        $this->assertEquals(
            now()->subDay()->toDateString(),
            DieselDelivery::first()->received_on->toDateString(),
        );
    }

    public function test_an_unreadable_date_is_refused_rather_than_guessed(): void
    {
        $this->flourQuota(343);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/diesel/deliveries', [
                'litres' => 100,
                'received_on' => 'یک روز قبل',
            ])
            ->assertStatus(422);

        $this->assertSame(0, DieselDelivery::count());
    }

    public function test_the_month_keeps_the_rate_it_was_given(): void
    {
        $this->flourQuota(343);

        // A quota of 2,229.5 litres cannot otherwise say whether it came
        // from 6.5 a sack or from 7 and a smaller flour quota.
        $this->assertEquals(6.5, (float) DieselAllocation::first()->litres_per_bag);
    }

    public function test_the_app_is_told_how_the_figure_was_arrived_at(): void
    {
        $this->flourQuota(343);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/diesel/quota')
            ->assertOk();

        $this->assertEquals(6.5, $response->json('data.allocation.litres_per_bag'));
        $this->assertStringContainsString(
            '343',
            $response->json('data.allocation.derivation_label'),
        );
    }

    public function test_a_new_rate_recomputes_this_months_litres(): void
    {
        $this->flourQuota(343);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/diesel/quota', ['litres_per_bag' => 7])
            ->assertOk();

        $this->assertEquals(2401, $response->json('data.allocation.total_litres'));
        $this->assertEquals(7, $response->json('data.allocation.litres_per_bag'));
    }

    public function test_a_new_rate_becomes_the_default_for_next_month(): void
    {
        $this->flourQuota(343);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/diesel/quota', ['litres_per_bag' => 7])
            ->assertOk();

        // A rate told to us once should not have to be told again.
        $this->assertEquals(7.0, DieselAllocation::rateInForce());
    }

    public function test_the_depots_own_figure_beats_the_arithmetic(): void
    {
        $this->flourQuota(343);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/diesel/quota', [
                'litres_per_bag' => 6.5,
                'total_litres' => 2230,
            ])
            ->assertOk();

        // 343 at 6.5 works out at 2,229.5; the docket says 2,230, and the
        // litres the shop can actually draw are the ones on the docket.
        $this->assertEquals(2230, $response->json('data.allocation.total_litres'));
        $this->assertEquals(6.5, $response->json('data.allocation.litres_per_bag'));
    }

    public function test_amending_a_month_with_no_quota_says_so(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/diesel/quota', ['litres_per_bag' => 7])
            ->assertStatus(404);
    }

    public function test_staff_cannot_change_the_rate(): void
    {
        $this->flourQuota(343);

        $this->actingAs($this->baker, 'sanctum')
            ->patchJson('/api/v1/diesel/quota', ['litres_per_bag' => 99])
            ->assertForbidden();

        $this->assertEquals(6.5, (float) DieselAllocation::first()->litres_per_bag);
    }

    public function test_baking_burns_the_same_rate_the_quota_is_built_on(): void
    {
        $this->flourQuota(343);
        $this->mixDough(10);

        // The depot allows 6.5 a sack because a sack takes 6.5 to bake.
        $quota = DieselAllocation::current();

        $this->assertEquals(10.0, $quota->bags_baked);
        $this->assertEquals(65.0, $quota->consumed_litres);
    }

    public function test_the_tank_is_what_arrived_less_what_was_burned(): void
    {
        $this->flourQuota(343);
        $this->mixDough(10); // 65 litres burned

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/diesel/deliveries', ['litres' => 200])
            ->assertCreated();

        $quota = DieselAllocation::current();

        $this->assertEquals(135.0, $quota->in_tank_litres);
        $this->assertFalse($quota->is_tank_empty);
    }

    public function test_quota_left_and_fuel_left_are_different_questions(): void
    {
        $this->flourQuota(343);
        $this->mixDough(40); // 260 litres burned

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/diesel/deliveries', ['litres' => 200])
            ->assertCreated();

        $quota = DieselAllocation::current();

        // Plenty of quota left to draw, and nothing in the tank to bake
        // with. Only one of the two stops the oven.
        $this->assertEquals(2030.0, $quota->remaining_litres);
        $this->assertEquals(-60.0, $quota->in_tank_litres);
        $this->assertTrue($quota->is_tank_empty);
    }

    public function test_the_app_is_told_both_figures(): void
    {
        $this->flourQuota(343);
        $this->mixDough(10);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/diesel/deliveries', ['litres' => 200])
            ->assertCreated();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/diesel/quota')
            ->assertOk();

        $this->assertEquals(65, $response->json('data.allocation.consumed_litres'));
        $this->assertEquals(10, $response->json('data.allocation.bags_baked'));
        $this->assertEquals(135, $response->json('data.allocation.in_tank_litres'));
        $this->assertEquals(2030, $response->json('data.allocation.remaining_litres'));
    }

    public function test_a_month_with_no_baking_burns_nothing(): void
    {
        $this->flourQuota(343);

        $this->assertEquals(0.0, DieselAllocation::current()->consumed_litres);
    }

    public function test_a_changed_rate_moves_the_estimate_with_it(): void
    {
        $this->flourQuota(343);
        $this->mixDough(10);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/diesel/quota', ['litres_per_bag' => 7])
            ->assertOk();

        // The month's own rate drives the estimate, not the setting.
        $this->assertEquals(70.0, DieselAllocation::current()->consumed_litres);
    }

    // ------------------------------- the period is the 5th to the 4th

    public function test_the_quota_period_runs_from_the_fifth(): void
    {
        $allocation = $this->flourQuota(343);
        $quota = DieselAllocation::current();

        [$from, $to] = $quota->quotaRange();

        // Not the calendar month: the mill issues flour against a period
        // that starts on the 5th and the depot follows it.
        $this->assertSame('05', Jalali::format($from, 'd'));
        $this->assertSame('04', Jalali::format($to, 'd'));
        $this->assertTrue($from->gt($allocation->month_start));
    }

    public function test_a_delivery_before_the_period_belongs_to_the_last_one(): void
    {
        $this->flourQuota(343);
        $quota = DieselAllocation::current();
        [$from] = $quota->quotaRange();

        DieselDelivery::create([
            'user_id' => $this->admin->id,
            'received_on' => $from->copy()->subDay(),
            'litres' => 500,
        ]);

        // The 4th is the last day of the period before. Counting it here
        // would charge one period for fuel drawn against another.
        $this->assertEquals(0.0, DieselAllocation::current()->delivered_litres);
    }

    public function test_a_delivery_on_the_first_day_of_the_period_counts(): void
    {
        $this->flourQuota(343);
        [$from] = DieselAllocation::current()->quotaRange();

        DieselDelivery::create([
            'user_id' => $this->admin->id,
            'received_on' => $from,
            'litres' => 500,
        ]);

        $this->assertEquals(500.0, DieselAllocation::current()->delivered_litres);
    }

    public function test_baking_before_the_period_is_not_charged_to_it(): void
    {
        $this->flourQuota(343);
        [$from] = DieselAllocation::current()->quotaRange();

        DoughEntry::create([
            'user_id' => $this->admin->id,
            'bag_count' => 10,
            'status' => 'shaped',
        ])->forceFill(['created_at' => $from->copy()->subDay()])->save();

        $this->assertEquals(0.0, DieselAllocation::current()->consumed_litres);
    }

    public function test_the_month_is_split_across_the_three_flour_periods(): void
    {
        $this->flourQuota(343)->syncPeriods();

        $periods = DieselAllocation::current()->periods();

        // The fuel is issued against flour that arrives in three lots, so
        // it belongs to those same three periods.
        $this->assertCount(3, $periods);
        $this->assertSame(1, $periods[0]['period_number']);
        $this->assertSame(3, $periods[2]['period_number']);

        // Roughly a third of the month's litres each, and they come to the
        // month's own figure rather than drifting from it.
        $this->assertEqualsWithDelta(
            2230,
            array_sum(array_column($periods, 'litres')),
            3,
        );
    }

    public function test_exactly_one_period_is_the_current_one(): void
    {
        $this->flourQuota(343)->syncPeriods();

        $current = array_filter(
            DieselAllocation::current()->periods(),
            fn (array $p) => $p['is_current'],
        );

        $this->assertCount(1, $current);
    }

    public function test_a_delivery_lands_in_the_period_it_was_drawn_in(): void
    {
        $this->flourQuota(343)->syncPeriods();
        $quota = DieselAllocation::current();
        [$from] = $quota->quotaRange();

        DieselDelivery::create([
            'user_id' => $this->admin->id,
            'received_on' => $from,
            'litres' => 700,
        ]);

        $periods = DieselAllocation::current()->periods();

        $this->assertEquals(700, $periods[0]['delivered_litres']);
        $this->assertEquals(0, $periods[1]['delivered_litres']);
    }

    public function test_staff_cannot_record_a_delivery(): void
    {
        $this->flourQuota(343);

        $this->actingAs($this->baker, 'sanctum')
            ->postJson('/api/v1/diesel/deliveries', ['litres' => 100])
            ->assertForbidden();

        $this->assertSame(0, DieselDelivery::count());
    }

    public function test_staff_cannot_read_the_quota(): void
    {
        $this->actingAs($this->baker, 'sanctum')
            ->getJson('/api/v1/diesel/quota')
            ->assertForbidden();
    }
}
