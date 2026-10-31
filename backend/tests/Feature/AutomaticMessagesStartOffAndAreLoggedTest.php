<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankSms;
use App\Models\ChaneEntry;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\DoughEntry;
use App\Models\InventoryItem;
use App\Models\NotificationSetting;
use App\Models\Sale;
use App\Models\SmsLog;
use App\Models\User;
use App\Support\ManagerAlert;
use App\Support\Money;
use App\Support\ShopChecks;
use App\Support\Sms;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Every automatic message has its own switch and starts off; what is sent,
 * or not, is logged with the number masked. No real SMS leaves a test.
 */
class AutomaticMessagesStartOffAndAreLoggedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private User $baker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first()->update(['currency' => 'toman', 'bread_price' => 5000]);
        Money::forgetCache();
        Sms::fake();
        ManagerAlert::fake();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->seller = User::factory()->create(['is_active' => true, 'name' => 'مریم']);
        $this->seller->assignRole('seller');
        $this->baker = User::factory()->create(['is_active' => true, 'name' => 'علی', 'phone' => '09121234567']);
        $this->baker->assignRole('shater');
    }

    protected function tearDown(): void
    {
        Sms::stopFaking();
        parent::tearDown();
    }

    private function sale(string $type, float $amount, array $extra = []): Sale
    {
        $dough = DoughEntry::create(['user_id' => $this->seller->id, 'bag_count' => 1]);
        $chane = ChaneEntry::create([
            'dough_entry_id' => $dough->id, 'user_id' => $this->seller->id, 'chane_count' => 100,
            'normal_weight_kg' => 85, 'nanino_weight_kg' => 0, 'spray_flour_kg' => 0, 'status' => 'sold',
        ]);

        return Sale::create(array_merge([
            'chane_entry_id' => $chane->id, 'user_id' => $this->seller->id, 'bread_count' => 100,
            'payment_type' => $type, 'amount' => $amount, 'amount_difference' => 0,
        ], $extra));
    }

    private function penalty(): int
    {
        $id = $this->actingAs($this->seller, 'sanctum')->postJson('/api/v1/penalty-requests', [
            'user_id' => $this->baker->id, 'type' => 'fine', 'reason' => 'late', 'amount' => 500_000,
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/penalty-requests/{$id}/approve")->assertOk();

        return $id;
    }

    public function test_every_switch_starts_off(): void
    {
        $s = NotificationSetting::current();

        $this->assertFalse($s->sms_partner_weekly);
        $this->assertFalse($s->sms_staff_penalty);
        $this->assertNull($s->seller_debt_threshold);
        $this->assertFalse($s->credit_reminder_enabled);
        $this->assertFalse($s->card_reader_check_enabled);
    }

    public function test_a_penalty_sends_nothing_while_the_switch_is_off_but_is_logged(): void
    {
        $this->penalty();

        $this->assertSame([], Sms::sent());
        $log = SmsLog::sole();
        $this->assertSame('skipped', $log->status);
        $this->assertSame('0912***4567', $log->phone_masked);
    }

    public function test_a_penalty_and_its_cancellation_each_send_one_sms_when_on(): void
    {
        NotificationSetting::current()->update(['sms_staff_penalty' => true]);

        $id = $this->penalty();
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/penalty-requests/{$id}/cancel")->assertOk();

        $sent = Sms::sent();
        $this->assertCount(2, $sent);
        $this->assertSame('09121234567', $sent[0]['phone']);
        $this->assertStringContainsString('جریمهٔ', $sent[0]['message']);
        $this->assertStringContainsString('لغو شد', $sent[1]['message']);
        $this->assertSame(['sent', 'sent'], SmsLog::orderBy('id')->pluck('status')->all());
        $this->assertStringNotContainsString('1234567', SmsLog::first()->phone_masked);
    }

    public function test_the_partner_weekly_summary_is_in_bags_and_only_when_on(): void
    {
        $this->actingAs($this->admin);
        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 8000, 'purchase');
        $partner = Customer::create(['name' => 'نانوایی کنت', 'type' => Customer::PARTNER_TYPE, 'phone' => '09351112233', 'is_active' => true]);
        ConsignmentFlour::create(['customer_id' => $partner->id, 'direction' => 'lent', 'bags' => 5, 'occurred_on' => now()->subDays(2)->toDateString()]);

        $this->artisan('partners:weekly-sms')->assertSuccessful();
        $this->assertSame([], Sms::sent());

        NotificationSetting::current()->update(['sms_partner_weekly' => true]);
        $this->artisan('partners:weekly-sms')->assertSuccessful();

        $this->assertCount(1, Sms::sent());
        $this->assertStringContainsString('کیسه', Sms::sent()[0]['message']);
        $this->assertSame('partner_weekly', SmsLog::sole()->kind);
    }

    public function test_the_manager_hears_when_a_seller_owes_more_than_the_threshold(): void
    {
        $this->sale('cash', 600_000);

        $this->artisan('sellers:debt-alert')->assertSuccessful();
        $this->assertSame([], ManagerAlert::sent(), 'no threshold, no alert');

        NotificationSetting::current()->update(['seller_debt_threshold' => 500_000]);
        $this->artisan('sellers:debt-alert')->assertSuccessful();

        $this->assertCount(1, ManagerAlert::sent());
        $this->assertStringContainsString('مریم', ManagerAlert::sent()[0]);

        // And the seller sees it in the app.
        $this->actingAs($this->seller, 'sanctum')->getJson('/api/v1/sales/my-account')
            ->assertOk()->assertJsonPath('data.balance', 600000);
        $this->assertNotNull(
            $this->actingAs($this->seller, 'sanctum')->getJson('/api/v1/sales/my-account')->json('data.debt_alert')
        );
    }

    public function test_old_credit_and_a_customer_over_the_limit_are_reminded(): void
    {
        $school = Customer::create(['name' => 'دبستان شهید', 'type' => 'school', 'credit_limit' => 1_000_000]);
        $this->travelTo(now()->subDays(40));
        $this->sale('schools', 300_000, ['customer_id' => $school->id]);
        $this->travelBack();

        NotificationSetting::current()->update(['credit_reminder_enabled' => true, 'credit_reminder_days' => 30]);
        $this->artisan('customers:credit-reminder')->assertSuccessful();

        $this->assertCount(1, ManagerAlert::sent());
        $this->assertStringContainsString('دبستان شهید', ManagerAlert::sent()[0]);
        $this->assertNull(ShopChecks::creditLimitWarning($school));

        $this->sale('schools', 800_000, ['customer_id' => $school->id]);
        $this->assertNotNull(ShopChecks::creditLimitWarning($school->fresh()));
    }

    public function test_card_sales_are_compared_with_the_next_days_deposit(): void
    {
        $day = Carbon::parse('2026-10-20 12:00');
        $this->travelTo($day);
        $this->sale('card', 1_000_000);
        $this->travelBack();

        $at = '2026-10-21 08:00:00';
        BankSms::create([
            'sender' => '300017', 'body' => 'pos', 'received_at' => $at, 'hash' => BankSms::hashOf('300017', 'pos', $at),
            'direction' => 'in', 'kind' => 'pos_group', 'amount' => 950_000,
        ]);

        $r = ShopChecks::cardReader($day);
        $this->assertEquals(-50_000.0, $r['difference']);

        NotificationSetting::current()->update(['card_reader_check_enabled' => true, 'card_reader_tolerance' => 10_000]);
        $this->artisan('bank:card-reader-check', ['--date' => '2026-10-20'])->assertSuccessful();
        $this->assertCount(1, ManagerAlert::sent());
        $this->assertStringContainsString('مغایرت کارتخوان', ManagerAlert::sent()[0]);

        NotificationSetting::current()->update(['card_reader_tolerance' => 60_000]);
        $this->artisan('bank:card-reader-check', ['--date' => '2026-10-20'])->assertSuccessful();
        $this->assertCount(1, ManagerAlert::sent(), 'within tolerance stays quiet');
    }

    public function test_the_daily_profit_is_sales_less_flour_wages_and_expenses(): void
    {
        $this->seller->update(['monthly_salary' => 3_000_000]);
        $this->sale('cash', 400_000);

        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/reports/daily-profit')->assertOk()->json('data');

        $this->assertCount(7, $data['days']);
        $today = last($data['days']);
        $this->assertEquals(400_000, $today['income']);
        $this->assertEquals(100_000, $today['wages']);
        $this->assertEquals(300_000, $today['profit']);
    }

    public function test_the_seller_reads_their_own_ledger(): void
    {
        $this->sale('cash', 200_000);

        $this->actingAs($this->seller, 'sanctum')->getJson('/api/v1/sales/my-account/ledger')
            ->assertOk()
            ->assertJsonPath('data.reconciled', true)
            ->assertJsonCount(1, 'data.entries');

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/seller-accounts/{$this->seller->id}/ledger")
            ->assertOk()->assertJsonPath('data.balance', 200000);
    }

    public function test_the_partner_report_preview_masks_the_number(): void
    {
        $this->actingAs($this->admin);
        $partner = Customer::create(['name' => 'نانوایی کنت', 'type' => Customer::PARTNER_TYPE, 'phone' => '09351112233', 'is_active' => true]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/consignment-flour/partners/{$partner->id}/report")
            ->assertOk()
            ->assertJsonPath('data.phone_masked', '0935***2233')
            ->assertJsonPath('data.has_phone', true);
    }

    public function test_the_settings_page_and_the_ledger_page_open(): void
    {
        $this->actingAs($this->admin);
        $this->get('/admin/manage-notifications')->assertOk()->assertSee('پیامک‌ها و هشدارها');
        $this->get('/admin/seller-ledger?seller='.$this->seller->id)->assertOk()->assertSee('دفتر حساب');
    }
}
