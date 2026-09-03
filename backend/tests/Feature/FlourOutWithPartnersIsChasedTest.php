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

    private function lend(Customer $to, float $bags, int $daysAgo, bool $dateIsAGuess = false): ConsignmentFlour
    {
        $record = ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'customer_id' => $to->id,
            'direction' => 'lent',
            'bags' => $bags,
            'occurred_on' => now()->subDays($daysAgo),
            'date_is_approximate' => $dateIsAGuess,
        ]);

        return $record;
    }

    private function borrow(Customer $from, float $bags, int $daysAgo): ConsignmentFlour
    {
        return ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'customer_id' => $from->id,
            'direction' => 'borrowed',
            'bags' => $bags,
            'occurred_on' => now()->subDays($daysAgo),
        ]);
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

        $this->borrow($partner, 30, 40);

        // Sacks the shop owes are a debt, not a thing to chase. They are
        // also sitting in the store, where the balance already shows them.
        $this->assertSame([], $this->issues());
    }

    /**
     * نانوایی کنت held twenty sacks of this shop's flour while the shop
     * held twelve of theirs. Chasing twenty is asking for flour that is
     * not owed, and neither party would recognise the figure.
     */
    public function test_what_the_shop_borrowed_back_comes_off_what_it_lent(): void
    {
        $partner = $this->partner('نانوایی کنت');

        $this->lend($partner, 20, 20);
        $this->borrow($partner, 12, 18);

        $issues = $this->issues();

        $this->assertCount(1, $issues);
        $this->assertEqualsWithDelta(8, $issues[0]->magnitude, 0.01);
        $this->assertStringContainsString('8', $issues[0]->detail);
        // And it shows its working, so the owner can check the eight
        // against the twenty and the twelve he remembers.
        $this->assertStringContainsString('12', $issues[0]->detail);
    }

    public function test_a_partner_who_gave_back_more_than_he_took_is_not_chased(): void
    {
        $partner = $this->partner('نانوایی کنت');

        $this->lend($partner, 10, 30);
        $this->borrow($partner, 25, 28);

        // The shop is the debtor here. Nothing to chase.
        $this->assertSame([], $this->issues());
    }

    public function test_netting_is_per_partner_not_across_the_shop(): void
    {
        $this->lend($this->partner('نانوایی هیدوچ'), 50, 20);
        $this->borrow($this->partner('نانوایی کنت'), 50, 20);

        // Flour borrowed from one bakery does not settle a debt owed by
        // another. Fifty sacks are still out with هیدوچ.
        $issues = $this->issues();

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('هیدوچ', $issues[0]->title);
        $this->assertEqualsWithDelta(50, $issues[0]->magnitude, 0.01);
    }

    /**
     * The twenty sacks at نانوایی ناهوت went a month with no record, were
     * entered on the day the owner finally mentioned them, and so arrived
     * on file looking one day old. The warning built to chase exactly that
     * flour would have stayed quiet another fortnight.
     */
    public function test_a_handover_date_nobody_knows_is_chased_at_once(): void
    {
        $this->lend($this->partner('نانوایی ناهوت'), 20, 1, dateIsAGuess: true);

        $issues = $this->issues();

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('ناهوت', $issues[0]->title);
    }

    public function test_a_guessed_date_does_not_claim_an_age_it_cannot_know(): void
    {
        $this->lend($this->partner('نانوایی ناهوت'), 20, 1, dateIsAGuess: true);

        $issue = $this->issues()[0];

        // Saying "1 day ago" would be worse than saying nothing: it is the
        // wrong answer stated confidently.
        $this->assertStringContainsString('نامعلوم', $issue->detail);
        $this->assertStringNotContainsString('1 روز پیش', $issue->detail);
    }

    public function test_an_exact_date_still_gets_its_fortnight(): void
    {
        // The flag is an admission of ignorance, not a way to shout early
        // about every ordinary loan.
        $this->lend($this->partner('نانوایی هیدوچ'), 20, 5, dateIsAGuess: false);

        $this->assertSame([], $this->issues());
    }

    public function test_the_chase_carries_the_number_to_ring(): void
    {
        $partner = $this->partner('نانوایی هیدوچ');
        $partner->update(['phone' => '09151234567']);

        $this->lend($partner, 50, 20);

        // A debt with no way to ask for it back is half a warning.
        $this->assertStringContainsString('09151234567', $this->issues()[0]->suggestion);
    }

    public function test_a_partner_with_no_number_is_said_to_have_none(): void
    {
        $this->lend($this->partner('نانوایی هیدوچ'), 50, 20);

        $this->assertStringContainsString(
            'شمارهٔ تماس این همکار در سیستم نیست',
            $this->issues()[0]->suggestion
        );
    }
}
