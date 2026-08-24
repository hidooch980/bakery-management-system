<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bakery;
use App\Models\Expense;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the audit trail costs per financial write.
 *
 * `RecordsAudit` went onto nine models that move money, and each of them
 * now writes a second row on every save. This project's own rule is to
 * measure before and after any change on a path the shop uses all day —
 * the issue-centre badge once added 320 queries and 390ms to every panel
 * page — and that rule was not followed when the trail went in.
 *
 * So: an assertion rather than a benchmark. A benchmark tells you a
 * number on the day you run it; an assertion tells whoever adds the tenth
 * model that they have doubled the cost.
 */
class TheAuditTrailCostsOneWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first();
    }

    private function anExpense(): array
    {
        return [
            'title' => 'گازوئیل',
            'category' => 'fuel',
            'amount' => 1_000_000,
            'spent_on' => now(),
        ];
    }

    public function test_recording_an_expense_costs_a_handful_of_queries(): void
    {
        // Warm whatever caches on first touch, so the count below is the
        // steady state and not the first-ever save.
        Expense::create($this->anExpense());

        DB::flushQueryLog();
        DB::enableQueryLog();

        Expense::create($this->anExpense());

        $queries = count(DB::getQueryLog());

        // Generous on purpose: the point is to catch a tenfold change, not
        // to pin an exact number that a harmless refactor would break.
        $this->assertLessThan(
            15,
            $queries,
            "recording one expense took {$queries} queries",
        );
    }

    public function test_the_trail_adds_one_row_and_not_a_row_per_field(): void
    {
        $expense = Expense::create($this->anExpense());

        DB::flushQueryLog();
        DB::enableQueryLog();

        $expense->update(['amount' => 2_000_000, 'title' => 'گازوئیل تانکر']);

        // Two fields changed. A trail that wrote one row per field would
        // grow with the width of the table rather than with the number of
        // edits, and nobody would notice until a wide model was added.
        $this->assertSame(
            1,
            AuditLog::where('auditable_id', $expense->id)
                ->where('event', 'updated')
                ->count(),
        );
    }

    public function test_a_save_that_changes_nothing_writes_no_trail(): void
    {
        $expense = Expense::create($this->anExpense());

        $before = AuditLog::count();

        $expense->save();
        $expense->update(['amount' => $expense->amount]);

        // Filament saves a form whether or not anything moved. A trail
        // that recorded those would bury the real edits in noise within a
        // week of the shop using the panel normally.
        $this->assertSame($before, AuditLog::count());
    }
}
