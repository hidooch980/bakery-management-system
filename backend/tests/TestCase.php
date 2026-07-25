<?php

namespace Tests;

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
