<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * A nightly backup, at an hour the shop is shut.
 *
 * withoutOverlapping so a slow run is never started twice, and the output
 * is kept so a failed night can be read the morning after.
 */
Schedule::command('backup:database')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backup.log'));

/*
 * Closing the sessions nobody is using.
 *
 * A token here lasted forever until 2026-08-17, and two of the five on
 * this shop's server had been issued in July, used once, and never touched
 * again. Both would still have opened the whole system on the day somebody
 * found the phone they were on.
 *
 * At four, after the backup, so a night that runs long does not have the
 * two treading on each other.
 */
Schedule::command('tokens:prune-idle')
    ->dailyAt('04:00')
    ->withoutOverlapping();

/* And the ones that have simply reached their six months. */
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('04:10');

// A key is only needed for as long as a phone might still replay the
// write it names; after that it is a copy of a response nobody reads.
Schedule::command('idempotency:prune')
    ->dailyAt('04:20');

/*
 * Automatic alerts. Each one checks its own switch in «تنظیمات پیام‌ها»
 * and does nothing while it is off — which is how they all start.
 */
// Saturday morning: each partner's flour account, in bags, by SMS.
Schedule::command('partners:weekly-sms')
    ->weeklyOn(6, '09:00')
    ->timezone('Asia/Tehran')
    ->withoutOverlapping();

// A seller owing more than the threshold: the manager hears on Bale.
Schedule::command('sellers:debt-alert')
    ->dailyAt('10:00')
    ->timezone('Asia/Tehran')
    ->withoutOverlapping();

// School and customer credit that has sat too long, or over its limit.
Schedule::command('customers:credit-reminder')
    ->dailyAt('10:05')
    ->timezone('Asia/Tehran')
    ->withoutOverlapping();

// Yesterday's records against the shop's seller rules: each hit is a
// pending warning the manager approves or rejects. Rules start off.
Schedule::command('sellers:rule-warnings')
    ->dailyAt('10:10')
    ->timezone('Asia/Tehran')
    ->withoutOverlapping();

// Yesterday's card sales against the card reader's deposit, which the
// bank pays in and texts the following morning.
Schedule::command('bank:card-reader-check')
    ->dailyAt('11:00')
    ->timezone('Asia/Tehran')
    ->withoutOverlapping();
