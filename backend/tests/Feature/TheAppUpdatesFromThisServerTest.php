<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub is often blocked from Iran, so the updater asks this server first.
 * The manifest beside the APK is the whole truth; a missing or broken one
 * means «nothing published here», never an error page.
 */
class TheAppUpdatesFromThisServerTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/bakery-downloads-'.uniqid();
        mkdir($this->dir);
        config(['bakery.app_release.manifest' => $this->dir.'/latest.json']);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/latest.json');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function publish(array $manifest): void
    {
        file_put_contents($this->dir.'/latest.json', json_encode($manifest));
    }

    private function release(array $overrides = []): array
    {
        return array_merge([
            'version' => '5.31.0',
            'version_code' => 10514,
            'file' => 'bakery-app-v5.31.0.apk',
            'size' => 48175473,
            'sha256' => 'e113b1719846e363057752457b632966e1b71e0ff78871121d21c3b158e6ee27',
            'notes' => 'نسخهٔ تازه',
        ], $overrides);
    }

    public function test_it_names_the_newest_release_without_signing_in(): void
    {
        $this->publish($this->release());

        $this->getJson('https://baker.molido.ir/api/v1/app/latest')
            ->assertOk()
            ->assertJsonPath('data.version', '5.31.0')
            ->assertJsonPath('data.version_code', 10514)
            ->assertJsonPath('data.size', 48175473)
            ->assertJsonPath('data.sha256', 'e113b1719846e363057752457b632966e1b71e0ff78871121d21c3b158e6ee27')
            ->assertJsonPath('data.notes', 'نسخهٔ تازه')
            ->assertJsonPath('data.url', 'https://baker.molido.ir/download/bakery-app-v5.31.0.apk');
    }

    public function test_a_phone_asking_over_the_ip_downloads_over_the_ip(): void
    {
        $this->publish($this->release());

        $this->getJson('http://37.32.21.125/api/v1/app/latest')
            ->assertOk()
            ->assertJsonPath('data.url', 'http://37.32.21.125/download/bakery-app-v5.31.0.apk');
    }

    public function test_a_leading_v_is_dropped_so_the_app_compares_numbers(): void
    {
        $this->publish($this->release(['version' => 'v5.32.0']));

        $this->getJson('/api/v1/app/latest')->assertJsonPath('data.version', '5.32.0');
    }

    public function test_an_absolute_url_is_used_when_no_file_is_named(): void
    {
        $manifest = $this->release(['url' => 'https://cdn.example/app.apk']);
        unset($manifest['file']);
        $this->publish($manifest);

        $this->getJson('/api/v1/app/latest')->assertJsonPath('data.url', 'https://cdn.example/app.apk');
    }

    public function test_nothing_published_is_a_quiet_404(): void
    {
        $this->getJson('/api/v1/app/latest')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_a_half_written_manifest_reads_as_nothing_published(): void
    {
        file_put_contents($this->dir.'/latest.json', '{"version": "5.3');

        $this->getJson('/api/v1/app/latest')->assertNotFound();
    }

    public function test_a_manifest_with_a_bad_checksum_is_not_trusted(): void
    {
        $this->publish($this->release(['sha256' => 'not-a-hash']));

        $this->getJson('/api/v1/app/latest')->assertNotFound();
    }

    public function test_a_path_in_the_file_name_cannot_point_elsewhere(): void
    {
        $this->publish($this->release(['file' => '../../etc/passwd']));

        $this->getJson('https://baker.molido.ir/api/v1/app/latest')
            ->assertJsonPath('data.url', 'https://baker.molido.ir/download/passwd');
    }

    public function test_it_is_rate_limited(): void
    {
        $this->publish($this->release());

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/app/latest')->assertOk();
        }

        $this->getJson('/api/v1/app/latest')->assertStatus(429);
    }
}
