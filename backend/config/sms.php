<?php

/*
 * Sending a text message.
 *
 * Nothing here talks to a provider until one is configured. The default
 * driver writes the message to the log instead, which means the whole
 * forgotten-password flow can be built, tested and used before an account
 * exists anywhere — and on the day the shop buys one, a single line in
 * .env switches it over with no code change.
 *
 * The alternative was to wait for the account before writing any of it,
 * and then write it against a provider nobody had used yet.
 */
return [

    // log | kavenegar | ghasedak
    'driver' => env('SMS_DRIVER', 'log'),

    // The number or name the message appears to come from. Iranian
    // providers issue this with the account.
    'from' => env('SMS_FROM'),

    'kavenegar' => [
        'key' => env('KAVENEGAR_API_KEY'),
        'url' => 'https://api.kavenegar.com/v1',
    ],

    'ghasedak' => [
        'key' => env('GHASEDAK_API_KEY'),
        'url' => 'https://api.ghasedak.me/v2',
    ],

    /*
     * The one-time code.
     *
     * Six digits and five minutes: long enough to read a message and type
     * it, short enough that a code glimpsed on a lock screen is worthless
     * by the time anyone acts on it.
     */
    'code' => [
        'length' => 6,
        'minutes' => 5,

        // How many codes one phone may ask for in an hour. Without this,
        // the endpoint is a way to make somebody's phone ring all night at
        // the shop's expense — each message is paid for.
        'per_hour' => 3,

        // Wrong guesses before the code is burned. Six digits is a million
        // combinations; five attempts is not a threat, but leaving it
        // unbounded would be.
        'attempts' => 5,
    ],

];
