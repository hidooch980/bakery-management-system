<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\User;
use App\Support\IssueScanner;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «خرید از کارخانه وحدت دوبار ثبت شده.»
 *
 * Typed in once at the door and once from the paper that evening, and
 * nothing could tell the second from a second lorry — so the store read
 * forty sacks for twenty and the mill was owed twice. The API now
 * refuses the twin and names the first; the issues page lists the ones
 * that got in before it did.
 */
class AnInvoiceTypedTwiceIsCaughtTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        BankAccount::create([
            'title' => 'حساب اصلی',
            'opening_balance' => 100_000_000,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    private function invoice(array $overrides = []): array
    {
        return array_merge([
            'supplier_name' => 'کارخانه وحدت',
            'paid_amount' => 0,
            'items' => [
                ['item' => 'flour', 'bags' => 20, 'unit_price' => 20_000],
            ],
        ], $overrides);
    }

    private function send(array $payload)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/purchases', $payload);
    }

    public function test_the_same_invoice_twice_in_a_day_is_refused_and_the_first_is_named(): void
    {
        $first = $this->send($this->invoice())->assertCreated()->json('data.id');

        $this->send($this->invoice())
            ->assertStatus(409)
            ->assertJsonPath('data.duplicate_of', $first);

        // Refused means nothing was written: one invoice, twenty sacks.
        $this->assertSame(1, Purchase::count());
        $this->assertEqualsWithDelta(800, InventoryItem::ofKey('flour')->balance, 0.01);
    }

    public function test_a_second_lorry_is_recorded_when_the_caller_says_so(): void
    {
        $this->send($this->invoice())->assertCreated();

        $this->send($this->invoice(['force' => true]))->assertCreated();

        $this->assertSame(2, Purchase::count());
    }

    public function test_a_different_total_is_a_different_purchase(): void
    {
        $this->send($this->invoice())->assertCreated();

        $this->send($this->invoice([
            'items' => [['item' => 'flour', 'bags' => 10, 'unit_price' => 20_000]],
        ]))->assertCreated();

        $this->assertSame(2, Purchase::count());
    }

    public function test_the_issues_page_lists_a_pair_that_got_in(): void
    {
        $this->send($this->invoice())->assertCreated();
        $later = $this->send($this->invoice(['force' => true]))->assertCreated()->json('data.id');

        $issue = (new IssueScanner)->scan()->first(
            fn ($i) => str_starts_with($i->key, 'duplicate-purchase-')
        );

        $this->assertNotNull($issue);
        $this->assertSame('duplicate-purchase-'.$later, $issue->key);
        $this->assertStringContainsString('کارخانه وحدت', $issue->title);
        $this->assertStringContainsString('2 بار', $issue->title);
        $this->assertSame('/admin/purchases/'.$later.'/edit', $issue->url);
    }

    public function test_a_shop_with_no_twins_has_no_such_issue(): void
    {
        $this->send($this->invoice())->assertCreated();

        $keys = (new IssueScanner)->scan()->pluck('key');

        $this->assertFalse($keys->contains(fn ($k) => str_starts_with($k, 'duplicate-purchase-')));
    }
}
