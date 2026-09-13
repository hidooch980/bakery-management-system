<?php

namespace Tests\Feature;

use App\Filament\Resources\BankAccountResource;
use App\Models\Bakery;
use App\Models\BankAccount;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Tests\TestCase;

/**
 * Naming the drawer, from the screen that names every other account.
 *
 * `BankAccount::cashBox()` finds the till by its flag, and everything that
 * banks cash — customer collections, seller handovers, counter sales of
 * flour — goes through it. The flag was set once by a migration, for an
 * account titled exactly «صندوق نقد», and after that there was no way to
 * set it at all: the column is fillable, the model casts it, and the form
 * that edits bank accounts has never offered it.
 *
 * So a shop that did not happen to have an account by that exact name had
 * no till, could not make one, and every one of those cash paths silently
 * had nowhere to put the money.
 *
 * The «one at a time» rule is here too. The migration that added the
 * column wrote it down — «two tills is a question with no answer» — and
 * then only `is_default` ever enforced it.
 */
class TheShopCanSayWhichAccountIsTheTillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();
    }

    private function account(string $title, array $attributes = []): BankAccount
    {
        return BankAccount::create(array_merge([
            'title' => $title,
            'opening_balance' => 0,
            'is_active' => true,
        ], $attributes));
    }

    public function test_the_form_offers_the_till_flag(): void
    {
        // Without this the owner cannot name a till at all, and every cash
        // path quietly has nowhere to put the money. The column was
        // fillable and cast the whole time; only the screen was missing.
        $form = BankAccountResource::form(Form::make(new class extends Component implements HasForms
        {
            use InteractsWithForms;

            public function render()
            {
                return '';
            }
        }));

        $names = collect($form->getComponents(true))
            ->flatMap(fn ($component) => $component->getChildComponents())
            ->map(fn ($component) => method_exists($component, 'getName')
                ? $component->getName()
                : null)
            ->filter()
            ->all();

        $this->assertContains('is_cash_box', $names);
        $this->assertContains('is_default', $names, 'the sibling flag, as a control');
    }

    public function test_naming_a_new_till_clears_the_old_one(): void
    {
        // «two tills is a question with no answer» — written when the
        // column was added, and enforced for is_default only.
        $first = $this->account('صندوق قدیمی', ['is_cash_box' => true]);
        $second = $this->account('صندوق نو', ['is_cash_box' => true]);

        $this->assertFalse($first->fresh()->is_cash_box);
        $this->assertTrue($second->fresh()->is_cash_box);
        $this->assertSame($second->id, BankAccount::cashBox()?->id);
    }

    public function test_an_account_can_be_both_the_till_and_the_default(): void
    {
        // A one-account shop keeps its cash and its takings in the same
        // place, and neither flag should push the other off.
        $only = $this->account('صندوق', ['is_cash_box' => true, 'is_default' => true]);

        $this->assertTrue($only->fresh()->is_cash_box);
        $this->assertTrue($only->fresh()->is_default);
    }

    public function test_clearing_the_flag_leaves_the_shop_with_no_till(): void
    {
        $till = $this->account('صندوق', ['is_cash_box' => true]);

        $till->update(['is_cash_box' => false]);

        $this->assertNull(BankAccount::cashBox());
    }

    public function test_an_inactive_till_is_not_the_till(): void
    {
        $this->account('صندوق بسته', ['is_cash_box' => true, 'is_active' => false]);

        $this->assertNull(BankAccount::cashBox());
    }
}
