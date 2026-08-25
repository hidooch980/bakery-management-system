{{--
    The date and the time, on every page of the panel.

    Neither appeared anywhere in it. A shop where the quota period runs
    5th→4th, where lateness is counted per day and where a batch is now
    allowed once a day, is a shop where «what is today» is a working
    question, and the answer was on the wall clock rather than the screen.

    The date is rendered here, in Jalali and in the app's timezone. The
    seconds tick in the browser, because a clock that only moves when the
    page reloads is a screenshot of a clock.

    Alpine ships with the panel, so this needs no build — the same reason
    the ground stylesheet is a style block. See filament/panel-ground.
--}}
@php
    $now = now();
@endphp
<div
    class="fi-topbar-clock hidden items-center gap-3 px-3 text-sm md:flex"
    x-data="{
        clock: @js(\App\Support\Jalali::time($now)),
        tick() {
            // The browser's own clock, formatted with Persian digits to
            // match the date beside it. Intl does the digits; no table
            // of numerals to keep in step with anything.
            this.clock = new Date().toLocaleTimeString('fa-IR', {
                hour: '2-digit', minute: '2-digit', hour12: false,
            });
        },
    }"
    x-init="tick(); setInterval(() => tick(), 15000)"
>
    <span class="text-gray-500 dark:text-gray-400">
        {{ \App\Support\Jalali::longDate($now) }}
    </span>
    <span
        class="font-semibold tabular-nums text-gray-700 dark:text-gray-200"
        x-text="clock"
    >{{ \App\Support\Jalali::time($now) }}</span>
</div>
