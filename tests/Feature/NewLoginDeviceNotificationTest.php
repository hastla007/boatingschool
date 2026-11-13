<?php

namespace Tests\Feature;

use App\Notifications\NewLoginDeviceNotification;
use App\Services\NewLoginDeviceNotifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Hinweis-Mail bei Anmeldung von einem neuen Gerät für Team-Rollen und
 * Superadmins (NewLoginDeviceNotifier): erste Anmeldung überhaupt nur
 * merken, bekanntes Gerät still, neues Gerät -> Mail. Lernende nie.
 */
class NewLoginDeviceNotificationTest extends TestCase
{
    use InteractsWithTenants;

    private const FIREFOX_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';

    private const SAFARI_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdUserIds as $id) {
                DB::table('api_device_session')->where('user_id', $id)->delete();
            }
        });
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function webLogin(string $email, string $userAgent, string $ip, string $tenantSlug): void
    {
        Auth::forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent])
            ->withSession(['dev_tenant_slug' => $tenantSlug])
            ->post('/login', ['email' => $email, 'password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/logout');
    }

    private function devices(string $userId): int
    {
        return $this->onAdmin(fn () => DB::table('known_login_device')->where('user_id', $userId)->count());
    }

    public function test_an_owner_is_notified_only_for_a_new_device_and_not_on_the_first_login(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        // Allererste Anmeldung: nur merken.
        $this->webLogin($owner->email, self::FIREFOX_WINDOWS, '203.0.113.7', $tenant->slug);
        $this->assertSame(1, $this->devices($owner->id));
        Notification::assertNothingSentTo($owner);

        // Gleiches Gerät, andere Adresse im selben /24-Netz: weiter bekannt.
        $this->webLogin($owner->email, self::FIREFOX_WINDOWS, '203.0.113.99', $tenant->slug);
        $this->assertSame(1, $this->devices($owner->id));
        Notification::assertNothingSentTo($owner);

        // Neues Gerät: Hinweis-Mail.
        $this->webLogin($owner->email, self::SAFARI_MAC, '198.51.100.23', $tenant->slug);
        $this->assertSame(2, $this->devices($owner->id));
        Notification::assertSentTo($owner, NewLoginDeviceNotification::class, function (NewLoginDeviceNotification $n) use ($owner) {
            $mail = $n->toMail($owner);
            $text = implode("\n", $mail->introLines);

            return $mail->subject === 'Neue Anmeldung bei Ihrem Konto'
                && str_contains($text, 'Safari unter macOS')
                && str_contains($text, '198.51.100.x')
                && ! str_contains($text, '198.51.100.23')
                && str_contains($text, 'Uhr')
                && str_contains($text, 'Passwort');
        });

        // Gespeichert ist nur ein Hash, keine IP-Adresse und kein User-Agent.
        $columns = Schema::connection('pgsql_admin')->getColumnListing('known_login_device');
        $this->assertEqualsCanonicalizing(['id', 'user_id', 'fingerprint', 'first_seen_at', 'last_seen_at'], $columns);
        $row = $this->onAdmin(fn () => (array) DB::table('known_login_device')->where('user_id', $owner->id)->first());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['fingerprint']);
        $this->assertStringNotContainsString('203.0.113', implode('|', array_map('strval', $row)));
    }

    public function test_learners_are_not_tracked_or_notified(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $this->webLogin($learner->email, self::FIREFOX_WINDOWS, '203.0.113.7', $tenant->slug);
        $this->webLogin($learner->email, self::SAFARI_MAC, '198.51.100.23', $tenant->slug);

        $this->assertSame(0, $this->devices($learner->id));
        Notification::assertNothingSent();
    }

    public function test_an_app_login_from_a_new_device_notifies_an_instructor(): void
    {
        $tenant = $this->createTestTenant();
        $instructor = $this->createTenantUser($tenant, 'instructor');
        $this->webLogin($instructor->email, self::FIREFOX_WINDOWS, '203.0.113.7', $tenant->slug);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44', 'HTTP_USER_AGENT' => 'okhttp/4.12.0'])
            ->postJson('/api/v1/auth/login', ['email' => $instructor->email, 'password' => 'password', 'device_name' => 'Pixel', 'device_platform' => 'android'])
            ->assertOk();

        Notification::assertSentTo($instructor, NewLoginDeviceNotification::class,
            fn (NewLoginDeviceNotification $n) => $n->browser === 'Lern-App' && $n->os === 'Android' && $n->maskedIp === '192.0.2.x');
        $this->assertSame(2, $this->devices($instructor->id));
    }

    public function test_a_superadmin_is_tracked_and_erasing_the_account_removes_the_devices(): void
    {
        $tenant = $this->createTestTenant();
        $superadmin = $this->createSuperAdmin();
        $this->webLogin($superadmin->email, self::FIREFOX_WINDOWS, '203.0.113.7', $tenant->slug);
        $this->webLogin($superadmin->email, self::SAFARI_MAC, '203.0.113.7', $tenant->slug);

        Notification::assertSentToTimes($superadmin, NewLoginDeviceNotification::class, 1);
        $this->assertSame(2, $this->devices($superadmin->id));

        $this->onAdmin(fn () => $superadmin->delete());
        $this->assertSame(0, $this->devices($superadmin->id));
    }

    public function test_ip_helpers_mask_and_shorten_addresses(): void
    {
        $this->assertSame('203.0.113.x', NewLoginDeviceNotifier::maskIp('203.0.113.200'));
        $this->assertSame('2001:db8:1::/48', NewLoginDeviceNotifier::maskIp('2001:db8:1:2:3:4:5:6'));
        $this->assertSame(NewLoginDeviceNotifier::ipPrefix('203.0.113.1'), NewLoginDeviceNotifier::ipPrefix('203.0.113.254'));
        $this->assertNotSame(NewLoginDeviceNotifier::ipPrefix('203.0.113.1'), NewLoginDeviceNotifier::ipPrefix('203.0.114.1'));
        $this->assertSame(['browser' => 'Firefox', 'os' => 'Windows'], NewLoginDeviceNotifier::describe(self::FIREFOX_WINDOWS));
    }
}
