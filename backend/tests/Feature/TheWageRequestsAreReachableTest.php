<?php

namespace Tests\Feature;

use App\Filament\Resources\SalaryPaymentRequestResource;
use App\Filament\Resources\SalaryPaymentRequestResource\Pages\ListSalaryPaymentRequests;
use App\Filament\Resources\SalaryPaymentResource;
use App\Filament\Resources\SalaryPaymentResource\Pages\CreateSalaryPayment;
use App\Models\Bakery;
use App\Models\SalaryPayment;
use App\Models\SalaryPaymentRequest;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Wage requests, from the desk the wages are actually written at.
 *
 * The feature shipped with an API and a phone screen and no page in the
 * panel, so the owner could be asked for wages only somewhere he does not
 * do the paying. Same shape as the balance sheet that had no page: a
 * thing that is built, tested, and unreachable.
 *
 * These tests are about reachability first, and about the two decisions
 * that would quietly rot second — that there is no approve button, and
 * that the panel and the phone refuse in the same words.
 */
class TheWageRequestsAreReachableTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->baker = User::factory()->create([
            'name' => 'عبدالله',
            'is_active' => true,
            'monthly_salary' => 15_000_000,
        ]);

        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function aRequest(array $attributes = []): SalaryPaymentRequest
    {
        return SalaryPaymentRequest::create([
            'user_id' => $this->baker->id,
            'period_start' => now()->startOfMonth(),
            'note' => 'کرایه خانه',
            ...$attributes,
        ]);
    }

    public function test_the_page_opens_and_shows_who_is_waiting(): void
    {
        $this->aRequest();

        Livewire::test(ListSalaryPaymentRequests::class)
            ->assertOk()
            ->assertSee('عبدالله')
            ->assertSee('کرایه خانه');
    }

    public function test_the_menu_says_how_many_are_waiting(): void
    {
        $this->assertNull(SalaryPaymentRequestResource::getNavigationBadge());

        $this->aRequest();

        $this->assertSame('1', SalaryPaymentRequestResource::getNavigationBadge());
    }

    public function test_an_answered_request_stops_counting_against_the_menu(): void
    {
        $request = $this->aRequest();

        $request->reject($this->owner, 'تا آخر ماه صبر کنید');

        $this->assertNull(SalaryPaymentRequestResource::getNavigationBadge());
    }

    public function test_the_page_opens_on_the_ones_still_waiting(): void
    {
        // The answered ones are a record, not work. Opening on «همه» would
        // bury one person waiting under a year of settled months.
        $this->assertSame('pending', (new ListSalaryPaymentRequests)->getDefaultActiveTab());
    }

    public function test_the_estimate_takes_off_what_has_already_been_drawn(): void
    {
        StaffAdvance::create([
            'user_id' => $this->baker->id,
            'amount' => 2_000_000,
            'paid_on' => now(),
        ]);

        // 150,000,000 Rial agreed, 20,000,000 Rial already in his pocket
        // — the figures are stored in Toman, which is why they read a
        // zero short here. Showing the agreed wage on this page would
        // have the owner reaching for a sum 20,000,000 Rial too big.
        $this->assertEqualsWithDelta(
            13_000_000,
            $this->aRequest()->estimatedNet(),
            0.01,
        );
    }

    public function test_an_advance_bigger_than_the_wage_does_not_read_as_a_debt_to_the_shop(): void
    {
        StaffAdvance::create([
            'user_id' => $this->baker->id,
            'amount' => 40_000_000,
            'paid_on' => now(),
        ]);

        // Not a negative estimate. What is left of the advance stands and
        // comes off the month after, exactly as the payslip does it.
        $this->assertSame(0.0, $this->aRequest()->estimatedNet());
    }

    public function test_a_person_with_no_agreed_wage_gets_no_invented_figure(): void
    {
        $this->baker->update(['monthly_salary' => null]);

        $this->assertNull($this->aRequest()->fresh('user')->estimatedNet());
    }

    public function test_there_is_no_approve_action_on_the_page(): void
    {
        $request = $this->aRequest();

        // The absence is the design: paying is what a yes means, and it
        // happens on the pay sheet where the figures are on screen. If an
        // approve button ever appears here, it writes a wage nobody read.
        Livewire::test(ListSalaryPaymentRequests::class)
            ->assertTableActionDoesNotExist('approve')
            ->assertTableActionVisible('pay', record: $request)
            ->assertTableActionVisible('reject', record: $request);
    }

    public function test_paying_is_the_only_way_to_a_yes_and_it_answers_the_request(): void
    {
        $request = $this->aRequest();

        SalaryPayment::create([
            'user_id' => $this->baker->id,
            'period_start' => $request->period_start,
            'base_amount' => 15_000_000,
            'paid_on' => now(),
        ]);

        $request->refresh();

        $this->assertSame(SalaryPaymentRequest::PAID, $request->status);
        $this->assertNotNull($request->salary_payment_id);
    }

    public function test_the_pay_action_carries_the_person_and_the_month_across(): void
    {
        $request = $this->aRequest();

        $url = SalaryPaymentResource::getUrl('create', [
            'user_id' => $request->user_id,
            'period_start' => $request->period_start->toDateString(),
        ]);

        Livewire::test(ListSalaryPaymentRequests::class)
            ->assertTableActionHasUrl('pay', $url, record: $request);

        $this->assertStringContainsString('user_id='.$this->baker->id, $url);
    }

    public function test_the_pay_sheet_opens_already_filled_in(): void
    {
        $request = $this->aRequest();

        // One tap, or the friction grows an approve button back.
        Livewire::withQueryParams([
            'user_id' => $this->baker->id,
            'period_start' => $request->period_start->toDateString(),
        ])
            ->test(CreateSalaryPayment::class)
            ->assertFormSet([
                'user_id' => $this->baker->id,
                // Jalali, not the Gregorian the link carried: JalaliDateInput
                // converts on the way in, and the shop reads Shamsi. The
                // prefill hands it a Gregorian date exactly as the field's
                // own default does, and the field turns both into the same
                // thing.
                'period_start' => Jalali::date($request->period_start),
                'base_amount' => Money::convert(15_000_000),
            ]);
    }

    public function test_the_pay_sheet_still_has_its_own_defaults_when_nobody_sent_anything(): void
    {
        // Reached from the menu rather than from a request. The prefill
        // must write over the defaults, never replace them.
        Livewire::test(CreateSalaryPayment::class)
            ->assertFormSet([
                'bonus' => 0,
                'deduction' => 0,
                'period_start' => Jalali::date(Jalali::currentMonthRange()[0]),
            ]);
    }

    public function test_a_refusal_needs_a_reason_and_says_who_gave_it(): void
    {
        $request = $this->aRequest();

        Livewire::test(ListSalaryPaymentRequests::class)
            ->callTableAction('reject', $request, ['decision_note' => ''])
            ->assertHasTableActionErrors(['decision_note']);

        $this->assertTrue($request->fresh()->is_pending);

        Livewire::test(ListSalaryPaymentRequests::class)
            ->callTableAction('reject', $request, ['decision_note' => 'تا آخر ماه صبر کنید'])
            ->assertHasNoTableActionErrors();

        $request->refresh();

        $this->assertSame(SalaryPaymentRequest::REJECTED, $request->status);
        $this->assertSame('تا آخر ماه صبر کنید', $request->decision_note);
        $this->assertSame($this->owner->id, $request->decided_by);
        $this->assertNotNull($request->decided_at);
    }

    public function test_the_panel_and_the_phone_refuse_in_the_same_place(): void
    {
        $request = $this->aRequest();

        // Both go through the model. Two copies of this would drift, and
        // the one that drifts is the one nobody is looking at.
        $this->patchJson("/api/v1/salary-requests/{$request->id}/reject", [
            'decision_note' => 'ماه بعد',
        ])->assertOk();

        $this->assertSame(SalaryPaymentRequest::REJECTED, $request->fresh()->status);

        // And an answered one cannot be answered twice, from either side.
        Livewire::test(ListSalaryPaymentRequests::class)
            ->assertTableActionHidden('reject', record: $request->fresh())
            ->assertTableActionHidden('pay', record: $request->fresh());
    }
}
