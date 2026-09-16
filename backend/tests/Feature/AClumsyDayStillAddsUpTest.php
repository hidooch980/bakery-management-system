<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\Expense;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * یک روزِ واقعی، با آدم‌های واقعی.
 *
 * تستِ ماهانه مسیرِ درست را می‌رود: هرکس کارِ خودش را به‌موقع و درست
 * ثبت می‌کند. هیچ روزی در هیچ نانوایی‌ای این‌طور نیست.
 *
 * آدم دکمه را دوبار می‌زند چون گوشی کند بوده. عدد را غلط می‌زند و
 * بعد درستش می‌کند. چیزی را ثبت می‌کند که نباید و پاکش می‌کند.
 * فروشنده کمتر از بدهی‌اش تحویل می‌دهد. مالک همان قبض را دو بار
 * پرداخت‌شده حساب می‌کند.
 *
 * سؤال این تست یکی است و همان سؤالِ همیشه است: بعد از این‌همه
 * دست‌کاری، دفتر هنوز با پول می‌خواند؟ چون هر یک از این‌ها اگر ردیفی
 * جا بگذارد یا دوبار بشمارد، عددی می‌سازد که درست به نظر می‌رسد و
 * نیست — و این نانوایی دو بار همین هفته آن را دیده.
 */
class AClumsyDayStillAddsUpTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 3_000;

    private User $owner;

    private User $doughMaker;

    private User $chaneGir;

    private User $seller;

    private BankAccount $bank;

    private BankAccount $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman', 'bread_price' => self::PRICE]);
        Money::forgetCache();

        $this->owner = $this->staff('مالک', 'admin');
        $this->doughMaker = $this->staff('خمیرگیر', 'dough_maker', 6_000_000);
        $this->chaneGir = $this->staff('چانه‌گیر', 'chane_gir', 7_000_000);
        $this->seller = $this->staff('فروشنده', 'seller', 5_000_000);

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 100_000_000,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_cash_box' => true,
            'is_active' => true,
        ]);

        foreach (['flour' => 20_000, 'salt' => 2_000, 'yeast_dry' => 1_000] as $item => $kg) {
            $this->as($this->owner)->postJson('/api/v1/inventory/movements', [
                'item' => $item,
                'direction' => 'in',
                'quantity' => $kg,
            ])->assertCreated();
        }
    }

    private function staff(string $name, string $role, int $salary = 0): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'monthly_salary' => $salary,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function as(User $user): self
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * One write, carrying one idempotency key.
     *
     * The header is cleared afterwards. `withHeader` on a test case is
     * sticky, so a key set for a sale rode along on the next unrelated
     * call and the middleware — correctly — refused it as a key reused
     * for a different request. A real phone sends a fresh key per write;
     * this makes the test behave the way the phone does.
     */
    private function write(User $who, string $key, string $method, string $url, array $body = [])
    {
        $response = $this->as($who)
            ->withHeaders(['Idempotency-Key' => $key])
            ->json($method, $url, $body);

        $this->flushHeaders();

        return $response;
    }

    private function bankBalance(): float
    {
        return round((float) $this->bank->fresh()->balance, 2);
    }

    private function tillBalance(): float
    {
        return round((float) $this->till->fresh()->balance, 2);
    }

    private function bake(int $chane = 150, bool $again = false): ChaneEntry
    {
        $dough = $this->as($this->doughMaker)->postJson('/api/v1/dough-entries', [
            'bag_count' => 10,
            'yeast_type' => 'dry',
            'force' => $again,
        ])->assertCreated()->json('data.entry');

        $entry = $this->as($this->chaneGir)->postJson('/api/v1/chane-entries', [
            'dough_entry_id' => $dough['id'],
            'chane_count' => $chane,
            'spray_flour_kg' => 3,
            'force' => $again,
        ])->assertCreated()->json('data.entry');

        return ChaneEntry::findOrFail($entry['id']);
    }

    // ------------------------------------------ دکمه را دوبار زد

    public function test_the_phone_was_slow_so_he_pressed_it_twice(): void
    {
        $batch = $this->bake();

        $body = [
            'chane_entry_id' => $batch->id,
            'payments' => [['payment_type' => 'card', 'bread_count' => 150, 'amount' => 150 * self::PRICE]],
        ];

        // The same request, the same key: the app retrying after a
        // timeout, not a second sale. Both come back 201 and the shop
        // banked one lot of takings.
        $first = $this->write($this->seller, 'retry-ab-12-cd', 'POST', '/api/v1/sales', $body)
            ->assertCreated();

        $second = $this->write($this->seller, 'retry-ab-12-cd', 'POST', '/api/v1/sales', $body)
            ->assertCreated();

        $this->assertSame('true', $second->headers->get('Idempotent-Replay'));
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Sale::count());
        $this->assertEqualsWithDelta(100_000_000 + 150 * self::PRICE, $this->bankBalance(), 0.01);
    }

    public function test_a_second_advance_the_same_day_is_a_second_advance_not_a_retry(): void
    {
        // A different key, so the shop is saying «this really is another
        // one». Eight characters at least — the middleware refuses a key
        // too short to be unique, which is a guard and not an obstacle:
        // «k1» from two phones on one day is one key by accident. Two people can genuinely be given money on one day, and a
        // guard that refused the second would be worse than none: the
        // second advance would go unwritten and the bank would not know.
        foreach (['advance-one-1405', 'advance-two-1405'] as $key) {
            $this->write($this->owner, $key, 'POST', '/api/v1/staff-advances', [
                'user_id' => $this->doughMaker->id,
                'amount' => 1_000_000,
            ])->assertCreated();
        }

        $this->assertSame(2, StaffAdvance::count());
        $this->assertEqualsWithDelta(100_000_000 - 2_000_000, $this->bankBalance(), 0.01);
    }

    public function test_the_same_key_on_a_different_amount_is_refused_rather_than_answered(): void
    {
        $this->write($this->owner, 'collided-key-1405', 'POST', '/api/v1/staff-advances', [
            'user_id' => $this->doughMaker->id,
            'amount' => 1_000_000,
        ])->assertCreated();

        // A different body under a key already used is either a bug or
        // somebody's key colliding. Handing back the first answer would
        // tell the shop two million left when one did.
        $this->write($this->owner, 'collided-key-1405', 'POST', '/api/v1/staff-advances', [
            'user_id' => $this->doughMaker->id,
            'amount' => 2_000_000,
        ])->assertStatus(409);

        $this->assertSame(1, StaffAdvance::count());
        $this->assertEqualsWithDelta(100_000_000 - 1_000_000, $this->bankBalance(), 0.01);
    }

    // ----------------------------------------- عدد را غلط زد و درست کرد

    public function test_he_typed_the_wage_wrong_and_fixed_it(): void
    {
        $slip = $this->as($this->owner)->postJson('/api/v1/salaries', [
            'user_id' => $this->chaneGir->id,
            'period_start' => '1405/05/01',
            'base_amount' => 70_000_000,
            'paid_on' => '1405/05/31',
        ])->assertCreated()->json('data');

        // Seventy million instead of seven. The bank is wrong by
        // 63,000,000 for as long as it takes him to notice.
        $this->assertEqualsWithDelta(100_000_000 - 70_000_000, $this->bankBalance(), 0.01);

        $this->as($this->owner)->putJson("/api/v1/salaries/{$slip['id']}", [
            'base_amount' => 7_000_000,
        ])->assertOk();

        // Rebuilt, not added to. A correction that stacked would leave the
        // bank down 77,000,000 and every figure after it wrong.
        $this->assertEqualsWithDelta(100_000_000 - 7_000_000, $this->bankBalance(), 0.01);
        $this->assertSame(1, SalaryPayment::find($slip['id'])->bankTransactions()->count());
    }

    public function test_he_paid_the_bill_from_the_wrong_account_and_moved_it(): void
    {
        $expense = $this->as($this->owner)->postJson('/api/v1/expenses', [
            'category' => 'utilities',
            'title' => 'قبض برق',
            'amount' => 2_000_000,
            'paid_in_cash' => true,
        ])->assertCreated()->json('data');

        // Out of a drawer that has nothing in it — which is how he finds
        // out he paid it from the bank.
        $this->assertEqualsWithDelta(-2_000_000, $this->tillBalance(), 0.01);

        $this->as($this->owner)->putJson("/api/v1/expenses/{$expense['id']}", [
            'bank_account_id' => $this->bank->id,
        ])->assertOk();

        // Moved, not copied. The drawer is whole again and the bank carries
        // it once.
        $this->assertSame(0.0, $this->tillBalance());
        $this->assertEqualsWithDelta(100_000_000 - 2_000_000, $this->bankBalance(), 0.01);
        $this->assertSame(1, Expense::find($expense['id'])->bankTransactions()->count());
    }

    public function test_he_entered_something_that_never_happened_and_deleted_it(): void
    {
        $advance = $this->as($this->owner)->postJson('/api/v1/staff-advances', [
            'user_id' => $this->seller->id,
            'amount' => 3_000_000,
        ])->assertCreated()->json('data');

        $this->as($this->owner)
            ->deleteJson("/api/v1/staff-advances/{$advance['id']}")
            ->assertOk();

        // The money comes back and the man owes nothing. A deletion that
        // left the posting behind would be three million the shop thinks
        // it spent and a debt nobody can find the other half of.
        $this->assertEqualsWithDelta(100_000_000, $this->bankBalance(), 0.01);
        $this->assertSame(0.0, StaffAdvance::outstandingFor($this->seller->id));
    }

    // -------------------------------------- فروشنده کمتر تحویل داد

    public function test_the_seller_handed_over_less_than_he_owed(): void
    {
        $batch = $this->bake();

        $this->as($this->seller)->postJson('/api/v1/sales', [
            'chane_entry_id' => $batch->id,
            'payments' => [['payment_type' => 'cash', 'bread_count' => 150, 'amount' => 150 * self::PRICE]],
        ])->assertCreated();

        $owed = 150 * self::PRICE;

        // He hands over most of it and says he will bring the rest.
        $this->as($this->seller)->postJson('/api/v1/settlement-requests', [
            'paid_cash' => $owed - 50_000,
        ])->assertCreated();

        // Read off the owner's own screen — the seller-accounts page,
        // which is where he actually sees somebody waiting to hand over.
        $row = collect($this->as($this->owner)->getJson('/api/v1/seller-accounts')
            ->assertOk()->json('data.sellers'))
            ->firstWhere('id', $this->seller->id);

        $this->assertNotNull($row['request'] ?? null, 'درخواست تسویه روی صفحهٔ مالک نیست.');

        $this->as($this->owner)
            ->postJson("/api/v1/settlement-requests/{$row['request']['id']}/confirm", [])
            ->assertOk();

        // The drawer holds what he actually handed over — not what he
        // owed. A shop that banked the full figure would show fifty
        // thousand in a drawer nobody put it in.
        $this->assertEqualsWithDelta($owed - 50_000, $this->tillBalance(), 0.01);

        // And the fifty thousand is still his to bring.
        $accounts = collect($this->as($this->owner)->getJson('/api/v1/seller-accounts')
            ->assertOk()->json('data.sellers'));

        $row = $accounts->firstWhere('id', $this->seller->id);

        $this->assertNotNull($row, 'کسری فروشنده از فهرست افتاده — یعنی بخشیده شده.');

        // The whole sale is still open: a sale settles whole or not at
        // all, so the 400,000 sits as credit against it until the rest
        // arrives. What matters is that the 50,000 is still findable.
        $this->assertEqualsWithDelta($owed, (float) $row['settleable'], 0.01);
        // The debt is still the whole sale — a sale settles whole or not
        // at all — but what he handed over is on the page beside it, and
        // «still_owed» is the figure the owner would actually ask for.
        $this->assertEqualsWithDelta($owed, (float) $row['settleable'], 0.01);
        $this->assertEqualsWithDelta($owed - 50_000, (float) $row['on_account'], 0.01);
        $this->assertEqualsWithDelta(50_000, (float) $row['still_owed'], 0.01);
    }

    // ------------------------------------------- کلِ روزِ شلوغ، با هم

    public function test_after_all_of_it_the_books_still_agree_with_the_money(): void
    {
        // Every mistake above, on one shop, one after another.
        $batch = $this->bake();

        $body = [
            'chane_entry_id' => $batch->id,
            'payments' => [
                ['payment_type' => 'cash', 'bread_count' => 100, 'amount' => 100 * self::PRICE],
                ['payment_type' => 'card', 'bread_count' => 50, 'amount' => 50 * self::PRICE],
            ],
        ];

        // Tapped twice.
        $this->write($this->seller, 'clumsy-day-sale-1', 'POST', '/api/v1/sales', $body)
            ->assertCreated();
        $this->write($this->seller, 'clumsy-day-sale-1', 'POST', '/api/v1/sales', $body)
            ->assertCreated();

        // Settled in full this time.
        $this->as($this->owner)
            ->postJson("/api/v1/seller-accounts/{$this->seller->id}/settle", [])
            ->assertOk();

        // A bill paid from the wrong account, then moved.
        $expense = $this->as($this->owner)->postJson('/api/v1/expenses', [
            'category' => 'fuel',
            'title' => 'گازوئیل',
            'amount' => 1_000_000,
            'paid_in_cash' => true,
        ])->assertCreated()->json('data');

        $this->as($this->owner)->putJson("/api/v1/expenses/{$expense['id']}", [
            'bank_account_id' => $this->bank->id,
        ])->assertOk();

        // An advance entered by mistake and removed.
        $wrong = $this->as($this->owner)->postJson('/api/v1/staff-advances', [
            'user_id' => $this->seller->id,
            'amount' => 3_000_000,
        ])->assertCreated()->json('data');

        $this->as($this->owner)->deleteJson("/api/v1/staff-advances/{$wrong['id']}")->assertOk();

        // A real advance, kept.
        $this->as($this->owner)->postJson('/api/v1/staff-advances', [
            'user_id' => $this->doughMaker->id,
            'amount' => 1_000_000,
        ])->assertCreated();

        // A wage typed wrong and corrected.
        $slip = $this->as($this->owner)->postJson('/api/v1/salaries', [
            'user_id' => $this->doughMaker->id,
            'period_start' => '1405/05/01',
            'base_amount' => 60_000_000,
            'paid_on' => '1405/05/31',
        ])->assertCreated()->json('data');

        $this->as($this->owner)->putJson("/api/v1/salaries/{$slip['id']}", [
            'base_amount' => 6_000_000,
        ])->assertOk();

        // Counted by hand, as if none of the fumbling had happened —
        // because none of it should have left a trace on the money.
        $this->assertEqualsWithDelta(
            100_000_000
                + 50 * self::PRICE          // the card half of one sale, banked once
                - 1_000_000                 // the diesel, from the bank in the end
                - 1_000_000                 // the advance that was real
                - (6_000_000 - 1_000_000),  // the wage, net of that advance
            $this->bankBalance(),
            0.01,
        );

        $this->assertEqualsWithDelta(100 * self::PRICE, $this->tillBalance(), 0.01);

        // One sale, one expense, one advance, one payslip. The retry, the
        // wrong account, the wrong figure and the deleted row left nothing
        // behind.
        $this->assertSame(1, Sale::where('payment_type', 'cash')->count());
        $this->assertSame(1, Expense::count());
        $this->assertSame(1, StaffAdvance::count());
        $this->assertSame(1, SalaryPayment::count());

        // And the drawer counts clean, which is the only proof the owner
        // can actually hold in his hand.
        $count = $this->as($this->owner)->postJson('/api/v1/cash-counts', [
            'counted_amount' => 100 * self::PRICE,
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(0, (float) $count['difference'], 0.01);
    }

    public function test_the_split_he_typed_did_not_add_up_to_what_he_owed(): void
    {
        // The shape the phone actually sends: a breakdown per payment
        // type, and no «amount» at all when the seller said he was
        // handing over the lot.
        //
        // The split dialog shows a mismatch in red but does not stop him
        // confirming, so a seller who types ۴۰۰ into a ۴۵۰ account gets
        // this request through — and the shop used to close the whole
        // account for it.
        $batch = $this->bake();

        $this->as($this->seller)->postJson('/api/v1/sales', [
            'chane_entry_id' => $batch->id,
            'payments' => [['payment_type' => 'cash', 'bread_count' => 150, 'amount' => 150 * self::PRICE]],
        ])->assertCreated();

        $owed = 150 * self::PRICE;

        $this->as($this->seller)->postJson('/api/v1/settlement-requests', [
            'payments' => [['payment_type' => 'cash', 'amount' => $owed - 50_000]],
        ])->assertCreated();

        $row = collect($this->as($this->owner)->getJson('/api/v1/seller-accounts')
            ->assertOk()->json('data.sellers'))
            ->firstWhere('id', $this->seller->id);

        $this->as($this->owner)
            ->postJson("/api/v1/settlement-requests/{$row['request']['id']}/confirm", [])
            ->assertOk();

        $after = collect($this->as($this->owner)->getJson('/api/v1/seller-accounts')
            ->assertOk()->json('data.sellers'))
            ->firstWhere('id', $this->seller->id);

        $this->assertNotNull($after, 'حساب کامل بسته شد — یعنی ۵۰ هزار بخشیده شد.');
        $this->assertEqualsWithDelta(50_000, (float) $after['still_owed'], 0.01);

        // And the drawer holds what he actually counted out, not what he
        // owed.
        $this->assertEqualsWithDelta($owed - 50_000, $this->tillBalance(), 0.01);
    }

    public function test_handing_over_the_whole_account_still_closes_it(): void
    {
        // The other half of the same change, and the one that must not
        // have moved: a split that does add up clears the account exactly
        // as it always did.
        $batch = $this->bake();

        $this->as($this->seller)->postJson('/api/v1/sales', [
            'chane_entry_id' => $batch->id,
            'payments' => [['payment_type' => 'cash', 'bread_count' => 150, 'amount' => 150 * self::PRICE]],
        ])->assertCreated();

        $owed = 150 * self::PRICE;

        $this->as($this->seller)->postJson('/api/v1/settlement-requests', [
            'payments' => [['payment_type' => 'cash', 'amount' => $owed]],
        ])->assertCreated();

        $row = collect($this->as($this->owner)->getJson('/api/v1/seller-accounts')
            ->assertOk()->json('data.sellers'))
            ->firstWhere('id', $this->seller->id);

        $this->as($this->owner)
            ->postJson("/api/v1/settlement-requests/{$row['request']['id']}/confirm", [])
            ->assertOk();

        $this->assertNull(
            collect($this->as($this->owner)->getJson('/api/v1/seller-accounts')
                ->assertOk()->json('data.sellers'))
                ->firstWhere('id', $this->seller->id),
        );

        $this->assertEqualsWithDelta($owed, $this->tillBalance(), 0.01);
    }

    public function test_an_older_phone_that_sends_nothing_still_settles_the_lot(): void
    {
        // A copy of the app from before the split existed sends neither an
        // amount nor a breakdown, and means «all of it, in notes». That
        // path is the reason the whole-account default exists and it has
        // to keep working.
        $batch = $this->bake();

        $this->as($this->seller)->postJson('/api/v1/sales', [
            'chane_entry_id' => $batch->id,
            'payments' => [['payment_type' => 'cash', 'bread_count' => 150, 'amount' => 150 * self::PRICE]],
        ])->assertCreated();

        $this->as($this->seller)->postJson('/api/v1/settlement-requests', [])->assertCreated();

        $row = collect($this->as($this->owner)->getJson('/api/v1/seller-accounts')
            ->assertOk()->json('data.sellers'))
            ->firstWhere('id', $this->seller->id);

        $this->as($this->owner)
            ->postJson("/api/v1/settlement-requests/{$row['request']['id']}/confirm", [])
            ->assertOk();

        $this->assertEqualsWithDelta(150 * self::PRICE, $this->tillBalance(), 0.01);
        $this->assertNull(
            collect($this->as($this->owner)->getJson('/api/v1/seller-accounts')
                ->assertOk()->json('data.sellers'))
                ->firstWhere('id', $this->seller->id),
        );
    }
}
