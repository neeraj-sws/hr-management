<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

/**
 * The desktop app's update check. The administrator publishes a release by
 * setting DESKTOP_LATEST_VERSION, DESKTOP_DOWNLOAD_URL (https) and
 * DESKTOP_SHA256 in .env; the app refuses an installer whose checksum differs.
 */
class DesktopReleaseController extends ApiController
{
    public function latest(): JsonResponse
    {
        $release = config('services.desktop');
        $published = ! empty($release['version']) && ! empty($release['url']) && preg_match('/^[a-f0-9]{64}$/i', (string) ($release['sha256'] ?? ''));

        return response()->json(['data' => $published ? ['version' => $release['version'], 'url' => $release['url'], 'sha256' => strtolower($release['sha256']), 'notes' => $release['notes'] ?? null] : null]);
    }
}
