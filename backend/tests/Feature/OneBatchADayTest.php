<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One batch a day.
 *
 * The owner asked for it on 1405/06/03, and the shop was already doing
 * it: 28 dough entries across 28 days and never two on one. So this makes
 * a rule of what already happens, and what it actually catches is the
 * double entry — the same morning recorded twice — rather than a second
 * batch nobody was going to knead.
 *
 * Deliberately not per person. The shop kneads once, whoever is holding
 * the phone, and two people recording the same morning is exactly the
 * mistake this stops. A guard scoped to the user would let it through.
 */
class OneBatchADayTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    private User $shaper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first();

        foreach ([InventoryItem::FLOUR, InventoryItem::SALT,
            InventoryItem::YEAST_DRY] as $key) {
            InventoryItem::ofKey($key)->move('in', 5000, 'purchase');
        }

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('dough_maker');

        $this->shaper = User::factory()->create(['is_active' => true]);
        $this->shaper->assignRole('chane_gir');
    }

    private function knead(?User $as = null, int $bags = 10, bool $force = false): TestResponse
    {
        return $this->actingAs($as ?? $this->baker, 'sanctum')
            ->postJson('/api/v1/dough-entries', [
                'bag_count' => $bags,
                'force' => $force,
            ]);
    }

    private function shape(int $doughId, bool $force = false): TestResponse
    {
        return $this->actingAs($this->shaper, 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $doughId,
                'chane_count' => 50,
                'spray_flour_kg' => 1,
                'force' => $force,
            ]);
    }

    public function test_a_second_dough_the_same_day_is_refused(): void
    {
        $this->knead()->assertSuccessful();

        $this->knead()
            ->assertStatus(409)
            ->assertJsonPath('message', 'امروز 10 کیسه خمیر ثبت شده است. روزی یک بار.');

        $this->assertSame(1, DoughEntry::count());
    }

    public function test_the_refusal_names_what_is_already_recorded(): void
    {
        $this->knead(bags: 14)->assertSuccessful();

        // «One a day» on its own leaves somebody wondering whether their
        // entry went in at all. Naming the bag count answers that without
        // making them go and look.
        $this->knead(bags: 9)->assertJsonPath(
            'message',
            'امروز 14 کیسه خمیر ثبت شده است. روزی یک بار.',
        );
    }

    public function test_somebody_else_recording_the_same_morning_is_also_refused(): void
    {
        $this->knead(bags: 11)->assertSuccessful();

        $second = User::factory()->create(['is_active' => true]);
        $second->assignRole('dough_maker');

        // The shop kneads once, whoever is holding the phone. A guard
        // scoped to the user would let this straight through, and this is
        // the mistake the rule is actually for.
        $this->knead($second, bags: 12)->assertStatus(409);

        $this->assertSame(1, DoughEntry::count());
    }

    public function test_yesterdays_dough_does_not_block_today(): void
    {
        $yesterday = DoughEntry::create([
            'user_id' => $this->baker->id,
            'bag_count' => 10,
        ]);
        $yesterday->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $this->knead()->assertSuccessful();

        $this->assertSame(2, DoughEntry::count());
    }

    public function test_a_second_chane_the_same_day_is_refused(): void
    {
        $dough = $this->knead()->json('data.id')
            ?? DoughEntry::latest('id')->first()->id;

        $this->shape($dough)->assertSuccessful();

        // A second dough cannot exist today, but if one somehow did, the
        // dough guard alone would not stop it being shaped: a chane entry
        // is one per *dough*, not one per day.
        $other = DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);

        $this->shape($other->id)
            ->assertStatus(409)
            ->assertJsonPath('message', 'امروز 50 چانه ثبت شده است. روزی یک بار.');

        $this->assertSame(1, ChaneEntry::count());
    }

    public function test_yesterdays_chane_does_not_block_today(): void
    {
        $old = DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);
        $oldChane = ChaneEntry::create([
            'dough_entry_id' => $old->id,
            'user_id' => $this->shaper->id,
            'chane_count' => 50,
            'normal_weight_kg' => 42,
            'nanino_weight_kg' => 0,
            'spray_flour_kg' => 0,
        ]);
        $oldChane->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $today = DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);

        $this->shape($today->id)->assertSuccessful();

        $this->assertSame(2, ChaneEntry::count());
    }

    public function test_a_genuinely_second_batch_still_gets_through_on_confirmation(): void
    {
        $this->knead(bags: 10)->assertSuccessful();

        // Refusing outright would mean a real second batch — a big order,
        // a holiday — could not be recorded at all, and an unrecordable
        // batch is one that goes unrecorded. On 24 Mordad the answer to
        // three taps in thirty-five minutes was to make the second one
        // deliberate, not impossible. That still holds.
        $this->knead(bags: 7, force: true)->assertSuccessful();

        $this->assertSame(2, DoughEntry::count());
    }

    public function test_a_second_shaping_also_gets_through_on_confirmation(): void
    {
        $first = DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);
        $this->shape($first->id)->assertSuccessful();

        $second = DoughEntry::create(['user_id' => $this->baker->id, 'bag_count' => 10]);

        $this->shape($second->id, force: true)->assertSuccessful();

        $this->assertSame(2, ChaneEntry::count());
    }
}
