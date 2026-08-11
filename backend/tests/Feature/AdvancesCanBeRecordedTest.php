<?php

namespace Tests\Feature;

use App\Filament\Resources\StaffAdvanceResource\Pages\CreateStaffAdvance;
use App\Filament\Resources\StaffAdvanceResource\Pages\ListStaffAdvances;
use App\Models\SalaryPayment;
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
 * Recovering an advance off the next payslip was built and tested long
 * before anything could create one: there was no page and no endpoint, so
 * the shop paid staff early and the deduction never came.
 */
class AdvancesCanBeRecordedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->staff = User::factory()->create(['is_active' => true]);
        $this->staff->assignRole('seller');

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_advances_page_opens(): void
    {
        Livewire::test(ListStaffAdvances::class)->assertOk();
    }

    public function test_an_advance_can_be_recorded_from_the_panel(): void
    {
        Livewire::test(CreateStaffAdvance::class)
            ->fillForm([
                'user_id' => $this->staff->id,
                'amount' => Money::convert(2_000_000),
                'paid_on' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertEquals(2_000_000.0, (float) StaffAdvance::first()->amount);
    }

    public function test_it_records_who_handed_the_money_over(): void
    {
        Livewire::test(CreateStaffAdvance::class)
            ->fillForm([
                'user_id' => $this->staff->id,
                'amount' => Money::convert(500_000),
                'paid_on' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // An entry nobody is named against is not evidence of anything.
        $this->assertSame($this->admin->id, StaffAdvance::first()->recorded_by);
    }

    public function test_the_advance_comes_off_the_next_payslip(): void
    {
        StaffAdvance::create([
            'user_id' => $this->staff->id,
            'recorded_by' => $this->admin->id,
            'amount' => 2_000_000,
            'paid_on' => now()->subDays(5),
        ]);

        SalaryPayment::create([
            'user_id' => $this->staff->id,
            'period_start' => Jalali::currentMonthRange()[0],
            'period_label' => 'مرداد ۱۴۰۵',
            'base_amount' => 10_000_000,
            'bonus' => 0,
            'deduction' => 0,
        ]);

        // The whole point of the feature: pay brought forward comes back.
        $this->assertEquals(
            2_000_000.0,
            (float) StaffAdvance::first()->recovered,
        );
    }

    public function test_what_is_still_owed_is_visible(): void
    {
        $advance = StaffAdvance::create([
            'user_id' => $this->staff->id,
            'recorded_by' => $this->admin->id,
            'amount' => 3_000_000,
            'paid_on' => now(),
        ]);

        $this->assertEquals(3_000_000.0, $advance->outstanding);
        $this->assertFalse($advance->is_settled);
    }
}
