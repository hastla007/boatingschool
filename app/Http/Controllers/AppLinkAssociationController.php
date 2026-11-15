<?php

namespace App\Http\Controllers;

use App\Services\AppAccountDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Zuordnungsdateien für Universal Links (iOS) und App Links (Android): Ist
 * die App installiert, öffnet sie den Bestätigungslink der Kontolöschung
 * direkt, sonst landet er auf der Web-Bestätigung. Nur auf der zentralen
 * Domain und nur mit konfigurierten Werten (config/mobile_api.php, app_links),
 * sonst 404.
 */
class AppLinkAssociationController extends Controller
{
    public function apple(Request $request): JsonResponse
    {
        $appIds = $this->list(config('mobile_api.app_links.ios_app_ids'));
        abort_if($appIds === [] || ! $this->onCentralDomain($request), 404);

        return $this->json([
            'applinks' => [
                'details' => [[
                    'appIDs' => $appIds,
                    'components' => [['/' => AppAccountDeletion::confirmationPath(), 'comment' => 'Bestätigung der Kontolöschung']],
                ]],
            ],
        ]);
    }

    public function android(Request $request): JsonResponse
    {
        $package = trim((string) config('mobile_api.app_links.android_package'));
        $fingerprints = $this->list(config('mobile_api.app_links.android_sha256_fingerprints'));
        abort_if($package === '' || $fingerprints === [] || ! $this->onCentralDomain($request), 404);

        return $this->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $package,
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]]);
    }

    private function onCentralDomain(Request $request): bool
    {
        return strtolower($request->getHost()) === strtolower((string) config('app.central_domain'));
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data, 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }
}
