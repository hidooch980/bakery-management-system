<?php

namespace Tests\Feature;

use App\Filament\Resources\FlourSaleResource\Pages\CreateFlourSale;
use App\Filament\Resources\FlourSaleResource\Pages\ListFlourSales;
use App\Filament\Widgets\BakeryStatsOverview;
use App\Filament\Widgets\FlourQuotaOverview;
use App\Models\Bakery;
use App\Models\FlourAllocation;
use App\Models\FlourSale;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * آرد همه‌جا فقط به کیسه دیده می‌شود — داشبورد، سهمیه، فهرست فروش،
 * پیام‌ها. تنها استثنا: فروشنده در فرم فروش آرد می‌تواند به کیلو هم وارد
 * کند (پیش‌فرض کیسه)، و وزن همچنان به کیلوگرم ذخیره می‌شود.
 */
class FlourIsOnlyEverShownInSacksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'flour_price_per_kg' => 30_000,
            'currency' => 'toman',
        ]);
        Money::forgetCache();

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 1000, 'purchase');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_flour_stock_on_the_dashboard_says_sacks_and_never_kilos(): void
    {
        $html = Livewire::test(BakeryStatsOverview::class)->html();

        $this->assertStringContainsString('25 کیسه', $html);
        $this->assertStringNotContainsString('1,000.00 کیلوگرم', $html);
        $this->assertStringNotContainsString('کیلوگرم', $html);
    }

    public function test_the_quota_widget_says_sacks_and_never_kilos(): void
    {
        Carbon::setTestNow(Jalali::parse('1405/05/10'));

        FlourAllocation::create([
            'month_start' => Jalali::parse('1405/05/05'),
            'month_label' => 'مرداد 1405',
            'total_kg' => 3000,
            'carryover_bags' => 5,
        ])->syncPeriods();

        $html = Livewire::test(FlourQuotaOverview::class)->html();

        $this->assertStringContainsString('کیسه', $html);
        $this->assertStringNotContainsString('کیلوگرم', $html);
    }

    public function test_the_panel_sale_form_opens_on_sacks(): void
    {
        Livewire::test(CreateFlourSale::class)
            ->assertFormSet(['unit' => FlourSale::BAG]);
    }

    public function test_the_seller_may_still_type_kilos_and_kilos_are_stored(): void
    {
        Livewire::test(CreateFlourSale::class)
            ->fillForm([
                'unit' => FlourSale::KG,
                'quantity' => 20,
                'unit_price' => 30000,
                'payment_type' => 'cash',
                'user_id' => $this->admin->id,
                'sold_on' => Jalali::date(now()),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sale = FlourSale::sole();

        $this->assertEquals(20, (float) $sale->weight_kg);
        $this->assertSame('0.5 کیسه', $sale->quantity_label);
    }

    public function test_a_sack_sale_is_stored_as_its_weight(): void
    {
        Livewire::test(CreateFlourSale::class)
            ->fillForm([
                'unit' => FlourSale::BAG,
                'quantity' => 2,
                'unit_price' => 1200000,
                'payment_type' => 'cash',
                'user_id' => $this->admin->id,
                'sold_on' => Jalali::date(now()),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sale = FlourSale::sole();

        $this->assertEquals(80, (float) $sale->weight_kg);
        $this->assertSame('2 کیسه', $sale->quantity_label);
    }

    public function test_the_sale_list_says_sacks_even_for_a_kilo_sale(): void
    {
        FlourSale::create([
            'unit' => FlourSale::KG,
            'quantity' => 10,
            'unit_price' => 30000,
            'payment_type' => 'cash',
            'user_id' => $this->admin->id,
            'sold_on' => now()->toDateString(),
        ]);

        $html = Livewire::test(ListFlourSales::class)->html();

        $this->assertStringContainsString('0.25 کیسه', $html);
        $this->assertStringNotContainsString('10 کیلوگرم', $html);
    }

    public function test_the_app_gets_the_day_in_sacks_and_a_refusal_in_sacks(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/flour-sales', ['unit' => 'kg', 'quantity' => 20, 'payment_type' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.quantity_label', '0.5 کیسه');

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/flour-sales/today')
            ->assertOk()
            ->assertJsonPath('data.summary.total_bags', 0.5);

        $refusal = $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/flour-sales', ['unit' => 'bag', 'quantity' => 100, 'payment_type' => 'cash'])
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('24.5 کیسه', $refusal);
        $this->assertStringNotContainsString('کیلوگرم', $refusal);
    }
}
