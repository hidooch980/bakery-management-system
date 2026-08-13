<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerInteractionResource;
use App\Filament\Resources\CustomerInteractionResource\Pages\ListCustomerInteractions;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\CustomerResource\RelationManagers\SalesRelationManager;
use App\Filament\Widgets\CustomerDebtsTable;
use App\Filament\Widgets\DueFollowUpsTable;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\CustomerInteraction;
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
 * The customer side of the panel, which existed only in pieces: the calls
 * were reachable one customer at a time, and the collection list and the
 * call list were widgets mounted on no page at all.
 */
class CustomerCrmPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $school;

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

        $this->school = Customer::create([
            'name' => 'دبستان شهید بهشتی',
            'type' => 'school',
            'phone' => '09120000000',
        ]);

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

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function interaction(?string $followUp, string $summary = 'شرح'): CustomerInteraction
    {
        return CustomerInteraction::create([
            'customer_id' => $this->school->id,
            'user_id' => $this->admin->id,
            'type' => 'debt_chase',
            'summary' => $summary,
            'follow_up_on' => $followUp,
        ]);
    }

    private function debt(float $amount): Sale
    {
        return Sale::create([
            'user_id' => $this->admin->id,
            'chane_entry_id' => $this->chane->id,
            'payment_type' => 'schools',
            'customer_id' => $this->school->id,
            'bread_count' => 10,
            'amount' => $amount,
        ]);
    }

    public function test_the_call_list_spans_every_customer(): void
    {
        $other = Customer::create(['name' => 'اداره برق', 'type' => 'office']);

        $this->interaction(now()->subDay()->toDateString(), 'تماس با مدرسه');

        CustomerInteraction::create([
            'customer_id' => $other->id,
            'user_id' => $this->admin->id,
            'type' => 'call',
            'summary' => 'تماس با اداره',
            'follow_up_on' => now()->toDateString(),
        ]);

        // One page answering "who have we not called back", which no
        // single customer's page can.
        Livewire::test(ListCustomerInteractions::class)
            ->assertCanSeeTableRecords(CustomerInteraction::all());
    }

    public function test_it_opens_on_the_calls_that_are_owed_today(): void
    {
        $due = $this->interaction(now()->subDay()->toDateString(), 'سررسید شده');
        $later = $this->interaction(now()->addWeek()->toDateString(), 'هفته بعد');
        $none = $this->interaction(null, 'چیزی قول داده نشد');

        Livewire::test(ListCustomerInteractions::class)
            ->assertCanSeeTableRecords([$due])
            ->assertCanNotSeeTableRecords([$later, $none]);
    }

    public function test_a_follow_up_can_be_ticked_off_from_the_list(): void
    {
        $record = $this->interaction(now()->subDay()->toDateString());

        Livewire::test(ListCustomerInteractions::class)
            ->callTableAction('complete', $record);

        $this->assertNotNull($record->fresh()->completed_at);
    }

    public function test_nothing_to_tick_off_shows_no_tick(): void
    {
        // A call that promised nothing is already finished; offering to
        // complete it would be offering to complete nothing.
        $record = $this->interaction(null);

        Livewire::test(ListCustomerInteractions::class)
            ->set('activeTab', 'all')
            ->assertTableActionHidden('complete', $record);
    }

    public function test_the_menu_counts_the_calls_owed_today(): void
    {
        $this->assertNull(CustomerInteractionResource::getNavigationBadge());

        $this->interaction(now()->subDay()->toDateString());

        $this->assertSame('1', CustomerInteractionResource::getNavigationBadge());
    }

    public function test_the_customer_list_carries_the_collection_and_call_widgets(): void
    {
        // Both were written and tested but mounted nowhere, so neither
        // rendered in the panel at all.
        $page = new ListCustomers;
        $method = new \ReflectionMethod($page, 'getHeaderWidgets');
        $method->setAccessible(true);
        $widgets = $method->invoke($page);

        $this->assertContains(CustomerDebtsTable::class, $widgets);
        $this->assertContains(DueFollowUpsTable::class, $widgets);
    }

    public function test_the_customer_list_shows_what_each_one_owes(): void
    {
        $this->debt(120_000);
        $this->debt(80_000);

        $html = preg_replace('/\s+/u', ' ', strip_tags(
            Livewire::test(ListCustomers::class)->html()
        ));

        // Summed in the query, so the figure is the sum of both invoices.
        $this->assertStringContainsString(Money::format(200_000), $html);
    }

    public function test_a_settled_sale_leaves_the_customers_debt(): void
    {
        $paid = $this->debt(120_000);
        $this->debt(80_000);

        $paid->update(['settled_on' => now()]);

        $html = preg_replace('/\s+/u', ' ', strip_tags(
            Livewire::test(ListCustomers::class)->html()
        ));

        $this->assertStringContainsString(Money::format(80_000), $html);
        $this->assertStringNotContainsString(Money::format(200_000), $html);
    }

    public function test_a_customers_own_page_lists_what_they_bought(): void
    {
        $this->debt(120_000);

        Livewire::test(SalesRelationManager::class, [
            'ownerRecord' => $this->school,
            'pageClass' => EditCustomer::class,
        ])->assertCanSeeTableRecords(Sale::all());
    }

    public function test_one_invoice_can_be_settled_on_its_own(): void
    {
        $sale = $this->debt(120_000);

        Livewire::test(SalesRelationManager::class, [
            'ownerRecord' => $this->school,
            'pageClass' => EditCustomer::class,
        ])->callTableAction('settle', $sale);

        // Part payment settles particular sales, not a fraction of all of
        // them, so the invoice is the unit here.
        $this->assertNotNull($sale->fresh()->settled_on);
    }

    public function test_a_cash_sale_offers_no_settle_button(): void
    {
        $cash = Sale::create([
            'user_id' => $this->admin->id,
            'chane_entry_id' => $this->chane->id,
            'payment_type' => 'cash',
            'customer_id' => $this->school->id,
            'bread_count' => 10,
            'amount' => 50_000,
        ]);

        Livewire::test(SalesRelationManager::class, [
            'ownerRecord' => $this->school,
            'pageClass' => EditCustomer::class,
        ])->assertTableActionHidden('settle', $cash);
    }
}
