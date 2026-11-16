<?php

namespace App\Http\Controllers;

use App\Services\AppAccountDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Zuordnungsdateien für Universal Links (iOS) und App Links (Android): Ist
 * die App installiert, öffnet sie die Links aus unseren Mails direkt, sonst
 * landen sie im Browser. Pfade: config/mobile_api.php, app_links.paths; ohne
 * App-Werte 404.
 *
 * Standard ist nur die Bestätigung der Kontolöschung -- die kann jede
 * veröffentlichte App-Version. Passwort festlegen/zurücksetzen, Einladung
 * annehmen und E-Mail bestätigen kommen erst dazu, wenn die App sie
 * verarbeiten kann (sonst öffnete ein installierter, älterer Stand diese
 * Links statt der Webseite und liefe ins Leere): dafür
 * MOBILE_API_APP_LINK_PATHS auf recommendedPathsValue() setzen
 * (deploy/README.md, Abschnitt 10).
 *
 * Auf welchen Hosts: Alle diese Mails bauen ihre Links über CanonicalUrl
 * (AccountSetupLink, ResetPassword/VerifyEmail in AppServiceProvider,
 * TenantInvitationNotification, AppAccountDeletion) -- also immer auf der
 * zentralen Domain (Pfad-Modus: CENTRAL_DOMAIN/<slug>/…) bzw. im
 * Subdomain-Modus auf <slug>.CENTRAL_DOMAIN, nie auf der eigenen Domain
 * einer Schule (Sicherheitsreview S1: deren DNS kann ein Dritter
 * kontrollieren). Die Dateien gibt es deshalb nur auf der zentralen Domain
 * und, wenn TENANT_URL_MODE=subdomain, auf deren Subdomains -- auf eigenen
 * Domains nicht, dort führt kein Mail-Link hin.
 */
class AppLinkAssociationController extends Controller
{
    /** Empfohlene Pfade außer der Kontolöschung (nicht Standard, s. o.): Routennamen samt Hinweis für Apple. */
    private const RECOMMENDED_ROUTES = [
        'password.reset' => 'Passwort festlegen oder zurücksetzen',
        'invitations.accept' => 'Einladung annehmen',
        'verification.verify' => 'E-Mail-Adresse bestätigen',
        // Beitrittslink einer Lerngruppe (LearningGroupService, Infoseite im Web).
        'learning-group-link.show' => 'Lerngruppe beitreten',
    ];

    private const DELETION_COMMENT = 'Bestätigung der Kontolöschung';

    public function apple(Request $request): JsonResponse
    {
        $appIds = self::list(config('mobile_api.app_links.ios_app_ids'));
        $host = $this->servedHost($request);
        abort_if($appIds === [] || $host === null, 404);
        // Schul-Subdomain mit nur der Kontolöschung (Standard): nichts zu öffnen.
        $components = self::components($host === 'central');
        abort_if($components === [], 404);

        return $this->json([
            'applinks' => [
                'details' => [[
                    'appIDs' => $appIds,
                    'components' => $components,
                ]],
            ],
        ]);
    }

    public function android(Request $request): JsonResponse
    {
        $package = trim((string) config('mobile_api.app_links.android_package'));
        $fingerprints = self::list(config('mobile_api.app_links.android_sha256_fingerprints'));
        abort_if($package === '' || $fingerprints === [] || $this->servedHost($request) === null, 404);

        // Android prüft nur die Zuordnung zum Host; die Pfade stehen im
        // Intent-Filter der App (deploy/README.md, Abschnitt 10).
        return $this->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $package,
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]]);
    }

    /**
     * Empfohlene Pfade für eine App, die alle Links aus den Mails verarbeiten
     * kann: Kontolöschung plus die Pfade der Routen aus RECOMMENDED_ROUTES
     * (Routenparameter als „*“).
     *
     * @return array<string, string> Pfad => Hinweis
     */
    public static function recommendedPaths(): array
    {
        $paths = [AppAccountDeletion::confirmationPath() => self::DELETION_COMMENT];
        foreach (self::RECOMMENDED_ROUTES as $name => $comment) {
            $route = Route::getRoutes()->getByName($name);
            if ($route) {
                $paths['/'.preg_replace('/\{[^}]+\}/', '*', trim($route->uri(), '/'))] = $comment;
            }
        }

        return $paths;
    }

    /** Wert für MOBILE_API_APP_LINK_PATHS mit allen empfohlenen Pfaden (siehe deploy/README.md, Abschnitt 10). */
    public static function recommendedPathsValue(): string
    {
        return implode(',', array_keys(self::recommendedPaths()));
    }

    /**
     * Konfigurierte Pfade (MOBILE_API_APP_LINK_PATHS, kommagetrennt) oder
     * der Standard: nur die Bestätigung der Kontolöschung.
     *
     * @return array<string, string> Pfad => Hinweis
     */
    private static function paths(): array
    {
        $deletion = AppAccountDeletion::confirmationPath();
        $configured = self::list(config('mobile_api.app_links.paths'));
        if ($configured === []) {
            return [$deletion => self::DELETION_COMMENT];
        }

        $known = self::recommendedPaths();
        $paths = [];
        foreach ($configured as $path) {
            $path = '/'.ltrim($path, '/');
            $paths[$path] = $known[$path] ?? 'Link aus einer Mail';
        }

        return $paths;
    }

    /**
     * Apple-Format: je Pfad ein Eintrag mit „/“-Muster. Jeder Pfad außer
     * der Kontolöschung zusätzlich mit führendem Schul-Slug (Pfad-Modus
     * /<slug>/…, siehe TenantPath). Die Kontolöschung gibt es nur ohne Slug
     * auf der zentralen Domain (AppAccountDeletionController).
     *
     * @return list<array{'/': string, comment: string}>
     */
    private static function components(bool $central): array
    {
        $deletion = AppAccountDeletion::confirmationPath();
        $components = [];
        foreach (self::paths() as $path => $comment) {
            if ($path === $deletion) {
                if ($central) {
                    $components[] = ['/' => $path, 'comment' => $comment];
                }

                continue;
            }
            $components[] = ['/' => $path, 'comment' => $comment];
            $components[] = ['/' => '/*'.$path, 'comment' => $comment.' (Pfad-Modus /<slug>/…)'];
        }

        return $components;
    }

    /** 'central', 'subdomain' (Schul-Subdomain im Subdomain-Modus) oder null (keine Auslieferung). */
    private function servedHost(Request $request): ?string
    {
        $host = strtolower($request->getHost());
        $central = strtolower((string) config('app.central_domain'));
        if ($host === $central) {
            return 'central';
        }

        // Genau ein Label vor der zentralen Domain (<slug>.<zentrale Domain>).
        $label = str_ends_with($host, '.'.$central) ? substr($host, 0, -strlen('.'.$central)) : '';
        if ($label !== '' && ! str_contains($label, '.') && config('app.tenant_url_mode') === 'subdomain') {
            return 'subdomain';
        }

        return null;
    }

    /** @return list<string> */
    private static function list(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data, 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }
}
