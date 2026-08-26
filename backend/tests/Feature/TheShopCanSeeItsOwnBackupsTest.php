<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «سیستم بک‌آپ در اپلیکیشن مدیر فعال بشه».
 *
 * The dumps have run twice a day for weeks and there was no way to see
 * that without an ssh session — so no way to notice if they stopped. That
 * is the failure mode worth guarding: not a backup that fails loudly, but
 * one that quietly is not happening while everything looks fine.
 */
class TheShopCanSeeItsOwnBackupsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->dir = storage_path('app/backups');

        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0750, true);
        }

        // A previous run's files would make these assertions meaningless.
        foreach (glob("{$this->dir}/*.sql.gz") ?: [] as $stale) {
            unlink($stale);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/*.sql.gz") ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function dump(string $name, int $hoursAgo, int $bytes = 28_000): void
    {
        $path = "{$this->dir}/{$name}";
        file_put_contents($path, str_repeat('x', $bytes));
        touch($path, now()->subHours($hoursAgo)->timestamp);
    }

    /**
     * Named `reading` rather than `status`, which is final on PHPUnit's
     * TestCase and produces a fatal error at parse time rather than a
     * failing test. `post` was the same trap earlier in this project.
     */
    private function reading(): array
    {
        return $this->actingAs($this->admin())
            ->getJson('/api/v1/backups')
            ->assertOk()
            ->json('data');
    }

    public function test_it_reports_when_the_last_backup_was_taken(): void
    {
        $this->dump('bakery_db_2026-08-26_053001.sql.gz', 3);
        $this->dump('bakery_db_2026-08-25_130002.sql.gz', 20);

        $status = $this->reading();

        $this->assertSame(2, $status['count']);
        $this->assertSame(3, $status['hours_since']);
        $this->assertNotNull($status['latest_at_display']);
    }

    public function test_a_backup_from_this_morning_is_not_stale(): void
    {
        $this->dump('bakery_db_2026-08-26_053001.sql.gz', 6);

        $this->assertFalse($this->reading()['is_stale']);
    }

    public function test_missing_two_scheduled_runs_is_stale(): void
    {
        // Twice a day, so a day and a half without one means at least two
        // runs did not happen. That is the quiet failure this exists for.
        $this->dump('bakery_db_2026-08-24_053001.sql.gz', 40);

        $this->assertTrue($this->reading()['is_stale']);
    }

    public function test_no_backups_at_all_is_stale_rather_than_calm(): void
    {
        $status = $this->reading();

        $this->assertSame(0, $status['count']);
        $this->assertNull($status['latest_at']);
        // An empty folder read as «fine» is the worst answer available.
        $this->assertTrue($status['is_stale']);
    }

    public function test_it_lists_the_recent_ones_newest_first(): void
    {
        $this->dump('older.sql.gz', 30);
        $this->dump('newest.sql.gz', 1);
        $this->dump('middle.sql.gz', 12);

        $names = array_column($this->reading()['recent'], 'name');

        $this->assertSame(['newest.sql.gz', 'middle.sql.gz', 'older.sql.gz'], $names);
    }

    public function test_the_list_does_not_grow_without_end(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->dump("backup_{$i}.sql.gz", $i + 1);
        }

        // Sixty are kept on disk. A phone screen listing sixty filenames
        // is a screen nobody reads; the count and the total say the rest.
        $this->assertCount(5, $this->reading()['recent']);
        $this->assertSame(12, $this->reading()['count']);
    }

    public function test_the_file_itself_is_not_downloadable(): void
    {
        $this->dump('bakery_db_2026-08-26_053001.sql.gz', 2);

        // Wages, debts and every customer in one file over the API. There
        // is no route for it and there should not be.
        $this->actingAs($this->admin())
            ->getJson('/api/v1/backups/bakery_db_2026-08-26_053001.sql.gz')
            ->assertNotFound();
    }

    public function test_staff_cannot_read_the_backup_status(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller)
            ->getJson('/api/v1/backups')
            ->assertForbidden();
    }

    public function test_staff_cannot_trigger_one(): void
    {
        $seller = User::factory()->create(['is_active' => true]);
        $seller->assignRole('seller');

        $this->actingAs($seller)
            ->postJson('/api/v1/backups')
            ->assertForbidden();
    }
}
