<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * یک ماه کامل نانوایی، با ده کارمندِ ساختگی، از راه همان API‌ای که
 * گوشی‌ها می‌زنند.
 *
 * هر تستِ دیگرِ این پروژه یک تکه را جدا نگه می‌دارد. چیزی که هیچ‌کدام
 * نمی‌گیرند این است: وقتی همهٔ تکه‌ها پشت سر هم روی یک نانوایی اجرا
 * شوند، آیا دفتر آخرِ ماه با پولِ واقعی می‌خواند؟ دو تا از باگ‌های این
 * هفته — مساعده روی صندوق، حقوق روی صندوق — هرکدام تستِ سبزِ خودشان را
 * داشتند و باز هم پول جای غلط می‌نشست، چون هیچ‌جا کلِ ماه با هم جمع
 * زده نمی‌شد.
 *
 * پس اینجا جمع زده می‌شود. هر تومانی که وارد یا خارج می‌شود دستی
 * شمرده می‌شود و آخرِ کار با موجودیِ حساب‌ها مقایسه می‌شود. اگر روزی
 * مسیری پول را جایی ننشاند یا دوبار بنشاند، این تست قرمز می‌شود حتی
 * اگر تستِ اختصاصیِ خودش سبز بماند.
 *
 * و با چند نفر از هر نقش، نه یکی: یک فروشنده هرگز نشان نمی‌دهد که
 * حسابِ فروشنده‌ها از هم جدا می‌ماند، و یک شاطر نشان نمی‌دهد که نانِ
 * منزلِ هرکس از فیشِ خودش کم می‌شود.
 */
class AWholeMonthAtTheBakeryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What a loaf sells for, said out loud.
     *
     * The server prices the bread itself and charges the seller for the
     * difference, so an `amount` invented here would arrive as a seller
     * who is short. Every figure in this file is loaves × this.
     */
    private const PRICE = 3_000;

    private const WAGE = [
        'خمیرگیر' => 6_000_000,
        'چانه‌گیر' => 7_000_000,
        'شاطر' => 9_000_000,
        'فروشنده' => 5_000_000,
    ];

    private User $owner;

    /** @var Collection<int, User> */
    private $doughMakers;

    /** @var Collection<int, User> */
    private $chaneGirs;

    /** @var Collection<int, User> */
    private $shaters;

    /** @var Collection<int, User> */
    private $sellers;

    private BankAccount $bank;

    private BankAccount $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        // Toman throughout, so every figure in this file is the figure the
        // ledger holds and nothing has to be converted while reading it.
        Bakery::first()->update([
            'currency' => 'toman',
            'bread_price' => self::PRICE,
        ]);
        Money::forgetCache();

        $this->owner = $this->staff('مالک', 'admin');
        $this->doughMakers = $this->crew('خمیرگیر', 'dough_maker', 2);
        $this->chaneGirs = $this->crew('چانه‌گیر', 'chane_gir', 2);
        $this->shaters = $this->crew('شاطر', 'shater', 3);
        $this->sellers = $this->crew('فروشنده', 'seller', 4);

        $this->bank = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 300_000_000,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_cash_box' => true,
            'is_active' => true,
        ]);

        $this->stockUp();
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

    /** @return Collection<int, User> */
    private function crew(string $name, string $role, int $count)
    {
        return collect(range(1, $count))->map(
            fn (int $n) => $this->staff("{$name} {$n}", $role, self::WAGE[$name])
        );
    }

    /** Everyone who draws a wage. */
    private function everyone()
    {
        return $this->doughMakers
            ->concat($this->chaneGirs)
            ->concat($this->shaters)
            ->concat($this->sellers);
    }

    /**
     * What is in the store room before the month starts.
     *
     * Through the stock movements, not a purchase: this is the shop as it
     * was left last month, and it must not add a payment to a mill that
     * this month's arithmetic would then have to subtract.
     */
    private function stockUp(): void
    {
        foreach (['flour' => 20_000, 'salt' => 2_000, 'yeast_dry' => 1_000] as $item => $kg) {
            $this->as($this->owner)->postJson('/api/v1/inventory/movements', [
                'item' => $item,
                'direction' => 'in',
                'quantity' => $kg,
            ])->assertCreated();
        }
    }

    private function as(User $user): self
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function bankBalance(): float
    {
        return round((float) $this->bank->fresh()->balance, 2);
    }

    private function tillBalance(): float
    {
        return round((float) $this->till->fresh()->balance, 2);
    }

    // ----------------------------------------------------------- the month

    /** Flour arrives. Part paid on the spot, the rest on the mill's account. */
    private function buyFlour(): array
    {
        return $this->as($this->owner)->postJson('/api/v1/purchases', [
            'supplier_name' => 'آسیاب مرکزی',
            'invoice_no' => 'F-1405-05',
            'paid_amount' => 30_000_000,
            'bank_account_id' => $this->bank->id,
            'items' => [
                ['item' => 'flour', 'bags' => 40, 'unit_price' => 1_500_000],
            ],
        ])->assertCreated()->json('data');
    }

    /**
     * One morning's batch: a خمیرگیر kneads, a چانه‌گیر counts the chane off
     * it. The شاطر works the oven and records nothing — which is why he is
     * on the roster here without ever appearing in these two calls.
     *
     * The shop kneads once a day whoever is holding the phone, so a second
     * batch has to say so — `force` is how the app says the person was
     * shown today's batch and confirmed this is another one.
     */
    private function bake(int $chane = 1_200, ?User $doughMaker = null, ?User $chaneGir = null, bool $again = false): ChaneEntry
    {
        $dough = $this->as($doughMaker ?? $this->doughMakers->first())
            ->postJson('/api/v1/dough-entries', [
                'bag_count' => 10,
                'yeast_type' => 'dry',
                'force' => $again,
            ])->assertCreated()->json('data.entry');

        $entry = $this->as($chaneGir ?? $this->chaneGirs->first())
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough['id'],
                'chane_count' => $chane,
                'spray_flour_kg' => 3,
                'force' => $again,
            ])->assertCreated()->json('data.entry');

        return ChaneEntry::findOrFail($entry['id']);
    }

    /**
     * A batch goes out through one seller, broken down by how it was paid.
     *
     * The batch is baked to the size of what is sold off it: whatever it
     * held and nobody bought is charged to the seller as a shortfall, and
     * a batch left half-sold here would show up as a seller in debt in
     * every figure below.
     *
     * Not one call per payment type: a chane entry is claimed by the
     * first sale against it and then closed, because two sellers tapping
     * the same batch is bread out of the warehouse twice. So the split
     * travels with the one call, which is also how the app sends it.
     *
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function sell(ChaneEntry $batch, User $seller, array $payments): array
    {
        return $this->as($seller)->postJson('/api/v1/sales', [
            'chane_entry_id' => $batch->id,
            'payments' => $payments,
        ])->assertCreated()->json('data');
    }

    /**
     * One payment line, in the shape the sales endpoint takes.
     *
     * The money is the shop's own price for those loaves. Anything else
     * and the seller is recorded as having come up short, which is a real
     * feature and not what any of these tests is asking about.
     */
    private function paid(string $type, int $loaves, array $extra = []): array
    {
        return array_merge([
            'payment_type' => $type,
            'bread_count' => $loaves,
            'amount' => $loaves * self::PRICE,
        ], $extra);
    }

    /** What a batch of this many loaves is worth. */
    private function worth(int $loaves): float
    {
        return $loaves * self::PRICE;
    }

    private function settle(User $seller): void
    {
        $this->as($this->owner)
            ->postJson("/api/v1/seller-accounts/{$seller->id}/settle", [])
            ->assertOk();
    }

    private function payWage(User $person, float $base): array
    {
        return $this->as($this->owner)->postJson('/api/v1/salaries', [
            'user_id' => $person->id,
            'period_start' => '1405/05/01',
            'base_amount' => $base,
            'paid_on' => '1405/05/31',
        ])->assertCreated()->json('data');
    }

    // ------------------------------------------------------------ the tests

    public function test_a_days_bread_goes_from_flour_to_takings(): void
    {
        $this->buyFlour();

        $batch = $this->bake(900);

        $this->sell($batch, $this->sellers[0], [
            $this->paid('cash', 600),
            $this->paid('card', 300),
        ]);

        // The chain holds: the batch knows its dough, the sales know their
        // batch, and nothing was recorded against a batch that does not
        // exist.
        $this->assertSame(1, DoughEntry::count());
        $this->assertNotNull($batch->doughEntry);
        $this->assertSame(2, Sale::count());
        $this->assertSame(900, (int) Sale::sum('bread_count'));
    }

    public function test_card_takings_reach_the_bank_and_cash_does_not(): void
    {
        $batch = $this->bake(900);

        $before = $this->bankBalance();

        $this->sell($batch, $this->sellers[0], [
            $this->paid('card', 300),
            $this->paid('cash', 600),
        ]);

        // Card money lands in an account the same day. Cash does not — it
        // is in the seller's pocket until they hand it over, and a shop
        // that banked it on the sale would show money it cannot touch.
        $this->assertEqualsWithDelta($before + $this->worth(300), $this->bankBalance(), 0.01);
        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_four_sellers_takings_do_not_run_into_one_another(): void
    {
        // A batch each, and a different amount each, so a total that came
        // out right by accident cannot hide one seller's money landing on
        // another's account.
        foreach ([100, 200, 300, 400] as $i => $loaves) {
            $batch = $this->bake($loaves, again: $i > 0);

            $this->sell($batch, $this->sellers[$i], [$this->paid('cash', $loaves)]);
        }

        // Only the third hands over. The other three still owe exactly
        // what they took, and none of them owes any of his.
        $this->settle($this->sellers[2]);

        $this->assertEqualsWithDelta($this->worth(300), $this->tillBalance(), 0.01);

        $accounts = collect($this->as($this->owner)->getJson('/api/v1/seller-accounts')
            ->assertOk()->json('data.sellers'));

        foreach ([100, 200, 400] as $i => $loaves) {
            // 0, 1 and 3 — the third is the one who settled.
            $seller = $this->sellers[$i > 1 ? $i + 1 : $i];
            $row = $accounts->firstWhere('id', $seller->id);

            $this->assertNotNull($row, "حساب «{$seller->name}» در فهرست نیست.");
            $this->assertEqualsWithDelta($this->worth($loaves), (float) $row['settleable'], 0.01);
        }

        // And the one who handed over is off the list entirely — the page
        // is what is still owed, not a roll call.
        $this->assertNull($accounts->firstWhere('id', $this->sellers[2]->id));
    }

    public function test_bread_taken_home_is_charged_to_that_persons_payslip(): void
    {
        $batch = $this->bake(50);

        // Two different شاطرs take bread home on the same day, for
        // different amounts. A deduction that went to whoever came first,
        // or to the seller who rang it up, would still total the same.
        $this->sell($batch, $this->sellers[0], [
            $this->paid('home', 20, ['consumed_by_user_id' => $this->shaters[0]->id]),
            $this->paid('home', 30, ['consumed_by_user_id' => $this->shaters[1]->id]),
        ]);

        // Nobody paid at the counter, so no account moved — but it is not
        // free either.
        $this->assertSame(0.0, $this->tillBalance());

        $first = $this->payWage($this->shaters[0], self::WAGE['شاطر']);
        $second = $this->payWage($this->shaters[1], self::WAGE['شاطر']);
        $third = $this->payWage($this->shaters[2], self::WAGE['شاطر']);

        $this->assertEqualsWithDelta($this->worth(20), (float) SalaryPayment::find($first['id'])->bread_deduction, 0.01);
        $this->assertEqualsWithDelta($this->worth(30), (float) SalaryPayment::find($second['id'])->bread_deduction, 0.01);

        // And the one who took none is charged none.
        $this->assertEqualsWithDelta(0, (float) SalaryPayment::find($third['id'])->bread_deduction, 0.01);
    }

    public function test_an_advance_asked_for_and_granted_leaves_the_bank(): void
    {
        $this->as($this->doughMakers[0])->postJson('/api/v1/advance-requests', [
            'amount' => 2_000_000,
            'reason' => 'اجاره خانه',
        ])->assertCreated();

        $pending = $this->as($this->owner)->getJson('/api/v1/advance-requests')
            ->assertOk()->json('data');
        $request = $pending['data'][0] ?? $pending[0];

        $before = $this->bankBalance();

        $this->as($this->owner)
            ->patchJson("/api/v1/advance-requests/{$request['id']}/approve", [])
            ->assertOk();

        // «علی الحساب‌ها ... باید روی حساب سفید باشد». Nobody named an
        // account, so it comes off the bank and the drawer is untouched.
        $this->assertEqualsWithDelta($before - 2_000_000, $this->bankBalance(), 0.01);
        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_the_wage_pays_the_advance_back_and_only_the_rest_leaves(): void
    {
        $this->as($this->owner)->postJson('/api/v1/staff-advances', [
            'user_id' => $this->doughMakers[0]->id,
            'amount' => 2_000_000,
        ])->assertCreated();

        $before = $this->bankBalance();

        $this->payWage($this->doughMakers[0], self::WAGE['خمیرگیر']);

        // The advance already left the bank when it was handed over.
        // Paying the gross now would take it a second time.
        $this->assertEqualsWithDelta($before - 4_000_000, $this->bankBalance(), 0.01);
        $this->assertSame(0.0, StaffAdvance::outstandingFor($this->doughMakers[0]->id));

        // And it was his advance, not the shop's: the man beside him owes
        // nothing and is paid in full.
        $before = $this->bankBalance();
        $this->payWage($this->doughMakers[1], self::WAGE['خمیرگیر']);
        $this->assertEqualsWithDelta($before - 6_000_000, $this->bankBalance(), 0.01);
    }

    public function test_wages_and_advances_both_leave_the_bank_never_the_drawer(): void
    {
        // The whole of this week's two bugs, asked as one question of a
        // shop that has both a bank and a drawer, and nine people on it.
        foreach ($this->everyone() as $person) {
            $this->as($this->owner)->postJson('/api/v1/staff-advances', [
                'user_id' => $person->id,
                'amount' => 1_000_000,
            ])->assertCreated();
        }

        // 11 × 1,000,000 out, all of it off the bank.
        $this->assertEqualsWithDelta(300_000_000 - 11_000_000, $this->bankBalance(), 0.01);
        $this->assertSame(0.0, $this->tillBalance());
        $this->assertSame(11, BankTransaction::where('reason', 'advance')->count());

        foreach ($this->everyone() as $person) {
            $this->payWage($person, (float) $person->monthly_salary);
        }

        // Gross 2×6 + 2×7 + 3×9 + 4×5 = 73,000,000, less the 11,000,000 of
        // advances each wage takes back = 62,000,000. And the advances
        // themselves already came off, so the bank is down 73,000,000 all
        // told — not 84,000,000, which is what taking the gross twice
        // would look like.
        $this->assertEqualsWithDelta(300_000_000 - 73_000_000, $this->bankBalance(), 0.01);
        $this->assertSame(0.0, $this->tillBalance());
        $this->assertSame(11, BankTransaction::where('reason', 'salary')->count());
    }

    public function test_an_expense_from_the_drawer_and_one_from_the_bank(): void
    {
        $batch = $this->bake(600);
        $this->sell($batch, $this->sellers[0], [$this->paid('cash', 600)]);
        $this->settle($this->sellers[0]);

        $this->as($this->owner)->postJson('/api/v1/expenses', [
            'category' => 'fuel',
            'title' => 'گازوئیل',
            'amount' => 1_000_000,
            'paid_in_cash' => true,
        ])->assertCreated();

        $this->as($this->owner)->postJson('/api/v1/expenses', [
            'category' => 'utilities',
            'title' => 'قبض برق',
            'amount' => 2_000_000,
        ])->assertCreated();

        // Each came out of the one it was said to come out of. The
        // drawer's money is the cash the seller handed over.
        $this->assertEqualsWithDelta($this->worth(600) - 1_000_000, $this->tillBalance(), 0.01);
        $this->assertEqualsWithDelta(300_000_000 - 2_000_000, $this->bankBalance(), 0.01);
    }

    public function test_paying_the_mill_comes_off_the_account_it_was_paid_from(): void
    {
        $purchase = $this->buyFlour();

        $before = $this->bankBalance();

        $this->as($this->owner)->postJson('/api/v1/supplier-payments', [
            'supplier_id' => $purchase['supplier_id'],
            'purchase_id' => $purchase['id'],
            'amount' => 10_000_000,
            'bank_account_id' => $this->bank->id,
        ])->assertCreated();

        $this->assertEqualsWithDelta($before - 10_000_000, $this->bankBalance(), 0.01);
    }

    public function test_counting_the_drawer_agrees_with_the_books(): void
    {
        $batch = $this->bake(600);
        $this->sell($batch, $this->sellers[0], [$this->paid('cash', 600)]);
        $this->settle($this->sellers[0]);

        $count = $this->as($this->owner)->postJson('/api/v1/cash-counts', [
            'counted_amount' => $this->worth(600),
        ])->assertCreated()->json('data');

        // Nothing to explain. A shop where this is not zero is a shop with
        // a question to answer, and the point of counting is to be told.
        $this->assertEqualsWithDelta(0, (float) $count['difference'], 0.01);
    }

    public function test_the_whole_month_adds_up_to_the_toman(): void
    {
        // Everything above, one after the other on one shop with eleven
        // people on it, with every movement counted by hand. This is the
        // assertion the two account bugs would have failed while their own
        // tests stayed green.
        $purchase = $this->buyFlour();                                   // bank −30,000,000

        // Two mornings, a different crew each, so the batches are not one
        // person's habit repeated — and a batch per seller, because a
        // batch is claimed by the sale against it.
        // Each batch is baked to the size of what comes off it: 100 loaves
        // sold for cash, 50 on the reader, and 20 more on the Tuesday for
        // the شاطر who takes bread home.
        $first = true;
        $monday = collect($this->sellers)->map(function () use (&$first) {
            $batch = $this->bake(150, $this->doughMakers[0], $this->chaneGirs[0], again: ! $first);
            $first = false;

            return $batch;
        });

        $tuesday = collect($this->sellers)->map(
            fn (User $seller, int $i) => $this->bake(
                $i === 0 ? 170 : 150,
                $this->doughMakers[1],
                $this->chaneGirs[1],
                again: true,
            )
        );

        // Four sellers over two days, cash and card, and one loaf of
        // «منزل» against a شاطر's own payslip.
        foreach ([$monday, $tuesday] as $day) {
            foreach ($day as $i => $batch) {
                $lines = [
                    $this->paid('cash', 100),                             // 8 × 100 loaves
                    $this->paid('card', 50),                              // 8 ×  50 loaves
                ];

                // One شاطر takes bread home on the Tuesday, against his
                // own payslip rather than the seller's account.
                if ($i === 0 && $day === $tuesday) {
                    $lines[] = $this->paid('home', 20, [
                        'consumed_by_user_id' => $this->shaters[0]->id,
                    ]);
                }

                $this->sell($batch, $this->sellers[$i], $lines);
            }
        }

        foreach ($this->sellers as $seller) {
            $this->settle($seller);                                      // till +2,000,000 each
        }

        $this->as($this->owner)->postJson('/api/v1/supplier-payments', [
            'supplier_id' => $purchase['supplier_id'],
            'amount' => 10_000_000,
            'bank_account_id' => $this->bank->id,
        ])->assertCreated();                                             // bank −10,000,000

        $this->as($this->owner)->postJson('/api/v1/expenses', [
            'category' => 'fuel',
            'title' => 'گازوئیل',
            'amount' => 1_000_000,
            'paid_in_cash' => true,
        ])->assertCreated();                                             // till −1,000,000

        $this->as($this->owner)->postJson('/api/v1/expenses', [
            'category' => 'utilities',
            'title' => 'قبض برق',
            'amount' => 2_000_000,
        ])->assertCreated();                                             // bank −2,000,000

        foreach ($this->everyone() as $person) {
            $this->as($this->owner)->postJson('/api/v1/staff-advances', [
                'user_id' => $person->id,
                'amount' => 1_000_000,
            ])->assertCreated();                                         // bank −11,000,000
        }

        foreach ($this->everyone() as $person) {
            $this->payWage($person, (float) $person->monthly_salary);
        }
        // Gross 73,000,000, less 11,000,000 of advances recovered and the
        // 20 loaves one شاطر took home.

        $this->assertEqualsWithDelta(
            300_000_000
                - 30_000_000                 // flour paid on delivery
                + $this->worth(8 * 50)       // card takings, banked as they are rung up
                - 10_000_000                 // the mill
                - 2_000_000                  // the electricity bill
                - 11_000_000                 // advances
                - (73_000_000                // wages, gross
                    - 11_000_000             //   less every advance, recovered
                    - $this->worth(20)),     //   less the bread taken home
            $this->bankBalance(),
            0.01,
        );

        $this->assertEqualsWithDelta(
            $this->worth(8 * 100)     // cash handed over by four sellers over two days
                - 1_000_000,          // the diesel
            $this->tillBalance(),
            0.01,
        );

        // And the drawer can be counted against that figure without an
        // adjustment appearing out of nowhere to make it fit.
        $count = $this->as($this->owner)->postJson('/api/v1/cash-counts', [
            'counted_amount' => $this->worth(8 * 100) - 1_000_000,
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(0, (float) $count['difference'], 0.01);

        // Nobody is left owing the shop, and the shop is left owing
        // nobody. A month that balances on the accounts but leaves a
        // seller's takings hanging is not a month that closed.
        $accounts = collect($this->as($this->owner)->getJson('/api/v1/seller-accounts')
            ->assertOk()->json('data.sellers'));

        foreach ($this->sellers as $seller) {
            $this->assertNull(
                $accounts->firstWhere('id', $seller->id),
                "«{$seller->name}» هنوز بدهکار است.",
            );
        }

        foreach ($this->everyone() as $person) {
            $this->assertSame(0.0, StaffAdvance::outstandingFor($person->id));
        }
    }

    public function test_every_owners_report_opens_after_a_month_of_real_data(): void
    {
        // Not what they say — that is each report's own test. That they
        // answer at all once there is a month of everything behind them,
        // which is the state no single-feature test ever builds.
        $this->test_the_whole_month_adds_up_to_the_toman();

        foreach ([
            '/api/v1/today',
            '/api/v1/reports/dashboard',
            '/api/v1/reports/financial',
            '/api/v1/reports/payroll',
            '/api/v1/reports/balance-sheet',
            '/api/v1/reports/profit-and-loss',
            '/api/v1/reports/debts',
            '/api/v1/reports/production',
            '/api/v1/reports/sales',
            '/api/v1/reports/flour',
            '/api/v1/reports/sellers',
            '/api/v1/reports/staff-yield',
            '/api/v1/reports/inventory',
            '/api/v1/seller-accounts',
            '/api/v1/bank-accounts',
            '/api/v1/cash-counts',
            '/api/v1/suppliers/balances',
        ] as $report) {
            $this->as($this->owner)->getJson($report)->assertOk();
        }
    }
}
