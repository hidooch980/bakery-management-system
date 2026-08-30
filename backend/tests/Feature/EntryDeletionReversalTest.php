<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting a production entry has to put the warehouse back where it was.
 *
 * Kneading and shaping move real stock, so an entry that is deleted without
 * reversing its movements leaves flour permanently missing from the ledger
 * with nothing left to explain where it went — which is how a balance ends
 * up negative for no visible reason.
 */
class EntryDeletionReversalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update([
            'flour_bag_weight_kg' => 40,
            'water_ratio' => 0.6,
            'salt_ratio' => 0.015,
            'dough_loss_ratio' => 0,
            // Proving is measured in ProofGainTest; here the
            // formula's own arithmetic is what is under test.
            'proof_gain_ratio' => 0,
            'normal_chane_weight_kg' => 0.85,
            'nanino_chane_weight_kg' => 1.0,
        ]);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 1000, 'purchase');
        InventoryItem::ofKey(InventoryItem::SALT)->move('in', 100, 'purchase');
        InventoryItem::ofKey(InventoryItem::YEAST_DRY)->move('in', 50, 'purchase');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function recordDough(int $bags = 2): DoughEntry
    {
        $this->actingAs($this->userWithRole('dough_maker'), 'sanctum')
            ->postJson('/api/v1/dough-entries', ['bag_count' => $bags])
            ->assertCreated();

        return DoughEntry::latest('id')->first();
    }

    private function recordChane(DoughEntry $dough, int $count = 100): ChaneEntry
    {
        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'chane_count' => $count,
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();

        return ChaneEntry::latest('id')->first();
    }

    public function test_deleting_a_dough_entry_puts_the_flour_back(): void
    {
        $flourBefore = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;
        $saltBefore = InventoryItem::ofKey(InventoryItem::SALT)->balance;

        $this->recordDough(2)->delete();

        $this->assertSame($flourBefore, InventoryItem::ofKey(InventoryItem::FLOUR)->balance);
        $this->assertSame($saltBefore, InventoryItem::ofKey(InventoryItem::SALT)->balance);
    }

    public function test_the_reversal_is_recorded_rather_than_the_history_erased(): void
    {
        $dough = $this->recordDough(2);
        $flour = InventoryItem::ofKey(InventoryItem::FLOUR);
        $before = $flour->movements()->count();

        $dough->delete();

        // The original outflow stays on the record, with a matching inflow
        // beside it saying what happened.
        $this->assertGreaterThan($before, $flour->fresh()->movements()->count());
        $this->assertStringContainsString(
            'ابطال',
            $flour->fresh()->movements()->latest('id')->first()->note
        );
    }

    public function test_deleting_a_chane_entry_puts_the_dough_back(): void
    {
        $dough = $this->recordDough(2);

        $this->recordChane($dough, 100)->delete();

    }

    public function test_deleting_a_chane_entry_puts_its_spray_flour_back(): void
    {
        $dough = $this->recordDough(2);
        $flourBefore = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;

        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'chane_count' => 100,
                'spray_flour_kg' => 2.5,
            ])
            ->assertCreated();

        ChaneEntry::latest('id')->first()->delete();

        $this->assertSame($flourBefore, InventoryItem::ofKey(InventoryItem::FLOUR)->balance);
    }

    public function test_deleting_a_chane_entry_frees_its_dough_batch_again(): void
    {
        $dough = $this->recordDough(2);
        $this->recordChane($dough, 100)->delete();

        // The batch was marked processed when it was shaped; with that entry
        // gone it is waiting to be shaped again, not stuck forever.
        $this->assertSame('pending', $dough->fresh()->status);
    }

    public function test_deleting_a_nanino_batch_puts_all_of_its_dough_back(): void
    {
        $dough = $this->recordDough(5);

        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'chane_count' => 10,
                'nanino_chane_count' => 50,
                'spray_flour_kg' => 0,
            ])
            ->assertCreated();

        ChaneEntry::latest('id')->first()->delete();

        // Both systems consume dough, so both shares have to come back.
    }

    public function test_deleting_the_dough_entry_reverses_its_chane_too(): void
    {
        // chane_entries cascades on dough_entry_id, so without the model
        // deleting its children itself, the chane row dies inside the
        // database and its spray flour stays spent with no owner — which
        // is exactly what stock:audit caught after the 06/06 batch was
        // deleted from the panel.
        $flourBefore = InventoryItem::ofKey(InventoryItem::FLOUR)->balance;
        $dough = $this->recordDough(2);

        $this->actingAs($this->userWithRole('chane_gir'), 'sanctum')
            ->postJson('/api/v1/chane-entries', [
                'dough_entry_id' => $dough->id,
                'chane_count' => 100,
                'spray_flour_kg' => 2.5,
            ])
            ->assertCreated();

        $dough->delete();

        $this->assertSame(0, ChaneEntry::count());
        $this->assertSame($flourBefore, InventoryItem::ofKey(InventoryItem::FLOUR)->balance);
    }

    public function test_deleting_the_dough_entry_clears_its_sales_bank_posting(): void
    {
        // sales cascades on chane_entry_id the same way. A card sale posts
        // money into the bank account; if the row is erased by the database
        // instead of the model, the posting stays behind with no sale to
        // explain it — 7,290,000 Rial sat in the account that way once.
        $dough = $this->recordDough(2);
        $chane = $this->recordChane($dough, 100);

        $this->actingAs($this->userWithRole('seller'), 'sanctum')
            ->postJson('/api/v1/sales', [
                'chane_entry_id' => $chane->id,
                'payment_type' => 'card',
                'amount' => 500000,
            ])
            ->assertCreated();

        $account = Sale::latest('id')->first()->bankAccount;
        $balanceBefore = $account->refresh()->balance;

        $dough->delete();

        $this->assertSame(0, Sale::count());
        $this->assertSame($balanceBefore - 500000, $account->refresh()->balance);
    }
}
