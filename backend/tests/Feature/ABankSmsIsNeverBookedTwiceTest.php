<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankSms;
use App\Models\User;
use App\Support\BankSmsMatcher;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «حتی حقوق و خرید آرد و اقساط پیامش میاد، تکراری ثبت نشه».
 */
class ABankSmsIsNeverBookedTwiceTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->bank = BankAccount::create(['title' => 'ملی — کارتخوان', 'opening_balance' => 0, 'is_active' => true]);
    }

    private function sms(string $direction, float $amount, string $at = '2026-10-25 10:00:00', string $body = 'x', ?int $account = null): BankSms
    {
        return BankSms::create([
            'sender' => '300017',
            'body' => $body,
            'received_at' => $at,
            'hash' => BankSms::hashOf('300017', $body, $at),
            'direction' => $direction,
            'amount' => $amount,
            'bank_account_id' => $account ?? $this->bank->id,
        ]);
    }

    public function test_a_salary_already_paid_is_matched_not_offered_again(): void
    {
        $staff = User::factory()->create(['name' => 'جلیل درازهی']);
        $tx = $this->bank->record('out', 650_000, 'salary', $staff->id, null, 'حقوق', '2026-10-25');

        $sms = BankSmsMatcher::settle($this->sms('out', 650_000, '2026-10-25 17:12:00', 'salary'));

        $this->assertSame('matched', $sms->status);
        $this->assertSame($tx->id, $sms->bank_transaction_id);
        $this->assertStringStartsWith('حقوق', BankSmsMatcher::describe($tx));
    }

    public function test_an_instalment_the_next_day_still_matches_but_three_days_later_does_not(): void
    {
        $this->bank->record('out', 1_200_000, 'loan', null, null, null, '2026-10-24');

        $this->assertSame('matched', BankSmsMatcher::settle($this->sms('out', 1_200_000, '2026-10-25 08:00:00', 'a'))->status);

        $this->bank->record('out', 999_000, 'loan', null, null, null, '2026-10-20');
        $this->assertSame('pending', BankSmsMatcher::settle($this->sms('out', 999_000, '2026-10-25 08:00:00', 'b'))->status);
    }

    public function test_direction_amount_and_account_must_all_agree(): void
    {
        $other = BankAccount::create(['title' => 'ملت', 'opening_balance' => 0, 'is_active' => true]);
        $this->bank->record('in', 500_000, 'sale', null, null, null, '2026-10-25');

        $this->assertSame('pending', BankSmsMatcher::settle($this->sms('out', 500_000, body: 'dir'))->status);
        $this->assertSame('pending', BankSmsMatcher::settle($this->sms('in', 500_001, body: 'amt'))->status);
        $this->assertSame('pending', BankSmsMatcher::settle($this->sms('in', 500_000, body: 'acc', account: $other->id))->status);
    }

    public function test_two_possible_movements_leave_it_for_a_person_to_pick(): void
    {
        $this->bank->record('out', 300_000, 'advance', null, null, null, '2026-10-25');
        $this->bank->record('out', 300_000, 'expense', null, null, null, '2026-10-25');

        $sms = BankSmsMatcher::settle($this->sms('out', 300_000));

        $this->assertSame('ambiguous', $sms->status);
        $this->assertNull($sms->bank_transaction_id);
        $this->assertCount(2, BankSmsMatcher::candidates($sms));
    }

    public function test_one_movement_is_claimed_by_one_sms_only(): void
    {
        $this->bank->record('out', 400_000, 'purchase', null, null, null, '2026-10-25');

        $first = BankSmsMatcher::settle($this->sms('out', 400_000, body: 'one'));
        $second = BankSmsMatcher::settle($this->sms('out', 400_000, body: 'two'));

        $this->assertSame('matched', $first->status);
        $this->assertSame('pending', $second->status);
    }

    public function test_a_movement_recorded_later_takes_its_waiting_sms(): void
    {
        $sms = BankSmsMatcher::settle($this->sms('out', 777_000, '2026-10-25 09:00:00'));
        $this->assertSame('pending', $sms->status);

        $tx = $this->bank->record('out', 777_000, 'expense', null, null, 'حمل نمک', '2026-10-25');

        $sms->refresh();
        $this->assertSame('matched', $sms->status);
        $this->assertSame($tx->id, $sms->bank_transaction_id);
    }

    public function test_the_same_sms_twice_is_stored_once(): void
    {
        $this->sms('in', 1_000, '2026-10-25 10:00:20', 'same');

        $this->expectException(QueryException::class);
        $this->sms('in', 1_000, '2026-10-25 10:00:50', 'same');
    }
}
