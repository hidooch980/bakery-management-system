<?php

namespace Tests;

use App\Models\InventoryItem;
use App\Support\CurrentBakery;
use App\Support\Money;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests wipe and reseed whatever database they are pointed at, so refuse
     * to run against anything that is not clearly a test database.
     *
     * This guards against a cached config (`php artisan optimize`) silently
     * overriding the connection settings in phpunit.xml, which would aim the
     * whole suite at production data.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Held statically for the life of a request; a test process runs
        // many, against a database that is wiped between them.
        CurrentBakery::forget();

        // Same reason. A test that switches the shop to Rial leaves the unit
        // cached in this process while the next test gets a fresh database
        // saying Toman — so amounts came back ten times over in a test that
        // had never mentioned Rial.
        Money::forgetCache();

        // Same reason again. The resolved item also carries a remembered
        // balance, so a stale one would be the previous test's stock read
        // against this test's ledger.
        InventoryItem::forgetResolved();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($database !== ':memory:' && ! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against the '{$database}' database. "
                .'Expected a name ending in "_test". '
                .'If the config is cached, run `php artisan config:clear` first.'
            );
        }
    }
}
