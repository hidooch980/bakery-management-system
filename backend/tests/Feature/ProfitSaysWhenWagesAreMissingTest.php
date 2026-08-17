<?php

namespace Tests\Feature;

use App\Filament\Widgets\MoneyAtAGlance;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\SalaryPayment;
use App\Models\Sale;
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
 * The profit figure says so when a month's wages are not in it.
 *
 * This shop has never recorded a wage — no payslips, nothing on the expense
 * sheet — while owing a thousand million Rial a month across five people.
 * So «سود خالص ماه» has been overstated by the whole payroll since the day
 * it opened, and there was nothing on the number to say so.
 *
 * The issue centre reports it as well, but an issue can now be answered,
 * and answering that one is a perfectly reasonable thing for this owner to
 * do. That must not quietly turn the headline figure back into something
 * that looks trustworthy.
 */
class ProfitSaysWhenWagesAreMissingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'currency' => 'toman',
            'bread_price' => 5000,
            'normal_chane_weight_kg' => 0.85,
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create([
            'is_active' => true,
            'monthly_salary' => 53_000_000,
        ]);
        $this->admin->assignRole('admin');

        User::factory()->create(['is_active' => true, 'monthly_salary' => 15_000_000])
            ->assignRole('shater');

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** A day's trading, so the month has both takings and a profit figure. */
    private function sell(): void
    {
        $dough = DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => 1]);

        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);

        Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $this->admin->id,
            'bread_count' => 100,
            'payment_type' => 'cash',
            'amount' => 500_000,
            'amount_difference' => 0,
        ]);
    }

    public function test_it_says_wages_are_missing(): void
    {
        $this->sell();

        Livewire::test(MoneyAtAGlance::class)->assertSee('حقوق ثبت نشده');
    }

    /**
     * Pro-rated to the days gone, not the whole period's payroll.
     *
     * The income beside it is the income so far. Setting a whole period's
     * wages against a part period's takings overstates the hole, and it is
     * the mistake I made out loud before this test existed — subtracting a
     * full month's payroll from twenty-two days of trading and calling the
     * answer the real profit.
     */
    public function test_the_figure_is_what_has_been_run_up_so_far(): void
    {
        $this->sell();

        [$from, $to] = Jalali::currentQuotaPeriod();

        $days = (int) $from->diffInDays($to) + 1;
        $gone = (int) $from->diffInDays(now()) + 1;

        // Both wages together, for the part of the period that has passed.
        $expected = round(68_000_000 * $gone / $days, 2);

        Livewire::test(MoneyAtAGlance::class)->assertSee(Money::format($expected));

        // And it is genuinely less than the whole period's payroll unless
        // the period happens to have ended today.
        if ($gone < $days) {
            $this->assertLessThan(68_000_000, $expected);
        }
    }

    public function test_it_says_which_way_the_figure_is_wrong(): void
    {
        $this->sell();

        Livewire::test(MoneyAtAGlance::class)->assertSee('از واقعیت بیشتر است');
    }

    public function test_a_recorded_payslip_gives_the_margin_back(): void
    {
        $this->sell();

        SalaryPayment::create([
            'user_id' => $this->admin->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'مرداد',
            'base_amount' => 53_000_000,
            'net_amount' => 53_000_000,
            'paid_on' => now(),
        ]);

        Livewire::test(MoneyAtAGlance::class)
            ->assertSee('حاشیه سود')
            ->assertDontSee('حقوق ثبت نشده');
    }

    public function test_wages_paid_as_an_expense_count_too(): void
    {
        $this->sell();

        // This shop pays wages straight off the expense sheet. Counting
        // only payslips would cry wolf every month.
        Expense::create([
            'user_id' => $this->admin->id,
            'category' => 'salary',
            'title' => 'حقوق مرداد',
            'amount' => 68_000_000,
            'spent_on' => now(),
        ]);

        Livewire::test(MoneyAtAGlance::class)->assertDontSee('حقوق ثبت نشده');
    }

    public function test_a_shop_that_owes_no_wages_is_left_alone(): void
    {
        User::query()->update(['monthly_salary' => 0]);

        $this->sell();

        // Owing nothing is not the same as having recorded nothing.
        Livewire::test(MoneyAtAGlance::class)
            ->assertSee('حاشیه سود')
            ->assertDontSee('حقوق ثبت نشده');
    }

    public function test_the_warning_does_not_stop_the_figure_being_shown(): void
    {
        $this->sell();

        // The number is still there and still right about what it counts.
        // What changed is that it no longer claims to be the whole story.
        Livewire::test(MoneyAtAGlance::class)
            ->assertOk()
            ->assertSee('سود خالص ماه');
    }
}
