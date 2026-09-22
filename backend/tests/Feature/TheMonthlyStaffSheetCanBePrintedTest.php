<?php

namespace Tests\Feature;

use App\Filament\Pages\StaffMonthReport;
use App\Models\Bakery;
use App\Models\SalaryPayment;
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
 * «گزارش ماهانه قابل چاپ».
 *
 * The figures existed, spread across the payroll report, the attendance
 * one and each person's advances. None of it went anywhere a person
 * could hold — and the shop's payroll conversations happen at a desk
 * with a sheet between two people.
 *
 * Which is why this is a panel page rather than a screen in the phone
 * app: printing happens at the desk.
 */
class TheMonthlyStaffSheetCanBePrintedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->baker = User::factory()->create(['is_active' => true, 'name' => 'شاطر رضا']);
        $this->baker->assignRole('shater');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->owner);
    }

    public function test_the_month_is_on_the_sheet_with_the_people_in_it(): void
    {
        $this->payslipFor($this->baker, 10_000_000);

        Livewire::test(StaffMonthReport::class)
            ->assertSee('شاطر رضا')
            ->assertSee(Jalali::monthLabel(now()));
    }

    public function test_the_sheet_carries_the_print_rules(): void
    {
        $this->payslipFor($this->baker, 10_000_000);

        // Printing the panel as it stands puts the sidebar, the topbar
        // and every button on the sheet. Without these the page is a
        // screenshot of an app rather than something to hand somebody.
        Livewire::test(StaffMonthReport::class)
            ->assertSee('@media print', escape: false)
            ->assertSee('staff-month-report__controls');
    }

    public function test_a_month_with_nothing_in_it_says_so(): void
    {
        Livewire::test(StaffMonthReport::class)
            ->assertSee('در این ماه حقوقی صادر نشده');
    }

    public function test_an_earlier_month_can_be_asked_for(): void
    {
        $lastMonth = now()->copy()->subMonth();

        $this->payslipFor($this->baker, 7_000_000, $lastMonth);

        // This month has nothing; last month has him.
        Livewire::test(StaffMonthReport::class)
            ->assertDontSee('شاطر رضا')
            ->set('month', Jalali::format($lastMonth, 'Y/m'))
            ->assertSee('شاطر رضا');
    }

    public function test_the_total_is_the_rows_and_not_a_second_answer(): void
    {
        $this->payslipFor($this->baker, 10_000_000);

        $other = User::factory()->create(['is_active' => true, 'name' => 'چانه‌گیر علی']);
        $other->assignRole('chane_gir');
        $this->payslipFor($other, 6_000_000);

        $page = new StaffMonthReport;
        $page->month = Jalali::format(now(), 'Y/m');

        $this->assertSame(
            Money::format(16_000_000),
            $page->totalFormatted(),
        );
    }

    public function test_somebody_with_nothing_in_the_month_is_not_a_row_of_zeroes(): void
    {
        $this->payslipFor($this->baker, 10_000_000);

        $quiet = User::factory()->create(['is_active' => true, 'name' => 'کسی که این ماه نبود']);
        $quiet->assignRole('seller');

        $page = new StaffMonthReport;
        $page->month = Jalali::format(now(), 'Y/m');

        $names = $page->rows()->pluck('name')->all();

        $this->assertContains('شاطر رضا', $names);
        $this->assertNotContains('کسی که این ماه نبود', $names);
    }

    // ---------------------------------------------------------- helpers

    private function payslipFor(User $person, float $amount, $when = null): void
    {
        [$start] = Jalali::monthRangeFor($when ?? now());

        SalaryPayment::create([
            'user_id' => $person->id,
            'period_start' => $start,
            'period_label' => Jalali::monthLabel($start) ?? '',
            'base_amount' => $amount,
            'net_amount' => $amount,
        ]);
    }
}
