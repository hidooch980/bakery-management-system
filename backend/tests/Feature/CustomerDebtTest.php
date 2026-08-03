<?php

namespace Tests\Feature;

use App\Filament\Widgets\CustomerDebtsTable;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\Customer;
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
 * What the schools and offices owe, gathered per buyer rather than left as
 * a list of separate receipts.
 */
class CustomerDebtTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ChaneEntry $chane;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman', 'flour_bag_weight_kg' => 40]);
        Money::forgetCache();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        // Every sale hangs off a batch; the batch itself is not what these
        // tests are about, so one shared one stands in for all of them.
        $dough = DoughEntry::create([
            'user_id' => $this->admin->id,
            'bag_count' => 1,
            'status' => 'shaped',
        ]);

        $this->chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
            'status' => 'sold',
        ]);
    }

    private function school(string $name): Customer
    {
        return Customer::create(['name' => $name, 'type' => 'school']);
    }

    private function debt(Customer $customer, float $amount, int $daysAgo = 0): Sale
    {
        $sale = Sale::create([
            'user_id' => $this->admin->id,
            'chane_entry_id' => $this->chane->id,
            'payment_type' => 'schools',
            'customer_id' => $customer->id,
            'bread_count' => 10,
            'amount' => $amount,
        ]);

        $sale->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $sale;
    }

    public function test_debts_are_summed_per_customer(): void
    {
        $school = $this->school('دبستان شهید بهشتی');
        $this->debt($school, 50000);
        $this->debt($school, 30000);

        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/customer-debts')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['customers']);
        $this->assertEqualsWithDelta(80000, $data['customers'][0]['amount'], 0.01);
        $this->assertSame(2, $data['customers'][0]['sale_count']);
        $this->assertSame(20, $data['customers'][0]['bread_count']);
        $this->assertEqualsWithDelta(80000, $data['total'], 0.01);
    }

    public function test_a_settled_sale_is_not_counted(): void
    {
        $school = $this->school('هنرستان');
        $this->debt($school, 50000);
        $this->debt($school, 30000)->update(['settled_on' => now()]);

        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/customer-debts')
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(50000, $data['customers'][0]['amount'], 0.01);
    }

    public function test_the_longest_waiting_customer_comes_first(): void
    {
        $this->debt($this->school('تازه'), 10000, daysAgo: 2);
        $this->debt($this->school('قدیمی'), 10000, daysAgo: 45);

        $customers = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/customer-debts')
            ->assertOk()
            ->json('data.customers');

        $this->assertSame('قدیمی', $customers[0]['name']);
        $this->assertTrue($customers[0]['is_overdue']);
        $this->assertFalse($customers[1]['is_overdue']);
    }

    public function test_cash_sales_are_not_a_debt(): void
    {
        $school = $this->school('مدرسه');

        Sale::create([
            'user_id' => $this->admin->id,
            'chane_entry_id' => $this->chane->id,
            'payment_type' => 'cash',
            'customer_id' => $school->id,
            'bread_count' => 10,
            'amount' => 50000,
        ]);

        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/customer-debts')
            ->assertOk()
            ->json('data');

        $this->assertSame([], $data['customers']);
    }

    public function test_settling_clears_every_open_sale_for_that_customer(): void
    {
        $school = $this->school('اداره برق');
        $first = $this->debt($school, 50000);
        $second = $this->debt($school, 30000);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customer-debts/{$school->id}/settle")
            ->assertOk();

        $this->assertNotNull($first->fresh()->settled_on);
        $this->assertNotNull($second->fresh()->settled_on);
    }

    public function test_settling_a_customer_with_nothing_open_is_refused(): void
    {
        $school = $this->school('بدون بدهی');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/customer-debts/{$school->id}/settle")
            ->assertStatus(422);
    }

    public function test_a_seller_cannot_read_the_debt_list(): void
    {
        $seller = User::factory()->create();
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/customer-debts')
            ->assertForbidden();
    }

    // ------------------------------------------------------------- panel

    public function test_the_panel_widget_lists_each_debtor_once(): void
    {
        $school = $this->school('دبستان نمونه');
        $this->debt($school, 50000);
        $this->debt($school, 30000);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = preg_replace('/\s+/u', ' ', strip_tags(
            Livewire::test(CustomerDebtsTable::class)->html()
        ));

        $this->assertSame(1, substr_count($html, 'دبستان نمونه'));
        // The two receipts show as one figure to collect.
        $this->assertStringContainsString('80,000', $html);
    }

    public function test_the_panel_can_settle_a_customer_in_one_go(): void
    {
        $school = $this->school('اداره آب');
        $sale = $this->debt($school, 50000);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CustomerDebtsTable::class)
            ->callTableAction('settleAll', $school)
            ->assertHasNoTableActionErrors();

        $this->assertNotNull($sale->fresh()->settled_on);
    }
}
