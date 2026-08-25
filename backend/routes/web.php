<?php

use App\Models\Bakery;
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
