<?php

namespace Tests\Feature;

use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Bakery;
use App\Models\Expense;
use App\Models\SalaryPayment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who changed a figure, when, and what it was before.
 *
 * This shop has carried four ten-times errors and every one survived
 * because nothing recorded the previous value. «تاریخچهٔ مالی overwrite
 * نشود» was the first non-negotiable rule and had no table behind it.
 *
 * The tests that matter here are the ones about what the trail refuses:
 * an audit log that can be edited answers a different question from the
 * one it was built for.
 */
class AFigureThatChangesSaysWhoChangedItTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['currency' => 'rial']);
        Money::forgetCache();

        $this->owner = User::factory()->create(['name' => 'مدیر', 'is_active' => true]);
        $this->owner->assignRole('admin');

        $this->actingAs($this->owner);
    }

    private function anExpense(): Expense
    {
        return Expense::create([
            'title' => 'گازوئیل',
            'category' => 'fuel',
            'amount' => 1_000_000,
            'spent_on' => now(),
        ]);
    }

    private function logsFor($record)
    {
        return AuditLog::where('auditable_type', $record::class)
            ->where('auditable_id', $record->id)
            ->orderBy('id');
    }

    public function test_creating_a_cost_is_written_down_with_who_did_it(): void
    {
        $expense = $this->anExpense();

        $log = $this->logsFor($expense)->first();

        $this->assertNotNull($log);
        $this->assertSame(AuditLog::CREATED, $log->event);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('مدیر', $log->actor);
        $this->assertSame('گازوئیل', $log->subject);
        $this->assertNull($log->before);
        $this->assertEquals(1_000_000, $log->after['amount']);
    }

    public function test_the_previous_figure_is_kept(): void
    {
        $expense = $this->anExpense();

        $expense->update(['amount' => 10_000_000]);

        $log = $this->logsFor($expense)->where('event', AuditLog::UPDATED)->first();

        // The whole reason this table exists. Four ten-times errors went
        // unattributed because nobody could say what the number had been.
        // Numeric, not string: a decimal column reads back as «1000000.00»
        // and the point is the figure, not how MySQL spells it.
        $this->assertEquals(1_000_000, $log->before['amount']);
        $this->assertEquals(10_000_000, $log->after['amount']);
    }

    public function test_only_the_fields_that_moved_are_recorded(): void
    {
        $expense = $this->anExpense();

        $expense->update(['amount' => 2_000_000]);

        $log = $this->logsFor($expense)->where('event', AuditLog::UPDATED)->first();

        // The disputed number is always one number. A whole-row snapshot
        // buries it under thirty columns that did not move.
        $this->assertSame(['amount'], array_keys($log->after));
        $this->assertCount(1, $log->changes());
    }

    public function test_a_save_that_changes_nothing_writes_nothing(): void
    {
        $expense = $this->anExpense();

        $before = AuditLog::count();

        $expense->update(['amount' => 1_000_000]);
        $expense->touch();

        // Rows saying «تغییر کرد» that changed nothing are worse than
        // silence: they are what makes a trail stop being read.
        $this->assertSame($before, AuditLog::count());
    }

    public function test_timestamps_are_never_the_change(): void
    {
        $expense = $this->anExpense();

        $expense->update(['amount' => 3_000_000]);

        $log = $this->logsFor($expense)->where('event', AuditLog::UPDATED)->first();

        $this->assertArrayNotHasKey('updated_at', $log->after);
        $this->assertArrayNotHasKey('created_at', $log->after);
    }

    public function test_a_deleted_record_leaves_what_it_was(): void
    {
        $expense = $this->anExpense();
        $id = $expense->id;

        $expense->delete();

        $log = AuditLog::where('auditable_type', Expense::class)
            ->where('auditable_id', $id)
            ->where('event', AuditLog::DELETED)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(1_000_000, $log->before['amount']);
        // The record is gone; the name it went by is not.
        $this->assertSame('گازوئیل', $log->subject);
    }

    public function test_the_trail_cannot_be_rewritten(): void
    {
        $log = $this->logsFor($this->anExpense())->first();

        $log->event = AuditLog::CREATED;
        $log->before = ['amount' => 1];

        // Enforced, not documented. An audit trail is worth exactly what
        // it costs to alter.
        $this->assertFalse($log->save());
        $this->assertNull($log->fresh()->before);
    }

    public function test_the_trail_cannot_be_deleted(): void
    {
        $log = $this->logsFor($this->anExpense())->first();

        $this->assertFalse($log->delete());
        $this->assertNotNull($log->fresh());
    }

    public function test_a_change_with_nobody_signed_in_says_so_rather_than_guessing(): void
    {
        auth()->logout();

        $log = $this->logsFor($this->anExpense())->first();

        // A migration, the scheduler and an artisan command all change
        // figures with no user attached. Naming the first user in the
        // table would be a lie the trail then repeats for ever.
        $this->assertNull($log->user_id);
        $this->assertSame('سامانه', $log->actor);
    }

    public function test_the_name_survives_the_user_being_removed(): void
    {
        $expense = $this->anExpense();

        $this->owner->delete();

        $log = $this->logsFor($expense)->first()->fresh();

        // The foreign key goes null, and the trail still says who.
        $this->assertNull($log->user_id);
        $this->assertSame('مدیر', $log->actor);
    }

    public function test_a_payslip_is_named_after_the_person_it_paid(): void
    {
        $baker = User::factory()->create(['name' => 'عبدالله', 'monthly_salary' => 15_000_000]);

        $payslip = SalaryPayment::create([
            'user_id' => $baker->id,
            'period_start' => now()->startOfMonth(),
            'base_amount' => 15_000_000,
            'paid_on' => now(),
        ]);

        $log = $this->logsFor($payslip)->first();

        $this->assertStringContainsString('عبدالله', $log->subject);
    }

    public function test_an_advance_is_named_after_the_person_too(): void
    {
        $baker = User::factory()->create(['name' => 'محمد حنیف']);

        $advance = StaffAdvance::create([
            'user_id' => $baker->id,
            'amount' => 2_000_000,
            'paid_on' => now(),
        ]);

        $this->assertStringContainsString('محمد حنیف', $this->logsFor($advance)->first()->subject);
    }

    public function test_the_page_opens_and_shows_the_change(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->anExpense()->update(['amount' => 9_000_000]);

        // A trail nobody can open is the same failure as a report nobody
        // can open, and this panel has already shipped that twice.
        Livewire::test(ListAuditLogs::class)
            ->assertOk()
            ->assertSee('مدیر')
            ->assertSee('هزینه');
    }

    public function test_the_page_offers_no_way_to_change_anything(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $log = $this->logsFor($this->anExpense())->first();

        $this->assertFalse(AuditLogResource::canCreate());
        $this->assertFalse(AuditLogResource::canEdit($log));
        $this->assertFalse(AuditLogResource::canDelete($log));
    }

    public function test_a_figure_is_written_the_way_this_shop_writes_figures(): void
    {
        // «1000000.00» is a decimal point to a database and a thousands
        // separator to anyone raised on these ledgers. The trail is read
        // on the day a figure is disputed, which is the worst possible day
        // to be unsure where the point is.
        $this->assertSame('1،000،000', AuditLogResource::say('1000000.00', 'amount'));
        $this->assertSame('150،000،000', AuditLogResource::say(150000000, 'amount'));
    }

    public function test_a_real_fraction_is_not_rounded_away_behind_the_reader(): void
    {
        $this->assertSame('1،000،000.50', AuditLogResource::say('1000000.50', 'amount'));
    }

    public function test_an_identifier_is_not_dressed_up_as_an_amount(): void
    {
        // «1،234» for a user id would be a separator pretending to be money.
        $this->assertSame('1234', AuditLogResource::say(1234, 'user_id'));
    }

    public function test_nothing_reads_as_a_dash_rather_than_a_blank(): void
    {
        $this->assertSame('—', AuditLogResource::say(null, 'amount'));
    }
}
