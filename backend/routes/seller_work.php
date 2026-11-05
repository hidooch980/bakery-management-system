<?php

use App\Http\Controllers\Api\SellerWorkController;
use App\Http\Controllers\Api\PartnerFlourCorrectionController;
use Illuminate\Support\Facades\Route;

// اصلاح رکورد اصلی فقط از اپ مدیر و با همان محدودیت نانوایی انجام می‌شود.
Route::prefix('v1/seller-work')->middleware([
    'auth:sanctum', 'active', 'app-version', 'picks-bakery', 'idempotent', 'role:admin',
])->group(function () {
    Route::get('/{seller}', [SellerWorkController::class, 'show']);
    Route::patch('/{seller}/sales/{sale}', [SellerWorkController::class, 'sale']);
    Route::post('/{seller}/attendance', [SellerWorkController::class, 'addAttendance']);
    Route::patch('/{seller}/attendance/{attendance}', [SellerWorkController::class, 'attendance']);
});

// ثبت و اصلاح از هم جدا هستند؛ فروشنده اجازه اصلاح موجودی همکاران را نمی‌گیرد.
Route::prefix('v1/manager-corrections/partner-flour')->middleware([
    'auth:sanctum', 'active', 'app-version', 'picks-bakery', 'idempotent', 'role:admin', 'permission:manage-inventory',
])->group(function () {
    Route::get('/{consignment}', [PartnerFlourCorrectionController::class, 'show']);
    Route::patch('/{consignment}', [PartnerFlourCorrectionController::class, 'update']);
});
