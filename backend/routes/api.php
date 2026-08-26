<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BakeryController;
use App\Http\Controllers\Api\BakeryShareController;
use App\Http\Controllers\Api\BalanceSheetController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\ChaneBoardController;
use App\Http\Controllers\Api\ChaneEntryController;
use App\Http\Controllers\Api\ConsignmentFlourController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerDebtController;
use App\Http\Controllers\Api\CustomerInteractionController;
use App\Http\Controllers\Api\DoughEntryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FlourAllocationController;
use App\Http\Controllers\Api\FlourSaleController;
use App\Http\Controllers\Api\FlourStockController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\IncomeController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PowerBiExportController;
use App\Http\Controllers\Api\QuotaController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SalaryController;
use App\Http\Controllers\Api\SalaryPaymentRequestController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SellerAccountController;
use App\Http\Controllers\Api\SellerCollectionController;
use App\Http\Controllers\Api\SellerPerformanceController;
use App\Http\Controllers\Api\SettlementRequestController;
use App\Http\Controllers\Api\StaffAdjustmentController;
use App\Http\Controllers\Api\StaffAdvanceController;
use App\Http\Controllers\Api\StaffAdvanceRequestController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\WorkStartController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bakery Management API (v1)
|--------------------------------------------------------------------------
| Every route below is permission-gated with spatie/laravel-permission.
| There is no public registration route — only an admin may create accounts.
*/

Route::prefix('v1')->group(function () {
    // Unauthenticated on purpose and deliberately empty of detail: the app
    // asks it only "are you the bakery, and are you up?" while deciding
    // which published address to talk to during a server move.
    Route::get('/health', fn () => response()->json([
        'success' => true,
        'service' => 'bakery',
    ]));

    // Five a minute. Ten was set when nobody had counted how many people
    // actually log in here: five staff, once a day each, on phones that
    // keep them signed in. Anything faster than five is not this shop.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // Forgotten passwords, by text. Both answer the same way whether the
    // number is registered or not, so neither can be used to find out who
    // works here. The throttle is a second wall: the real limit is counted
    // per phone number inside the controller, because that is what costs
    // money and rings at night.
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:6,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:10,1');

    // `idempotent` sits inside the auth group, not on the api group: it
    // needs to know who is asking, and group middleware runs before
    // sanctum has resolved the user. It is a no-op unless the client
    // sends an Idempotency-Key, so older app versions are unaffected.
    Route::middleware(['auth:sanctum', 'idempotent'])->group(function () {
        // --- Available to every authenticated user ---
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/change-password', [AuthController::class, 'changePassword'])
            ->middleware('permission:change-password');
        Route::get('/bakery', [BakeryController::class, 'show']);

        // --- Attendance (all staff) ---
        Route::middleware('permission:record-attendance')->group(function () {
            Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
            Route::get('/attendance/today', [AttendanceController::class, 'today']);
            Route::get('/attendance/my-history', [AttendanceController::class, 'myHistory']);

            // Whoever is holding a phone ticks in the ones who are not.
            Route::middleware('permission:record-attendance-for-others')->group(function () {
                Route::get('/attendance/roster', [AttendanceController::class, 'roster']);
                Route::post('/attendance/check-in/{user}', [AttendanceController::class, 'checkInFor']);
            });
        });

        // --- Dough maker ---
        Route::post('/dough-entries', [DoughEntryController::class, 'store'])
            ->middleware('permission:record-dough');
        Route::get('/dough-entries/my-history', [DoughEntryController::class, 'myHistory'])
            ->middleware('permission:view-own-dough-history');

        // --- Chane gir ---
        Route::get('/dough-entries/pending', [DoughEntryController::class, 'pending'])
            ->middleware('permission:view-pending-dough');
        Route::post('/chane-entries', [ChaneEntryController::class, 'store'])
            ->middleware('permission:record-chane');
        Route::get('/chane-entries/my-history', [ChaneEntryController::class, 'myHistory'])
            ->middleware('permission:view-own-chane-history');

        // --- Seller ---
        Route::get('/chane-entries/pending', [ChaneEntryController::class, 'pending'])
            ->middleware('permission:view-pending-chane');
        Route::post('/sales', [SaleController::class, 'store'])
            ->middleware('permission:record-sale');
        Route::get('/sales/today', [SaleController::class, 'today'])
            ->middleware('permission:view-own-sales');
        Route::get('/sales/payment-types', [SaleController::class, 'paymentTypes']);
        Route::get('/sales/my-account', [SaleController::class, 'myAccount'])
            ->middleware('permission:view-own-sales');

        // The seller asks to settle; the admin confirms it in the panel.
        Route::middleware('permission:view-own-sales')->group(function () {
            // What the schools, offices and dormitories owe this seller,
            // and the money they hand back.
            Route::get('/my-collections', [SellerCollectionController::class, 'index']);
            Route::post('/my-collections/{customer}/collect', [SellerCollectionController::class, 'collect']);

            Route::get('/settlement-requests', [SettlementRequestController::class, 'index']);
            // The open debts, one line each, for a seller handing over only
            // part of what they owe.
            Route::get('/settlement-requests/settleable', [SettlementRequestController::class, 'settleable']);
            // One figure the seller can pay against, rather than a list
            // of sales to reconcile.
            Route::get('/settlement-requests/account', [SettlementRequestController::class, 'account']);
            Route::post('/settlement-requests', [SettlementRequestController::class, 'store']);
        });

        // --- Seller: flour sold by the kilo or by the sack ---
        Route::post('/flour-sales', [FlourSaleController::class, 'store'])
            ->middleware('permission:record-flour-sale');
        Route::get('/flour-sales/today', [FlourSaleController::class, 'today'])
            ->middleware('permission:view-own-flour-sales');
        Route::get('/flour-sales/options', [FlourSaleController::class, 'options'])
            ->middleware('permission:record-flour-sale');

        // --- Admin: staff management ---
        Route::middleware('permission:manage-users')->group(function () {
            Route::get('/users/roles', [UserManagementController::class, 'roles']);
            Route::patch('/users/{user}/toggle-active', [UserManagementController::class, 'toggleActive']);
            Route::apiResource('users', UserManagementController::class);
        });

        // --- Daily start ticks for shaping and baking ---
        Route::get('/work-starts/today', [WorkStartController::class, 'today']);
        Route::post('/work-starts', [WorkStartController::class, 'store'])
            ->middleware('permission:record-work-start');
        Route::get('/work-starts/rules', [WorkStartController::class, 'rules']);

        // A person's own record, behind no permission at all: it is about
        // them, and the tariff only works as a rule if the person it
        // applies to can see where they stand in it.
        Route::get('/work-starts/mine', [WorkStartController::class, 'mine']);
        Route::get('/work-starts/late-report', [WorkStartController::class, 'lateReport'])
            ->middleware('permission:view-work-start-report');

        // --- Production board: shater, chane gir and seller ---
        Route::get('/chane-board', [ChaneBoardController::class, 'show'])
            ->middleware('permission:view-chane-board');

        // --- Customers: sellers read, admins manage ---
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/types', [CustomerController::class, 'types']);
        Route::middleware('permission:manage-customers')->group(function () {
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::put('/customers/{customer}', [CustomerController::class, 'update']);
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
        });

        // --- Warehouse ---
        Route::get('/inventory', [InventoryController::class, 'index'])
            ->middleware('permission:view-inventory');
        Route::get('/inventory/movements', [InventoryController::class, 'movements'])
            ->middleware('permission:view-inventory');
        Route::middleware('permission:manage-inventory')->group(function () {
            Route::post('/inventory/movements', [InventoryController::class, 'store']);
            Route::patch('/inventory/{key}/threshold', [InventoryController::class, 'updateThreshold']);
        });

        // --- Flour quota, split across the three delivery periods ---
        Route::middleware('permission:view-inventory')->group(function () {
            Route::get('/flour-allocations/current', [FlourAllocationController::class, 'current']);
            Route::get('/flour-allocations', [FlourAllocationController::class, 'index']);
        });
        Route::middleware('permission:manage-inventory')->group(function () {
            Route::post('/flour-allocations', [FlourAllocationController::class, 'store']);
            Route::put('/flour-allocations/{allocation}', [FlourAllocationController::class, 'update']);
            Route::delete('/flour-allocations/{allocation}', [FlourAllocationController::class, 'destroy']);

            // Diesel follows the flour quota above, and was panel-only:
            // the litres off a docket had to be carried back to a desk to
            // be entered, and by then the docket was in somebody's pocket.
            Route::get('/diesel/quota', [QuotaController::class, 'dieselQuota']);
            Route::patch('/diesel/quota', [QuotaController::class, 'updateDieselQuota']);
            Route::post('/diesel/deliveries', [QuotaController::class, 'storeDieselDelivery']);
            Route::delete('/diesel/deliveries/{delivery}', [QuotaController::class, 'destroyDieselDelivery']);

            Route::get('/consignment-flour/balance', [ConsignmentFlourController::class, 'balance']);
            // Before the {consignment} routes below, or «partners» is read
            // as an id and matched by the model binding.
            Route::get('/consignment-flour/partners', [ConsignmentFlourController::class, 'partners']);
            Route::get('/consignment-flour', [ConsignmentFlourController::class, 'index']);
            Route::post('/consignment-flour', [ConsignmentFlourController::class, 'store']);
            Route::patch('/consignment-flour/{consignment}/settle', [ConsignmentFlourController::class, 'settle']);
            Route::delete('/consignment-flour/{consignment}', [ConsignmentFlourController::class, 'destroy']);
        });

        // --- Holidays: everyone reads, admin manages ---
        Route::get('/holidays', [HolidayController::class, 'index']);
        Route::get('/holidays/today', [HolidayController::class, 'today']);
        Route::get('/holidays/types', [HolidayController::class, 'types']);
        Route::middleware('permission:manage-bakery')->group(function () {
            Route::post('/holidays', [HolidayController::class, 'store']);
            Route::put('/holidays/{holiday}', [HolidayController::class, 'update']);
            Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy']);
        });

        // --- Admin: bakery settings ---
        Route::put('/bakery', [BakeryController::class, 'update'])
            ->middleware('permission:manage-bakery');

        // --- Admin: backups ---
        // Status and a «take one now», nothing that hands the file over:
        // the whole shop in one download is not a convenience worth the
        // risk, and a .sql.gz is no use on a phone.
        Route::middleware('permission:manage-bakery')->group(function () {
            Route::get('/backups', [BackupController::class, 'index']);
            Route::post('/backups', [BackupController::class, 'store']);
        });

        // --- Admin: reports & flour stock ---
        Route::middleware('permission:view-all-reports')->group(function () {
            Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);
            Route::get('/reports/production', [ReportController::class, 'production']);
            Route::get('/reports/sales', [ReportController::class, 'sales']);

            // What each seller sold, and one seller sale by sale. Sits
            // beside the other reports and behind the same permission:
            // it is the same class of question, asked per person.
            Route::get('/reports/sellers', [SellerPerformanceController::class, 'index']);
            Route::get('/reports/sellers/{seller}', [SellerPerformanceController::class, 'show']);
            Route::get('/reports/flour', [ReportController::class, 'flourConsumption']);
            Route::get('/reports/efficiency', [ReportController::class, 'efficiency']);
            // What the shop got through, a day, a week or a month at a time.
            Route::get('/reports/consumption-series', [ReportController::class, 'consumptionSeries']);

            Route::get('/flour/balance', [FlourStockController::class, 'balance']);
            Route::get('/flour/movements', [FlourStockController::class, 'index']);
            Route::post('/flour/movements', [FlourStockController::class, 'store']);
        });

        Route::middleware('permission:view-attendance-reports')->group(function () {
            Route::get('/reports/attendance', [ReportController::class, 'attendance']);
            Route::get('/reports/attendance-summary', [ReportController::class, 'attendanceSummary']);
        });

        // Every employee can see their own payslips. Declared before the
        // salaries resource so `/salaries/{salary}` does not swallow "mine".
        Route::get('/salaries/mine', [SalaryController::class, 'mine']);

        // The same money as one figure, for the card on their home screen.
        // Their pay was visible to everyone but them.
        Route::get('/salaries/my-summary', [SalaryController::class, 'mySummary']);

        // And what they have drawn against them. Whoever took the advance
        // is the person who most needs to know what next month is short by.
        Route::get('/staff-advances/mine', [StaffAdvanceController::class, 'mine']);

        // Asking for one, which used to happen in the doorway and left no
        // record of who asked or what was said back. Open to every
        // employee: asking is not the same as granting.
        // Asking to be paid for the month. No amount is sent — the wage is
        // what was agreed, and the person asking is saying he has not had
        // it, not proposing a figure.
        Route::get('/salary-requests/mine', [SalaryPaymentRequestController::class, 'mine']);
        Route::post('/salary-requests', [SalaryPaymentRequestController::class, 'store']);
        Route::delete('/salary-requests/{salaryRequest}', [SalaryPaymentRequestController::class, 'destroy']);

        Route::get('/advance-requests/mine', [StaffAdvanceRequestController::class, 'mine']);
        Route::post('/advance-requests', [StaffAdvanceRequestController::class, 'store']);
        Route::delete('/advance-requests/{advanceRequest}', [StaffAdvanceRequestController::class, 'destroy']);

        // --- Admin: finance (expenses & payroll) ---
        Route::middleware('permission:manage-finance')->group(function () {
            Route::get('/expenses/categories', [ExpenseController::class, 'categories']);
            Route::apiResource('expenses', ExpenseController::class)->except(['show']);

            // Rewards and penalties, recorded on the day. The pay sheet
            // opens on their total rather than asking the owner to
            // remember who was late three weeks ago.
            Route::get('/staff-adjustments/period', [StaffAdjustmentController::class, 'forPeriod']);
            Route::get('/staff-adjustments', [StaffAdjustmentController::class, 'index']);
            Route::post('/staff-adjustments', [StaffAdjustmentController::class, 'store']);
            Route::delete('/staff-adjustments/{adjustment}', [StaffAdjustmentController::class, 'destroy']);

            Route::get('/salaries/employees', [SalaryController::class, 'employees']);
            Route::patch('/salaries/{salary}/mark-paid', [SalaryController::class, 'markPaid']);
            Route::apiResource('salaries', SalaryController::class)->except(['show']);

            Route::get('/staff-advances/outstanding', [StaffAdvanceController::class, 'outstanding']);

            // No approve route on purpose: paying the person through the
            // pay sheet is what approval means, and that is where the
            // figures are on screen before the money moves.
            Route::get('/salary-requests', [SalaryPaymentRequestController::class, 'index']);
            Route::patch('/salary-requests/{salaryRequest}/reject', [SalaryPaymentRequestController::class, 'reject']);

            Route::get('/advance-requests', [StaffAdvanceRequestController::class, 'index']);
            Route::patch('/advance-requests/{advanceRequest}/approve', [StaffAdvanceRequestController::class, 'approve']);
            Route::patch('/advance-requests/{advanceRequest}/reject', [StaffAdvanceRequestController::class, 'reject']);
            Route::apiResource('staff-advances', StaffAdvanceController::class)
                ->parameters(['staff-advances' => 'advance'])
                ->only(['index', 'store', 'destroy']);
        });

        // --- Admin: miscellaneous income ---
        Route::middleware('permission:manage-finance')->group(function () {
            Route::get('/incomes/categories', [IncomeController::class, 'categories']);
            Route::apiResource('incomes', IncomeController::class)->except(['show']);

            // --- Seller accounts: what each seller still owes ---
            Route::get('/seller-accounts', [SellerAccountController::class, 'index']);
            Route::post('/seller-accounts/{seller}/settle', [SellerAccountController::class, 'settle']);
            Route::post('/seller-accounts/{seller}/settle-loaves', [SellerAccountController::class, 'settleLoaves']);
            Route::post('/settlement-requests/{settlement}/confirm', [SellerAccountController::class, 'confirm']);
            Route::post('/settlement-requests/{settlement}/reject', [SellerAccountController::class, 'reject']);

            // --- CRM: what was said to a customer, and what is owed back ---
            Route::get('/follow-ups', [CustomerInteractionController::class, 'dueFollowUps']);
            Route::get('/customers/{customer}/interactions', [CustomerInteractionController::class, 'index']);
            Route::post('/customers/{customer}/interactions', [CustomerInteractionController::class, 'store']);
            Route::post('/interactions/{interaction}/complete', [CustomerInteractionController::class, 'complete']);

            // --- What the schools and offices still owe ---
            Route::get('/customer-debts', [CustomerDebtController::class, 'index']);
            Route::post('/customer-debts/{customer}/settle', [CustomerDebtController::class, 'settle']);

            // --- Partner shares (دنگ) and the profit split ---
            Route::get('/shares/split', [BakeryShareController::class, 'split']);
            Route::get('/shares/settlements', [BakeryShareController::class, 'settlements']);
            Route::post('/shares/{share}/settle', [BakeryShareController::class, 'settle']);
            Route::apiResource('shares', BakeryShareController::class)->except(['show']);
        });

        // --- Admin: bank accounts and balances ---
        Route::middleware('permission:manage-finance')->group(function () {
            Route::post('/bank-accounts/transfer', [BankAccountController::class, 'transfer']);
            Route::get('/bank-accounts/{account}/transactions', [BankAccountController::class, 'transactions']);
            Route::post('/bank-accounts/{account}/transactions', [BankAccountController::class, 'record']);
            // The parameter must be named `account` to match the controller
            // signature, or implicit binding silently hands over an empty model.
            Route::apiResource('bank-accounts', BankAccountController::class)
                ->except(['show'])
                ->parameters(['bank-accounts' => 'account']);
        });

        // --- Admin: financial reports ---
        Route::middleware('permission:view-financial-reports')->group(function () {
            Route::get('/reports/financial', [ReportController::class, 'financial']);
            Route::get('/reports/financial-trend', [ReportController::class, 'financialTrend']);
            Route::get('/reports/payroll', [ReportController::class, 'payroll']);
            Route::get('/reports/debts', [ReportController::class, 'debts']);
            // What the shop owns against what it owes, as of now.
            Route::get('/reports/balance-sheet', [BalanceSheetController::class, 'show']);
            // Income and cost bucketed daily, weekly or monthly.
            Route::get('/reports/financial-series', [ReportController::class, 'financialSeries']);
            // Flat rows for Power BI and anything else that models its own
            // data — see docs/POWERBI.md.
            Route::get('/reports/export/{dataset}', [PowerBiExportController::class, 'show']);
        });

    });
});
