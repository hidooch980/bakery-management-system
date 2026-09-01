<?php

namespace Tests\Feature;

use App\Filament\Resources\SaleResource\Pages\ListSales;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use App\Support\SaleRecorder;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A deduction the shop can show, not only assert.
 *
 * The payslip carries one figure for bread taken home. A worker who
 * disagrees with it has a fair question — which loaves? — and until now
 * nothing could answer it. That is the shape of the conversation this
 * shop already had once, when one employee's «16,600,000 unsettled»
 * turned out to be three different things and settling the headline
 * would have charged him 7,100,000 out of his own pocket.
 */
class WhichLoavesAWorkerOwesForTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private User $worker;

    private User $otherWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'water_ratio' => 0.6,
            'salt_ratio' => 0.015,
            'dough_loss_ratio' => 0,
            'proof_gain_ratio' => 0,
            'normal_chane_weight_kg' => 0.85,
            'bread_price' => 10000,
            'currency' => 'rial',
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');

        $this->worker = User::factory()->create([
            'is_active' => true,
            'monthly_salary' => 5000000,
        ]);
        $this->worker->assignRole('shater');

        $this->otherWorker = User::factory()->create(['is_active' => true]);
        $this->otherWorker->assignRole('shater');

        $this->actingAs($this->admin);
    }

    private function tookHome(User $who, int $loaves): Sale
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 5000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 200, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 200, 'purchase');

        $dough = User::factory()->create(['is_active' => true]);
        $dough->assignRole('dough_maker');
        $chaneGir = User::factory()->create(['is_active' => true]);
        $chaneGir->assignRole('chane_gir');

        $this->actingAs($dough, 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => 30]);
        $this->actingAs($chaneGir, 'sanctum')->postJson('/api/v1/chane-entries', [
            'dough_entry_id' => DoughEntry::latest('id')->first()->id,
            'chane_count' => $loaves,
            'spray_flour_kg' => 0,
        ]);

        $this->actingAs($this->admin);

        return SaleRecorder::record(
            ChaneEntry::latest('id')->first(),
            [[
                'payment_type' => 'home',
                'bread_count' => $loaves,
                'amount' => null,
                'customer_id' => null,
                'consumed_by_user_id' => $who->id,
                'note' => null,
            ]],
            $this->seller->id,
        )[0];
    }

    public function test_the_sales_list_names_who_took_the_bread(): void
    {
        $this->tookHome($this->worker, 12);

        Livewire::test(ListSales::class)
            ->assertCanSeeTableRecords(Sale::all())
            ->assertTableColumnStateSet('consumer.name', $this->worker->name, Sale::first());
    }

    public function test_the_list_can_be_narrowed_to_one_worker(): void
    {
        $mine = $this->tookHome($this->worker, 12);
        $theirs = $this->tookHome($this->otherWorker, 5);

        Livewire::test(ListSales::class)
            ->filterTable('consumed_by_user_id', $this->worker->id)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_the_list_can_show_only_what_is_still_owed(): void
    {
        $unpaid = $this->tookHome($this->worker, 12);
        $paid = $this->tookHome($this->otherWorker, 5);

        // A payslip for the other worker absorbs theirs entirely.
        SalaryPayment::create([
            'user_id' => $this->otherWorker->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'base_amount' => 5000000,
            'bonus' => 0,
            'deduction' => 0,
        ]);

        Livewire::test(ListSales::class)
            ->filterTable('bread_unsettled', true)
            ->assertCanSeeTableRecords([$unpaid])
            ->assertCanNotSeeTableRecords([$paid]);
    }
}
