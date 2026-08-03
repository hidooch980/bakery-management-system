<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BakeryShare;
use App\Models\BankAccount;
use App\Models\InventoryMovement;
use App\Models\ShareSettlement;
use App\Models\User;
use App\Support\IssueScanner;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards on the money side: a partner must not be paid twice for the same
 * stretch, and an account that has gone below zero must be surfaced rather
 * than left for someone to notice at the end of the month.
 */
class FinancialGuardsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function settle(BakeryShare $share, string $from, string $until, float $amount)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/shares/{$share->id}/settle?from={$from}&until={$until}", [
                'amount' => $amount,
            ]);
    }

    public function test_a_partner_cannot_be_settled_twice_for_the_same_period(): void
    {
        $share = BakeryShare::create(['name' => 'شریک', 'dang' => 3]);

        $this->settle($share, '1405/05/01', '1405/05/31', 1_000_000)->assertCreated();
        $this->settle($share, '1405/05/01', '1405/05/31', 1_000_000)->assertStatus(409);

        // The second attempt must not have paid them again.
        $this->assertSame(1, ShareSettlement::count());
    }

    public function test_an_overlapping_period_is_refused_too(): void
    {
        $share = BakeryShare::create(['name' => 'شریک', 'dang' => 3]);

        $this->settle($share, '1405/05/01', '1405/05/31', 1_000_000)->assertCreated();

        // Half of this range is already paid for, so it cannot go through.
        $this->settle($share, '1405/05/15', '1405/06/15', 1_000_000)->assertStatus(409);

        $this->assertSame(1, ShareSettlement::count());
    }

    public function test_the_next_period_settles_normally(): void
    {
        $share = BakeryShare::create(['name' => 'شریک', 'dang' => 3]);

        $this->settle($share, '1405/05/01', '1405/05/31', 1_000_000)->assertCreated();
        $this->settle($share, '1405/06/01', '1405/06/31', 1_000_000)->assertCreated();

        $this->assertSame(2, ShareSettlement::count());
    }

    public function test_another_partner_is_unaffected_by_the_first_ones_settlement(): void
    {
        $one = BakeryShare::create(['name' => 'شریک اول', 'dang' => 3]);
        $two = BakeryShare::create(['name' => 'شریک دوم', 'dang' => 3]);

        $this->settle($one, '1405/05/01', '1405/05/31', 1_000_000)->assertCreated();
        // Each partner is owed their own share of the same period.
        $this->settle($two, '1405/05/01', '1405/05/31', 1_000_000)->assertCreated();

        $this->assertSame(2, ShareSettlement::count());
    }

    public function test_a_negative_bank_balance_is_reported(): void
    {
        $account = BankAccount::create([
            'title' => 'صندوق فروشگاه',
            'opening_balance' => 0,
            'is_default' => true,
        ]);
        $account->record('out', 500_000);

        $issue = app(IssueScanner::class)->scan()
            ->firstWhere('key', "negative-bank-{$account->id}");

        $this->assertNotNull($issue);
        $this->assertStringContainsString('صندوق فروشگاه', $issue->title);
        // Inventing a deposit would hide the one that is actually missing.
        $this->assertFalse($issue->isAutoFixable());
    }

    public function test_an_account_in_credit_is_not_reported(): void
    {
        $account = BankAccount::create([
            'title' => 'صندوق فروشگاه',
            'opening_balance' => 1_000_000,
            'is_default' => true,
        ]);
        $account->record('out', 500_000);

        $this->assertNull(
            app(IssueScanner::class)->scan()->firstWhere('key', "negative-bank-{$account->id}")
        );
    }

    public function test_the_reversal_reasons_have_labels_in_the_ledger(): void
    {
        // A movement whose reason has no label shows a bare English key in
        // the panel, which reads as a glitch.
        foreach (['production_reversal', 'flour_sale_reversal'] as $reason) {
            $this->assertArrayHasKey(
                $reason,
                InventoryMovement::REASONS,
                "reason {$reason} needs a Persian label"
            );
        }
    }
}
