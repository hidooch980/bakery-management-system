<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «بجز مبلغ کارتخوان همه برن همین حساب، همه مدل درامدها. درامد کارتخوان
 * فقط بره حساب سفید.»
 *
 * The shop's own rule, and until now only half of it was true. Bread sales
 * already followed it: a card line names the bank the moment it is
 * recorded, and a cash line names nothing because that money is still in
 * the seller's pocket until they hand it over — at which point it reaches
 * the drawer.
 *
 * Miscellaneous income did the opposite. With no account named it defaulted
 * to the bank, on an assumption written before the shop had a drawer at
 * all: «money in lands in the shop's account unless it is said to have
 * stayed in the till».
 */
class IncomeGoesToTheTillUnlessItCameByCardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private BankAccount $bank;

    private BankAccount $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman', 'bread_price' => 5000]);
        Money::forgetCache();

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_active' => true,
            'is_cash_box' => true,
        ]);
    }

    private function recordIncome(array $payload = [])
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/incomes', array_merge([
                'category' => 'other',
                'title' => 'اجاره تابلو',
                'amount' => 500_000,
            ], $payload));
    }

    private function tillBalance(): float
    {
        return (float) $this->till->fresh()->balance;
    }

    private function bankBalance(): float
    {
        return (float) $this->bank->fresh()->balance;
    }

    public function test_income_with_no_account_named_goes_to_the_till(): void
    {
        $this->recordIncome()->assertCreated();

        $this->assertEqualsWithDelta(500_000, $this->tillBalance(), 0.01);
        $this->assertSame(0.0, $this->bankBalance());
    }

    public function test_naming_the_bank_still_sends_it_there(): void
    {
        // Which is how «این یکی کارتخوانی بود» is said.
        $this->recordIncome(['bank_account_id' => $this->bank->id])->assertCreated();

        $this->assertEqualsWithDelta(500_000, $this->bankBalance(), 0.01);
        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_a_shop_with_no_till_falls_back_to_the_bank(): void
    {
        // Losing the income over a missing flag would be worse than putting
        // it somewhere the owner can move it from.
        $this->till->update(['is_cash_box' => false]);

        $this->recordIncome()->assertCreated();

        $this->assertEqualsWithDelta(500_000, $this->bankBalance(), 0.01);
    }

    // ------------------------------------------------- bread, for contrast

    private function batch(): ChaneEntry
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $dough = DoughEntry::create(['user_id' => $seller->id, 'bag_count' => 2]);

        return ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $seller->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 2,
        ]);
    }

    public function test_a_card_sale_reaches_the_bank_not_the_till(): void
    {
        $batch = $this->batch();

        Sale::create([
            'chane_entry_id' => $batch->id,
            'user_id' => $batch->user_id,
            'payment_type' => 'card',
            'bread_count' => 100,
            'amount' => 500_000,
            'bank_account_id' => $this->bank->id,
        ]);

        $this->assertEqualsWithDelta(500_000, $this->bankBalance(), 0.01);
        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_a_cash_sale_moves_nothing_until_it_is_handed_over(): void
    {
        // The money is in the seller's pocket. Banking it at the moment of
        // sale and again at settlement would count it twice — which is why
        // bread sales get no till fallback.
        $batch = $this->batch();

        Sale::create([
            'chane_entry_id' => $batch->id,
            'user_id' => $batch->user_id,
            'payment_type' => 'cash',
            'bread_count' => 100,
            'amount' => 500_000,
        ]);

        $this->assertSame(0.0, $this->tillBalance());
        $this->assertSame(0.0, $this->bankBalance());
    }

    public function test_a_card_sale_recorded_from_the_app_names_the_bank(): void
    {
        // The path the seller's phone actually takes. The account is not
        // chosen on the phone — the recorder picks it — so this is where
        // «کارتخوان فقط حساب سفید» is really decided.
        $batch = $this->batch();

        $this->actingAs($batch->user, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $batch->id,
                'payments' => [[
                    'payment_type' => 'card',
                    'bread_count' => 100,
                    'amount' => 500_000,
                ]],
            ])
            ->assertCreated();

        $this->assertEqualsWithDelta(500_000, $this->bankBalance(), 0.01);
        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_card_money_never_lands_in_the_drawer(): void
    {
        // A shop that flagged one account as both the default and the till
        // would otherwise have card takings booked as cash in hand, and the
        // drawer would read high by every card sale — money nobody can find
        // when they count it.
        $this->bank->update(['is_default' => false]);
        $this->till->update(['is_default' => true]);

        $batch = $this->batch();

        $this->actingAs($batch->user, 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $batch->id,
                'payments' => [[
                    'payment_type' => 'card',
                    'bread_count' => 100,
                    'amount' => 500_000,
                ]],
            ])
            ->assertCreated();

        $this->assertSame(0.0, $this->tillBalance(), 'card takings are not cash in hand');
        $this->assertEqualsWithDelta(500_000, $this->bankBalance(), 0.01);
    }
}
