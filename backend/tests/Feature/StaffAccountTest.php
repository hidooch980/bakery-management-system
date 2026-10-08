<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\SalaryPayment;
use App\Models\StaffAdjustment;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first()->update(['currency' => 'toman']);
        Money::forgetCache();
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->worker = User::factory()->create(['is_active' => true, 'monthly_salary' => 1000]);
        $this->worker->assignRole('seller');
        $this->actingAs($this->admin, 'sanctum');
    }

    private function advance(): StaffAdvance
    {
        return StaffAdvance::create(['user_id' => $this->worker->id, 'amount' => 300, 'paid_on' => now()]);
    }

    public function test_مدیر_می‌تواند_مساعده_را_در_این_فیش_کسر_نکند(): void
    {
        $this->advance();
        $this->postJson('/api/v1/salaries', [
            'user_id' => $this->worker->id, 'period_start' => '1405/07/01',
            'base_amount' => 1000, 'recover_advances' => false, 'recover_bread' => false,
        ])->assertCreated()->assertJsonPath('data.net_amount', 1000)->assertJsonPath('data.advance_deduction', 0);
        $this->assertEquals(300, StaffAdvance::outstandingFor($this->worker->id));
    }

    public function test_اصلاح_فیش_تسویه_را_برمی‌گرداند_و_دوباره_اعمال_می‌کند(): void
    {
        $advance = $this->advance();
        $slip = SalaryPayment::create(['user_id' => $this->worker->id, 'period_start' => now()->startOfMonth(), 'base_amount' => 1000]);
        $this->getJson('/api/v1/staff-accounts/'.$this->worker->id)->assertOk()
            ->assertJsonPath('data.payslips.0.advance_links.0.id', $advance->id)
            ->assertJsonPath('data.advances.0.salary_ids.0', $slip->id);
        $this->patchJson('/api/v1/salaries/'.$slip->id, ['recover_advances' => false, 'note' => 'بدهی به دوره بعد منتقل شود'])
            ->assertOk()->assertJsonPath('data.net_amount', 1000);
        $this->assertEquals(300, $advance->fresh()->outstanding);
        $this->patchJson('/api/v1/salaries/'.$slip->id, ['recover_advances' => true])->assertOk()->assertJsonPath('data.net_amount', 700);
        $this->assertEquals(0, $advance->fresh()->outstanding);
    }

    public function test_مساعده_تسویه‌شده_مستقیم_ویرایش_نمی‌شود(): void
    {
        $advance = $this->advance();
        SalaryPayment::create(['user_id' => $this->worker->id, 'period_start' => now()->startOfMonth(), 'base_amount' => 1000]);
        $this->patchJson('/api/v1/staff-account-entries/advance/'.$advance->id, ['amount' => 20, 'note' => 'اصلاح مبلغ'])
            ->assertStatus(409);
        $this->assertEquals(300, $advance->fresh()->amount);
    }

    public function test_اصلاح_مساعده_روی_رکورد_اصلی_است(): void
    {
        $advance = $this->advance();
        $this->patchJson('/api/v1/staff-account-entries/advance/'.$advance->id, ['amount' => 200, 'note' => 'اصلاح اشتباه ثبت'])
            ->assertOk();
        $this->assertEquals(200, $advance->fresh()->amount);
        $this->assertEquals(200, StaffAdvance::outstandingFor($this->worker->id));
    }

    public function test_کسورات_روزانه_با_ارزش_واقعی_نمایش_داده_می‌شود(): void
    {
        $a = StaffAdjustment::create(['user_id' => $this->worker->id, 'kind' => 'penalty', 'basis' => 'days', 'days' => 3, 'reason' => 'غیبت ثبت‌شده', 'occurred_on' => now()]);
        $this->getJson('/api/v1/staff-accounts/'.$this->worker->id)->assertOk()
            ->assertJsonPath('data.adjustments.0.amount', $a->value);
    }

    public function test_کارمند_به_پرونده_مالی_مدیر_دسترسی_ندارد(): void
    {
        $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/staff-accounts/'.$this->worker->id)->assertForbidden();
    }

    public function test_پرونده_نانوایی_دیگر_قابل_خواندن_نیست(): void
    {
        $other = Bakery::create(['name' => 'نانوایی دوم']);
        $person = User::factory()->create(['bakery_id' => $other->id]);
        $this->getJson('/api/v1/staff-accounts/'.$person->id)->assertNotFound();
    }
}
