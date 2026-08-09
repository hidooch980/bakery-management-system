<?php

namespace Tests\Feature;

use App\Filament\Widgets\BakeryStatsOverview;
use App\Filament\Widgets\ExpenseByCategoryChart;
use App\Filament\Widgets\FinancialOverview;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every figure the user reads must follow the configured unit. A place that
 * quietly formats in Toman while the shop displays Rial understates the
 * number tenfold, so this walks the whole surface rather than spot-checking.
 */
class CurrencyConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->givenSomeMoneyMoved();
    }

    /** One sale, one expense and one paid salary, all worth 1,000 Toman. */
    private function givenSomeMoneyMoved(): void
    {
        Bakery::first()->update([
            'normal_chane_weight_kg' => 0.85,
            'bread_price' => 1000,
        ]);

        $dough = DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => 1]);
        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->admin->id,
            'chane_count' => 10,
            'normal_weight_kg' => 8.5,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);

        Sale::create([
            'chane_entry_id' => $chane->id,
            'user_id' => $this->admin->id,
            'payment_type' => 'cash',
            'amount' => 1000,
        ]);

        Expense::create([
            'category' => 'fuel',
            'title' => 'سوخت',
            'amount' => 1000,
            'spent_on' => now(),
        ]);

        SalaryPayment::create([
            'user_id' => $this->admin->id,
            'period_start' => now()->startOfMonth(),
            'period_label' => 'تست',
            'base_amount' => 1000,
            'paid_on' => now(),
        ]);
    }

    private function useCurrency(string $currency): void
    {
        Bakery::first()->update(['currency' => $currency]);
        Money::forgetCache();
    }

    // ------------------------------------------------------------- the API

    #[DataProvider('moneyEndpoints')]
    public function test_endpoint_labels_amounts_in_the_configured_unit(
        string $endpoint,
        string $path,
    ): void {
        $this->useCurrency(Money::TOMAN);
        $this->actingAs($this->admin, 'sanctum')
            ->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath($path, fn ($value) => str_contains((string) $value, 'تومان'));

        $this->useCurrency(Money::RIAL);
        $this->actingAs($this->admin, 'sanctum')
            ->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath($path, fn ($value) => str_contains((string) $value, 'ریال'));
    }

    public static function moneyEndpoints(): array
    {
        return [
            'dashboard sales' => ['/api/v1/reports/dashboard', 'data.sales_amount_formatted'],
            'sales report' => ['/api/v1/reports/sales', 'data.total_amount_formatted'],
            'financial income' => ['/api/v1/reports/financial', 'data.income.sales_formatted'],
            'financial expenses' => ['/api/v1/reports/financial', 'data.expenses.total_formatted'],
            'financial profit' => ['/api/v1/reports/financial', 'data.profit.formatted'],
            'payroll total' => ['/api/v1/reports/payroll', 'data.total_net_formatted'],
            'salary net' => ['/api/v1/salaries', 'data.data.0.net_amount_formatted'],
            'expense amount' => ['/api/v1/expenses', 'data.data.0.amount_formatted'],
            'employee salary' => ['/api/v1/salaries/employees', 'data.0.monthly_salary_formatted'],
        ];
    }

    public function test_switching_to_rial_multiplies_the_displayed_figure_by_ten(): void
    {
        $this->useCurrency(Money::TOMAN);
        $toman = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/reports/financial')
            ->json('data.income.sales_formatted');

        $this->useCurrency(Money::RIAL);
        $rial = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/reports/financial')
            ->json('data.income.sales_formatted');

        $this->assertSame('1/000 تومان', $toman);
        $this->assertSame('10/000 ریال', $rial);
    }

    public function test_seller_summary_follows_the_unit(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        Sale::query()->update(['user_id' => $seller->id]);

        $this->useCurrency(Money::RIAL);

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/sales/today')
            ->assertOk()
            ->assertJsonPath('data.summary.total_amount_formatted', '10/000 ریال')
            // The raw figure stays in Toman so clients can compute with it.
            ->assertJsonPath('data.summary.total_amount', 1000);
    }

    public function test_bakery_endpoint_reports_the_unit(): void
    {
        $this->useCurrency(Money::RIAL);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/bakery')
            ->assertOk()
            ->assertJsonPath('data.currency', 'rial')
            ->assertJsonPath('data.currency_label', 'ریال');
    }

    // ----------------------------------------------------------- the panel

    #[DataProvider('moneyWidgets')]
    public function test_widget_renders_in_the_configured_unit(string $widget): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(
            Filament::getPanel('admin')
        );

        $this->useCurrency(Money::RIAL);

        Livewire::test($widget)
            ->assertSee('ریال')
            ->assertDontSee('تومان');
    }

    public static function moneyWidgets(): array
    {
        return [
            'financial overview' => [FinancialOverview::class],
            'bakery stats' => [BakeryStatsOverview::class],
        ];
    }

    public function test_charts_plot_values_in_the_display_unit(): void
    {
        $data = function (): array {
            $widget = new ExpenseByCategoryChart;
            $method = new \ReflectionMethod($widget, 'getData');
            $method->setAccessible(true);

            return $method->invoke($widget)['datasets'][0]['data'] ?? [];
        };

        $this->useCurrency(Money::TOMAN);
        $tomanChart = $data();

        $this->useCurrency(Money::RIAL);
        $rialChart = $data();

        // A chart drawn in Toman beside stat cards in Rial would misread by 10x.
        $this->assertSame(1000.0, (float) ($tomanChart[0] ?? 0));
        $this->assertSame(10000.0, (float) ($rialChart[0] ?? 0));
    }
}
