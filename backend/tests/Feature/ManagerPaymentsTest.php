<?php

namespace Tests\Feature;

use App\Models\Bakery;
use App\Models\BankAccount;
use App\Models\Loan;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagerPaymentsTest extends TestCase
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

    private function account(): BankAccount
    {
        return BankAccount::create(['title' => 'بانک آزمایشی', 'opening_balance' => 2000, 'is_active' => true]);
    }

    private function loan(): Loan
    {
        return Loan::create(['title' => 'وام دستگاه', 'principal' => 1000, 'instalment_amount' => 100, 'instalment_count' => 10, 'first_due_on' => today()]);
    }

    public function test_علی‌الحساب_مدیر_هم_بدهی_و_هم_گردش_حساب_دارد(): void
    {
        $account = $this->account();
        $this->postJson('/api/v1/staff-advances', ['user_id' => $this->worker->id, 'amount' => 300, 'bank_account_id' => $account->id])
            ->assertCreated();
        $this->assertEquals(300, StaffAdvance::outstandingFor($this->worker->id));
        $this->assertEquals(1700, $account->fresh()->balance);
        $this->assertDatabaseHas('staff_advances', ['user_id' => $this->worker->id, 'recorded_by' => $this->admin->id]);
    }

    public function test_قسط_فقط_یکبار_مانده_وام_و_حساب_را_کم_می‌کند(): void
    {
        $account = $this->account();
        $loan = $this->loan();
        $data = ['amount' => 100, 'paid_on' => today()->toDateString(), 'bank_account_id' => $account->id];
        $headers = ['Idempotency-Key' => 'e52972d4-f871-4ce4-8e1c-273771552210'];
        $this->postJson('/api/v1/loans/'.$loan->id.'/payments', $data, $headers)->assertCreated();
        $this->postJson('/api/v1/loans/'.$loan->id.'/payments', $data, $headers)->assertCreated();
        $this->assertEquals(900, $loan->fresh()->remaining);
        $this->assertEquals(1900, $account->fresh()->balance);
        $this->assertEquals(1, $loan->payments()->count());
        $this->getJson('/api/v1/loans')->assertOk()->assertJsonPath('data.0.remaining', 900);
    }

    public function test_پرداخت_بیشتر_از_مانده_وام_رد_می‌شود(): void
    {
        $account = $this->account();
        $loan = $this->loan();
        $this->postJson('/api/v1/loans/'.$loan->id.'/payments', ['amount' => 1001, 'paid_on' => today()->toDateString(), 'bank_account_id' => $account->id])
            ->assertUnprocessable();
        $this->assertEquals(0, $loan->payments()->count());
        $this->assertEquals(2000, $account->fresh()->balance);
    }

    public function test_وام_بانکی_برای_کارمند_و_نانوایی_دیگر_بسته_است(): void
    {
        $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/loans')->assertForbidden();
        $this->actingAs($this->admin, 'sanctum');
        $other = Bakery::create(['name' => 'نانوایی دیگر']);
        $person = User::factory()->create(['bakery_id' => $other->id]);
        $this->postJson('/api/v1/staff-advances', ['user_id' => $person->id, 'amount' => 100])->assertNotFound();
        $loan = Loan::create(['title' => 'وام دیگر', 'principal' => 500, 'instalment_amount' => 50, 'first_due_on' => today()]);
        $loan->bakery_id = $other->id;
        $loan->save();
        $this->postJson('/api/v1/loans/'.$loan->id.'/payments', ['amount' => 50, 'paid_on' => today()->toDateString(), 'bank_account_id' => $this->account()->id])->assertNotFound();
    }
}
