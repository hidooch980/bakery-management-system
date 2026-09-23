<?php

use App\Http\Controllers\Api\BakeryApplicationController;
use App\Http\Middleware\PicksTheBakeryInThePanel;
use App\Models\Bakery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The public face of the shop.
 *
 * This served Laravel's default welcome page until 1405/06/03 — the
 * framework's logo, its documentation links, and «Log in / Register»
 * pointing at routes this application does not have. Anybody who typed the
 * address saw a page that said nothing about a bakery.
 */
Route::get('/', function () {
    // The oldest shop is the one this address belongs to. Reading it from
    // the database rather than writing it into the template means a rename
    // in the panel reaches the website without a deploy.
    $bakery = Bakery::query()->oldest('id')->first();

    return view('welcome', ['bakery' => $bakery]);
})->name('home');

/*
 * درخواستِ نانوایی تازه — فرم، و فرستادنش.
 *
 * پشتش از قبل ساخته شده بود (BakeryApplicationController) ولی هیچ
 * صفحه‌ای نداشت، یعنی عملاً وجود نداشت: تنها راهِ فرستادنِ درخواست
 * یک تماسِ API بود، که نانوایِ سیستانی نمی‌گیردش.
 *
 * همان کنترلر را صدا می‌زند نه اینکه منطق را دوباره بنویسد. دو راهِ
 * ساختنِ یک درخواست، همان چیزی است که روزی دو جور رفتار می‌کند.
 */
Route::get('/signup', fn () => view('signup'))->name('signup');

Route::post('/signup', function (Request $request) {
    $response = app(BakeryApplicationController::class)
        ->store($request);

    // ۲۰۱ یعنی تازه ثبت شد، ۲۰۰ یعنی همین شماره از قبل درخواستی
    // در انتظار داشت. هر دو برای کسی که فرم را پر کرده یک چیز
    // می‌گویند: پیامت رسید، دوباره نفرست.
    return back()->with('sent', $response->getStatusCode() < 300);
})
    ->middleware('throttle:3,10')
    ->name('signup.store');

/*
 * Switching shop, from the panel's topbar.
 *
 * An owner who holds more than one bakery had no way to look at the
 * second one in the panel: the phone names the shop in a header, and a
 * browser sends no such thing. The choice is kept in the session by
 * [PicksTheBakeryInThePanel]; this is the one place it is set.
 *
 * A POST rather than a link, so CSRF covers it and so a shop is never
 * switched by something merely *fetching* a URL.
 */
Route::post('/panel/shop', function (Request $request) {
    $asked = (int) $request->input('bakery_id');

    abort_unless($request->user()?->canReachBakery($asked), 403);

    $request->session()->put(PicksTheBakeryInThePanel::KEY, $asked);

    // Back to where they were standing. A switch that always landed on
    // the dashboard would make comparing the same screen across two
    // shops a matter of navigating there again every time.
    return back();
})
    ->middleware(['web', 'auth'])
    ->name('panel.shop.switch');
