<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\ChaneEntry;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use App\Support\SystemVersusOven;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the oven made against what سامانه نانینو saw of it.
 *
 * Bread sold on the card reader registers with the national system; cash,
 * credit, bread taken home and bread given away do not. The flour quota
 * follows what the system sees, so the gap is the part of the month's
 * baking that earns the shop nothing towards next month's flour.
 */
class SystemVersusOvenTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole('seller');
    }

    private function bake(int $loaves): ChaneEntry
    {
        $dough = DoughEntry::create([
            'user_id' => $this->seller->id,
            'bag_count' => 10,
        ]);

        return ChaneEntry::create([
            'dough_entry_id' => $dough->id,
            'user_id' => $this->seller->id,
            'chane_count' => $loaves,
            'normal_weight_kg' => $loaves * 0.85,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 5,
        ]);
    }

    private function sell(ChaneEntry $batch, string $type, int $loaves): Sale
    {
        return Sale::create([
            'chane_entry_id' => $batch->id,
            'user_id' => $this->seller->id,
            'payment_type' => $type,
            'bread_count' => $loaves,
            'amount' => in_array($type, ['home', 'charity'], true) ? null : $loaves * 10_000,
        ]);
    }

    public function test_the_card_is_what_the_system_sees(): void
    {
        $batch = $this->bake(777);
        $this->sell($batch, 'card', 718);

        $period = SystemVersusOven::forMonth();

        $this->assertSame(777, $period->baked());
        $this->assertSame(718, $period->seenBySystem());
    }

    public function test_the_gap_is_the_baking_the_system_missed(): void
    {
        $batch = $this->bake(777);
        $this->sell($batch, 'card', 718);

        // Not the sum of the unseen kinds: bread shaped and never accounted
        // for at all has to land here rather than vanish.
        $this->assertSame(59, SystemVersusOven::forMonth()->gap());
    }

    public function test_cash_credit_home_and_charity_are_all_invisible(): void
    {
        $batch = $this->bake(777);
        $this->sell($batch, 'card', 700);
        $this->sell($batch, 'cash', 30);
        $this->sell($batch, 'credit', 20);
        $this->sell($batch, 'home', 15);
        $this->sell($batch, 'charity', 12);

        $unseen = SystemVersusOven::forMonth()->unseen();

        $this->assertSame(30, $unseen['cash']);
        $this->assertSame(20, $unseen['credit']);
        $this->assertSame(15, $unseen['home']);
        $this->assertSame(12, $unseen['charity']);
        $this->assertArrayNotHasKey('card', $unseen);
    }

    public function test_it_reports_what_share_the_system_saw(): void
    {
        $batch = $this->bake(1_000);
        $this->sell($batch, 'card', 870);

        $this->assertSame(87.0, SystemVersusOven::forMonth()->shareSeen());
    }

    public function test_a_month_with_no_baking_claims_no_share(): void
    {
        // Zero of zero is not a hundred per cent, and it is not an error.
        $this->assertSame(0.0, SystemVersusOven::forMonth()->shareSeen());
        $this->assertSame(0, SystemVersusOven::forMonth()->gap());
    }

    public function test_selling_more_than_was_baked_does_not_invent_a_negative_gap(): void
    {
        $batch = $this->bake(100);
        $this->sell($batch, 'card', 120);

        // A miscount somewhere, but a negative shortfall on a dashboard
        // reads as a surplus and is worse than saying nothing.
        $this->assertSame(0, SystemVersusOven::forMonth()->gap());
    }

    public function test_a_debt_collected_on_the_card_is_reported_apart(): void
    {
        $account = BankAccount::create([
            'title' => 'حساب سفید',
            'opening_balance' => 0,
            'is_active' => true,
            'is_default' => true,
        ]);

        $customer = Customer::create(['name' => 'مدرسه']);

        $account->record('in', 500_000, 'settlement', $this->seller->id, null,
            'وصول نسیه با کارتخوان — '.$customer->name);

        // It registers with the system when it is collected, not when the
        // bread went out, so it closes part of an earlier month's gap
        // rather than this one's — reported, but never folded into the
        // loaf count.
        $period = SystemVersusOven::forMonth();

        $this->assertEquals(500_000, $period->collectedOnCard());
        $this->assertSame(0, $period->seenBySystem());
    }

    public function test_a_cash_collection_is_not_counted_as_seen(): void
    {
        $account = BankAccount::create([
            'title' => 'صندوق نقد',
            'opening_balance' => 0,
            'is_active' => true,
            'is_cash_box' => true,
        ]);

        $account->record('in', 500_000, 'settlement', $this->seller->id, null,
            'وصول نسیه نقدی — مدرسه');

        $this->assertEquals(0, SystemVersusOven::forMonth()->collectedOnCard());
    }

    public function test_last_months_baking_is_not_this_months(): void
    {
        $batch = $this->bake(500);
        $this->sell($batch, 'card', 500);

        ChaneEntry::query()->update(['created_at' => now()->copy()->subMonths(2)]);
        Sale::query()->update(['created_at' => now()->copy()->subMonths(2)]);

        $this->assertSame(0, SystemVersusOven::forMonth()->baked());
    }
}
