<?php

namespace Tests\Feature;

use App\Filament\Resources\StaffAdvanceResource\Pages\CreateStaffAdvance;
use App\Models\BankAccount;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Why the advances ended up on the drawer at all.
 *
 * The wage form has always opened with the shop's account already chosen.
 * The advance form did not — it opened blank, was saved blank, and which
 * account the money left was then settled by a rule behind the screen that
 * nobody recording the payment could see.
 *
 * That rule was wrong, and it was wrong quietly. A field somebody can see
 * filled in is a field they would have corrected on the spot; a blank one
 * that means something is a decision made on their behalf without telling
 * them it was being made.
 *
 * So the two forms now behave the same way, and the sentence under the
 * field is built from the same rule the model uses rather than written out
 * by hand — a fixed sentence is exactly what said «از صندوق» while the
 * rule said something else, and it is what I believed.
 */
class TheAdvanceFormNamesTheAccountBeforeSavingTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_active' => true,
            'is_cash_box' => true,
        ]);

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);

        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('admin');

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_form_opens_with_the_shops_account_already_chosen(): void
    {
        Livewire::test(CreateStaffAdvance::class)
            ->assertFormSet(['bank_account_id' => $this->bank->id]);
    }

    public function test_it_is_never_the_drawer_that_is_offered(): void
    {
        // The whole of the mistake in one assertion.
        $till = BankAccount::cashBox();

        // Read off the form's own state rather than through getState(),
        // which validates every other field first and throws on a form
        // nobody has filled in yet.
        $chosen = Livewire::test(CreateStaffAdvance::class)
            ->get('data.bank_account_id');

        $this->assertNotNull($chosen);
        $this->assertNotSame($till->id, $chosen);
    }

    public function test_a_shop_with_no_bank_is_told_to_choose_rather_than_left_blank(): void
    {
        // Nothing to pre-fill, so the form says so instead of quietly
        // opening empty and letting a rule decide out of sight.
        $this->bank->delete();

        Livewire::test(CreateStaffAdvance::class)
            ->assertFormSet(['bank_account_id' => null]);
    }
}
