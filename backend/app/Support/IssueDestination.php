<?php

namespace App\Support;

/**
 * Which screen on the phone deals with a given issue.
 *
 * «امروز» lists what needs the owner and, until now, listed it and
 * stopped. A row saying «موجودی «حساب اصلی» منفی است» left him to work
 * out for himself that the answer is behind «مالی», and the panel had a
 * link for exactly this while the phone had none — `SystemIssue::$url`
 * has always been there and the phone was never told about it.
 *
 * A panel path is no use on a handset, so the mapping is to the app's own
 * tabs by name. It lives on the server with everything else «امروز» says,
 * so a new check gets its destination without an app release; the phone
 * ignores a name it does not know and simply shows no button, which is
 * what it did for every issue before today.
 */
class IssueDestination
{
    /** The tab labels the phone's admin home actually has. */
    public const WAREHOUSE = 'warehouse';

    public const FINANCE = 'finance';

    public const STAFF = 'staff';

    public const OVERVIEW = 'overview';

    /**
     * Matched on the key's prefix, because keys carry the row's id — a
     * loan issue is `loan-due-7`, not `loan-due`. Longest prefix first,
     * so `seller-account-stale-3` is not read as `seller-account-3`;
     * they happen to go to the same place today and would not have to.
     */
    private const ROUTES = [
        'negative-stock-' => self::WAREHOUSE,
        'low-stock-' => self::WAREHOUSE,
        'empty-stock-' => self::WAREHOUSE,
        'quota-over' => self::WAREHOUSE,
        'reader-gap-' => self::WAREHOUSE,
        'consignment-open-' => self::WAREHOUSE,
        'diesel-tank-empty-' => self::WAREHOUSE,
        'diesel-running-out-' => self::WAREHOUSE,

        'negative-bank-' => self::FINANCE,
        'seller-account-stale-' => self::FINANCE,
        'seller-account-' => self::FINANCE,
        'unsettled-shortfalls' => self::FINANCE,
        'trading-at-a-loss-' => self::FINANCE,
        'loan-due-' => self::FINANCE,
        'monthly-' => self::FINANCE,
        'expenses-mostly-other' => self::FINANCE,

        'stale-dough' => self::OVERVIEW,
        'stale-chane' => self::OVERVIEW,

        // Deliberately absent: `missing-settings`. The shop's settings are
        // not on the phone at all, so a button promising to take him there
        // would be a lie — he does that at the desk.
    ];

    /** The tab for this issue, or null when the phone has nowhere to send him. */
    public static function forKey(string $key): ?string
    {
        foreach (self::routes() as $prefix => $tab) {
            if (str_starts_with($key, $prefix)) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * The routes, longest prefix first.
     *
     * Sorted here rather than trusted to the order they are written in:
     * the table above is edited by hand and a shorter prefix slipping
     * above a longer one would silently swallow it.
     *
     * @return array<string, string>
     */
    private static function routes(): array
    {
        $routes = self::ROUTES;

        uksort($routes, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $routes;
    }
}
