<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Money;
use App\Support\SaleRecorder;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «کارکنان نان اگه بدون پول بردن، در فیش حقوقشان پایان ماه حساب بشه و کسر
 * بشه» — the owner, 1405/06/10. «فروشنده انتخاب می‌کنه».
 *
 * Bread that leaves without money was already recorded as «منزل» and
 * charged to nobody. It stays off the seller's account — that part was
 * always right, and reading `sales.user_id` as the consumer is exactly the
 * mistake that nearly charged one employee another's shortfall. What is
 * new is that the seller names who took it, and that person's payslip
 * settles it.
 */
class BreadTakenHomeIsSettledAtMonthEndTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'water_ratio' => 0.6,
            'salt_ratio' => 0.015,
            'dough_loss_ratio' => 0,
            'proof_gain_ratio' => 0,
            'normal_chane_weight_kg' => 0.85,
            'bread_price' => 10000,
            'currency' => 'rial',
        ]);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->worker = User::factory()->create([
            'is_active' => true,
            'monthly_salary' => 5000000,
        ]);
        $this->worker->assignRole('shater');
    }

    /** A real batch, through the same path the shop uses. */
    private function batch(int $count = 100): ChaneEntry
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 5000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 200, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 200, 'purchase');

        $dough = User::factory()->create(['is_active' => true]);
        $dough->assignRole('dough_maker');

        $chaneGir = User::factory()->create(['is_active' => true]);
        $chaneGir->assignRole('chane_gir');

        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 30]);

        $this->actingAs($chaneGir, 'sanctum')->postJson('/api/v1/chane-entries', [
            'dough_entry_id' => DoughEntry::latest('id')->first()->id,
            'chane_count' => $count,
            'spray_flour_kg' => 0,
        ]);

        return ChaneEntry::latest('id')->first();
    }

    /** Sells a whole batch home to the worker, and returns the sale. */
    private function tookHome(int $loaves): Sale
    {
        $sales = SaleRecorder::record(
            $this->batch($loaves),
            [[
                'payment_type' => 'home',
                'bread_count' => $loaves,
                'amount' => null,
                'customer_id' => null,
                'consumed_by_user_id' => $this->worker->id,
                'note' => null,
            ]],
            $this->seller->id,
        );

        return $sales[0];
    }

    private function payslip(float $base = 5000000): SalaryPayment
    {
        return SalaryPayment::create([
            'user_id' => $this->worker->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'base_amount' => $base,
            'bonus' => 0,
            'deduction' => 0,
        ]);
    }

    public function test_the_bread_is_charged_to_the_worker_not_the_seller(): void
    {
        $sale = $this->tookHome(12);

        $this->assertSame($this->worker->id, $sale->consumed_by_user_id);
        // The seller still recorded it, and is still not charged for it.
        $this->assertSame($this->seller->id, $sale->user_id);
        $this->assertEqualsWithDelta(120000, (float) $sale->consumed_amount, 0.01);
    }

    public function test_it_stays_off_the_sellers_account(): void
    {
        $this->tookHome(12);

        // The whole point of «منزل» being a giveaway. Charging the seller
        // for bread they handed to somebody else is the bug this replaces,
        // not the one it introduces.
        $this->assertSame(
            0,
            Sale::sellerAccountOutstanding()->where('user_id', $this->seller->id)->count(),
        );
    }

    public function test_the_price_is_frozen_when_the_bread_goes(): void
    {
        $sale = $this->tookHome(10);

        Bakery::first()->update(['bread_price' => 25000]);

        // Still a hundred thousand. A later price rise must not rewrite
        // what somebody already owed — the same rule a shortfall follows.
        $this->assertEqualsWithDelta(100000, (float) $sale->fresh()->consumed_amount, 0.01);
    }

    public function test_the_payslip_deducts_it(): void
    {
        $this->tookHome(30); // 300,000

        $payslip = $this->payslip();

        $this->assertEqualsWithDelta(300000, (float) $payslip->bread_deduction, 0.01);
        $this->assertEqualsWithDelta(4700000, (float) $payslip->net_amount, 0.01);
    }

    public function test_a_second_payslip_does_not_charge_it_again(): void
    {
        $this->tookHome(30);

        $this->payslip();

        $next = SalaryPayment::create([
            'user_id' => $this->worker->id,
            'period_start' => now()->addMonth()->startOfMonth()->toDateString(),
            'base_amount' => 5000000,
            'bonus' => 0,
            'deduction' => 0,
        ]);

        $this->assertEqualsWithDelta(0, (float) $next->bread_deduction, 0.01);
        $this->assertEqualsWithDelta(5000000, (float) $next->net_amount, 0.01);
    }

    public function test_re_saving_the_same_payslip_does_not_double_it(): void
    {
        $this->tookHome(30);

        $payslip = $this->payslip();
        $payslip->update(['note' => 'دست‌نخورده']);

        // The guard that `advanceToRecover` needs, for the same reason:
        // this payslip's own recoveries are set aside before the sum.
        $this->assertEqualsWithDelta(300000, (float) $payslip->fresh()->bread_deduction, 0.01);
        $this->assertEqualsWithDelta(4700000, (float) $payslip->fresh()->net_amount, 0.01);
    }

    public function test_deleting_the_payslip_hands_the_bread_back(): void
    {
        $this->tookHome(30);

        $payslip = $this->payslip();
        $payslip->delete();

        $this->assertEqualsWithDelta(
            300000,
            Sale::staffBreadOutstandingFor($this->worker->id),
            0.01,
        );
    }

    public function test_more_bread_than_the_pay_waits_for_next_month(): void
    {
        // 800 loaves at 10,000 is 8,000,000 against a 5,000,000 wage.
        $this->tookHome(800);

        $payslip = $this->payslip();

        // Never a negative payslip. What fits is taken; the rest waits.
        $this->assertEqualsWithDelta(5000000, (float) $payslip->bread_deduction, 0.01);
        $this->assertEqualsWithDelta(0, (float) $payslip->net_amount, 0.01);

        $this->assertEqualsWithDelta(
            3000000,
            Sale::staffBreadOutstandingFor($this->worker->id),
            0.01,
        );
    }

    public function test_the_advance_is_recovered_before_the_bread(): void
    {
        StaffAdvance::create([
            'user_id' => $this->worker->id,
            'amount' => 4000000,
            'paid_on' => now()->subDays(3)->toDateString(),
        ]);

        $this->tookHome(300); // 3,000,000

        $payslip = $this->payslip();

        // Money already handed over comes back first; the bread takes what
        // the advance left, and the rest of it waits.
        $this->assertEqualsWithDelta(4000000, (float) $payslip->advance_deduction, 0.01);
        $this->assertEqualsWithDelta(1000000, (float) $payslip->bread_deduction, 0.01);
        $this->assertEqualsWithDelta(0, (float) $payslip->net_amount, 0.01);
    }

    public function test_charity_is_owed_by_nobody(): void
    {
        SaleRecorder::record(
            $this->batch(20),
            [[
                'payment_type' => 'charity',
                'bread_count' => 20,
                'amount' => null,
                'customer_id' => null,
                // Even if a client sends one, a gift is not a debt.
                'consumed_by_user_id' => $this->worker->id,
                'note' => null,
            ]],
            $this->seller->id,
        );

        $this->assertEqualsWithDelta(
            0,
            Sale::staffBreadOutstandingFor($this->worker->id),
            0.01,
        );
    }

    public function test_home_bread_with_nobody_named_is_still_owed_by_nobody(): void
    {
        // The shop's older rows, and anyone who does not name a person.
        SaleRecorder::record(
            $this->batch(20),
            [[
                'payment_type' => 'home',
                'bread_count' => 20,
                'amount' => null,
                'customer_id' => null,
                'consumed_by_user_id' => null,
                'note' => null,
            ]],
            $this->seller->id,
        );

        $payslip = $this->payslip();

        $this->assertEqualsWithDelta(0, (float) $payslip->bread_deduction, 0.01);
    }

    public function test_the_seller_names_the_worker_through_the_api(): void
    {
        $chane = $this->batch(40);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payments' => [
                    ['payment_type' => 'cash', 'bread_count' => 30, 'amount' => 300000],
                    [
                        'payment_type' => 'home',
                        'bread_count' => 10,
                        'consumed_by_user_id' => $this->worker->id,
                    ],
                ],
            ])
            ->assertCreated();

        $this->assertEqualsWithDelta(
            100000,
            Sale::staffBreadOutstandingFor($this->worker->id),
            0.01,
        );
    }
}
