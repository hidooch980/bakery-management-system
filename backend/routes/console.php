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
