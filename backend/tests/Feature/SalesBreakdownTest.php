<?php

namespace Tests\Feature;

use App\Filament\Widgets\SalesByPaymentTypeBreakdown;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The sales page breaks the day down by payment type — bread moved and
 * money taken for each — then totals them and flags any gap between the
 * money collected and what the bread should have cost.
 */
class SalesBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['bread_price' => 5000, 'currency' => 'toman']);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function sell(string $paymentType, int $breadCount, float $amount): void
    {
        $dough = DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => 1]);
        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->admin->id,
            'chane_count' => $breadCount,
            'normal_weight_kg' => $breadCount * 0.85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);

        Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $this->admin->id,
            'payment_type' => $paymentType,
            'bread_count' => $breadCount,
            'amount' => $amount,
        ]);
    }

    /**
     * The widget's visible text, with markup and layout whitespace removed —
     * so assertions read like what the admin actually sees on the page.
     */
    private function widgetText(): string
    {
        $html = Livewire::test(SalesByPaymentTypeBreakdown::class)->html();

        return preg_replace('/\s+/u', ' ', strip_tags($html));
    }

    public function test_each_payment_type_reports_its_bread_and_its_money(): void
    {
        $this->sell('cash', 100, 500_000);
        $this->sell('card', 40, 200_000);

        $text = $this->widgetText();

        $this->assertStringContainsString('نقد 500/000 تومان 100 نان', $text);
        $this->assertStringContainsString('کارتخوان 200/000 تومان 40 نان', $text);
        // A type with no sales today still appears, at zero.
        $this->assertStringContainsString('مدارس 0 تومان 0 نان', $text);
    }

    public function test_the_totals_add_every_payment_type_together(): void
    {
        $this->sell('cash', 100, 500_000);
        $this->sell('card', 40, 200_000);

        // 140 loaves across two sales, 700,000 collected.
        $this->assertStringContainsString(
            'جمع کل فروش 700/000 تومان 140 نان در 2 فقره',
            $this->widgetText()
        );
    }

    public function test_money_matching_the_bread_leaves_no_difference(): void
    {
        // 140 loaves at the configured 5,000 is exactly 700,000.
        $this->sell('cash', 100, 500_000);
        $this->sell('card', 40, 200_000);

        $this->assertStringContainsString(
            'اختلاف 0 تومان نسبت به 700/000 تومان مورد انتظار',
            $this->widgetText()
        );
    }

    public function test_a_shortfall_in_the_money_is_surfaced_as_a_difference(): void
    {
        // 100 loaves should bring 500,000, but only 450,000 was recorded.
        $this->sell('cash', 100, 450_000);

        $this->assertStringContainsString(
            'اختلاف -50/000 تومان نسبت به 500/000 تومان مورد انتظار',
            $this->widgetText()
        );
    }

    public function test_money_over_the_expected_is_flagged_too(): void
    {
        // 100 loaves should bring 500,000, but 520,000 was recorded.
        $this->sell('cash', 100, 520_000);

        $this->assertStringContainsString('اختلاف +20/000 تومان', $this->widgetText());
    }

    public function test_yesterdays_sales_do_not_count_towards_today(): void
    {
        $this->sell('cash', 100, 500_000);

        // created_at is not fillable, so it has to be set directly — an
        // update() would quietly drop it and leave the sale dated today.
        $sale = Sale::first();
        $sale->created_at = now()->subDay();
        $sale->save();

        $this->assertStringContainsString(
            'جمع کل فروش 0 تومان 0 نان در 0 فقره',
            $this->widgetText()
        );
    }

    public function test_the_difference_is_not_claimed_without_a_bread_price(): void
    {
        Bakery::first()->update(['bread_price' => null]);
        $this->sell('cash', 100, 450_000);

        $this->assertStringContainsString(
            'قیمت نان در تنظیمات نانوایی ثبت نشده است',
            $this->widgetText()
        );
    }
}
