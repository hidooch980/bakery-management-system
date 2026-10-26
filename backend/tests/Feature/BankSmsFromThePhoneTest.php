<?php

namespace Tests\Feature;

use App\Filament\Pages\BankSmsInbox;
use App\Models\BankAccount;
use App\Models\BankSms;
use App\Models\BankSmsAccount;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use App\Support\BankSmsParser;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «بساز، فقط پیامک‌های جدید»: the phone sends Sepah SMS, nothing is booked
 * until the owner says so, and nothing is ever booked twice.
 *
 * The three bodies are the shapes Bank Sepah sends, with made-up numbers.
 */
class BankSmsFromThePhoneTest extends TestCase
{
    use RefreshDatabase;

    // The user's three real Bank Sepah shapes, with a made-up account.
    public const WITHDRAWAL = "بانک سپه\nبرداشت:1,111,000\nحساب :\u{202A}0101234567890\u{202C}\nمانده:98,765,000\n8/4-07:00";

    public const POS_GROUP = "پرداخت گروهي\nحساب:0101234567890\nمبلغ:12,345,000\nمانده:111,110,000\nزمان:1405/8/4\nواريز گروهي فروش نذري نان با کارتخوان هوشمند بانک سپه";

    public const PAYA = "واريز:3,333,000ريال\nحساب:0101234567890\nمانده:114,443,000\n8/4-09:00\nانتقال وجه پايا";

    private User $owner;

    private BankAccount $sepah;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->owner->assignRole('admin');

        $this->sepah = BankAccount::create(['title' => 'سپه — حساب اصلی', 'opening_balance' => 0, 'is_active' => true]);
        BankSmsAccount::create(['last4' => '7890', 'bank_account_id' => $this->sepah->id]);
    }

    private function send(string $body, string $at = '2026-10-26 08:41:00', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->postJson('/api/v1/bank-sms', [
            'messages' => [['sender' => '9830003', 'body' => $body, 'received_at' => $at]],
        ]);
    }

    // ---------------------------------------------------------- parser

    public function test_the_users_three_sepah_messages_are_read_exactly(): void
    {
        $a = BankSmsParser::parse('x', "بانک سپه\nبرداشت:120,049,500\nحساب :\u{202A}****2405\u{202C}\nمانده:35,000,000\n8/4-15:44");
        $this->assertSame(['out', 12_004_950.0, 3_500_000.0, '2405', '1405/08/04 15:44', 'سپه'],
            [$a['direction'], $a['amount'], $a['balance'], $a['last4'], $a['time'], $a['bank']]);

        $b = BankSmsParser::parse('x', "پرداخت گروهي\nحساب\nمبلغ:1,550,000\nمانده\nزمان:1405/8/4\nواريز گروهي فروش نذري نان با کارتخوان هوشمند بانک سپه");
        $this->assertSame(['in', 'pos_group', 155_000.0, null, '1405/08/04', 'card_sale'],
            [$b['direction'], $b['kind'], $b['amount'], $b['balance'], $b['time'], $b['suggested_category']]);

        // No «بانک سپه» line at all, and the labels left bare.
        $c = BankSmsParser::parse('x', "واريز:74,750,000ريال\nحساب\nمانده\n8/4-4:51\nانتقال وجه پايا");
        $this->assertSame(['in', 'paya', 7_475_000.0, null, null, '1405/08/04 04:51', 'سپه'],
            [$c['direction'], $c['kind'], $c['amount'], $c['balance'], $c['last4'], $c['time'], $c['bank']]);
    }

    public function test_a_message_naming_no_account_goes_to_the_only_tied_one(): void
    {
        $id = $this->send("واريز:74,750,000ريال\nحساب\nمانده\n8/4-4:51\nانتقال وجه پايا", '2026-10-26 04:51:00')
            ->json('data.results.0.id');

        $sms = BankSms::find($id);
        $this->assertSame($this->sepah->id, $sms->bank_account_id);
        $this->assertSame('سپه', $sms->bank);
        $this->assertNull($this->sepah->refresh()->sms_balance);
    }

    public function test_a_card_reader_deposit_is_booked_on_the_day_before_unless_changed(): void
    {
        $pos = $this->send(self::POS_GROUP, '2026-10-26 08:41:00')->json('data.results.0.id');
        $paya = $this->send(self::PAYA, '2026-10-26 09:00:00')->json('data.results.0.id');

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/bank-sms')
            ->assertJsonFragment(['id' => $pos, 'default_date' => '2026-10-25']);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/bank-sms/{$pos}/confirm", ['category' => 'card_sale'])->assertOk();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/bank-sms/{$paya}/confirm", ['category' => 'other'])->assertOk();

        $this->assertSame('2026-10-25', Income::where('category', 'card_sale')->sole()->received_on->toDateString());
        $this->assertSame('2026-10-26', Income::where('category', 'other')->sole()->received_on->toDateString());
        $this->assertSame('2026-10-25', BankTransaction::find(BankSms::find($pos)->bank_transaction_id)->occurred_on->toDateString());

        // The balance still follows the SMS's own time.
        $this->assertSame('2026-10-26 09:00:00', $this->sepah->refresh()->sms_balance_at->format('Y-m-d H:i:s'));
    }

    public function test_the_owner_can_book_a_card_reader_deposit_on_another_day(): void
    {
        $pos = $this->send(self::POS_GROUP, '2026-10-26 08:41:00')->json('data.results.0.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bank-sms/{$pos}/confirm", ['category' => 'card_sale', 'date' => '1405/08/02'])->assertOk();

        $this->assertSame('2026-10-24', Income::sole()->received_on->toDateString());
    }

    public function test_a_sepah_withdrawal_is_read(): void
    {
        $p = BankSmsParser::parse('9830003', self::WITHDRAWAL);

        $this->assertSame('out', $p['direction']);
        $this->assertSame('withdrawal', $p['kind']);
        $this->assertEquals(111_100, $p['amount']);
        $this->assertEquals(9_876_500, $p['balance']);
        $this->assertSame('7890', $p['last4']);
        $this->assertSame('سپه', $p['bank']);
        $this->assertSame('other', $p['suggested_category']);
    }

    public function test_a_sepah_card_reader_group_deposit_suggests_bread_sales(): void
    {
        $p = BankSmsParser::parse('9830003', self::POS_GROUP);

        $this->assertSame('in', $p['direction']);
        $this->assertSame('pos_group', $p['kind']);
        $this->assertEquals(1_234_500, $p['amount']);
        $this->assertSame('7890', $p['last4']);
        $this->assertSame('card_sale', $p['suggested_category']);
    }

    public function test_a_sepah_paya_deposit_is_read(): void
    {
        $p = BankSmsParser::parse('9830003', self::PAYA);

        $this->assertSame('in', $p['direction']);
        $this->assertSame('paya', $p['kind']);
        $this->assertEquals(333_300, $p['amount']);
        $this->assertSame('other', $p['suggested_category']);
    }

    public function test_persian_digits_and_codes_are_handled(): void
    {
        $p = BankSmsParser::parse('x', 'بانک سپه برداشت از حساب ۰۱۰۱۲۳۴۵۶۷۸۹۰ مبلغ:۵۰۰,۰۰۰');
        $this->assertEquals(50_000, $p['amount']);
        $this->assertSame('7890', $p['last4']);

        $this->assertNull(BankSmsParser::parse('x', 'رمز پویا شما 123456 است'));
        $this->assertNull(BankSmsParser::parse('x', 'سلام، فردا جلسه داریم'));
    }

    public function test_the_full_account_number_never_leaves_the_body(): void
    {
        $masked = BankSmsParser::mask(self::POS_GROUP);

        $this->assertStringNotContainsString('0101234567890', $masked);
        $this->assertStringContainsString('****7890', $masked);
        $this->assertStringContainsString('12,345,000', $masked);
    }

    // -------------------------------------------------------- endpoint

    public function test_only_the_owner_may_send_or_read(): void
    {
        $this->postJson('/api/v1/bank-sms', ['messages' => []])->assertUnauthorized();

        $accountant = User::factory()->create(['is_active' => true]);
        $accountant->givePermissionTo('manage-finance');
        $this->send(self::PAYA, as: $accountant)->assertForbidden();
        $this->actingAs($accountant, 'sanctum')->getJson('/api/v1/bank-sms')->assertForbidden();

        $this->assertSame(0, BankSms::count());
    }

    public function test_the_same_sms_twice_is_kept_once_and_nothing_is_booked_on_arrival(): void
    {
        $this->send(self::POS_GROUP)->assertOk()->assertJsonPath('data.results.0.outcome', 'created');
        $this->send(self::POS_GROUP)->assertOk()->assertJsonPath('data.results.0.outcome', 'duplicate');

        $this->assertSame(1, BankSms::count());
        $this->assertSame(0, Income::count());
        $this->assertSame(0, BankTransaction::count());

        $sms = BankSms::first();
        $this->assertSame('pending', $sms->status);
        $this->assertSame($this->sepah->id, $sms->bank_account_id);
    }

    public function test_the_inbox_masks_the_account_and_counts_what_is_open(): void
    {
        $this->send(self::POS_GROUP);
        $this->send(self::WITHDRAWAL, '2026-10-26 07:00:00');

        $res = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/bank-sms')->assertOk();

        $res->assertJsonPath('data.open', 2);
        $this->assertStringNotContainsString('0101234567890', $res->getContent());
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/bank-sms/count')->assertJsonPath('data.open', 2);
    }

    // ---------------------------------------------------------- confirm

    public function test_confirming_a_deposit_books_it_like_a_manual_income(): void
    {
        $id = $this->send(self::POS_GROUP)->json('data.results.0.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bank-sms/{$id}/confirm", ['category' => 'card_sale'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');

        $income = Income::sole();
        $this->assertSame('card_sale', $income->category);
        $this->assertEquals(1_234_500, $income->amount);
        $this->assertSame($this->sepah->id, $income->bank_account_id);

        $tx = BankTransaction::sole();
        $this->assertSame('in', $tx->direction);
        $this->assertSame($tx->id, BankSms::find($id)->bank_transaction_id);

        // Asked again, it refuses: it is no longer open.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bank-sms/{$id}/confirm", ['category' => 'card_sale'])
            ->assertStatus(422);
        $this->assertSame(1, Income::count());
    }

    public function test_confirming_a_withdrawal_books_an_expense_and_ignore_books_nothing(): void
    {
        $out = $this->send(self::WITHDRAWAL, '2026-10-26 07:00:00')->json('data.results.0.id');
        $paya = $this->send(self::PAYA, '2026-10-26 09:00:00')->json('data.results.0.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bank-sms/{$out}/confirm", ['category' => 'freight', 'note' => 'حمل نمک'])
            ->assertOk();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/bank-sms/{$paya}/ignore")->assertOk();

        $this->assertSame('freight', Expense::sole()->category);
        $this->assertSame(0, Income::count());
        $this->assertSame('ignored', BankSms::find($paya)->status);
        $this->assertSame(1, BankTransaction::count());
    }

    public function test_an_unmapped_account_must_be_named_before_booking(): void
    {
        BankSmsAccount::query()->delete();
        $id = $this->send(self::PAYA)->json('data.results.0.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bank-sms/{$id}/confirm", ['category' => 'other'])
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bank-sms/{$id}/confirm", ['category' => 'other', 'bank_account_id' => $this->sepah->id])
            ->assertOk();
    }

    // --------------------------------------------------- never twice

    public function test_a_salary_already_paid_arrives_already_matched(): void
    {
        $this->sepah->record('out', 111_100, 'salary', $this->owner->id, null, 'حقوق', '2026-10-26');

        $this->send(self::WITHDRAWAL, '2026-10-26 07:00:00')->assertJsonPath('data.results.0.status', 'matched');
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/bank-sms/count')->assertJsonPath('data.open', 0);
    }

    public function test_picking_one_of_two_lookalikes_links_it(): void
    {
        $a = $this->sepah->record('out', 111_100, 'expense', null, null, 'الف', '2026-10-26');
        $this->sepah->record('out', 111_100, 'advance', null, null, 'ب', '2026-10-26');

        $id = $this->send(self::WITHDRAWAL, '2026-10-26 07:00:00')->json('data.results.0.id');
        $this->assertSame('ambiguous', BankSms::find($id)->status);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/bank-sms/{$id}/link", ['bank_transaction_id' => $a->id])
            ->assertOk()->assertJsonPath('data.status', 'matched');

        $this->assertSame($a->id, BankSms::find($id)->bank_transaction_id);
    }

    public function test_an_income_typed_by_hand_later_takes_its_sms_out_of_the_inbox(): void
    {
        $id = $this->send(self::PAYA, '2026-10-26 09:00:00')->json('data.results.0.id');

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/incomes', [
            'category' => 'other', 'title' => 'واریز مشتری', 'amount' => 333_300,
            'bank_account_id' => $this->sepah->id, 'received_on' => '2026-10-26',
        ])->assertCreated();

        $sms = BankSms::find($id);
        $this->assertSame('matched', $sms->status);
        $this->assertSame(BankTransaction::sole()->id, $sms->bank_transaction_id);
    }

    public function test_editing_a_confirmed_record_keeps_its_sms_and_deleting_it_reopens_the_sms(): void
    {
        $id = $this->send(self::POS_GROUP)->json('data.results.0.id');
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/bank-sms/{$id}/confirm", ['category' => 'card_sale']);

        $income = Income::sole();
        $income->update(['note' => 'ویرایش']);
        $this->assertSame(BankTransaction::sole()->id, BankSms::find($id)->bank_transaction_id);

        $income->delete();
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/bank-sms')->assertJsonPath('data.open', 1);
        $this->assertSame('pending', BankSms::find($id)->status);
    }

    public function test_mapping_digits_later_fills_in_the_account(): void
    {
        BankSmsAccount::query()->delete();
        $id = $this->send(self::PAYA)->json('data.results.0.id');
        $this->assertNull(BankSms::find($id)->bank_account_id);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/bank-sms/accounts', ['last4' => '7890', 'bank_account_id' => $this->sepah->id])
            ->assertOk();

        $this->assertSame($this->sepah->id, BankSms::find($id)->bank_account_id);
    }

    // --------------------------------------------------------- balance

    public function test_the_first_sms_sets_the_balance_and_an_older_one_never_overwrites_it(): void
    {
        $this->send(self::WITHDRAWAL, '2026-10-26 07:00:00');
        $this->sepah->refresh();
        $this->assertEquals(9_876_500, $this->sepah->sms_balance);

        $this->send(self::PAYA, '2026-10-26 09:00:00');
        $this->assertEquals(11_444_300, $this->sepah->refresh()->sms_balance);

        // Delivered late: older than what the account already shows.
        $this->send(self::POS_GROUP, '2026-10-26 08:41:00');
        $this->assertEquals(11_444_300, $this->sepah->refresh()->sms_balance);

        // Nothing was booked by any of it.
        $this->assertSame(0, Income::count() + Expense::count() + BankTransaction::count());

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/bank-sms/count')
            ->assertJsonPath('data.balances.0.balance', 11_444_300);
    }

    public function test_tying_digits_later_sets_the_balance_from_the_latest_sms(): void
    {
        BankSmsAccount::query()->delete();
        $this->send(self::PAYA, '2026-10-26 09:00:00');
        $this->assertNull($this->sepah->refresh()->sms_balance);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/bank-sms/accounts', ['last4' => '7890', 'bank_account_id' => $this->sepah->id]);

        $this->assertEquals(11_444_300, $this->sepah->refresh()->sms_balance);
    }

    // ----------------------------------------------------------- panel

    public function test_the_panel_page_books_and_ignores_through_the_same_code(): void
    {
        $in = $this->send(self::POS_GROUP)->json('data.results.0.id');
        $out = $this->send(self::WITHDRAWAL, '2026-10-26 07:00:00')->json('data.results.0.id');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->owner);

        Livewire::test(BankSmsInbox::class)
            ->assertSee('****7890')
            ->assertDontSee('0101234567890')
            ->call('confirm', $in)
            ->call('ignore', $out);

        $this->assertSame('card_sale', Income::sole()->category);
        $this->assertSame('ignored', BankSms::find($out)->status);
        $this->assertSame(BankTransaction::sole()->id, BankSms::find($in)->bank_transaction_id);
    }

    public function test_the_panel_page_is_for_the_admin_only(): void
    {
        $accountant = User::factory()->create(['is_active' => true]);
        $accountant->givePermissionTo('manage-finance');

        $this->assertFalse($this->actingAs($accountant) && BankSmsInbox::canAccess());
    }
}
