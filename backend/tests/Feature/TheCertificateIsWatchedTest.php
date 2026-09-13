<?php

namespace Tests\Feature;

use App\Support\IssueScanner;
use App\Support\SystemIssue;
use Database\Seeders\BakerySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The issues page sees the certificate before the phones do.
 *
 * certbot renews sixty days in and fails quietly when it cannot; the
 * site then works right up to the day it does not. The scanner reads the
 * certificate's own expiry off disk, so a missed renewal is a line on
 * «امروز» three weeks early rather than a morning of «سرور خراب است».
 */
class TheCertificateIsWatchedTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BakerySeeder::class);

        $this->dir = sys_get_temp_dir().'/cert-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** A self-signed certificate good for this many days, written to disk. */
    private function certificate(int $days): string
    {
        $path = $this->dir.'/fullchain.pem';

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'baker.test'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);

        file_put_contents($path, $pem);

        return $path;
    }

    private function issue(): ?SystemIssue
    {
        return (new IssueScanner)->scan()->firstWhere('key', 'certificate-running-out');
    }

    public function test_a_certificate_with_two_months_left_is_not_an_issue(): void
    {
        config(['bakery.tls_certificate' => $this->certificate(60)]);

        $this->assertNull($this->issue());
    }

    public function test_three_weeks_left_is_a_warning(): void
    {
        config(['bakery.tls_certificate' => $this->certificate(20)]);

        $issue = $this->issue();

        $this->assertNotNull($issue);
        $this->assertSame(SystemIssue::WARNING, $issue->severity);
        $this->assertStringContainsString('روز دیگر', $issue->title);
        $this->assertStringContainsString('certbot renew --dry-run', $issue->suggestion);
    }

    public function test_a_week_left_is_critical(): void
    {
        config(['bakery.tls_certificate' => $this->certificate(5)]);

        $this->assertSame(SystemIssue::CRITICAL, $this->issue()?->severity);
    }

    public function test_a_machine_without_the_file_has_no_such_issue(): void
    {
        // Every developer's laptop, and the test suite itself.
        config(['bakery.tls_certificate' => $this->dir.'/nowhere.pem']);

        $this->assertNull($this->issue());
    }

    public function test_a_file_that_is_not_a_certificate_is_ignored(): void
    {
        // Wrong is worse than absent here: a warning built from garbage
        // would send somebody to the server for nothing.
        file_put_contents($this->dir.'/junk.pem', 'not a certificate');
        config(['bakery.tls_certificate' => $this->dir.'/junk.pem']);

        $this->assertNull($this->issue());
    }
}
