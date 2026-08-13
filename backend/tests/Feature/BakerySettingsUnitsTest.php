<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prices are typed in whatever unit the shop displays and stored in Toman.
 * This endpoint filled the columns raw, so a Rial shop setting flour to
 * 12,000 stored 12,000 Toman - and every loaf was costed at ten times what
 * the factory charged.
 */
class BakerySettingsUnitsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => Money::RIAL]);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    protected function tearDown(): void
    {
        Money::forgetCache();
        parent::tearDown();
    }

    private function save(array $data)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/bakery', array_merge(['name' => 'نانوایی'], $data));
    }

    public function test_a_price_typed_in_rial_is_stored_in_toman(): void
    {
        $this->save(['flour_purchase_price_per_kg' => 12_000])->assertOk();

        // Ten Rial to the Toman: 12,000 Rial the kilo is 1,200 Toman.
        $this->assertEquals(1_200.0, (float) Bakery::first()->flour_purchase_price_per_kg);
    }

    public function test_every_money_field_converts_not_just_one(): void
    {
        $this->save([
            'bread_price' => 10_000,
            'flour_price_per_kg' => 20_000,
            'flour_price_per_bag' => 800_000,
            'late_tier1_amount' => 50_000,
            'late_tier2_amount' => 100_000,
        ])->assertOk();

        $bakery = Bakery::first();

        $this->assertEquals(1_000.0, (float) $bakery->bread_price);
        $this->assertEquals(2_000.0, (float) $bakery->flour_price_per_kg);
        $this->assertEquals(80_000.0, (float) $bakery->flour_price_per_bag);
        $this->assertEquals(5_000.0, (float) $bakery->late_tier1_amount);
        $this->assertEquals(10_000.0, (float) $bakery->late_tier2_amount);
    }

    public function test_a_toman_shop_is_left_exactly_as_it_was(): void
    {
        Bakery::first()->update(['currency' => Money::TOMAN]);
        Money::forgetCache();

        $this->save(['bread_price' => 1_000])->assertOk();

        $this->assertEquals(1_000.0, (float) Bakery::first()->bread_price);
    }

    public function test_untouched_fields_are_not_rescaled(): void
    {
        // A save that does not mention a price must not divide it again.
        Bakery::first()->update(['bread_price' => 1_000]);

        $this->save(['phone' => '09120000000'])->assertOk();

        $this->assertEquals(1_000.0, (float) Bakery::first()->bread_price);
    }

    public function test_the_read_side_still_gives_the_app_stored_toman(): void
    {
        Bakery::first()->update(['bread_price' => 1_000]);

        // The app multiplies this by a loaf count and formats once at the
        // point of rendering; a Rial figure here would show every total ten
        // times over.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/bakery')
            ->assertOk()
            ->assertJsonPath('data.bread_price', fn ($v) => (float) $v === 1_000.0)
            ->assertJsonPath('data.bread_price_formatted', '10/000 ریال');
    }
}
