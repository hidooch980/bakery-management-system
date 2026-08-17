<?php

namespace Tests\Feature;

use App\Filament\Widgets\MoneyAtAGlance;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Ledger;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A sack of flour is paid for once and must be charged for once.
 *
 * It appears in the books twice, though, at two different moments: as an
 * expense the day it is bought, and as stock leaving the store the day it
 * is kneaded. The dashboard subtracted both and charged the shop twice for
 * the same sack — 164,640,000 Rial of a 1.7 billion month, and in a leaner
 * month enough to turn a profit into a loss.
 *
 * The shop's own rule, in the owner's words: «پول اول پرداخت می‌شه». The
 * cost falls on the day the money leaves. Flour bought, bag baked, bread
 * sold — the first link is where it is counted.
 */
class FlourIsNotChargedTwiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'currency' => 'toman',
            'bread_price' => 5000,
            'normal_chane_weight_kg' => 0.85,
            'flour_purchase_price_per_kg' => 1200,
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true, 'monthly_salary' => 0]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** A sack bought and paid for, on the expense sheet where the shop puts it. */
    private function buyFlour(float $kg, float $cost): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)
            ->move('in', $kg, 'purchase', $this->admin->id);

        Expense::create([
            'user_id' => $this->admin->id,
            'category' => 'flour',
            'title' => 'خرید آرد',
            'amount' => $cost,
            'spent_on' => now(),
        ]);
    }

    /** The same flour, kneaded and sold. */
    private function bakeAndSell(int $bags, float $amount): void
    {
        $dough = DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => $bags]);

        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);

        Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $this->admin->id,
            'bread_count' => 100,
            'payment_type' => 'cash',
            'amount' => $amount,
            'amount_difference' => 0,
        ]);
    }

    private function window(): array
    {
        return Jalali::currentMonthRange();
    }

    public function test_the_headline_profit_counts_the_flour_once(): void
    {
        $this->buyFlour(1000, 1_200_000);
        $this->bakeAndSell(10, 5_000_000);

        [$from, $to] = $this->window();

        // Income less money out. The flour is in the expenses and nowhere
        // else — not also deducted as stock consumed.
        $this->assertEqualsWithDelta(
            Ledger::totalIncome($from, $to) - Ledger::totalExpenses($from, $to),
            Ledger::profit($from, $to),
            0.01,
        );
    }

    public function test_the_dashboard_and_the_report_agree(): void
    {
        $this->buyFlour(1000, 1_200_000);
        $this->bakeAndSell(10, 5_000_000);

        [$from, $to] = $this->window();

        // They did not, for as long as the dashboard kept its own
        // arithmetic: it read one flour purchase lower than the report on
        // the same data. A figure the owner sees every morning cannot
        // disagree with the report he checks it against.
        $response = $this->getJson('/api/v1/reports/financial?'.http_build_query([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]));

        $response->assertOk();

        $this->assertEqualsWithDelta(
            Ledger::profit($from, $to),
            (float) $response->json('data.profit.amount'),
            0.01,
        );
    }

    public function test_buying_flour_without_baking_it_still_costs_this_month(): void
    {
        // The shop's rule, stated plainly: a sack bought on the last day of
        // the month is this month's cost even though not a loaf of it has
        // been baked.
        $this->buyFlour(1000, 1_200_000);

        [$from, $to] = $this->window();

        $this->assertEqualsWithDelta(1_200_000, Ledger::flourPurchases($from, $to), 0.01);
        $this->assertEqualsWithDelta(-1_200_000, Ledger::profit($from, $to), 0.01);
    }

    public function test_operating_expenses_leave_the_flour_out(): void
    {
        $this->buyFlour(1000, 1_200_000);

        Expense::create([
            'user_id' => $this->admin->id,
            'category' => 'fuel',
            'title' => 'گازوئیل',
            'amount' => 300_000,
            'spent_on' => now(),
        ]);

        [$from, $to] = $this->window();

        // This is what the profit-and-loss statement pairs with cost of
        // goods; adding flour purchases back into it is the double charge.
        $this->assertEqualsWithDelta(300_000, Ledger::operatingExpenses($from, $to), 0.01);
    }

    public function test_the_widget_shows_the_same_figure_as_the_ledger(): void
    {
        $this->buyFlour(1000, 1_200_000);
        $this->bakeAndSell(10, 5_000_000);

        [$from, $to] = $this->window();

        Livewire::test(MoneyAtAGlance::class)
            ->assertOk()
            ->assertSee(Money::format(Ledger::profit($from, $to)));
    }
}
