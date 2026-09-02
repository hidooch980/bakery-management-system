<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\ProductionRecorder;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `stock:audit` asks how much a record moved, not merely whether it moved.
 *
 * The difference is not academic. On 1405/06/07 a batch entered as ten
 * sacks was corrected to twenty; the model had no hook on editing, so the
 * flour for the second ten was baked and sold without leaving the ledger.
 * The audit ran green for four days afterwards, on every deploy, because
 * the entry had moved 400 kg and the only question asked was whether it
 * had moved anything at all.
 *
 * Flour only, deliberately — see the note on the check itself. The salt
 * and yeast ratios have both been changed since the older batches were
 * mixed, and holding those to today's formula would report every old
 * record as wrong.
 */
class TheStockAuditAsksHowMuchTest extends TestCase
{
    use RefreshDatabase;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'water_ratio' => 0.7,
            'salt_ratio' => 0.016,
            'yeast_ratio' => 0.0025,
            'dough_loss_ratio' => 0,
            'proof_gain_ratio' => 0,
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
        ]);

        $this->baker = User::factory()->create(['is_active' => true]);
        $this->baker->assignRole('admin');
        $this->actingAs($this->baker);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 4000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 200, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 100, 'purchase');
    }

    /**
     * Edits the row the way the old code did — straight to the database,
     * with no model hook — because that is the state the shop's records
     * were actually in, and a check has to be proved against the damage it
     * exists to find.
     */
    private function editBehindTheModelsBack(DoughEntry $dough, int $bags): void
    {
        DB::table('dough_entries')->where('id', $dough->id)->update(['bag_count' => $bags]);
    }

    public function test_a_batch_whose_flour_does_not_match_its_sacks_is_reported(): void
    {
        $dough = ProductionRecorder::dough(10, $this->baker->id);
        $this->editBehindTheModelsBack($dough, 20);

        $this->artisan('stock:audit')
            ->expectsOutputToContain('رکورد مقدارِ درستی جابه‌جا نکرده‌اند')
            ->assertFailed();
    }

    public function test_the_reported_line_says_how_far_out_it_is(): void
    {
        $dough = ProductionRecorder::dough(10, $this->baker->id);
        $this->editBehindTheModelsBack($dough, 20);

        $this->artisan('stock:audit')
            ->expectsOutputToContain('اختلاف 400')
            ->assertFailed();
    }

    public function test_a_batch_that_moved_exactly_what_it_claims_passes(): void
    {
        ProductionRecorder::dough(10, $this->baker->id);

        $this->artisan('stock:audit')
            ->expectsOutputToContain('هر رکوردی که باید انبار را جابه‌جا می‌کرد، کرده است.')
            ->assertSuccessful();
    }

    /**
     * Correcting it through the model is the fix, and the audit has to
     * agree that it is fixed — otherwise the shop is told to chase a hole
     * that was already filled.
     */
    public function test_correcting_it_through_the_model_clears_the_report(): void
    {
        $dough = ProductionRecorder::dough(10, $this->baker->id);
        $this->editBehindTheModelsBack($dough, 20);

        $this->artisan('stock:audit')->assertFailed();

        // The record already says twenty; reconciling posts the 400 kg the
        // edit should have moved when it was made.
        $dough->refresh()->reconcileStock(10);

        $this->artisan('stock:audit')->assertSuccessful();
    }

    /**
     * The other three record types are held to the same question, and a
     * consignment is the one whose expected quantity flips sign — flour
     * lent leaves the store, flour borrowed arrives in it.
     */
    public function test_a_consignment_whose_sacks_were_edited_behind_the_model_is_reported(): void
    {
        $partner = Customer::create([
            'name' => 'نانوایی کنت',
            'type' => Customer::PARTNER_TYPE,
            'is_active' => true,
        ]);

        $lending = ConsignmentFlour::create([
            'customer_id' => $partner->id,
            'direction' => 'lent',
            'bags' => 5,
            'occurred_on' => today(),
        ]);

        $this->artisan('stock:audit')->assertSuccessful();

        DB::table('consignment_flours')->where('id', $lending->id)
            ->update(['bags' => 10, 'amount_kg' => 400]);

        $this->artisan('stock:audit')
            ->expectsOutputToContain('رکورد مقدارِ درستی جابه‌جا نکرده‌اند')
            ->assertFailed();
    }

    /**
     * A chane entry that dusted no flour moved none, and must not be read
     * as a record that is 0 kg out. It was already exempt from the older
     * pass; the quantity pass has to leave it alone for its own reason.
     */
    public function test_a_batch_shaped_without_spray_flour_is_not_reported(): void
    {
        $dough = ProductionRecorder::dough(10, $this->baker->id);
        ProductionRecorder::chane($dough, $this->baker->id, 400.0, 0.0, 0.0, 470);

        $this->artisan('stock:audit')->assertSuccessful();
    }
}
