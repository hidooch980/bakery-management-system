<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BakeryController;
use App\Http\Controllers\Api\BakeryShareController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\ChaneBoardController;
use App\Http\Controllers\Api\ChaneEntryController;
use App\Http\Controllers\Api\ConsignmentFlourController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DoughEntryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FlourAllocationController;
use App\Http\Controllers\Api\FlourSaleController;
use App\Http\Controllers\Api\FlourStockController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\IncomeController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SalaryController;
use App\Http\Controllers\Api\SaleController;
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
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
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

            Route::get('/consignment-flour/balance', [ConsignmentFlourController::class, 'balance']);
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

        // --- Admin: reports & flour stock ---
        Route::middleware('permission:view-all-reports')->group(function () {
            Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);
            Route::get('/reports/production', [ReportController::class, 'production']);
            Route::get('/reports/sales', [ReportController::class, 'sales']);
            Route::get('/reports/flour', [ReportController::class, 'flourConsumption']);
            Route::get('/reports/efficiency', [ReportController::class, 'efficiency']);

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

        // --- Admin: finance (expenses & payroll) ---
        Route::middleware('permission:manage-finance')->group(function () {
            Route::get('/expenses/categories', [ExpenseController::class, 'categories']);
            Route::apiResource('expenses', ExpenseController::class)->except(['show']);

            Route::get('/salaries/employees', [SalaryController::class, 'employees']);
            Route::patch('/salaries/{salary}/mark-paid', [SalaryController::class, 'markPaid']);
            Route::apiResource('salaries', SalaryController::class)->except(['show']);
        });

        // --- Admin: miscellaneous income ---
        Route::middleware('permission:manage-finance')->group(function () {
            Route::get('/incomes/categories', [IncomeController::class, 'categories']);
            Route::apiResource('incomes', IncomeController::class)->except(['show']);

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
        });
    });
});
