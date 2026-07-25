<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BakeryController;
use App\Http\Controllers\Api\ChaneEntryController;
use App\Http\Controllers\Api\DoughEntryController;
use App\Http\Controllers\Api\FlourStockController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\UserManagementController;
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

        // --- Admin: staff management ---
        Route::middleware('permission:manage-users')->group(function () {
            Route::get('/users/roles', [UserManagementController::class, 'roles']);
            Route::patch('/users/{user}/toggle-active', [UserManagementController::class, 'toggleActive']);
            Route::apiResource('users', UserManagementController::class);
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

        Route::get('/reports/attendance', [ReportController::class, 'attendance'])
            ->middleware('permission:view-attendance-reports');
    });
});
