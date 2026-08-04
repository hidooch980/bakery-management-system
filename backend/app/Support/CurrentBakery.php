<?php

namespace App\Support;

use App\Models\Bakery;

/**
 * Which bakery the request is about.
 *
 * The system ran one shop for its whole life, so everything simply read
 * "the" bakery and every figure in it belonged to that one. Now a user
 * belongs to a bakery and reads only theirs — but the answer still has to
 * be available in the same places it always was: inside the dough formula,
 * the currency, the calendar, a Filament table, an artisan command.
 *
 * Resolved from whoever is signed in, held for the request, and settable by
 * hand for the console — where nobody is signed in and the caller has to say
 * which shop it means.
 */
class CurrentBakery
{
    /** Resolved shops, by id — one lookup each, however often asked. */
    private static array $cached = [];

    private static ?int $forcedId = null;

    /**
     * The signed-in user's bakery.
     *
     * Falls back to the only bakery there is, which keeps every existing
     * install, seeder and test working unchanged — a shop that never had a
     * second bakery cannot be reading the wrong one.
     */
    public static function get(): ?Bakery
    {
        // The id is worked out afresh every time rather than remembered:
        // within one request a different user can be acted as, and holding
        // the first answer would quietly serve one person another's shop.
        $bakeryId = self::$forcedId ?? auth()->user()?->bakery_id;

        if ($bakeryId === null) {
            return Bakery::query()->oldest('id')->first();
        }

        return self::$cached[$bakeryId] ??= Bakery::find($bakeryId);
    }

    public static function id(): ?int
    {
        return self::get()?->id;
    }

    /**
     * Works a block against a named bakery, whoever is signed in.
     *
     * For the console and for anything that legitimately crosses shops —
     * a nightly job, a command creating a new bakery — where there is no
     * user to read the answer from.
     */
    public static function for(int $bakeryId, callable $callback): mixed
    {
        $previousId = self::$forcedId;
        self::$forcedId = $bakeryId;

        try {
            return $callback();
        } finally {
            self::$forcedId = $previousId;
        }
    }

    /** Cleared between requests and tests, so one never answers for another. */
    public static function forget(): void
    {
        self::$cached = [];
        self::$forcedId = null;
    }
}
