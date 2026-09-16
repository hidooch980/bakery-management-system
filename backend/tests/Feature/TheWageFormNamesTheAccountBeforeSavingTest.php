<?php

namespace Tests\Feature;

use App\Filament\Resources\SalaryPaymentResource\Pages\CreateSalaryPayment;
use App\Models\BankAccount;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The wage form always did name an account — it named the wrong one.
 *
 * This form was the one held up as the example when the advance form was
 * fixed: it opened with an account already chosen, and the advance form
 * did not. But «already chosen» was the shop's default account, which can
 * be the till, and underneath it the model turned a blank into the drawer.
 * Both ends agreed with each other and both were wrong.
 *
 * Neither could have been worked out from inside the code. The owner was
 * asked and said «حقوق و مزایا حساب سفید», and that answer is what these
 * assertions hold in place.
 */
class TheWageFormNamesTheAccountBeforeSavingTest extends TestCase
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
        Livewire::test(CreateSalaryPayment::class)
            ->assertFormSet(['bank_account_id' => $this->bank->id]);
    }

    public function test_it_is_never_the_drawer_that_is_offered(): void
    {
        // The whole of the mistake in one assertion.
        $till = BankAccount::cashBox();

        // Read off the form's own state rather than through getState(),
        // which validates every other field first and throws on a form
        // nobody has filled in yet.
        $chosen = Livewire::test(CreateSalaryPayment::class)
            ->get('data.bank_account_id');

        $this->assertNotNull($chosen);
        $this->assertNotSame($till->id, $chosen);
    }

    public function test_even_when_the_drawer_is_the_shops_default_account(): void
    {
        // The default account is a setting somebody chose for other
        // reasons, and in this shop it was the till. Reading wages off it
        // is how the form came to promise the drawer.
        $this->bank->update(['is_default' => false]);
        BankAccount::cashBox()->update(['is_default' => true]);

        $chosen = Livewire::test(CreateSalaryPayment::class)
            ->get('data.bank_account_id');

        $this->assertSame($this->bank->id, $chosen);
    }

    public function test_a_shop_with_no_bank_is_told_to_choose_rather_than_left_blank(): void
    {
        // Nothing to pre-fill, so the form says so instead of quietly
        // opening empty and letting a rule decide out of sight.
        $this->bank->delete();

        Livewire::test(CreateSalaryPayment::class)
            ->assertFormSet(['bank_account_id' => null]);
    }
}
