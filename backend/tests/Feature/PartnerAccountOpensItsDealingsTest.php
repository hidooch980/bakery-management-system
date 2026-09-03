<?php

namespace Tests\Feature;

use App\Filament\Pages\PartnerReport;
use App\Models\Bakery;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Money;
use App\Support\PartnerLedger;
use App\Support\PartnerPosition;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The partner report: an account per bakery, and the transfers behind it
 * one click away.
 *
 * On 2026-09-03 the store held 2,840 kg of flour while 4,160 kg net sat
 * with four partner bakeries — more out on loan than in the building —
 * and no screen said so. The issue centre named two of the four, the
 * consignment list showed six rows with no notion that two of them were
 * the same partner facing opposite ways, and nothing totalled it.
 */
class PartnerAccountOpensItsDealingsTest extends TestCase
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

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 10_000, 'purchase');
    }

    private function partner(string $name, ?string $phone = null): Customer
    {
        return Customer::create([
            'name' => $name,
            'type' => 'partner',
            'phone' => $phone,
        ]);
    }

    private function move(Customer $who, string $direction, float $bags, int $daysAgo, ?string $settled = null): ConsignmentFlour
    {
        return ConsignmentFlour::create([
            'user_id' => $this->admin->id,
            'customer_id' => $who->id,
            'direction' => $direction,
            'bags' => $bags,
            'occurred_on' => now()->subDays($daysAgo),
            'settled_on' => $settled,
        ]);
    }

    public function test_the_page_opens(): void
    {
        $this->get(PartnerReport::getUrl())->assertSuccessful();
    }

    public function test_each_partner_is_one_account_however_many_transfers(): void
    {
        $hidooch = $this->partner('نانوایی هیدوچ');

        $this->move($hidooch, 'lent', 50, 32);
        $this->move($hidooch, 'lent', 6, 28);
        $this->move($this->partner('نانوایی پدگان'), 'lent', 20, 27);

        $this->assertCount(2, PartnerLedger::positions());
    }

    public function test_the_account_shows_the_net_and_the_totals_show_the_shop(): void
    {
        $kent = $this->partner('نانوایی کنت');
        $this->move($kent, 'lent', 20, 9);
        $this->move($kent, 'borrowed', 12, 18);

        $this->move($this->partner('نانوایی هیدوچ'), 'lent', 56, 32);

        $page = new PartnerReport;

        // 56 owed by هیدوچ plus 8 net owed by کنت.
        $this->assertEqualsWithDelta(64, $page->netOwedToShop(), 0.01);
        $this->assertEqualsWithDelta(76, $page->totalLent(), 0.01);
        $this->assertEqualsWithDelta(12, $page->totalBorrowed(), 0.01);
        $this->assertEqualsWithDelta(0, $page->netOwedByShop(), 0.01);
    }

    public function test_a_partner_the_shop_owes_is_counted_on_the_other_side(): void
    {
        $kent = $this->partner('نانوایی کنت');
        $this->move($kent, 'lent', 5, 20);
        $this->move($kent, 'borrowed', 30, 20);

        $page = new PartnerReport;

        $this->assertEqualsWithDelta(25, $page->netOwedByShop(), 0.01);
        $this->assertEqualsWithDelta(0, $page->netOwedToShop(), 0.01);
    }

    /**
     * The two sides never net across partners. Twenty-five sacks borrowed
     * from one bakery do not pay off fifty owed to another, and a single
     * shop-wide figure would say they did.
     */
    public function test_the_two_sides_are_not_netted_against_each_other(): void
    {
        $this->move($this->partner('نانوایی هیدوچ'), 'lent', 50, 20);

        $kent = $this->partner('نانوایی کنت');
        $this->move($kent, 'borrowed', 25, 20);

        $page = new PartnerReport;

        $this->assertEqualsWithDelta(50, $page->netOwedToShop(), 0.01);
        $this->assertEqualsWithDelta(25, $page->netOwedByShop(), 0.01);
    }

    public function test_clicking_an_account_opens_its_dealings_and_clicking_again_closes_them(): void
    {
        $hidooch = $this->partner('نانوایی هیدوچ');
        $this->move($hidooch, 'lent', 50, 32);

        $key = (string) $hidooch->id;

        Livewire::test(PartnerReport::class)
            ->assertSet('openPartner', null)
            ->call('toggle', $key)
            ->assertSet('openPartner', $key)
            ->call('toggle', $key)
            ->assertSet('openPartner', null);
    }

    public function test_opening_one_account_closes_the_other(): void
    {
        $a = $this->partner('نانوایی هیدوچ');
        $b = $this->partner('نانوایی پدگان');
        $this->move($a, 'lent', 50, 32);
        $this->move($b, 'lent', 20, 27);

        Livewire::test(PartnerReport::class)
            ->call('toggle', (string) $a->id)
            ->call('toggle', (string) $b->id)
            ->assertSet('openPartner', (string) $b->id);
    }

    /**
     * A partner who has returned every sack for a year is a different
     * conversation from one who has never returned any, and the open
     * position alone cannot tell them apart.
     */
    public function test_the_dealings_include_settled_transfers_the_position_does_not(): void
    {
        $hidooch = $this->partner('نانوایی هیدوچ');

        $this->move($hidooch, 'lent', 50, 32);
        $this->move($hidooch, 'lent', 30, 90, settled: now()->subDays(80)->toDateString());

        $position = PartnerLedger::for($hidooch->id);

        // The debt is fifty; the history is both.
        $this->assertEqualsWithDelta(50, $position->netBags(), 0.01);
        $this->assertCount(2, (new PartnerReport)->dealings($position));
    }

    public function test_a_fully_settled_partner_leaves_the_report(): void
    {
        $hidooch = $this->partner('نانوایی هیدوچ');
        $record = $this->move($hidooch, 'lent', 50, 32);

        $this->assertCount(1, PartnerLedger::positions());

        $record->update(['settled_on' => now()]);

        $this->assertCount(0, PartnerLedger::positions());
    }

    public function test_the_report_counts_the_partners_it_cannot_telephone(): void
    {
        $this->move($this->partner('نانوایی هیدوچ'), 'lent', 50, 32);
        $this->move($this->partner('نانوایی پدگان', '09151234567'), 'lent', 20, 27);

        $this->assertSame(1, (new PartnerReport)->withoutPhoneCount());
    }

    public function test_the_partners_page_names_them_and_their_sacks(): void
    {
        $hidooch = $this->partner('نانوایی هیدوچ');
        $this->move($hidooch, 'lent', 50, 32);

        $this->get(PartnerReport::getUrl())
            ->assertSuccessful()
            ->assertSee('نانوایی هیدوچ')
            ->assertSee('50.0 کیسه');
    }

    /**
     * The scanner and the report must never quote two figures for the same
     * sacks. They ask the same class, and this is what says so.
     */
    public function test_the_warning_and_the_report_agree_on_the_number(): void
    {
        $kent = $this->partner('نانوایی کنت');
        $this->move($kent, 'lent', 20, 20);
        $this->move($kent, 'borrowed', 12, 18);

        $issue = (new IssueScanner)->scan()
            ->first(fn ($i) => str_starts_with($i->key, 'consignment-open'));

        $position = PartnerLedger::for($kent->id);

        $this->assertEqualsWithDelta($position->netBags(), $issue->magnitude, 0.01);
    }

    public function test_a_partner_position_knows_its_weight(): void
    {
        $hidooch = $this->partner('نانوایی هیدوچ');
        $this->move($hidooch, 'lent', 56, 32);

        $position = PartnerLedger::for($hidooch->id);

        // 56 sacks at the shop's 40 kg sack.
        $this->assertInstanceOf(PartnerPosition::class, $position);
        $this->assertEqualsWithDelta(2240, $position->netKg(), 0.01);
    }
}
