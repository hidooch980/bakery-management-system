<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bakery;
use App\Models\BakeryShare;
use App\Models\Concerns\RecordsAudit;
use App\Models\ConsignmentFlour;
use App\Models\DieselAllocation;
use App\Models\DieselDelivery;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FlourAllocation;
use App\Models\FlourPrice;
use App\Models\FlourSale;
use App\Models\Income;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Purchase;
use App\Models\SalaryPayment;
use App\Models\SalaryPaymentRequest;
use App\Models\Sale;
use App\Models\SellerAccountCredit;
use App\Models\SettlementRequest;
use App\Models\ShareSettlement;
use App\Models\StaffAdjustment;
use App\Models\StaffAdvance;
use App\Models\StaffAdvanceRequest;
use App\Models\SupplierPayment;
use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which records leave a trail, and under what name.
 *
 * The trail went in on the models that pay and receive money, and stopped
 * there. It missed the ones that decide how much is owed — the partner's
 * dang, the flour rate, the month's quota, the request an advance is
 * approved from. Those are typed by hand in the panel and settled weeks
 * later, which makes an edit to one of them exactly the kind of change
 * somebody comes back and disputes, and until now the panel forgot it the
 * moment the form closed.
 *
 * This list is the claim. If a money-moving model is added without the
 * trait, this test is where it is meant to fail.
 */
class TheTrailCoversWhatMovesMoneyTest extends TestCase
{
    use RefreshDatabase;

    /** Everything that moves money or goods, or sets what either is worth. */
    private const AUDITED = [
        BakeryShare::class,
        ConsignmentFlour::class,
        DieselAllocation::class,
        DieselDelivery::class,
        Expense::class,
        FixedAsset::class,
        FlourAllocation::class,
        FlourPrice::class,
        FlourSale::class,
        Income::class,
        Loan::class,
        LoanPayment::class,
        Purchase::class,
        Sale::class,
        SalaryPayment::class,
        SalaryPaymentRequest::class,
        SellerAccountCredit::class,
        SettlementRequest::class,
        ShareSettlement::class,
        StaffAdjustment::class,
        StaffAdvance::class,
        StaffAdvanceRequest::class,
        SupplierPayment::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);
        Bakery::first();
    }

    public function test_every_model_that_moves_money_records_a_trail(): void
    {
        foreach (self::AUDITED as $model) {
            $this->assertContains(
                RecordsAudit::class,
                class_uses_recursive($model),
                "{$model} moves money and leaves no trail",
            );
        }
    }

    public function test_every_audited_model_can_name_itself(): void
    {
        foreach (self::AUDITED as $model) {
            // Called on an empty instance on purpose: `auditSubject()` runs
            // on delete too, when the relations it reaches for may already
            // be gone. A subject that throws there would take the delete
            // down with it.
            $subject = (new $model)->auditSubject();

            $this->assertTrue(
                $subject === null || is_string($subject),
                "{$model} names itself with something that is not a name",
            );
        }
    }

    public function test_the_trail_keeps_the_name_a_request_had(): void
    {
        $staff = User::factory()->create(['name' => 'عبدالله']);

        $request = StaffAdvanceRequest::create([
            'user_id' => $staff->id,
            'amount' => 3_000_000,
            'reason' => 'اجارهٔ خانه',
            'status' => 'pending',
        ]);

        $log = AuditLog::where('auditable_type', StaffAdvanceRequest::class)
            ->where('auditable_id', $request->id)
            ->sole();

        // The name, not the id. The row it points at can be approved and
        // turned into an advance, and the trail still has to read as a
        // sentence about عبدالله a year later.
        $this->assertStringContainsString('عبدالله', (string) $log->subject);
    }
}
