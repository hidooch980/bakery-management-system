<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\SettlementRequest;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پیدا کردنِ تسویه‌هایی که با کسری بسته شده‌اند — و دست نزدن به آن‌ها.
 *
 * قاعده امروز درست شد، ولی تسویه‌ای که قبلاً تأیید شده، تأییدشده می‌ماند.
 * این دستور آن‌ها را پیدا می‌کند و **هیچ‌چیز را عوض نمی‌کند**: باز کردنِ
 * حسابِ چندماه‌پیشِ یک فروشنده تصمیمی نیست که یک دستور بگیرد.
 *
 * سخت‌ترین قسمتش این است که چه چیزی را **گزارش نکند**. نانی که بدون پول
 * رفته — منزل، مدارس، خیرات — بدهی را واقعاً می‌بندد و پولی هم بابتش
 * نمی‌آید. اگر این‌ها هم کسری شمرده شوند، صفحه پر می‌شود از فروشنده‌هایی
 * که بدهکار نیستند، و مالک از خواندنش دست می‌کشد. آن‌وقت گزارشی که
 * ساختیم بدتر از نبودنش است.
 */
class ForgivenSettlementsAreFoundNotFixedTest extends TestCase
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

        $this->seller = User::factory()->create(['name' => 'فروشنده', 'is_active' => true]);
        $this->seller->assignRole('seller');
    }

    private function settlement(array $attributes): SettlementRequest
    {
        return SettlementRequest::create(array_merge([
            'user_id' => $this->seller->id,
            'amount' => 450_000,
            'paid_cash' => 450_000,
            'paid_card' => 0,
            'confirmed_at' => now(),
        ], $attributes));
    }

    public function test_a_settlement_that_was_paid_in_full_is_not_reported(): void
    {
        $this->settlement([]);

        $this->artisan('settlements:forgiven')
            ->expectsOutputToContain('هیچ تسویه‌ای با کسری بسته نشده')
            ->assertSuccessful();
    }

    public function test_a_settlement_closed_for_less_than_it_owed_is_reported(): void
    {
        $this->settlement(['paid_cash' => 400_000]);

        $this->artisan('settlements:forgiven')
            ->expectsOutputToContain('جمع اختلاف')
            ->assertSuccessful();
    }

    public function test_bread_that_cleared_the_debt_without_money_is_not_a_gap(): void
    {
        // The whole account cleared, but only 300,000 of it in notes —
        // the rest was bread the staff took home. Nobody is short.
        $this->settlement([
            'paid_cash' => 300_000,
            'paid_breakdown' => ['cash' => 300_000, 'home' => 150_000],
        ]);

        $this->artisan('settlements:forgiven')
            ->expectsOutputToContain('هیچ تسویه‌ای با کسری بسته نشده')
            ->assertSuccessful();
    }

    public function test_a_gap_beyond_the_bread_is_still_reported(): void
    {
        // 300,000 in notes and 100,000 of bread against a 450,000 account.
        // Fifty thousand is unaccounted for and that is what this is for.
        $this->settlement([
            'paid_cash' => 300_000,
            'paid_breakdown' => ['cash' => 300_000, 'home' => 100_000],
        ]);

        $this->artisan('settlements:forgiven')
            ->expectsOutputToContain('جمع اختلاف')
            ->assertSuccessful();
    }

    public function test_a_request_nobody_confirmed_is_not_a_settlement(): void
    {
        // Still waiting on the owner. Nothing has been closed, so there is
        // nothing to have been closed short.
        $this->settlement(['paid_cash' => 400_000, 'confirmed_at' => null]);

        $this->artisan('settlements:forgiven')
            ->expectsOutputToContain('هیچ تسویه‌ای با کسری بسته نشده')
            ->assertSuccessful();
    }

    public function test_it_changes_nothing(): void
    {
        $request = $this->settlement(['paid_cash' => 400_000]);
        $before = $request->fresh()->toArray();

        $this->artisan('settlements:forgiven')->assertSuccessful();

        // A report that quietly reopened somebody's account would be the
        // worst version of this: the owner would find a seller in debt
        // again with no memory of why.
        $this->assertSame($before, $request->fresh()->toArray());
    }
}
