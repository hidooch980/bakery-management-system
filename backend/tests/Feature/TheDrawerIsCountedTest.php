<?php

namespace Tests\Feature;

use App\Filament\Resources\CashCountResource\Pages\CreateCashCount;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashCount;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «چقدر پول در کشو هست؟» — asked of the drawer, answered by the books.
 *
 * Everything closed this month was about money *reaching* the till.
 * Nothing said whether the figure is true: change is given from the same
 * drawer, notes are handed over in a hurry, and a sale typed at the wrong
 * price leaves a gap both sides of the ledger agree about.
 *
 * A gap found the same evening is a question somebody can still answer.
 * The same gap found at month end is a number nobody can do anything
 * with.
 */
class TheDrawerIsCountedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BankAccount $till;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->till = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 5_000_000,
            'is_active' => true,
            'is_cash_box' => true,
        ]);
    }

    private function countDrawer(array $body = [])
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/cash-counts', array_merge([
                'counted_amount' => 5_000_000,
            ], $body));
    }

    private function tillBalance(): float
    {
        return round((float) $this->till->fresh()->balance, 2);
    }

    public function test_a_drawer_that_agrees_is_recorded_as_agreeing(): void
    {
        $this->countDrawer()
            ->assertCreated()
            ->assertJsonPath('data.is_exact', true)
            ->assertJsonPath('data.difference_label', 'می‌خواند');

        $this->assertSame(1, CashCount::count());
    }

    public function test_a_short_drawer_is_named_as_short(): void
    {
        $this->countDrawer(['counted_amount' => 4_800_000])
            ->assertCreated()
            ->assertJsonPath('data.is_exact', false)
            ->assertJsonPath('data.difference_label', 'کسری');
    }

    public function test_more_in_the_drawer_than_the_books_know_is_named_as_extra(): void
    {
        $this->countDrawer(['counted_amount' => 5_200_000])
            ->assertCreated()
            ->assertJsonPath('data.difference_label', 'اضافه');
    }

    public function test_counting_alone_does_not_move_any_money(): void
    {
        // The whole point. A count that quietly corrected the books would
        // hide exactly what somebody is counting to find.
        $this->countDrawer(['counted_amount' => 4_800_000])->assertCreated();

        $this->assertEqualsWithDelta(5_000_000, $this->tillBalance(), 0.01);
        $this->assertSame(0, BankTransaction::count());
    }

    public function test_the_books_are_corrected_only_when_asked(): void
    {
        $this->countDrawer(['counted_amount' => 4_800_000, 'adjust' => true])
            ->assertCreated()
            ->assertJsonPath('data.adjusted', true);

        $this->assertEqualsWithDelta(4_800_000, $this->tillBalance(), 0.01);
    }

    public function test_an_extra_is_corrected_upwards(): void
    {
        $this->countDrawer(['counted_amount' => 5_200_000, 'adjust' => true])->assertCreated();

        $this->assertEqualsWithDelta(5_200_000, $this->tillBalance(), 0.01);
    }

    public function test_the_correction_points_back_at_the_count_that_caused_it(): void
    {
        // So the history reads: the drawer was short this much, and this
        // is what was done about it.
        $id = $this->countDrawer(['counted_amount' => 4_800_000, 'adjust' => true])
            ->assertCreated()->json('data.id');

        $this->assertNotNull(CashCount::find($id)->adjustment);
    }

    public function test_a_drawer_that_already_agrees_writes_no_correction(): void
    {
        $this->countDrawer(['adjust' => true])->assertCreated();

        $this->assertSame(0, BankTransaction::count());
    }

    public function test_the_expected_figure_is_kept_as_it_was_at_the_time(): void
    {
        // A count is a statement about one instant. Recomputing it later
        // against a ledger that has moved on would rewrite history every
        // time somebody opened the page.
        $this->countDrawer(['counted_amount' => 4_800_000])->assertCreated();

        $this->till->record('in', 1_000_000, 'income', $this->admin->id);

        $this->assertEqualsWithDelta(
            5_000_000,
            (float) CashCount::first()->expected_amount,
            0.01,
        );
    }

    public function test_the_page_says_what_to_count_against_and_when_it_was_last_done(): void
    {
        $this->travel(-3)->days();
        $this->countDrawer(['counted_amount' => 4_900_000])->assertCreated();
        $this->travelBack();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/cash-counts')
            ->assertOk()
            ->assertJsonPath('data.days_since_count', 3)
            ->assertJsonPath('data.counts.0.difference_label', 'کسری');
    }

    public function test_a_shop_with_no_till_is_told_so_rather_than_failing(): void
    {
        $this->till->update(['is_cash_box' => false]);

        $this->countDrawer()->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/cash-counts')->assertStatus(422);
    }

    // ------------------------------------------------------------ the panel

    public function test_the_panel_records_a_count_and_leaves_the_money_alone(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateCashCount::class)
            ->fillForm(['counted_amount' => 4_800_000, 'adjust' => false])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, CashCount::count());
        $this->assertEqualsWithDelta(5_000_000, $this->tillBalance(), 0.01);
    }

    public function test_the_panel_corrects_the_books_when_the_toggle_is_on(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateCashCount::class)
            ->fillForm(['counted_amount' => 4_800_000, 'adjust' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(4_800_000, $this->tillBalance(), 0.01);
        $this->assertNotNull(CashCount::first()->adjustment);
    }

    public function test_a_seller_cannot_count_the_drawer(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/cash-counts', ['counted_amount' => 1])
            ->assertForbidden();
    }
}
