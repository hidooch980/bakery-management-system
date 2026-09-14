<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\FlourSale;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * آردی که بخشیده می‌شود، پولی در کشو نمی‌گذارد.
 *
 * خیرات و منزل، آردی است که از انبار بیرون می‌رود و پولی بابتش نمی‌آید.
 * قیمت روی ردیف می‌ماند — مغازه باید بداند چه چیزی بخشیده — ولی همان
 * قیمت به صندوق هم نشانده می‌شد، انگار کسی بابتش اسکناس داده باشد.
 *
 * یک سالن خیرات و یک گونی منزل در ماه، صندوق را به اندازهٔ دو فروش واقعی
 * بالا می‌برد. هر دو طرف دفتر با هم می‌خواندند و هیچ‌کدام با کشو، که تنها
 * با شمردن پیدا می‌شود.
 *
 * `CashNeverBanked` از همان اول این دو نوع را از ترمیم کنار می‌گذاشت، پس
 * قاعده از ابتدا همین بود و فقط در مدل نوشته نشده بود.
 */
class FlourGivenAwayPutsNoMoneyInTheDrawerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private BankAccount $till;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'currency' => 'toman',
            'flour_bag_weight_kg' => 40,
        ]);
        Money::forgetCache();

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->till = BankAccount::create([
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

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 5_000, 'purchase');
    }

    private function sack(string $type): FlourSale
    {
        return FlourSale::create([
            'user_id' => $this->owner->id,
            'payment_type' => $type,
            'quantity' => 1,
            'unit' => 'bag',
            'unit_price' => 500_000,
            'sold_on' => now(),
        ]);
    }

    private function tillBalance(): float
    {
        return round((float) $this->till->fresh()->balance, 2);
    }

    public function test_charity_flour_moves_no_money(): void
    {
        $this->sack('charity');

        $this->assertSame(0.0, $this->tillBalance());
        $this->assertSame(0.0, round((float) $this->bank->fresh()->balance, 2));
    }

    public function test_flour_taken_home_moves_no_money(): void
    {
        $this->sack('home');

        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_what_was_given_away_is_still_worth_recording(): void
    {
        // ارزشش روی ردیف می‌ماند، فقط به حساب نمی‌نشیند: مغازه باید بتواند
        // بگوید ماه گذشته چقدر آرد بخشیده.
        $sack = $this->sack('charity');

        $this->assertEqualsWithDelta(500_000, (float) $sack->amount, 0.01);
        $this->assertCount(0, $sack->bankTransactions);
    }

    public function test_the_flour_still_leaves_the_store(): void
    {
        // بخشیدن یعنی آرد رفته. اگر انبار دست‌نخورده بماند، موجودی دروغ
        // می‌گوید و سفارش بعدی از روی عدد غلط بسته می‌شود.
        $this->sack('charity');

        $this->assertEqualsWithDelta(
            4_960,
            (float) InventoryItem::ofKey(InventoryItem::FLOUR)->balance,
            0.01,
        );
    }

    public function test_a_cash_sack_still_reaches_the_drawer(): void
    {
        $this->sack('cash');

        $this->assertEqualsWithDelta(500_000, $this->tillBalance(), 0.01);
    }

    public function test_a_card_sack_still_reaches_the_bank(): void
    {
        $this->sack('card');

        $this->assertEqualsWithDelta(500_000, (float) $this->bank->fresh()->balance, 0.01);
        $this->assertSame(0.0, $this->tillBalance());
    }

    public function test_a_sack_still_owed_for_reaches_neither(): void
    {
        $this->sack('credit');

        $this->assertSame(0.0, $this->tillBalance());
        $this->assertSame(0.0, round((float) $this->bank->fresh()->balance, 2));
    }

    public function test_a_month_of_giving_leaves_the_drawer_where_it_was(): void
    {
        // شکل واقعی ماه: یک فروش نقدی، یک خیرات، یک گونی منزل. کشو باید
        // فقط آن یکی فروش را نشان بدهد.
        $this->sack('cash');
        $this->sack('charity');
        $this->sack('home');

        $this->assertEqualsWithDelta(500_000, $this->tillBalance(), 0.01);
    }
}
