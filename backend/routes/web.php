<?php

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
