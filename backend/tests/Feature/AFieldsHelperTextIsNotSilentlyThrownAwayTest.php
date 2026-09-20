<?php

namespace Tests\Feature;

use App\Filament\Resources\IncomeResource\Pages\CreateIncome;
use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A sentence written for the screen actually reaches the screen.
 *
 * `helperText()` is a plain assignment in Filament — the second call on a
 * field throws the first away, without a warning or a deprecation. The
 * income form's account picker had two, and the one that survived was the
 * general one.
 *
 * The one nobody ever saw said «اگر کارتخوانی بود، حساب بانکی را انتخاب
 * کنید». That is the sentence that stops card takings being recorded as
 * notes in the drawer — the mistake that was found and fixed in the code
 * six separate times, written down for the owner, and then silently
 * dropped by the line below it.
 *
 * Proved by reading the rendered field before the fix: it returned the
 * general sentence alone.
 */
class AFieldsHelperTextIsNotSilentlyThrownAwayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        BankAccount::create([
            'title' => 'صندوق نقد',
            'is_active' => true,
            'is_cash_box' => true,
        ]);

        BankAccount::create([
            'title' => 'حساب سفید',
            'is_active' => true,
            'is_default' => true,
        ]);

        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('admin');

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function accountHelperText(): string
    {
        $form = Livewire::test(CreateIncome::class)->instance()->form;

        return (string) $form->getComponent('data.bank_account_id')->getHelperText();
    }

    public function test_the_card_reader_sentence_reaches_the_screen(): void
    {
        // The half that was being thrown away.
        $this->assertStringContainsString('کارتخوان', $this->accountHelperText());
    }

    public function test_the_default_is_still_explained(): void
    {
        // The field opens with the drawer chosen. Saying so is what makes
        // the card-reader sentence mean anything.
        $this->assertStringContainsString('صندوق', $this->accountHelperText());
    }

    public function test_what_choosing_an_account_does_is_still_said(): void
    {
        // The half that survived. Merging the two must not lose it either.
        $this->assertStringContainsString('گردش', $this->accountHelperText());
    }

    /**
     * The guard. Every field in the panel, not just this one.
     *
     * A second `helperText()` on a field is always a mistake — nobody
     * writes two sentences meaning to keep one. It is invisible in review
     * because the two calls are usually separated by several other
     * setters, which is exactly how this one survived.
     */
    public function test_no_field_in_the_panel_has_two_helper_texts(): void
    {
        $offenders = [];

        foreach ($this->panelFiles() as $file) {
            $lastField = null;
            $lastHelper = null;

            foreach (file($file) as $number => $line) {
                $number++;

                // Any static factory opens a new field — `::make()`, and
                // the shop's own `JalaliDateInput::today()` too. Matching
                // only `::make(` read two neighbouring fields as one and
                // reported a pair that was never there.
                if (preg_match('/::\w+\(/', $line)) {
                    $lastField = $number;
                    $lastHelper = null;
                }

                if (! str_contains($line, '->helperText(')) {
                    continue;
                }

                if ($lastHelper !== null && $lastField !== null && $lastHelper > $lastField) {
                    $offenders[] = basename($file).":{$lastHelper} و :{$number}";
                }

                $lastHelper = $number;
            }
        }

        $this->assertSame([], $offenders, 'راهنمای دوم، اولی را دور می‌اندازد: '.implode('، ', $offenders));
    }

    /** @return list<string> */
    private function panelFiles(): array
    {
        $files = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Filament'))
        );

        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
