<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The newest signed APK, as published on this server.
 *
 * Public on purpose: the updater runs before sign-in and on handsets whose
 * session has expired, and what it reveals — a version number and a link to
 * a file anyone can already download — is not a secret.
 *
 * The link is built from the address the phone used to ask, not from the
 * manifest: handsets built against the IP keep downloading over the IP, and
 * the ones on baker.molido.ir over the name.
 */
class AppReleaseController extends Controller
{
    use ApiResponse;

    public function latest(Request $request): JsonResponse
    {
        $manifest = self::manifest();

        if ($manifest === null) {
            return $this->error('نسخه‌ای روی این سرور منتشر نشده است.', 404);
        }

        $prefix = '/'.trim((string) config('bakery.app_release.download_path', '/download'), '/');
        $url = isset($manifest['file'])
            ? $request->getSchemeAndHttpHost().$prefix.'/'.rawurlencode(basename((string) $manifest['file']))
            : (string) ($manifest['url'] ?? '');

        return $this->success([
            'version' => ltrim((string) $manifest['version'], 'v'),
            'version_code' => isset($manifest['version_code']) ? (int) $manifest['version_code'] : null,
            'url' => $url,
            'size' => (int) ($manifest['size'] ?? 0),
            'sha256' => strtolower((string) ($manifest['sha256'] ?? '')) ?: null,
            'notes' => isset($manifest['notes']) ? (string) $manifest['notes'] : null,
        ]);
    }

    /**
     * The manifest, or null when it is missing, unreadable or incomplete.
     * A half-written file must read as «nothing published», never as a 500.
     */
    public static function manifest(): ?array
    {
        $path = (string) config('bakery.app_release.manifest');

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);

        if (! is_array($data) || empty($data['version'])) {
            return null;
        }

        if (empty($data['file']) && empty($data['url'])) {
            return null;
        }

        if (isset($data['sha256']) && ! preg_match('/^[0-9a-fA-F]{64}$/', (string) $data['sha256'])) {
            return null;
        }

        return $data;
    }
}
