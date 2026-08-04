<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\User;
use App\Support\CurrentBakery;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Two shops on one installation must never see each other's figures.
 */
class MultipleBakeriesTest extends TestCase
{
    use RefreshDatabase;

    private Bakery $first;

    private Bakery $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Money::forgetCache();

        $this->first = Bakery::query()->oldest('id')->first();

        $this->artisan('bakery:create', [
            'name' => 'نانوایی دوم',
            '--admin-name' => 'مدیر دوم',
            '--admin-email' => 'second@bakery.test',
            '--admin-phone' => '09120000002',
            '--admin-password' => 'secret1234',
        ])->assertSuccessful();

        $this->second = Bakery::query()->where('name', 'نانوایی دوم')->firstOrFail();
    }

    private function adminOf(Bakery $bakery): User
    {
        $existing = User::withoutGlobalScopes()
            ->where('bakery_id', $bakery->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->first();

        if ($existing) {
            return $existing;
        }

        return CurrentBakery::for($bakery->id, function () use ($bakery) {
            $user = User::create([
                'name' => 'مدیر',
                'email' => 'admin'.$bakery->id.'@bakery.test',
                'phone' => '0912000'.str_pad((string) $bakery->id, 4, '0', STR_PAD_LEFT),
                'password' => Hash::make('secret1234'),
                'is_active' => true,
                'bakery_id' => $bakery->id,
            ]);

            $user->assignRole('admin');

            return $user;
        });
    }

    // ------------------------------------------------------ making a shop

    public function test_the_command_opens_a_shop_with_its_own_admin(): void
    {
        $this->assertNotSame($this->first->id, $this->second->id);

        $admin = User::withoutGlobalScopes()
            ->where('email', 'second@bakery.test')->firstOrFail();

        $this->assertSame($this->second->id, $admin->bakery_id);
        $this->assertTrue($admin->hasRole('admin'));
    }

    public function test_a_login_already_in_use_is_refused(): void
    {
        $this->artisan('bakery:create', [
            'name' => 'نانوایی سوم',
            '--admin-name' => 'مدیر سوم',
            // Already taken by the second shop's admin.
            '--admin-email' => 'second@bakery.test',
            '--admin-phone' => '09120000003',
            '--admin-password' => 'secret1234',
        ])->assertFailed();

        $this->assertSame(0, Bakery::where('name', 'نانوایی سوم')->count());
    }

    // ------------------------------------------------------- what is seen

    public function test_a_shop_reads_only_its_own_records(): void
    {
        CurrentBakery::for($this->first->id, function () {
            Expense::create([
                'title' => 'گازوئیل نانوایی اول',
                'category' => 'fuel',
                'amount' => 100000,
                'spent_on' => now()->toDateString(),
            ]);
        });

        CurrentBakery::for($this->second->id, function () {
            Expense::create([
                'title' => 'گازوئیل نانوایی دوم',
                'category' => 'fuel',
                'amount' => 200000,
                'spent_on' => now()->toDateString(),
            ]);
        });

        $this->actingAs($this->adminOf($this->first));
        $this->assertSame(1, Expense::count());
        $this->assertSame('گازوئیل نانوایی اول', Expense::first()->title);

        $this->actingAs($this->adminOf($this->second));
        $this->assertSame(1, Expense::count());
        $this->assertSame('گازوئیل نانوایی دوم', Expense::first()->title);
    }

    public function test_a_new_record_is_stamped_with_the_signed_in_shop(): void
    {
        $admin = $this->adminOf($this->second);
        $this->actingAs($admin);

        $expense = Expense::create([
            'title' => 'آرد',
            'category' => 'flour',
            'amount' => 50000,
            'spent_on' => now()->toDateString(),
        ]);

        $this->assertSame($this->second->id, $expense->bakery_id);
    }

    public function test_each_shop_keeps_its_own_store(): void
    {
        // The same key in two shops: one global unique would have let the
        // first shop's flour stop the second from ever having any.
        $firstFlour = CurrentBakery::for(
            $this->first->id,
            fn () => InventoryItem::ofKey(InventoryItem::FLOUR)
        );

        $secondFlour = CurrentBakery::for(
            $this->second->id,
            fn () => InventoryItem::ofKey(InventoryItem::FLOUR)
        );

        $this->assertNotSame($firstFlour->id, $secondFlour->id);

        CurrentBakery::for($this->first->id, fn () => $firstFlour->move('in', 500, 'purchase'));

        $this->assertEqualsWithDelta(500.0, $firstFlour->fresh()->balance, 0.001);
        // The second shop's store is untouched by the first's delivery.
        $this->assertEqualsWithDelta(0.0, $secondFlour->fresh()->balance, 0.001);
    }

    public function test_settings_are_each_shops_own(): void
    {
        $this->first->update(['bread_price' => 5000]);
        $this->second->update(['bread_price' => 9000]);

        $this->actingAs($this->adminOf($this->first));
        $this->assertEqualsWithDelta(5000.0, (float) CurrentBakery::get()->bread_price, 0.01);

        $this->actingAs($this->adminOf($this->second));
        $this->assertEqualsWithDelta(9000.0, (float) CurrentBakery::get()->bread_price, 0.01);
    }

    public function test_production_recorded_in_one_shop_is_invisible_in_the_other(): void
    {
        CurrentBakery::for($this->first->id, function () {
            $user = User::withoutGlobalScopes()->where('bakery_id', $this->first->id)->first()
                ?? $this->adminOf($this->first);

            $dough = DoughEntry::create(['user_id' => $user->id, 'bag_count' => 3]);

            ChaneEntry::create([
                'dough_entry_id' => $dough->id,
                'user_id' => $user->id,
                'chane_count' => 50,
                'normal_weight_kg' => 42.5,
                'nanino_weight_kg' => 0,
                'spray_flour_kg' => 0,
            ]);
        });

        $this->actingAs($this->adminOf($this->second));

        $this->assertSame(0, DoughEntry::count());
        $this->assertSame(0, ChaneEntry::count());
        $this->assertSame(0, Sale::count());

        $this->actingAs($this->adminOf($this->first));

        $this->assertSame(1, DoughEntry::count());
        $this->assertSame(1, ChaneEntry::count());
    }

    public function test_a_report_can_deliberately_cross_shops(): void
    {
        CurrentBakery::for($this->first->id, fn () => Expense::create([
            'title' => 'الف', 'category' => 'fuel', 'amount' => 100, 'spent_on' => now()->toDateString(),
        ]));

        CurrentBakery::for($this->second->id, fn () => Expense::create([
            'title' => 'ب', 'category' => 'fuel', 'amount' => 200, 'spent_on' => now()->toDateString(),
        ]));

        $this->actingAs($this->adminOf($this->first));

        // Scoped by default; crossing has to be asked for by name.
        $this->assertSame(1, Expense::count());
        $this->assertSame(2, Expense::query()->acrossBakeries()->count());
    }
}
