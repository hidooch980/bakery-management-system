<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The log file has two writers and must stay open to both.
 *
 * www-data serves the site; the deploy user runs artisan, the scheduler and
 * the nightly backup. Whichever writes first owns the day's file, and at
 * Monolog's default 0644 the other is shut out of it until midnight. On the
 * server that read as every artisan command dying with "could not be opened
 * in append mode" — the command still ran, but its logging did not, which is
 * the worst version: the thing that stops working is the thing that would
 * have told you.
 *
 * Guarded here because config/logging.php is a file people regenerate from
 * the framework default, and the default is 0644.
 */
class LogsStayWritableByBothUsersTest extends TestCase
{
    public function test_the_file_channels_are_group_writable(): void
    {
        foreach (['single', 'daily'] as $channel) {
            $permission = config("logging.channels.{$channel}.permission");

            $this->assertNotNull(
                $permission,
                "کانال {$channel} دسترسی فایل را تعیین نکرده؛ پیش‌فرض 0644 است و کاربر دوم را بیرون می‌گذارد.",
            );

            $this->assertSame(
                0664,
                $permission,
                "کانال {$channel} باید 0664 باشد تا هر دو کاربر بتوانند بنویسند.",
            );
        }
    }

    public function test_the_log_is_not_readable_by_everyone_on_the_box(): void
    {
        foreach (['single', 'daily'] as $channel) {
            $permission = config("logging.channels.{$channel}.permission");

            // It carries request payloads. Group-writable is the point;
            // world-writable would be a different and worse thing.
            $this->assertSame(0, $permission & 0002, "کانال {$channel} نباید برای همه قابل نوشتن باشد.");
        }
    }
}
