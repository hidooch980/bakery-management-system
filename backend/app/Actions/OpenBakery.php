<?php

namespace App\Actions;

use App\Models\Bakery;
use App\Models\User;
use App\Support\CurrentBakery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Opening a shop: the shop row, its first admin, and nothing else.
 *
 * Two callers need this — the console command and the panel page — and a
 * shop opened one way must be identical to a shop opened the other. Held
 * here rather than in either of them, because the failure this prevents is
 * silent: the two drift by one field, and months later two shops that were
 * meant to be the same are quietly keeping different books.
 *
 * The new shop starts empty: no staff, no stock, no history. Everything it
 * records from here belongs to it alone.
 */
class OpenBakery
{
    /**
     * What a shop is, as opposed to what a shop does.
     *
     * Never copied. Two shops wearing one address, phone and logo read as
     * one place on every screen that shows them.
     */
    public const IDENTITY = ['name', 'address', 'phone', 'logo', 'description'];

    /**
     * Opens a shop, run either by a new manager or by somebody already here.
     *
     * A shop used to mean a new sign-in, because the only shape the system
     * knew was one person to one shop. An owner opening their third shop
     * got a third email and a third password to remember, and three
     * accounts that could never see each other's figures.
     *
     * So `$runBy` is the other way: the shop is handed to somebody who
     * already signs in, and appears in their switcher instead. No admin is
     * created and none of the login arguments are read — passing them
     * beside a `$runBy` would quietly make an account nobody asked for.
     *
     * @throws ValidationException when the login is taken or the password is blank
     */
    public function run(
        string $name,
        ?string $adminName = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $password = null,
        ?Bakery $copyFrom = null,
        ?User $runBy = null,
    ): Bakery {
        if ($runBy === null) {
            $this->refuseATakenLogin((string) $email, $phone);

            if (blank($password)) {
                throw ValidationException::withMessages([
                    'password' => 'رمز عبور نمی‌تواند خالی باشد.',
                ]);
            }
        }

        return DB::transaction(function () use ($name, $adminName, $email, $phone, $password, $copyFrom, $runBy) {
            $bakery = Bakery::create([
                'name' => $name,
                ...($copyFrom ? self::settingsFrom($copyFrom) : []),
            ]);

            if ($runBy !== null) {
                // Their home shop is untouched: this is a shop they also
                // reach, not a move. Taking it away later is deleting a
                // row, and the place they work cannot be taken from them
                // by accident.
                $runBy->bakeries()->syncWithoutDetaching([$bakery->id]);

                return $bakery;
            }

            // Created inside the new shop, so the user and everything the
            // panel later sets up for them is stamped with it rather than
            // with whichever shop happened to be first in the table.
            return CurrentBakery::for($bakery->id, function () use ($bakery, $adminName, $email, $phone, $password) {
                User::create([
                    'name' => $adminName,
                    'email' => $email,
                    'phone' => $phone,
                    'password' => Hash::make($password),
                    'is_active' => true,
                    'bakery_id' => $bakery->id,
                ])->assignRole('admin');

                return $bakery;
            });
        });
    }

    /**
     * The recipe from another shop — its weights, ratios, prices and
     * calendar, everything on the shop row that is a setting rather than a
     * name. Nothing on that row is a running total, so there is no balance
     * or debt here to carry across by accident.
     *
     * @return array<string, mixed>
     */
    public static function settingsFrom(Bakery $source): array
    {
        return collect($source->only($source->getFillable()))
            ->except(self::IDENTITY)
            ->all();
    }

    /**
     * A login names a person, not a shop, so it is unique across the whole
     * installation and is checked before anything at all is written.
     *
     * Blank phones are not compared: most staff have none, and a `where`
     * on null matches every one of them, which would refuse the second
     * shop on the grounds that the first one exists.
     */
    private function refuseATakenLogin(string $email, ?string $phone): void
    {
        $taken = User::query()
            ->where(function ($query) use ($email, $phone) {
                $query->where('email', $email);

                if (filled($phone)) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'email' => 'کاربری با این ایمیل یا شماره تلفن از قبل وجود دارد.',
            ]);
        }
    }
}
