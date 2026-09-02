<?php

namespace Tests\Feature;

use App\Filament\Pages\ShopToday;
use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Loan;
use App\Models\User;
use App\Support\ShopHealth;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «امروز» answers before it lists.
 *
 * The dashboard has twenty widgets and the owner reads none of them — he
 * asks «چک کن» and I run the checks and tell him in a sentence. The answer
 * existed and the software would not give it to him, and on 1405/06/07
 * that cost four days with a 400 kg hole in the ledger behind a green
 * screen.
 *
 * What this page must get right is the *separation*: a sound system and a
 * busy shop are different statements. Reading «سالم» beside «سه چیز کار
 * شماست» is correct and has to stay correct — a page that conflated them
 * would either cry wolf about a debt the owner already knows about, or go
 * quiet about a real fault because nobody owes anything.
 */
class OneAnswerBeforeTwentyWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['flour_bag_weight_kg' => 40]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        // A dump on disk, or the backup cycle fails and every test here
        // would be reading the machine rather than the shop.
        @mkdir(storage_path('app/backups'), 0775, true);
        file_put_contents(storage_path('app/backups/today-test.sql.gz'), 'x');
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/backups/today-test.sql.gz'));

        parent::tearDown();
    }

    private function page(): Testable
    {
        return Livewire::test(ShopToday::class);
    }

    public function test_it_opens_with_a_sentence_not_a_grid(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee('مغازه امروز سالم است.');
    }

    /**
     * The stamp is the whole reason the page exists. A green screen with
     * no time on it is exactly what those four days looked like.
     */
    public function test_it_says_it_looked_just_now_and_how_widely(): void
    {
        $this->page()->assertSee('چرخه همین حالا بررسی شد');
    }

    /**
     * A shop with nothing waiting is told so in as many words. The empty
     * state is the one most screens get wrong: twenty widgets full of
     * zeroes look identical to twenty widgets nobody has loaded.
     */
    public function test_a_shop_with_nothing_waiting_is_told_so_plainly(): void
    {
        $this->page()
            ->assertSee('هیچ چیز کار شما نیست.')
            ->assertSee('هیچ چیزی منتظر شما نیست.');
    }

    /**
     * A system fault and a shop debt are different things, and the page
     * must not let one speak for the other.
     */
    public function test_a_sound_system_still_says_sound_while_the_shop_is_busy(): void
    {
        // `next_due_on` is derived from the first due date and how many
        // instalments have been paid, so an overdue loan is arranged, not
        // asserted — writing the derived column would prove nothing.
        Loan::create([
            'title' => 'وام صادرات',
            'principal' => 40_000_000,
            'instalment_amount' => 4_000_000,
            'instalment_count' => 10,
            'first_due_on' => now()->subDays(2),
        ]);

        $this->page()
            ->assertSee('مغازه امروز سالم است.')
            ->assertSee('وام صادرات');
    }

    /**
     * And the other way: when the records contradict each other, the page
     * says so above everything and warns the figures cannot be trusted.
     */
    public function test_a_broken_ledger_is_said_before_anything_else(): void
    {
        // Flour out with nothing ever in: a balance below zero is
        // impossible in a real warehouse and is always a missing purchase.
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $flour->movements()->create([
            'direction' => 'out',
            'quantity' => 500,
            'reason' => 'production',
        ]);

        $this->page()
            ->assertSee('سیستم با خودش نمی‌خواند')
            ->assertSee('به عددهای پایین اعتماد نکنید')
            ->assertDontSee('مغازه امروز سالم است.');
    }

    public function test_the_figures_come_last_and_in_persian_digits(): void
    {
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 2606, 'purchase');
        BankAccount::create(['title' => 'حساب سفید', 'opening_balance' => 0]);

        $this->page()
            ->assertSee('آرد')
            ->assertSee('۶۵٫۲');
    }

    /**
     * The page and the command read one class, so they cannot answer
     * differently — which was the point of moving the checks out of the
     * command in the first place.
     */
    public function test_the_page_and_the_command_agree(): void
    {
        Customer::create([
            'name' => 'منصور پرکی نانوایی ناهوت ',
            'type' => 'school',
            'is_active' => true,
        ]);

        $health = ShopHealth::inspect();

        $this->assertContains(
            'نانوایی ثبت‌شده زیر نوعی جز همکار: 1',
            $health->warnings()
        );

        $this->artisan('shop:health')
            ->expectsOutputToContain('نانوایی ثبت‌شده زیر نوعی جز همکار: 1')
            ->assertSuccessful();

        $this->page()->assertSee('نانوایی ثبت‌شده زیر نوعی جز همکار: 1');
    }

    /** The old dashboard is not taken away from anyone who wants the grid. */
    public function test_the_dashboard_is_still_there(): void
    {
        $this->get('/admin')->assertSuccessful();
    }
}
