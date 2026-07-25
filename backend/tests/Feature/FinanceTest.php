<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    // ---------------------------------------------------------- expenses

    public function test_admin_can_record_an_expense(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson('/api/v1/expenses', [
                'category' => 'flour',
                'title' => 'خرید ۱۰ کیسه آرد',
                'amount' => 5_000_000,
                'spent_on' => '1405/05/03',
            ])
            ->assertCreated()
            ->assertJsonPath('data.category_label', 'خرید آرد')
            ->assertJsonPath('data.spent_on_jalali', '1405/05/03');

        $this->assertDatabaseHas('expenses', ['amount' => 5000000.00]);
    }

    public function test_expense_rejects_an_unknown_category(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson('/api/v1/expenses', [
                'category' => 'yacht',
                'title' => 'x',
                'amount' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_staff_cannot_touch_expenses(): void
    {
        foreach (['dough_maker', 'chane_gir', 'seller'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')
                ->getJson('/api/v1/expenses')
                ->assertForbidden();
        }
    }

    // ----------------------------------------------------------- payroll

    public function test_admin_can_record_a_salary_and_net_is_derived(): void
    {
        $employee = $this->userWithRole('seller');

        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson('/api/v1/salaries', [
                'user_id' => $employee->id,
                'period_start' => '1405/05/01',
                'base_amount' => 10_000_000,
                'bonus' => 2_000_000,
                'deduction' => 500_000,
            ])
            ->assertCreated()
            // Net is always base + bonus - deduction, never taken from input.
            ->assertJsonPath('data.net_amount', 11500000);
    }

    public function test_salary_is_unique_per_employee_and_period(): void
    {
        $employee = $this->userWithRole('seller');
        $admin = $this->userWithRole('admin');

        $payload = [
            'user_id' => $employee->id,
            'period_start' => '1405/05/01',
            'base_amount' => 1_000_000,
        ];

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/salaries', $payload)->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/salaries', $payload)->assertStatus(409);
    }

    public function test_salary_rejects_an_invalid_jalali_period(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->postJson('/api/v1/salaries', [
                'user_id' => $this->userWithRole('seller')->id,
                'period_start' => 'دیروز',
                'base_amount' => 1_000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('period_start');
    }

    public function test_marking_a_salary_paid_is_idempotent(): void
    {
        $employee = $this->userWithRole('seller');
        $admin = $this->userWithRole('admin');

        $salary = SalaryPayment::create([
            'user_id' => $employee->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'تست',
            'base_amount' => 1_000_000,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/salaries/{$salary->id}/mark-paid")
            ->assertOk()
            ->assertJsonPath('data.is_paid', true);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/salaries/{$salary->id}/mark-paid")
            ->assertStatus(409);
    }

    public function test_employee_sees_only_their_own_payslips(): void
    {
        $me = $this->userWithRole('seller');
        $other = $this->userWithRole('dough_maker');

        foreach ([$me, $other] as $user) {
            SalaryPayment::create([
                'user_id' => $user->id,
                'period_start' => now()->startOfMonth(),
                'period_label' => 'تست',
                'base_amount' => 1_000_000,
            ]);
        }

        $this->actingAs($me, 'sanctum')
            ->getJson('/api/v1/salaries/mine')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    // ------------------------------------------------- financial reports

    public function test_financial_report_nets_income_against_expenses(): void
    {
        $admin = $this->userWithRole('admin');

        Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 300_000,
            'spent_on' => now(),
        ]);

        SalaryPayment::create([
            'user_id' => $admin->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'تست',
            'base_amount' => 700_000,
            'paid_on' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/financial')
            ->assertOk()
            ->assertJsonPath('data.expenses.recorded', 300000)
            ->assertJsonPath('data.expenses.salaries_paid', 700000)
            ->assertJsonPath('data.expenses.total', 1000000)
            // No sales recorded, so the month is at a loss.
            ->assertJsonPath('data.profit.is_positive', false);
    }

    public function test_financial_reports_are_admin_only(): void
    {
        $this->actingAs($this->userWithRole('seller'), 'sanctum')
            ->getJson('/api/v1/reports/financial')
            ->assertForbidden();
    }

    public function test_financial_trend_returns_one_row_per_day(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->getJson('/api/v1/reports/financial-trend?from=1405/05/01&to=1405/05/05')
            ->assertOk()
            ->assertJsonCount(5, 'data.days');
    }

    // ------------------------------------------------------ jalali dates

    public function test_jalali_helper_round_trips_a_date(): void
    {
        $carbon = Jalali::parse('1405/05/03');

        $this->assertNotNull($carbon);
        $this->assertSame('1405/05/03', Jalali::date($carbon));
    }

    public function test_jalali_helper_rejects_nonsense(): void
    {
        $this->assertNull(Jalali::parse('not a date'));
        $this->assertNull(Jalali::parse(''));
        $this->assertNull(Jalali::parse(null));
    }

    public function test_jalali_helper_accepts_persian_digits(): void
    {
        $this->assertSame('1405/05/03', Jalali::date(Jalali::parse('۱۴۰۵/۰۵/۰۳')));
    }

    // ---------------------------------------------------------- currency

    public function test_money_formats_in_the_configured_unit(): void
    {
        \App\Models\Bakery::first()->update(['currency' => Money::TOMAN]);
        Money::forgetCache();
        $this->assertSame('1,000 تومان', Money::format(1000));

        \App\Models\Bakery::first()->update(['currency' => Money::RIAL]);
        Money::forgetCache();
        // Stored in Toman, displayed in Rial: ten times the amount.
        $this->assertSame('10,000 ریال', Money::format(1000));
    }

    public function test_admin_can_switch_currency(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->putJson('/api/v1/bakery', ['name' => 'تست', 'currency' => 'rial'])
            ->assertOk();

        $this->assertDatabaseHas('bakeries', ['currency' => 'rial']);
    }

    public function test_money_converts_a_typed_amount_back_to_toman(): void
    {
        \App\Models\Bakery::first()->update(['currency' => Money::RIAL]);
        Money::forgetCache();

        // A Rial shop types 10,000; storage must receive 1,000 Toman.
        $this->assertSame(1000.0, Money::toToman(10000));

        \App\Models\Bakery::first()->update(['currency' => Money::TOMAN]);
        Money::forgetCache();
        $this->assertSame(1000.0, Money::toToman(1000));
    }

    public function test_money_conversion_round_trips(): void
    {
        foreach ([Money::TOMAN, Money::RIAL] as $unit) {
            \App\Models\Bakery::first()->update(['currency' => $unit]);
            Money::forgetCache();

            // Whatever the unit, display -> stored -> display must be lossless.
            $this->assertSame(2500.0, Money::toToman(Money::convert(2500)));
        }
    }

    public function test_panel_form_save_does_not_drift_the_stored_amount(): void
    {
        \App\Models\Bakery::first()->update(['currency' => Money::RIAL]);
        Money::forgetCache();

        $expense = Expense::create([
            'category' => 'rent',
            'title' => 'اجاره',
            'amount' => 1000,
            'spent_on' => now(),
        ]);

        $this->actingAs($this->userWithRole('admin'));
        \Filament\Facades\Filament::setCurrentPanel(
            \Filament\Facades\Filament::getPanel('admin')
        );

        // Opening the form converts Toman -> Rial and saving converts back.
        // If either direction were missing or applied twice, the stored value
        // would drift by a factor of ten on every save.
        \Livewire\Livewire::test(
            \App\Filament\Resources\ExpenseResource\Pages\EditExpense::class,
            ['record' => $expense->getRouteKey()]
        )
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1000.00', $expense->fresh()->amount);
    }

    public function test_panel_expense_form_round_trips_the_displayed_amount(): void
    {
        \App\Models\Bakery::first()->update(['currency' => Money::RIAL]);
        Money::forgetCache();

        $expense = Expense::create([
            'category' => 'rent',
            'title' => 'اجاره',
            'amount' => 1000,
            'spent_on' => now(),
        ]);

        $this->actingAs($this->userWithRole('admin'));
        \Filament\Facades\Filament::setCurrentPanel(
            \Filament\Facades\Filament::getPanel('admin')
        );

        // Editing shows the stored Toman back in Rial.
        \Livewire\Livewire::test(
            \App\Filament\Resources\ExpenseResource\Pages\EditExpense::class,
            ['record' => $expense->getRouteKey()]
        )->assertFormSet(['amount' => 10000.0]);
    }

    public function test_currency_rejects_an_unknown_unit(): void
    {
        $this->actingAs($this->userWithRole('admin'), 'sanctum')
            ->putJson('/api/v1/bakery', ['name' => 'تست', 'currency' => 'dollar'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency');
    }
}
