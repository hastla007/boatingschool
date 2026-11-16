<?php

namespace Tests\Feature\Api;

use App\Models\CourseDefinition;
use App\Models\CourseWebshopLink;
use App\Models\Entitlement;
use App\Models\LearningGroup;
use App\Models\LearningGroupMember;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\EntitlementExpiringSoon;
use App\Services\LearningGroupService;
use App\Support\MailTemplates;
use Illuminate\Support\Facades\Notification;

/**
 * Ablauf-Hinweise für reine Gruppenzugänge (Lerngruppe der Plattform oder
 * aus WordPress übernommen): Dashboard, Kursübersicht, Mail und App ohne
 * Kauf-/Code-Hinweis; ein eigener, länger laufender Zugang behält ihn.
 */
class GroupAccessExpiryNoticeTest extends MobileApiTestCase
{
    private const SHOP = 'https://shop.example.test/verlaengerung';

    private const COURSE_SHOP = 'https://shop.example.test/src';

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => PlatformSetting::query()->update(['mail_templates' => null]));
        MailTemplates::flush();
        parent::tearDown();
    }

    /** Schule mit Verlängerungs-Shop und Kauf-Link für SRC. */
    private function school(): Tenant
    {
        $tenant = $this->createTestTenant();
        $this->onAdmin(function () use ($tenant) {
            $tenant->branding()->update(['renewal_webshop_link' => self::SHOP]);
            CourseWebshopLink::create(['tenant_id' => $tenant->id, 'course_id' => $this->existingCourse('SRC')->id, 'url' => self::COURSE_SHOP]);
        });

        return $tenant;
    }

    /** Laufende Lerngruppe mit der Person als Mitglied und ihrem Gruppenzugang. */
    private function groupAccess(Tenant $tenant, User $user, string $name, int $days): Entitlement
    {
        return $this->onAdmin(function () use ($tenant, $user, $name, $days) {
            $owner = $this->createTenantUser($tenant, 'learner');
            $group = LearningGroup::create([
                'tenant_id' => $tenant->id, 'owner_user_id' => $owner->id, 'name' => $name,
                'status' => 'active', 'seats' => 3, 'scopes' => [['course_code' => 'SRC', 'feature' => 'course']],
                'valid_from' => now()->subMonth(), 'valid_until' => now()->addDays($days),
                'join_code' => LearningGroupService::newJoinCode(),
            ]);
            $member = LearningGroupMember::create([
                'tenant_id' => $tenant->id, 'group_id' => $group->id, 'user_id' => $user->id, 'email' => $user->email,
                'status' => 'active', 'joined_at' => now()->subMonth(),
            ]);

            return Entitlement::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $this->existingCourse('SRC')->id,
                'status' => 'active', 'valid_from' => now()->subMonth(), 'valid_until' => now()->addDays($days),
                'source_type' => 'group', 'source_reference' => 'group:'.$group->id.':'.$member->id,
            ]);
        });
    }

    /** Aus WordPress übernommener Gruppenplatz (ImportMobileEntitlements, Format 2). */
    private function importedGroupAccess(Tenant $tenant, User $user, int $days): Entitlement
    {
        return $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $this->existingCourse('SRC')->id,
            'status' => 'active', 'valid_from' => now()->subMonth(), 'valid_until' => now()->addDays($days),
            'source_type' => 'wordpress', 'source_reference' => 'wp-group-'.$user->id,
            'import_meta' => ['source' => 'group', 'group_id' => 4711, 'order_id' => null, 'status' => 'active'],
        ]));
    }

    private function ownAccess(Tenant $tenant, User $user, int $days, string $source = 'manual'): Entitlement
    {
        return $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $this->existingCourse('SRC')->id,
            'status' => 'active', 'valid_from' => now()->subMonth(), 'valid_until' => now()->addDays($days),
            'source_type' => $source,
        ]));
    }

    private function date(Entitlement $entitlement): string
    {
        return $entitlement->valid_until->timezone('Europe/Berlin')->format('d.m.Y');
    }

    private function course(): CourseDefinition
    {
        return $this->existingCourse('SRC');
    }

    /** access-Block des Kurses aus GET /courses. */
    private function apiAccess(Tenant $tenant, User $user): array
    {
        $entry = collect($this->api('GET', '/courses', $user, $tenant)->assertOk()->json('data'))->firstWhere('id', $this->course()->id);

        return $entry['access'];
    }

    /** Gerenderter Text der Ablauf-Erinnerung (Betreff + Mail). */
    private function expiryMailText(User $user): string
    {
        $mail = Notification::sent($user, EntitlementExpiringSoon::class)->sole()->toMail($user);

        return $mail->subject."\n".implode("\n", [...$mail->introLines, (string) $mail->actionText, (string) $mail->actionUrl, ...$mail->outroLines]);
    }

    public function test_dashboard_shows_the_group_notice_without_code_or_purchase_link_for_pure_group_access(): void
    {
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $grant = $this->groupAccess($tenant, $user, 'Jugend (Nord)', 5);
        $name = $this->course()->name;

        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk()
            ->assertSee("Dein Zugang zu „{$name}“ über die Lerngruppe „Jugend (Nord)“ endet am {$this->date($grant)}.", false)
            ->assertSee('Für eine Verlängerung wende dich an deine Bootsschule.')
            ->assertDontSee('Verlängerungscode einlösen')
            ->assertDontSee('läuft am '.$this->date($grant).' ab', false);

        // Kursübersicht: ebenso kein Kauf-Knopf.
        $this->actingAsInTenant($user, $tenant)->get('/courses')->assertOk()
            ->assertSee('über die Lerngruppe „Jugend (Nord)“ endet am', false)
            ->assertDontSee('Verlängerungs-Code kaufen')
            ->assertDontSee(self::SHOP);

        // App: kein Verlängerungslink, der Ablauf selbst bleibt sichtbar.
        $access = $this->apiAccess($tenant, $user);
        $this->assertTrue($access['expires_soon']);
        $this->assertNotNull($access['expires_at']);
        $this->assertNull($access['renew_url']);
    }

    public function test_own_access_keeps_the_code_and_purchase_hints(): void
    {
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $own = $this->ownAccess($tenant, $user, 5);

        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk()
            ->assertSee('läuft am '.$this->date($own).' ab.', false)
            ->assertSee('Verlängerungscode einlösen')
            ->assertDontSee('Lerngruppe');
        $this->actingAsInTenant($user, $tenant)->get('/courses')->assertOk()
            ->assertSee('Verlängerungs-Code kaufen')->assertSee(self::SHOP);
        $this->assertSame(self::COURSE_SHOP, $this->apiAccess($tenant, $user)['renew_url']);
    }

    public function test_own_access_lasting_longer_than_the_group_gets_the_normal_notice(): void
    {
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $this->groupAccess($tenant, $user, 'Jugend', 3);
        $own = $this->ownAccess($tenant, $user, 10, 'coupon');

        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk()
            ->assertSee('läuft am '.$this->date($own).' ab.', false)
            ->assertSee('Verlängerungscode einlösen')
            ->assertDontSee('Lerngruppe');
        $this->actingAsInTenant($user, $tenant)->get('/courses')->assertOk()
            ->assertSee('Verlängerungs-Code kaufen')->assertDontSee('Lerngruppe');
        $this->assertSame(self::COURSE_SHOP, $this->apiAccess($tenant, $user)['renew_url']);

        // Gleiches Ende: der eigene Zugang zählt (der Code verlängert ihn).
        $this->onAdmin(fn () => Entitlement::where('user_id', $user->id)->update(['valid_until' => $own->valid_until]));
        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk()
            ->assertSee('Verlängerungscode einlösen')->assertDontSee('Lerngruppe');
        $this->assertSame(self::COURSE_SHOP, $this->apiAccess($tenant, $user)['renew_url']);
    }

    public function test_imported_wordpress_group_access_gets_the_general_group_text_on_dashboard_and_in_the_mail(): void
    {
        Notification::fake();
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $grant = $this->importedGroupAccess($tenant, $user, 5);
        $name = $this->course()->name;

        $this->actingAsInTenant($user, $tenant)->get('/dashboard')->assertOk()
            ->assertSee("Dein Zugang zu „{$name}“ über deine Lerngruppe endet am {$this->date($grant)}.", false)
            ->assertSee('Für eine Verlängerung wende dich an deine Bootsschule.')
            ->assertDontSee('Verlängerungscode einlösen');
        $this->actingAsInTenant($user, $tenant)->get('/courses')->assertOk()
            ->assertSee('über deine Lerngruppe endet am', false)->assertDontSee('Verlängerungs-Code kaufen');
        $this->assertNull($this->apiAccess($tenant, $user)['renew_url']);

        $this->artisan('app:remind-expiring-entitlements')->assertSuccessful();

        $text = $this->expiryMailText($user);
        $this->assertStringContainsString("Dein Zugang über die Lerngruppe endet am {$this->date($grant)}", $text);
        $this->assertStringContainsString("Dein Zugang zu „{$name}“ über deine Lerngruppe bei", $text);
        $this->assertStringContainsString('wende dich bitte an sie', $text);
        $this->assertStringNotContainsString('„“', $text);
        $this->assertStringNotContainsString('kaufen', $text);
        $this->assertStringNotContainsString('Verlängerungs-Code', $text);
        $this->assertStringNotContainsString('shop.example.test', $text);
        $this->assertNotNull($this->onAdmin(fn () => Entitlement::find($grant->id))->expiry_reminded_at);
    }

    public function test_template_texts_with_the_group_name_are_left_out_when_the_group_is_unknown(): void
    {
        Notification::fake();
        $this->onAdmin(fn () => PlatformSetting::current()->update(['mail_templates' => [
            'group_entitlement_expiring' => ['subject' => 'Gruppe „{gruppe}“ endet am {datum}', 'intro' => 'Hallo aus „{gruppe}“!', 'outro' => 'Dein Team von {schule}'],
        ]]));
        MailTemplates::flush();
        $tenant = $this->school();
        $user = $this->createTenantUser($tenant, 'learner');
        $grant = $this->importedGroupAccess($tenant, $user, 5);

        $this->artisan('app:remind-expiring-entitlements')->assertSuccessful();

        $mail = Notification::sent($user, EntitlementExpiringSoon::class)->sole()->toMail($user);
        $this->assertSame("Dein Zugang über die Lerngruppe endet am {$this->date($grant)}", $mail->subject, 'Standardbetreff statt Lücke');
        $this->assertStringStartsWith('Dein Zugang zu', $mail->introLines[0], 'Text davor mit {gruppe} entfällt');
        $this->assertContains('Dein Team von '.$tenant->name, $mail->outroLines, 'Texte ohne {gruppe} bleiben');
        $this->assertStringNotContainsString('{gruppe}', implode("\n", [$mail->subject, ...$mail->introLines, ...$mail->outroLines]));
    }
}
