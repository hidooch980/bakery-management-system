<?php

namespace Tests\Feature;

use App\Filament\Resources\SaleResource\Pages\CreateSale;
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
 * The panel records a sale a payment line at a time, the same shape the
 * seller's screen uses, so a batch settled part in cash and part by card
 * does not need two trips through the form.
 */
class SalePanelPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ChaneEntry $chane;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['bread_price' => 5000, 'currency' => 'toman']);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $dough = DoughEntry::create(['user_id' => $this->admin->id, 'bag_count' => 2]);
        $this->chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->admin->id,
            'chane_count' => 100,
            'normal_weight_kg' => 85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function create(array $payments)
    {
        return Livewire::test(CreateSale::class)
            ->fillForm([
                'chane_entry_id' => $this->chane->id,
                'user_id' => $this->admin->id,
                'payments' => $payments,
            ])
            ->call('create');
    }

    public function test_one_batch_can_be_settled_in_two_ways(): void
    {
        $this->create([
            ['payment_type' => 'cash', 'bread_count' => 60, 'amount' => 300_000],
            ['payment_type' => 'card', 'bread_count' => 40, 'amount' => 200_000],
        ])->assertHasNoFormErrors();

        $this->assertSame(2, Sale::count());
        $this->assertSame(60, (int) Sale::where('payment_type', 'cash')->sum('bread_count'));
        $this->assertSame(40, (int) Sale::where('payment_type', 'card')->sum('bread_count'));
        $this->assertSame('sold', $this->chane->fresh()->status);
    }

    public function test_the_batch_shortfall_is_counted_once(): void
    {
        $this->create([
            ['payment_type' => 'cash', 'bread_count' => 50, 'amount' => 250_000],
            ['payment_type' => 'card', 'bread_count' => 40, 'amount' => 200_000],
        ])->assertHasNoFormErrors();

        // 90 of 100 accounted for, so 10 loaves short — once, not per line.
        $this->assertSame(10, (int) Sale::sum('shortfall_count'));
    }

    public function test_more_bread_than_the_batch_holds_is_refused(): void
    {
        $this->create([
            ['payment_type' => 'cash', 'bread_count' => 80, 'amount' => 400_000],
            ['payment_type' => 'card', 'bread_count' => 40, 'amount' => 200_000],
        ]);

        // Nothing written, and the batch is still there to sell.
        $this->assertSame(0, Sale::count());
        $this->assertSame('pending', $this->chane->fresh()->status);
    }

    public function test_a_credit_line_without_a_buyer_is_refused(): void
    {
        $this->create([
            ['payment_type' => 'credit', 'bread_count' => 100, 'amount' => 500_000],
        ]);

        $this->assertSame(0, Sale::count());
    }

    public function test_a_credit_line_with_a_buyer_is_recorded(): void
    {
        $customer = Customer::create(['name' => 'دبستان', 'type' => 'school']);

        $this->create([
            ['payment_type' => 'cash', 'bread_count' => 60, 'amount' => 300_000],
            [
                'payment_type' => 'credit',
                'bread_count' => 40,
                'amount' => 200_000,
                'customer_id' => $customer->id,
            ],
        ])->assertHasNoFormErrors();

        $this->assertSame(
            $customer->id,
            Sale::where('payment_type', 'credit')->first()->customer_id
        );
    }

    public function test_each_line_carries_its_own_money_difference(): void
    {
        $this->create([
            // 60 loaves are worth 300,000 but only 280,000 was taken.
            ['payment_type' => 'cash', 'bread_count' => 60, 'amount' => 280_000],
            ['payment_type' => 'card', 'bread_count' => 40, 'amount' => 200_000],
        ])->assertHasNoFormErrors();

        $this->assertEquals(
            -20_000.0,
            (float) Sale::where('payment_type', 'cash')->first()->amount_difference
        );
        $this->assertEquals(
            0.0,
            (float) Sale::where('payment_type', 'card')->first()->amount_difference
        );
    }
}
