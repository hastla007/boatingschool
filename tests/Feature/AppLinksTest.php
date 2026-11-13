<?php

namespace Tests\Feature;

use App\Models\TenantBranding;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** /.well-known/apple-app-site-association und /.well-known/assetlinks.json für App-Deep-Links. */
class AppLinksTest extends TestCase
{
    use InteractsWithTenants;

    private const APPLE = '/.well-known/apple-app-site-association';

    private const ANDROID = '/.well-known/assetlinks.json';

    public function test_both_files_are_404_when_no_app_is_configured(): void
    {
        config(['app_links.ios_app_ids' => [], 'app_links.android_package' => null, 'app_links.android_sha256' => []]);

        $this->get('http://'.config('app.central_domain').self::APPLE)->assertNotFound();
        $this->get('http://'.config('app.central_domain').self::ANDROID)->assertNotFound();

        // Paketname ohne Fingerabdruck reicht nicht.
        config(['app_links.android_package' => 'de.example.app']);
        $this->get('http://'.config('app.central_domain').self::ANDROID)->assertNotFound();
    }

    public function test_apple_app_site_association_lists_app_ids_and_paths_as_json(): void
    {
        config(['app_links.ios_app_ids' => ['TEAMID.de.example.app'], 'app_links.paths' => ['/reset-password/*', '/*/reset-password/*']]);

        $response = $this->get('http://'.config('app.central_domain').self::APPLE)->assertOk();

        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
        $this->assertFalse($response->headers->has('Set-Cookie'), 'ohne Session');
        $this->assertSame(['TEAMID.de.example.app'], $response->json('applinks.details.0.appIDs'));
        $this->assertSame([['/' => '/reset-password/*'], ['/' => '/*/reset-password/*']], $response->json('applinks.details.0.components'));
        $this->assertSame('TEAMID.de.example.app', $response->json('applinks.details.0.appID'));
        $this->assertSame(['TEAMID.de.example.app'], $response->json('webcredentials.apps'));
        $this->assertStringContainsString('"/reset-password/*"', $response->getContent(), 'Schrägstriche nicht maskiert');
    }

    public function test_assetlinks_lists_package_and_fingerprints(): void
    {
        config(['app_links.android_package' => 'de.example.app', 'app_links.android_sha256' => ['aa:bb:cc', 'DD:EE:FF']]);

        $response = $this->get('http://'.config('app.central_domain').self::ANDROID)->assertOk();

        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('android_app', $response->json('0.target.namespace'));
        $this->assertSame('de.example.app', $response->json('0.target.package_name'));
        $this->assertSame(['AA:BB:CC', 'DD:EE:FF'], $response->json('0.target.sha256_cert_fingerprints'));
        $this->assertContains('delegate_permission/common.handle_all_urls', $response->json('0.relation'));
    }

    public function test_files_are_served_on_school_subdomains_and_custom_domains_without_redirect(): void
    {
        config(['app_links.ios_app_ids' => ['TEAMID.de.example.app'], 'app_links.android_package' => 'de.example.app', 'app_links.android_sha256' => ['AA:BB']]);
        $tenant = $this->createTestTenant('Deep Link Schule');
        $this->onAdmin(fn () => TenantBranding::where('tenant_id', $tenant->id)->update(['custom_domain' => 'lernen.deeplink-schule.test']));

        foreach (["{$tenant->slug}.".config('app.central_domain'), 'lernen.deeplink-schule.test'] as $host) {
            $this->get("http://{$host}".self::APPLE)->assertOk()->assertJsonPath('applinks.details.0.appIDs.0', 'TEAMID.de.example.app');
            $this->get("http://{$host}".self::ANDROID)->assertOk()->assertJsonPath('0.target.package_name', 'de.example.app');
        }
    }

    public function test_config_reads_comma_separated_environment_values(): void
    {
        $config = (function () {
            putenv('MOBILE_APP_IOS_APP_ID=TEAM.de.a, TEAM.de.b');
            putenv('MOBILE_APP_ANDROID_SHA256=AA:BB,CC:DD');
            try {
                return require config_path('app_links.php');
            } finally {
                putenv('MOBILE_APP_IOS_APP_ID');
                putenv('MOBILE_APP_ANDROID_SHA256');
            }
        })();

        $this->assertSame(['TEAM.de.a', 'TEAM.de.b'], $config['ios_app_ids']);
        $this->assertSame(['AA:BB', 'CC:DD'], $config['android_sha256']);
        $this->assertContains('/*/reset-password/*', $config['paths']);
    }
}
