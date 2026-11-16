<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Protokolltexte statt roher Aktionscodes („Letzte Aktivitäten“, /admin/audit,
 * Superadmin-Protokoll) und persönliche Einstellungen der Lernenden nicht im
 * Protokoll der Bootsschule.
 */
class AuditActionLabelsTest extends TestCase
{
    use InteractsWithTenants;

    private array $learnerIds = [];

    protected function tearDown(): void
    {
        $this->onAdmin(fn () => AuditLog::whereIn('entity_id', $this->learnerIds)->delete());
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    /**
     * Alle Aktionscodes, die app/ als Zeichenkette an AuditLog::record()
     * (drittes Argument) bzw. AuditLog::recordPersonalSetting() (zweites)
     * übergibt, plus die zusammengesetzten Codes.
     *
     * @return list<string>
     */
    private function recordedActions(): array
    {
        $actions = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = array_values(array_filter(token_get_all(file_get_contents($file->getPathname())),
                fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
            $count = count($tokens);
            for ($i = 0; $i < $count - 3; $i++) {
                $isCall = is_array($tokens[$i]) && in_array($tokens[$i][1], ['AuditLog', 'static', 'self'], true)
                    && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_DOUBLE_COLON
                    && is_array($tokens[$i + 2]) && in_array($tokens[$i + 2][1], ['record', 'recordPersonalSetting'], true) && $tokens[$i + 3] === '(';
                if (! $isCall || ($tokens[$i][1] !== 'AuditLog' && ! str_ends_with($file->getPathname(), 'AuditLog.php'))) {
                    continue;
                }
                $actionArgument = $tokens[$i + 2][1] === 'record' ? 2 : 1;
                $depth = 0;
                $argument = 0;
                $third = [];
                for ($j = $i + 3; $j < $count; $j++) {
                    $token = $tokens[$j];
                    if (in_array($token, ['(', '['], true)) {
                        $depth++;
                        if ($depth === 1) {
                            continue;
                        }
                    } elseif (in_array($token, [')', ']'], true)) {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    } elseif ($depth === 1 && $token === ',') {
                        $argument++;

                        continue;
                    }
                    if ($argument === $actionArgument) {
                        $third[] = $token;
                    }
                }
                if (count($third) === 1 && is_array($third[0]) && $third[0][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $actions[] = trim($third[0][1], '\'"');
                }
            }
        }

        // Zusammengesetzte Codes (TwoFactorController, EntitlementController, PraxisTaskController).
        array_push($actions, 'two_factor.enable', 'two_factor.disable', 'entitlement.suspend', 'entitlement.revoke', 'entitlement.reactivate',
            'superadmin.praxis_task.activate', 'superadmin.praxis_task.deactivate');

        return array_values(array_unique($actions));
    }

    public function test_every_action_written_in_app_has_a_german_text(): void
    {
        $actions = $this->recordedActions();
        $this->assertGreaterThan(50, count($actions), 'Aktionscodes wurden nicht gefunden.');
        $this->assertContains('profile.speech_consent.granted', $actions);

        foreach ($actions as $action) {
            $text = (new AuditLog(['action' => $action]))->description();
            $this->assertStringNotContainsString($action, $text, $action);
            $this->assertStringNotContainsString('hat eine Änderung vorgenommen', $text, "Kein eigener Text für {$action}");
        }
    }

    public function test_unknown_actions_never_show_the_raw_code(): void
    {
        $text = (new AuditLog(['action' => 'something.new_and_unknown']))->description();
        $this->assertSame('System hat eine Änderung vorgenommen.', $text);
    }

    public function test_personal_settings_of_learners_are_not_in_the_school_audit_views(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner', ['display_name' => 'Lena Lernend']);
        $this->learnerIds[] = $learner->id;

        $this->actingAsInTenant($learner, $tenant)->postJson('/profile/speech-consent')->assertOk();
        $this->actingAsInTenant($learner, $tenant)->deleteJson('/profile/speech-consent')->assertOk();
        $this->actingAsInTenant($learner, $tenant)->patch('/profile/mail-preferences', ['learning_reminders' => 0])->assertRedirect();

        $entries = $this->onAdmin(fn () => AuditLog::where('entity_id', $learner->id)->get());
        $this->assertEqualsCanonicalizing(
            ['profile.speech_consent.granted', 'profile.speech_consent.revoked', 'profile.mail_preferences'],
            $entries->pluck('action')->all());
        $this->assertTrue($entries->every(fn (AuditLog $e) => $e->tenant_id === null), 'Persönliche Einstellungen ohne Bootsschule protokollieren.');

        foreach (['/admin', '/admin/audit'] as $url) {
            $this->actingAsInTenant($owner, $tenant)->get($url)->assertOk()
                ->assertDontSee('Spracherkennung eingewilligt')
                ->assertDontSee('Einwilligung zur Spracherkennung widerrufen')
                ->assertDontSee('E-Mail-Benachrichtigungen geändert')
                ->assertDontSee('profile.speech_consent');
        }

        // Superadmin sieht sie als Plattform-Einträge mit lesbarem Text.
        $superadmin = $this->createSuperAdmin();
        $this->actingAs($superadmin)->get('/superadmin/audit?action=profile.speech_consent')->assertOk()
            ->assertSee('Lena Lernend hat in die Spracherkennung eingewilligt.')
            ->assertSee('Lena Lernend hat die Einwilligung zur Spracherkennung widerrufen.')
            ->assertSee('Plattform');
    }

    public function test_school_audit_shows_german_texts_instead_of_codes(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $this->onAdmin(fn () => AuditLog::record($tenant->id, null, 'profile.delete', 'tenant_user', null, null, ['channel' => 'app']));

        $this->actingAsInTenant($owner, $tenant)->get('/admin/audit')->assertOk()
            ->assertSee('Ein Teilnehmer hat das eigene Konto bei der Bootsschule gelöscht.')
            ->assertDontSee('profile.delete');
    }
}
