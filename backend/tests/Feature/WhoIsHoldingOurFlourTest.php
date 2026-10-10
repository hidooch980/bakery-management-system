<?php

namespace Tests\Feature;

use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «گزارشات آرد امانی در اپ».
 *
 * The list of consignments answers «what happened». The question actually
 * asked while standing in the store is «who has our sacks, how many, and
 * since when» — the owner states it in exactly those terms, down to the
 * days: «۵۶ کیسه دست عبدالرئوف، ۲۳ روز». It had to be worked out by
 * reading rows.
 */
class WhoIsHoldingOurFlourTest extends TestCase
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

        // Lending flour actually takes it out of the store — the model
        // moves stock, it does not merely note the loan — so a bakery
        // with an empty warehouse cannot lend a sack. That the first run
        // of these tests failed with InsufficientStockException is the
        // system working: the sacks in these rows are real sacks.
        InventoryItem::ofKey(InventoryItem::FLOUR)
            ->move('in', 200 * 45, 'purchase', $this->admin->id);
    }

    private function lend(string $partner, float $bags, int $daysAgo, ?string $settled = null): ConsignmentFlour
    {
        $customer = Customer::firstOrCreate(
            ['name' => $partner],
            ['type' => Customer::PARTNER_TYPE, 'is_active' => true],
        );

        return ConsignmentFlour::create([
            'customer_id' => $customer->id,
            'user_id' => $this->admin->id,
            'direction' => 'lent',
            'bags' => $bags,
            'amount_kg' => $bags * 45,
            'occurred_on' => now()->subDays($daysAgo)->toDateString(),
            'settled_on' => $settled,
        ]);
    }

    private function report(): array
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/v1/consignment-flour/partners')
            ->assertOk()
            ->json('data');
    }

    public function test_it_gathers_a_partners_sacks_into_one_line(): void
    {
        $this->lend('عبدالرئوف', 30, 23);
        $this->lend('عبدالرئوف', 26, 9);

        $report = $this->report();

        $this->assertCount(1, $report, 'دو ثبت از یک همکار باید یک سطر شود.');
        $this->assertSame('عبدالرئوف', $report[0]['partner_name']);
        $this->assertSame(2, $report[0]['entries']);
        $this->assertEqualsWithDelta(56.0, $report[0]['lent_bags'], 0.01);
    }

    public function test_the_age_is_the_oldest_row_not_an_average(): void
    {
        $this->lend('عبدالرئوف', 30, 23);
        $this->lend('عبدالرئوف', 26, 1);

        // An average would report 12 days and make a two-week-old debt
        // look fresh. The oldest sack is the one worth chasing.
        $this->assertSame(23, $this->report()[0]['days']);
    }

    public function test_settled_flour_is_history_and_the_partner_shows_square(): void
    {
        // «همه اسما باشه»: همکار می‌ماند، با ماندهٔ صفر.
        $this->lend('ممد زاکر', 20, 18, settled: now()->toDateString());

        $report = $this->report();

        $this->assertCount(1, $report);
        $this->assertEqualsWithDelta(0.0, $report[0]['net_bags'], 0.01);
        $this->assertTrue($report[0]['is_settled']);
        $this->assertSame('تسویه', $report[0]['headline']['label']);
        $this->assertNull($report[0]['days']);
    }

    public function test_every_partner_is_listed_square_or_not_owing_first(): void
    {
        $this->lend('ممد زاکر', 20, 18, settled: now()->toDateString());
        $this->lend('عبدالرئوف', 56, 23);
        Customer::create(['name' => 'الف بی‌ثبت', 'type' => Customer::PARTNER_TYPE, 'is_active' => true]);

        $report = $this->report();

        $this->assertSame(['عبدالرئوف', 'الف بی‌ثبت', 'ممد زاکر'], array_column($report, 'partner_name'));
        $this->assertSame(0, $report[1]['entries']);
    }

    public function test_the_biggest_debt_is_first(): void
    {
        $this->lend('ممد زاکر', 20, 18);
        $this->lend('عبدالرئوف', 56, 23);

        $report = $this->report();

        $this->assertSame('عبدالرئوف', $report[0]['partner_name']);
        $this->assertSame('ممد زاکر', $report[1]['partner_name']);
    }

    public function test_flour_borrowed_is_kept_apart_from_flour_lent(): void
    {
        $partner = Customer::firstOrCreate(
            ['name' => 'رحیم'],
            ['type' => Customer::PARTNER_TYPE, 'is_active' => true],
        );

        ConsignmentFlour::create([
            'customer_id' => $partner->id,
            'user_id' => $this->admin->id,
            'direction' => 'borrowed',
            'bags' => 10,
            'amount_kg' => 450,
            'occurred_on' => now()->subDays(3)->toDateString(),
        ]);

        $this->lend('رحیم', 4, 2);

        $row = $this->report()[0];

        // «همه چی اوتوماتیک»: چهار کیسهٔ دادیم از ده کیسهٔ گرفتیم کم شد؛
        // شش کیسه بدهکاریم و ردیفی برای «تسویه» باز نمی‌ماند.
        $this->assertEqualsWithDelta(0.0, $row['lent_bags'], 0.01);
        $this->assertEqualsWithDelta(6.0, $row['borrowed_bags'], 0.01);
        $this->assertEqualsWithDelta(-6.0, $row['net_bags'], 0.01);
    }

    public function test_flour_out_today_is_zero_days_not_a_fraction(): void
    {
        $this->lend('رحیم', 5, 0);

        $this->assertSame(0, $this->report()[0]['days']);
    }

    public function test_a_one_off_partner_with_no_customer_record_still_appears(): void
    {
        // Older rows carry a typed name rather than a defined partner.
        ConsignmentFlour::create([
            'partner_name' => 'نانوایی سر خیابان',
            'user_id' => $this->admin->id,
            'direction' => 'lent',
            'bags' => 3,
            'amount_kg' => 135,
            'occurred_on' => now()->subDays(4)->toDateString(),
        ]);

        $this->assertSame('نانوایی سر خیابان', $this->report()[0]['partner_name']);
    }
}
