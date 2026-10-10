<?php

namespace Tests\Feature;

use App\Filament\Pages\PartnerReport;
use App\Filament\Resources\ConsignmentFlourResource;
use App\Filament\Resources\PartnerResource;
use App\Filament\Resources\PartnerResource\Pages\PartnerStatementPage;
use App\Filament\Widgets\PartnerFlourSummary;
use App\Models\Bakery;
use App\Models\ConsignmentFlour;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\PartnerLedger;
use App\Support\PartnerStatement;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * پروندهٔ هر همکار: گردش ریز به کیسه با ماندهٔ بعد از هر ردیف.
 *
 * داده‌ها همان ردیف‌های واقعیِ محمداکبر قریشیان – کنت است که صاحب مغازه
 * در طرح دید و تأیید کرد: در پایان «طلب ما: ۱۰ کیسه».
 */
class EachPartnerHasAFileInSacksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        Bakery::first()->update(['flour_bag_weight_kg' => 40]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        InventoryItem::ofKey(InventoryItem::FLOUR)->move('in', 8000, 'purchase');
    }

    private function partner(string $name = 'محمداکبر قریشیان نانوایی کنت'): Customer
    {
        return Customer::create(['name' => $name, 'type' => Customer::PARTNER_TYPE, 'is_active' => true]);
    }

    private function record(Customer $p, string $direction, float $bags, string $on, ?string $settled = null): ConsignmentFlour
    {
        $r = ConsignmentFlour::create([
            'customer_id' => $p->id,
            'direction' => $direction,
            'bags' => $bags,
            'occurred_on' => $on,
        ]);

        if ($settled) {
            $r->update(['settled_on' => $settled]);
        }

        return $r;
    }

    /** ردیف‌های واقعی کنت. */
    private function kent(): Customer
    {
        $p = $this->partner();
        $this->record($p, 'borrowed', 12, '2026-08-07', '2026-10-08');
        $this->record($p, 'lent', 20, '2026-08-25', '2026-10-08');
        $this->record($p, 'lent', 10, '2026-09-27', '2026-10-08');
        $this->record($p, 'lent', 10, '2026-10-08');

        return $p;
    }

    private function stock(): float
    {
        return InventoryItem::ofKey(InventoryItem::FLOUR)->fresh()->balance;
    }

    public function test_گردش_کنت_همان_طرح_تأییدشده_است(): void
    {
        $s = PartnerStatement::for($this->kent());

        $this->assertSame(
            ['گرفتیم', 'دادیم', 'دادیم', 'پس دادیم', 'پس گرفتیم', 'پس گرفتیم', 'دادیم'],
            $s->rows->pluck('label')->all()
        );
        $this->assertSame([-12.0, 8.0, 18.0, 30.0, 20.0, 0.0, 10.0], $s->rows->pluck('balance')->map(fn ($b) => (float) $b)->all());
        $this->assertSame(10.0, $s->current);
        $this->assertSame('طلب ما: 10 کیسه', PartnerStatement::headline($s->current)['label']);
        $this->assertSame(40.0, $s->total(PartnerStatement::LENT));
        $this->assertSame(12.0, $s->total(PartnerStatement::BORROWED));
        $this->assertSame(42.0, $s->totalReturns());
    }

    public function test_در_یک_روز_اول_برگشت‌ها_می‌آیند_و_مانده_بی‌دلیل_منفی_نمی‌شود(): void
    {
        $s = PartnerStatement::for($this->kent());
        $sameDay = $s->rows->filter(fn ($r) => $r['date']->toDateString() === '2026-10-08')->values();

        $this->assertTrue($sameDay[0]['is_return']);
        $this->assertFalse($sameDay->last()['is_return']);
        $this->assertTrue($sameDay->every(fn ($r) => $r['balance'] >= 0));
    }

    public function test_ردیف_تسویه‌شدهٔ_قدیمی_برگشت_کامل_خوانده_می‌شود(): void
    {
        $s = PartnerStatement::for($this->kent());
        $return = $s->rows->firstWhere('label', 'پس دادیم');

        $this->assertStringStartsWith('برگشت کامل «گرفتیم 12 کیسه» مورخ ', $return['note']);
    }

    public function test_فیلتر_تاریخ_ماندهٔ_اول_دوره_را_می‌آورد(): void
    {
        $s = PartnerStatement::for($this->kent(), Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertSame(8.0, $s->opening);
        $this->assertCount(1, $s->rows);
        $this->assertSame(18.0, (float) $s->rows[0]['balance']);
        $this->assertSame(18.0, $s->closing);
        $this->assertSame(10.0, $s->current);
    }

    public function test_بدهی_و_تسویه_سرخط_درست_دارند(): void
    {
        $this->assertSame(['label' => 'بدهی ما: 3 کیسه', 'tone' => 'owes'], PartnerStatement::headline(-3));
        $this->assertSame(['label' => 'تسویه', 'tone' => 'settled'], PartnerStatement::headline(0));
        $this->assertSame('46.5', PartnerStatement::bags(46.5));
    }

    public function test_برگشت_بخشی_انبار_را_به_همان_اندازه_جابه‌جا_می‌کند(): void
    {
        $p = $this->partner('نانوایی کرشان');
        $r = $this->record($p, 'lent', 10, '2026-10-08');
        $this->assertSame(7600.0, $this->stock());

        $r->recordReturn(4, '2026-10-09', 'چهار کیسه آورد');
        $r->refresh();

        $this->assertNull($r->settled_on);
        $this->assertSame(6.0, $r->outstandingBags());
        $this->assertSame(7760.0, $this->stock());

        $s = PartnerStatement::for($p);
        $this->assertSame(['دادیم', 'پس گرفتیم'], $s->rows->pluck('label')->all());
        $this->assertSame(6.0, $s->current);
        $this->assertStringContainsString('برگشت بخشی از', $s->rows[1]['note']);

        // باقی‌مانده که برگشت، ردیف خودش تسویه می‌شود.
        $r->recordReturn(6, '2026-10-10');
        $r->refresh();

        $this->assertSame('2026-10-10', $r->settled_on->toDateString());
        $this->assertSame(8000.0, $this->stock());
        $this->assertSame(0.0, PartnerStatement::balanceOf($p));
    }

    public function test_بیشتر_از_باقی‌مانده_برگشت_نمی‌خورد(): void
    {
        $r = $this->record($this->partner('نانوایی پدگان'), 'borrowed', 5, '2026-10-08');

        $this->expectException(\InvalidArgumentException::class);
        $r->recordReturn(6);
    }

    public function test_تسویهٔ_دستی_بعد_از_برگشت_بخشی_فقط_باقی‌مانده_را_برمی‌گرداند(): void
    {
        $p = $this->partner('نانوایی هیدوچ');
        $r = $this->record($p, 'borrowed', 10, '2026-10-01');
        $this->assertSame(8400.0, $this->stock());

        $r->recordReturn(3, '2026-10-02');
        $this->assertSame(8280.0, $this->stock());

        $r->refresh()->update(['settled_on' => '2026-10-05']);
        $this->assertSame(8000.0, $this->stock());

        $s = PartnerStatement::for($p);
        $this->assertSame([-10.0, -7.0, 0.0], $s->rows->pluck('balance')->map(fn ($b) => (float) $b)->all());
        $this->assertStringStartsWith('برگشت باقی‌ماندهٔ', $s->rows[2]['note']);
    }

    public function test_حذف_برگشتی_که_ردیف_را_بسته_بود_ردیف_را_باز_می‌کند(): void
    {
        $r = $this->record($this->partner('نانوایی ناهوت'), 'lent', 5, '2026-10-01');
        $return = $r->recordReturn(5, '2026-10-03');
        $this->assertNotNull($r->fresh()->settled_on);
        $this->assertSame(8000.0, $this->stock());

        $return->delete();

        $this->assertNull($r->fresh()->settled_on);
        $this->assertSame(7800.0, $this->stock());
    }

    public function test_گزارش_همکاران_و_ترازنامه_برگشت_بخشی_را_کم_می‌کنند(): void
    {
        $p = $this->partner('نانوایی کلوکان');
        $r = $this->record($p, 'lent', 10, '2026-10-01');
        $r->recordReturn(4, '2026-10-02');

        $position = PartnerLedger::for($p->id);
        $this->assertSame(6.0, $position->netBags());

        $this->getJson('/api/v1/consignment-flour/balance')
            ->assertOk()
            ->assertJsonPath('data.lent_bags', 6)
            ->assertJsonPath('data.owed_to_us_bags', 6)
            ->assertJsonPath('data.headline.label', 'طلب ما: 6 کیسه');
    }

    public function test_خروجی_cs_v_با_bo_m_و_فارسی_است(): void
    {
        $csv = PartnerStatement::for($this->kent())->toCsv();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('مانده پس از ردیف (کیسه)', $csv);
        $this->assertStringContainsString('طلب ما: 10 کیسه', $csv);
        $this->assertStringNotContainsString('کیلو', $csv);
    }

    public function test_دکمهٔ_cs_v_فایل_می‌دهد(): void
    {
        $p = $this->kent();

        Livewire::test(PartnerStatementPage::class, ['record' => $p->getKey()])
            ->callAction('csv')
            ->assertFileDownloaded('partner-'.$p->id.'-statement.csv');
    }

    public function test_صفحهٔ_پرونده_سرخط_و_ردیف‌ها_را_نشان_می‌دهد(): void
    {
        $p = $this->kent();

        $this->get(PartnerResource::getUrl('statement', ['record' => $p]))
            ->assertOk()
            ->assertSee('پروندهٔ محمداکبر قریشیان نانوایی کنت')
            ->assertSee('طلب ما: 10 کیسه')
            ->assertSee('ماندهٔ اول دوره')
            ->assertSee('پس گرفتیم')
            ->assertDontSee('کیلوگرم');
    }

    public function test_فیلتر_صفحه_با_تاریخ_شمسی_کار_می‌کند(): void
    {
        $p = $this->kent();

        Livewire::test(PartnerStatementPage::class, ['record' => $p->getKey()])
            ->set('from', '1405/06/10')
            ->set('to', '1405/07/10')
            ->assertSee('بازه:');
    }

    public function test_ثبت_برگشت_از_صفحهٔ_پرونده(): void
    {
        $p = $this->partner('نانوایی کرشان');
        $r = $this->record($p, 'lent', 10, '2026-10-08');

        Livewire::test(PartnerStatementPage::class, ['record' => $p->getKey()])
            ->callAction('recordReturn', [
                'consignment_flour_id' => $r->id,
                'bags' => 2.5,
                'returned_on' => '1405/07/18',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(7.5, $r->fresh()->outstandingBags());
        $this->assertSame(7700.0, $this->stock());
    }

    public function test_طرف‌حسابی_که_همکار_نیست_پرونده_ندارد(): void
    {
        $school = Customer::create(['name' => 'مدرسه', 'type' => 'school', 'is_active' => true]);

        $this->get(PartnerResource::getUrl('index'))->assertOk()->assertDontSee('مدرسه');
        $this->get('/admin/partners/'.$school->id.'/statement')->assertNotFound();
        $this->getJson('/api/v1/consignment-flour/partners/'.$school->id.'/statement')->assertNotFound();
    }

    public function test_نام_همکار_همه‌جا_به_پرونده_می‌رود(): void
    {
        $p = $this->kent();
        $url = PartnerResource::getUrl('statement', ['record' => $p]);

        $this->get(PartnerResource::getUrl('index'))->assertOk()->assertSee($url, false);
        $this->get(ConsignmentFlourResource::getUrl('index'))->assertOk()->assertSee($url, false);
        $this->get(PartnerReport::getUrl())->assertOk()->assertSee($url, false);
    }

    public function test_داشبورد_جمع_طلب_و_بدهی_را_به_کیسه_می‌گوید(): void
    {
        $this->kent();
        $owing = $this->partner('نانوایی هیدوچ');
        $this->record($owing, 'borrowed', 3, '2026-10-09');

        $this->assertSame(
            ['owed_to_us' => 10.0, 'we_owe' => 3.0, 'net' => 7.0, 'partners_owing' => 1, 'partners_owed' => 1],
            PartnerStatement::totals()
        );

        Livewire::test(PartnerFlourSummary::class)
            ->assertSee('طلب ما از همکاران')
            ->assertSee('10 کیسه')
            ->assertSee('بدهی ما به همکاران')
            ->assertSee('طلب ما: 7 کیسه');
    }

    public function test_api_پرونده_را_با_مانده_هر_ردیف_می‌دهد(): void
    {
        $p = $this->kent();

        $this->getJson("/api/v1/consignment-flour/partners/{$p->id}/statement?from=1405/06/10")
            ->assertOk()
            ->assertJsonPath('data.headline.label', 'طلب ما: 10 کیسه')
            ->assertJsonPath('data.opening_bags', 8)
            ->assertJsonPath('data.rows.0.label', 'دادیم')
            ->assertJsonPath('data.rows.0.balance_bags', 18)
            ->assertJsonPath('data.current_bags', 10);

        $this->getJson('/api/v1/consignment-flour/partners')
            ->assertOk()
            ->assertJsonPath('data.0.partner_id', $p->id);
    }

    public function test_api_ثبت_برگشت(): void
    {
        $r = $this->record($this->partner('نانوایی کنت'), 'lent', 10, '2026-10-08');

        $this->postJson("/api/v1/consignment-flour/{$r->id}/returns", ['bags' => 4, 'returned_on' => '1405/07/18'])
            ->assertCreated()
            ->assertJsonPath('data.outstanding_bags', 6)
            ->assertJsonPath('data.returned_bags', 4);

        $this->postJson("/api/v1/consignment-flour/{$r->id}/returns", ['bags' => 7])
            ->assertStatus(422);
    }
}
