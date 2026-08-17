<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Money;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sacks lent to a partner bakery and not come back.
 *
 * The shop lends and borrows flour with the bakeries around it, and that is
 * ordinary — but the sacks are the shop's, and the store is short by exactly
 * what is out. On 2026-08-17 that was 76 sacks across two partners, the
 * oldest fifteen days old, and nothing said so: the seller's cash was
 * chased and flour worth more than most of those balances was not.
 */
class FlourOutWithPartnersIsChasedTest extends TestCase
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
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
            'bread_price' => 5000,
            'currency' => 'toman',
        ]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        // Lending takes the sacks out of the store, so there have to be
        // some or the movement is refused and the record never lands.
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 10_000, 'purchase');
    }

    private function partner(string $name): Customer
    {
        return Customer::create(['name' => $name, 'type' => 'partner']);
    }

    private function lend(Customer $to, float $bags, int $daysAgo): ConsignmentFlour
    {
        $record = ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'customer_id' => $to->id,
            'direction' => 'lent',
            'bags' => $bags,
            'occurred_on' => now()->subDays($daysAgo),
        ]);

        return $record;
    }

    /** @return array<int, SystemIssue> */
    private function issues(): array
    {
        return (new IssueScanner)->scan()
            ->filter(fn (SystemIssue $i) => str_starts_with($i->key, 'consignment-open'))
            ->values()
            ->all();
    }

    public function test_sacks_out_a_few_days_are_the_ordinary_rhythm(): void
    {
        $this->lend($this->partner('نانوایی هیدوچ'), 20, 5);

        // Sacks go back and forth within a week here as a matter of course.
        $this->assertSame([], $this->issues());
    }

    public function test_sacks_out_a_fortnight_are_chased(): void
    {
        $this->lend($this->partner('نانوایی هیدوچ'), 50, 15);

        $issues = $this->issues();

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('نانوایی هیدوچ', $issues[0]->title);
        $this->assertSame(SystemIssue::WARNING, $issues[0]->severity);
    }

    public function test_one_partner_is_one_issue_however_many_lendings(): void
    {
        $partner = $this->partner('نانوایی هیدوچ');

        $this->lend($partner, 50, 15);
        $this->lend($partner, 6, 11);

        // A name and a number is what the owner needs — not four rows about
        // the same person.
        $issues = $this->issues();

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('56', $issues[0]->detail);
        $this->assertEqualsWithDelta(56, $issues[0]->magnitude, 0.01);
    }

    public function test_two_partners_are_two_issues(): void
    {
        $this->lend($this->partner('نانوایی هیدوچ'), 50, 15);
        $this->lend($this->partner('نانوایی پدگان'), 20, 20);

        $this->assertCount(2, $this->issues());
    }

    public function test_the_age_counts_from_the_oldest_sack(): void
    {
        $partner = $this->partner('نانوایی هیدوچ');

        $this->lend($partner, 50, 15);
        $this->lend($partner, 6, 1);

        // A fresh lending must not make an old debt look new.
        $this->assertStringContainsString('15', $this->issues()[0]->detail);
    }

    public function test_settling_it_clears_the_issue(): void
    {
        $record = $this->lend($this->partner('نانوایی هیدوچ'), 50, 15);

        $this->assertCount(1, $this->issues());

        $record->update(['settled_on' => now()]);

        $this->assertSame([], $this->issues());
    }

    public function test_flour_borrowed_is_not_flour_owed_to_us(): void
    {
        $partner = $this->partner('نانوایی کنت');

        ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'customer_id' => $partner->id,
            'direction' => 'borrowed',
            'bags' => 30,
            'occurred_on' => now()->subDays(40),
        ]);

        // Sacks the shop owes are a debt, not a thing to chase. They are
        // also sitting in the store, where the balance already shows them.
        $this->assertSame([], $this->issues());
    }
}
