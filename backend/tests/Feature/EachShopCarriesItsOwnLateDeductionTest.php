<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\SalaryPayment;
use App\Models\StaffAdjustment;
use App\Models\User;
use App\Models\WorkStart;
use App\Support\CurrentBakery;
use App\Support\LateDeduction;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A person who works at two shops owes each of them separately.
 *
 * The tariff summed a month's late days across every shop and wrote one
 * deduction, taking its shop from whichever record happened to be first.
 *
 * Proved by running it: 100,000 late at one shop and 250,000 at another
 * became a single 350,000 deduction on the first shop's payslip, with
 * nothing at all on the second's. One shop paid a quarter of a million of
 * another shop's money and the other's books said the person was never
 * late.
 *
 * Harmless while a shop is the only shop — which is why it survived. The
 * comment above the query said «a person works at one shop», and that was
 * true until the day branches became separate bakeries.
 */
class EachShopCarriesItsOwnLateDeductionTest extends TestCase
{
    use RefreshDatabase;

    private Bakery $alef;

    private Bakery $beh;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->alef = Bakery::create(['name' => 'نانوایی الف', 'currency' => 'toman']);
        $this->beh = Bakery::create(['name' => 'نانوایی ب', 'currency' => 'toman']);
        Money::forgetCache();

        CurrentBakery::actAs($this->alef->id);

        $this->baker = User::factory()->create([
            'is_active' => true,
            'bakery_id' => $this->alef->id,
        ]);
        $this->baker->assignRole('shater');
    }

    private function lateAt(Bakery $shop, int $dayOfMonth, float $penalty): WorkStart
    {
        $day = now()->startOfMonth()->addDays($dayOfMonth)->toDateString();

        return WorkStart::forceCreate([
            'bakery_id' => $shop->id,
            'user_id' => $this->baker->id,
            'type' => WorkStart::CHANE,
            'date' => $day,
            'started_at' => $day.' 08:30:00',
            'is_late' => true,
            'penalty_amount' => $penalty,
        ]);
    }

    private function deductionAt(Bakery $shop): ?StaffAdjustment
    {
        return StaffAdjustment::acrossBakeries()
            ->where('user_id', $this->baker->id)
            ->where('source', LateDeduction::SOURCE)
            ->where('bakery_id', $shop->id)
            ->first();
    }

    public function test_each_shop_is_charged_only_what_happened_there(): void
    {
        $this->lateAt($this->alef, 3, 100_000);
        $this->lateAt($this->beh, 4, 250_000);

        LateDeduction::sync($this->baker->id, now());

        $this->assertEqualsWithDelta(100_000, (float) $this->deductionAt($this->alef)?->amount, 0.01);
        $this->assertEqualsWithDelta(250_000, (float) $this->deductionAt($this->beh)?->amount, 0.01);
    }

    public function test_there_is_one_row_per_shop_not_one_for_the_month(): void
    {
        $this->lateAt($this->alef, 3, 100_000);
        $this->lateAt($this->beh, 4, 250_000);

        LateDeduction::sync($this->baker->id, now());

        $this->assertSame(2, StaffAdjustment::acrossBakeries()
            ->where('source', LateDeduction::SOURCE)
            ->count());
    }

    public function test_several_late_days_at_one_shop_still_add_up(): void
    {
        // Splitting by shop must not split by day as well.
        $this->lateAt($this->alef, 3, 100_000);
        $this->lateAt($this->alef, 5, 40_000);
        $this->lateAt($this->beh, 4, 250_000);

        LateDeduction::sync($this->baker->id, now());

        $this->assertEqualsWithDelta(140_000, (float) $this->deductionAt($this->alef)?->amount, 0.01);
        $this->assertEqualsWithDelta(250_000, (float) $this->deductionAt($this->beh)?->amount, 0.01);
    }

    public function test_a_shop_whose_lateness_is_undone_has_its_row_cleared(): void
    {
        // Walking the late days alone would never reach a shop that has
        // none left, and the stale row would sit on its payslip.
        $atAlef = $this->lateAt($this->alef, 3, 100_000);
        $this->lateAt($this->beh, 4, 250_000);

        LateDeduction::sync($this->baker->id, now());
        $this->assertNotNull($this->deductionAt($this->alef));

        $atAlef->update(['is_late' => false, 'penalty_amount' => 0]);
        LateDeduction::sync($this->baker->id, now());

        $this->assertNull($this->deductionAt($this->alef));
        $this->assertEqualsWithDelta(250_000, (float) $this->deductionAt($this->beh)?->amount, 0.01);
    }

    public function test_a_settled_shop_is_left_alone_while_the_other_updates(): void
    {
        // A payslip already handed over is history at that shop — and the
        // other shop's month must still be free to move.
        $this->lateAt($this->alef, 3, 100_000);
        $this->lateAt($this->beh, 4, 250_000);
        LateDeduction::sync($this->baker->id, now());

        $slip = SalaryPayment::forceCreate([
            'bakery_id' => $this->alef->id,
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'base_amount' => 10_000_000,
            'net_amount' => 10_000_000,
        ]);

        $this->deductionAt($this->alef)->update(['salary_payment_id' => $slip->id]);

        $this->lateAt($this->alef, 6, 999_000);
        $this->lateAt($this->beh, 7, 50_000);
        LateDeduction::sync($this->baker->id, now());

        $this->assertEqualsWithDelta(100_000, (float) $this->deductionAt($this->alef)?->amount, 0.01);
        $this->assertEqualsWithDelta(300_000, (float) $this->deductionAt($this->beh)?->amount, 0.01);
    }

    public function test_one_shop_behaves_exactly_as_before(): void
    {
        // Every existing install has one bakery. The split must be
        // invisible to them.
        $this->lateAt($this->alef, 3, 100_000);
        $this->lateAt($this->alef, 5, 40_000);

        LateDeduction::sync($this->baker->id, now());

        $this->assertSame(1, StaffAdjustment::acrossBakeries()
            ->where('source', LateDeduction::SOURCE)
            ->count());
        $this->assertEqualsWithDelta(140_000, (float) $this->deductionAt($this->alef)?->amount, 0.01);
    }
}
