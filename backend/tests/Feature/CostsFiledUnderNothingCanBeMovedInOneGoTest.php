<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Half the shop's costs filed under «سایر», and no sane way back.
 *
 * The health check found 1,134,010,822 rial — 50٪ of three months of
 * spending — under a category that answers no question anybody asks of an
 * expense report. Rent, repairs and freight were indistinguishable, so
 * every cost figure the panel printed was a single undifferentiated lump.
 *
 * The category filter had always been there. What was missing was a way
 * to act on what it found: correcting a row meant opening it, changing
 * one field and saving, and there were hundreds. Work priced that high
 * does not get done, and the report stayed meaningless for as long as
 * that was true.
 *
 * The rows are moved one save at a time rather than by a mass update, and
 * that is the part worth a test: an expense rebuilds its bank posting on
 * save and writes an audit line, and `Expense::query()->update()` fires
 * neither. The books and the trail would have quietly stopped agreeing
 * with the rows, which is a worse failure than the one being fixed.
 */
class CostsFiledUnderNothingCanBeMovedInOneGoTest extends TestCase
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
        $this->actingAs($this->admin);
    }

    private function cost(string $title, string $category = 'other'): Expense
    {
        return Expense::create([
            'title' => $title,
            'category' => $category,
            'amount' => 250_000,
            'spent_on' => now()->toDateString(),
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_several_costs_are_refiled_in_one_go(): void
    {
        $rent = $this->cost('اجاره مرداد');
        $alsoRent = $this->cost('اجاره شهریور');
        $untouched = $this->cost('چیز دیگری');

        Livewire::test(ListExpenses::class)
            ->callTableBulkAction(
                'setCategory',
                [$rent->getKey(), $alsoRent->getKey()],
                ['category' => 'rent'],
            )
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame('rent', $rent->refresh()->category);
        $this->assertSame('rent', $alsoRent->refresh()->category);

        // The rows nobody ticked are left exactly as they were.
        $this->assertSame('other', $untouched->refresh()->category);
    }

    public function test_the_move_is_written_down_against_whoever_made_it(): void
    {
        $expense = $this->cost('تعمیر مشعل');

        Livewire::test(ListExpenses::class)
            ->callTableBulkAction(
                'setCategory',
                [$expense->getKey()],
                ['category' => 'maintenance'],
            );

        $logged = AuditLog::query()
            ->where('auditable_type', Expense::class)
            ->where('auditable_id', $expense->getKey())
            ->where('event', AuditLog::UPDATED)
            ->exists();

        $this->assertTrue(
            $logged,
            'جابه‌جایی دسته باید در سابقه بماند — وگرنه تغییرِ بی‌نام است.',
        );
    }

    public function test_a_retired_category_is_not_offered_as_a_destination(): void
    {
        // A row already filed under «خرید آرد» keeps reading in Persian
        // and keeps counting in the profit statement. But nothing is
        // moved *into* one: a purchase invoice is the way flour is
        // recorded now, and refiling a cost there would be a step back.
        $this->assertArrayNotHasKey('flour', Expense::CATEGORIES);
        $this->assertArrayHasKey('flour', Expense::categoryLabels());
    }

    public function test_the_tab_counts_what_is_still_unfiled(): void
    {
        $this->cost('یک');
        $this->cost('دو');
        $this->cost('سه', 'rent');

        $tabs = Livewire::test(ListExpenses::class)->instance()->getTabs();

        $this->assertSame(2, $tabs['other']->getBadge());
    }
}
