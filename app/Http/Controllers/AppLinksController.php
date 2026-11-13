<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Verknüpfung der Domain mit der Lernenden-App (config/app_links.php):
 * iOS und Android laden diese Dateien direkt (ohne Weiterleitung, als
 * JSON), um Links wie „Passwort festlegen“ in der App zu öffnen. Läuft wie
 * /tls-check außerhalb der web-Gruppe (keine Session, kein Mandant) und
 * gilt damit für die zentrale Domain, Subdomains und eigene Domains der
 * Schulen gleichermaßen. Nicht konfiguriert: 404.
 */
class AppLinksController extends Controller
{
    public function apple(): JsonResponse
    {
        $appIds = (array) config('app_links.ios_app_ids');
        abort_if($appIds === [], 404);
        $paths = (array) config('app_links.paths');

        return $this->json([
            'applinks' => [
                // "apps" ist für iOS 13+ bedeutungslos, älteres iOS verlangt den Schlüssel.
                'apps' => [],
                'details' => [[
                    'appIDs' => $appIds,
                    'components' => array_map(fn ($path) => ['/' => $path], $paths),
                    // Format bis iOS 12.
                    'appID' => $appIds[0],
                    'paths' => $paths,
                ]],
            ],
            // Gespeicherte Passwörter der Website auch in der App anbieten.
            'webcredentials' => ['apps' => $appIds],
        ]);
    }

    public function android(): JsonResponse
    {
        $package = config('app_links.android_package');
        $fingerprints = array_map('strtoupper', (array) config('app_links.android_sha256'));
        abort_if(! $package || $fingerprints === [], 404);

        return $this->json([[
            'relation' => ['delegate_permission/common.handle_all_urls', 'delegate_permission/common.get_login_creds'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $package,
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]]);
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data, 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }
}
