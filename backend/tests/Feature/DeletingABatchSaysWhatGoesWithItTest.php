<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\WhatDeletingCosts;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sentence that was missing on 1405/06/06.
 *
 * Deleting a dough entry is how a mistyped sack count gets corrected, and
 * it is the right way: the chane rows go with it and the flour returns to
 * the store, all on the model path so nothing is left half-reversed. That
 * part works and this does not change it.
 *
 * What nobody was told is that the day's sales go too, and their bank
 * postings with them. A batch was deleted to fix it, a card sale vanished
 * by cascade, and 7,290,000 sat in the account with no record to explain
 * it — found and typed back in by hand a fortnight later, with a note
 * still on the row saying so.
 *
 * The money never moved. Only the shop's knowledge of it was destroyed,
 * which is the kind of loss nothing reports: every total still adds up,
 * against a figure that is now wrong.
 *
 * Not a refusal. Correcting a genuinely wrong batch has to stay possible,
 * and the sales are re-entered afterwards — this is the sentence that
 * makes re-entering them something the owner knows to do.
 */
class DeletingABatchSaysWhatGoesWithItTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

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
            'is_default' => true,
        ]);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');
    }

    private function batch(): ChaneEntry
    {
        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 2,
        ]);

        return ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 2,
        ]);
    }

    private function sell(ChaneEntry $batch, float $amount, string $how = 'cash'): Sale
    {
        return Sale::create([
            'chane_entry_id' => $batch->id,
            'user_id' => $this->seller->id,
            'payment_type' => $how,
            'bread_count' => 50,
            'amount' => $amount,
            // A card sale names the account its money reached. Cash names
            // none: the notes are in the seller's hand until they hand over.
            'bank_account_id' => $how === 'card'
                ? BankAccount::defaultAccount()?->id
                : null,
        ]);
    }

    public function test_a_batch_with_nothing_sold_from_it_says_nothing(): void
    {
        // The ordinary case: a sack count spotted wrong minutes after it
        // was typed. A warning here is noise, and noise is what teaches
        // people to click through the warning that matters.
        $batch = $this->batch();

        $this->assertNull(WhatDeletingCosts::warningFor($batch));
        $this->assertNull(WhatDeletingCosts::warningFor($batch->doughEntry));
    }

    public function test_the_sales_about_to_go_are_counted_and_totalled(): void
    {
        $batch = $this->batch();
        $this->sell($batch, 400_000);
        $this->sell($batch, 350_000);

        $this->assertSame(
            [
                'count' => 2,
                'amount' => 750_000.0,
                'banked' => 0,
                'banked_amount' => 0.0,
            ],
            WhatDeletingCosts::of($batch),
        );
    }

    public function test_deleting_the_dough_reaches_the_sales_under_its_batches(): void
    {
        // The one somebody actually clicks. The sales hang off the chane
        // rows, two joins down from the row being deleted, which is
        // exactly why nobody saw them going.
        $batch = $this->batch();
        $this->sell($batch, 400_000);

        $counted = WhatDeletingCosts::of($batch->doughEntry);

        $this->assertSame(1, $counted['count']);
        $this->assertSame(400_000.0, $counted['amount']);
    }

    public function test_the_warning_names_the_money_and_says_to_re_enter(): void
    {
        $batch = $this->batch();
        $this->sell($batch, 400_000);

        $warning = WhatDeletingCosts::warningFor($batch->doughEntry);

        $this->assertNotNull($warning);
        $this->assertStringContainsString('1 فروش', $this->digits($warning));
        $this->assertStringContainsString('دوباره وارد کنید', $warning);
    }

    public function test_the_banked_money_really_does_leave_the_books(): void
    {
        // The warning is only worth anything if it is true, and the first
        // version of it was not: it said the money stays in the bank while
        // the record goes, and asserted that of a *cash* sale. Cash sits
        // in the seller's hand until they hand over — it never reached a
        // bank to stay in. Only a card sale is the thing 06/06 was.
        $batch = $this->batch();
        $card = $this->sell($batch, 400_000, 'card');

        $bank = BankAccount::defaultAccount();
        $this->assertSame(400_000.0, round((float) $bank->fresh()->balance, 2));

        $batch->doughEntry->delete();

        $this->assertNull(Sale::find($card->id));
        $this->assertSame(0.0, round((float) $bank->fresh()->balance, 2));
    }

    public function test_cash_is_not_described_as_money_sitting_in_a_bank(): void
    {
        $batch = $this->batch();
        $this->sell($batch, 400_000);

        $this->assertStringNotContainsString(
            'بانک',
            WhatDeletingCosts::warningFor($batch) ?? '',
        );
    }

    public function test_a_card_sale_is_named_as_money_the_bank_still_holds(): void
    {
        $batch = $this->batch();
        $this->sell($batch, 400_000);
        $this->sell($batch, 250_000, 'card');

        $warning = $this->digits(WhatDeletingCosts::warningFor($batch) ?? '');

        $this->assertStringContainsString('2 فروش', $warning);
        $this->assertStringContainsString('1 فروش به مبلغ', $warning);
        $this->assertStringContainsString('بانک', $warning);
    }

    /** Persian digits back to Latin, so a count can be asserted on. */
    private function digits(string $text): string
    {
        return str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $text,
        );
    }
}
