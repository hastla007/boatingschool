<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Architekturtest: Die Owner-Verbindung "pgsql_admin" umgeht Row Level
 * Security, withoutGlobalScopes() entfernt die anwendungsseitige
 * Mandantenfilterung (BelongsToTenant) -- beides darf nur in bewusst
 * freigegebenen Dateien vorkommen. Neue Verwendung: Begründung prüfen und
 * hier eintragen (siehe docs/architektur/owner-verbindung.md).
 *
 * Kommentare werden vor der Suche entfernt, damit Erklärtexte nicht zählen.
 */
class OwnerConnectionUsageTest extends TestCase
{
    /** Datei (relativ zum Projekt) => Begründung in einer Zeile. */
    private const ALLOWLIST = [
        // --- Infrastruktur: die Owner-Verbindung selbst ---
        'app/Support/OwnerConnection.php' => 'Der Helfer selbst (setzt und restauriert database.default).',
        'app/Http/Middleware/UseAdminDatabaseConnection.php' => 'Middleware "admin-db" für Superadmin- und Commerce-Routen.',
        // --- Superadmin (läuft hinter admin-db, bewusst mandantenübergreifend) ---
        'app/Http/Controllers/Superadmin/CouponController.php' => 'Kurswahl nur aus dem Plattform-Katalog (tenant_id NULL).',
        'app/Http/Controllers/Superadmin/CourseEditorController.php' => 'Plattform-Katalog pflegen, aktive Lernende über alle Schulen zählen.',
        'app/Http/Controllers/Superadmin/DashboardController.php' => 'Plattformweite Kennzahl: Anzahl Katalogkurse.',
        'app/Http/Controllers/Superadmin/ImpersonationController.php' => 'Mitgliedschaft in beliebiger Schule vor „Als Nutzer ansehen“ prüfen.',
        'app/Http/Controllers/Superadmin/NavigationTaskEditorController.php' => 'Geänderte Musterlösung: Bewertungen in allen Schulen zurücksetzen.',
        'app/Http/Controllers/Superadmin/PraxisFormTemplateController.php' => 'Formularvorlagen verweisen auf Kurse des Plattform-Katalogs.',
        'app/Http/Controllers/Superadmin/PruefungsausschussController.php' => 'Kurswahl nur aus dem Plattform-Katalog.',
        'app/Http/Controllers/Superadmin/ShopAccountController.php' => 'Shopkonten beliebiger Schulen verknüpfen/prüfen.',
        'app/Http/Controllers/Superadmin/TenantController.php' => 'Schule anlegen: Inhaberkonto plattformweit per E-Mail finden.',
        'app/Http/Controllers/Superadmin/UserAccountController.php' => 'Mitgliedschaften eines Kontos über alle Schulen verwalten.',
        'app/Http/Controllers/Superadmin/UserController.php' => 'Rollenänderung in beliebiger Schule (bestehende Mitgliedschaft lesen).',
        // --- Commerce-API (signierte Shop-Aufrufe ohne Mandantenkontext, admin-db) ---
        'app/Commerce/Outbox.php' => 'Ausgehende Shop-Ereignisse aller Schulen (Queue-Worker ohne Mandant).',
        'app/Commerce/ShopAccounts.php' => 'Verknüpfungscode einlösen: Schule ergibt sich erst aus dem Code.',
        'app/Commerce/ShopOrders.php' => 'Bestellungen aus dem Shop: Schule ergibt sich erst aus dem Shopkonto.',
        'app/Http/Controllers/Commerce/CommerceController.php' => 'Code-Batches per Shop-ID/Bestellung nachschlagen (kein Mandantenkontext).',
        'app/Models/CommerceShopAccount.php' => 'Kaufberechtigung prüfen, auch aus Shop-Aufrufen ohne Mandant.',
        // --- Konsolenbefehle (Cron/Deploy, laufen ohne Mandantenkontext) ---
        'app/Console/Commands/AnonymizeDatabase.php' => 'Staging-Kopie anonymisieren: personenbezogene Daten aller Schulen ersetzen (nie in production).',
        'app/Console/Commands/AuditMissingRequiredImages.php' => 'Inhaltsprüfung über den gesamten Fragenkatalog.',
        'app/Console/Commands/BackupApplication.php' => 'pg_dump mit den Zugangsdaten der Owner-Rolle.',
        'app/Services/BackupVerifier.php' => 'Probe-Wiederherstellung: temporäre Datenbank als Owner anlegen, befüllen, löschen.',
        'app/Console/Commands/CreateSuperAdmin.php' => 'Superadmin-Konto anlegen (vor jeder Schule).',
        'app/Console/Commands/ExpireAbandonedExamSessions.php' => 'Verwaiste Prüfungen aller Schulen beenden.',
        'app/Console/Commands/ExpireEntitlements.php' => 'Abgelaufene Zugänge aller Schulen markieren.',
        'app/Console/Commands/ImportContentFeedback.php' => 'Plattformweiter Inhaltsimport.',
        'app/Console/Commands/ImportContentQuestions.php' => 'Plattformweiter Inhaltsimport.',
        'app/Console/Commands/PrunePersonalData.php' => 'Löschfristen (DSGVO) über alle Schulen.',
        'app/Console/Commands/PruneInactiveLearners.php' => 'Löschfrist inaktiver Lernender: Aktivität und Kurszugänge über alle Schulen prüfen.',
        'app/Console/Commands/RemindExpiringEntitlements.php' => 'Ablauf-Erinnerungen für Lernende aller Schulen.',
        'app/Console/Commands/WarmCourseCache.php' => 'Kurs-Cache für alle veröffentlichten Kurse vorwärmen.',
        // --- Mandantenauflösung, Login und Konten (vor bzw. außerhalb eines Mandantenkontexts) ---
        'app/Http/Middleware/Api/ResolveApiTenant.php' => 'API: Schule per Slug und Mitgliedschaft auflösen, bevor RLS greift.',
        'app/Http/Middleware/EnsureAnyTenantMembership.php' => 'Mitgliedschaft prüfen, bevor ein Mandant gesetzt ist.',
        'app/Http/Middleware/EnsureTenantMembership.php' => 'Mitgliedschaft/Einladung prüfen, bevor ein Mandant gesetzt ist.',
        'app/Http/Middleware/ResolveTenant.php' => 'Schule per eigener Domain bzw. Branding finden (noch kein Mandant).',
        'app/Support/TenantPath.php' => 'Existiert der Pfad-Slug als Schule? (vor der Mandantenauflösung)',
        'app/Support/TrustedHosts.php' => 'Eigene Domains aller Schulen als vertrauenswürdige Hosts.',
        'app/Http/Controllers/TlsCheckController.php' => 'On-Demand-TLS: Domain gegen alle Schulen prüfen.',
        'app/Http/Controllers/Api/V1/AuthController.php' => 'API-Login/Passwort-Reset: Konto plattformweit per E-Mail finden.',
        'app/Http/Controllers/Auth/NewPasswordController.php' => 'Passwort setzen: Konto plattformweit per E-Mail finden.',
        'app/Http/Controllers/Auth/RegisteredUserController.php' => 'Registrierung: E-Mail plattformweit auf Dubletten prüfen.',
        'app/Http/Controllers/Auth/TwoFactorController.php' => '2FA-Pflicht über alle Mitgliedschaften, Protokoll ohne Mandant.',
        'app/Http/Controllers/MailPreferenceController.php' => 'Abmeldelink aus Mails: Konto ohne Anmeldung per signierter ID finden und Einstellung ändern.',
        'app/Http/Controllers/ImpersonationController.php' => 'Support-Ansicht: Einmal-Link einlösen, Protokoll vor/nach Anmeldung.',
        'app/Models/User.php' => 'Hat das Konto Team-Rollen in irgendeiner Schule?',
        'app/Api/Present.php' => 'API: Mitgliedschaften und Branding aller Schulen des Kontos.',
        'app/Notifications/ConfirmPendingEmail.php' => 'Anredeform des Kontos beim Mailversand (Queue ohne Mandant).',
        // --- Mandantenübergreifende Dienste ---
        'app/Services/AccountErasureService.php' => 'Konto löschen: Mitgliedschaften/Daten in allen Schulen.',
        'app/Services/PersonalDataExportService.php' => 'DSGVO-Auskunft: Sitzungen/Geräte sind kontoweit, nicht je Schule.',
        'app/Services/InvitationAcceptance.php' => 'Erstes Passwort eines eingeladenen Kontos: offene Einladungen aller Schulen (noch kein Mandant) finden.',
        'app/Services/InvitationCoupon.php' => 'Code beim Einladen prüfen: Kurs des Plattform-Katalogs (tenant_id NULL) laden.',
        'app/Services/ParticipantInviter.php' => 'Einladung: bestehendes Konto plattformweit per E-Mail finden.',
        'app/Services/TenantOffboardingService.php' => 'Schule auflösen: Exportdateien der Schule finden und löschen.',
        'app/Services/NewLoginDeviceNotifier.php' => 'Hinweis bei neuem Gerät: Team-Rollen des Kontos in allen Schulen prüfen.',
        'app/Services/CouponBatchFactory.php' => 'Code-Erzeugung: Eindeutigkeit gegen Codes aller Schulen prüfen (nur Abgleich, kein Lesen fremder Daten).',
        'app/Http/Controllers/Admin/ParticipantController.php' => 'Einladung durch Ausbilder: Konto zur E-Mail plattformweit finden.',
        'app/Http/Controllers/Admin/ParticipantToolsController.php' => 'Sammel-Einladung: bestehende Konten plattformweit per E-Mail finden.',
        'app/Http/Controllers/Admin/TeamController.php' => 'Team-Einladung: bestehendes Konto plattformweit per E-Mail finden.',
        'app/Models/ExamSession.php' => 'Offene Prüfungen eines Kontos über alle Schulen (openForUserAcrossTenants).',
        'app/Models/Coupon.php' => 'Codes müssen plattformweit eindeutig sein.',
        'app/Support/OpsAlert.php' => 'Support-Adresse aus den Plattform-Einstellungen.',
        'app/Support/ProductionReadiness.php' => 'Betriebsprüfung: Rollen, Queue und Plattformdaten.',
        // --- Plattform-Katalog (CourseDefinition mit tenant_id NULL statt Tenant-Scope) ---
        'app/Http/Controllers/Admin/AdminDashboardController.php' => 'Kursauswahl/Coupon-Übersicht aus dem Plattform-Katalog.',
        'app/Http/Controllers/Admin/AnnouncementController.php' => 'Kursnamen der Zielgruppe aus dem Plattform-Katalog.',
        'app/Http/Controllers/Admin/BulkRenewalController.php' => 'Kursnamen aus dem Plattform-Katalog.',
        'app/Http/Controllers/Admin/CourseSelectionController.php' => 'Kursauswahl der Schule aus dem Plattform-Katalog.',
        'app/Http/Controllers/Admin/EntitlementController.php' => 'Manuelle Freischaltung nur für Katalogkurse.',
        'app/Http/Controllers/Admin/PraxisExamAppointmentController.php' => 'Termin nur für Katalogkurse.',
        'app/Http/Controllers/Api/V1/CourseController.php' => 'API: angebotene Katalogkurse.',
        'app/Http/Controllers/CourseController.php' => 'Gesperrte Katalogkurse auf der Kursübersicht.',
        'app/Models/AuditLog.php' => 'Kursnamen im Protokoll auflösen (Katalogkurse); persönliche Einstellungen ohne Mandant protokollieren.',
        'app/Services/CouponRedemptionService.php' => 'Kurs eines Codes aus dem Plattform-Katalog.',
        'app/Services/PraxisFormDataResolver.php' => 'Kursnamen des Plattform-Katalogs.',
        'app/Support/ExamBooking.php' => 'Plattformweit deaktivierte Katalogkurse.',
        'app/Api/CourseFiles.php' => 'Kursdateien der App: Plattform-Katalogkurse (tenant_id null) über den Kurscode finden.',
        'app/Console/Commands/CreateLearningGroup.php' => 'CLI-Befehl ohne Request-Mandant: Lerngruppe für eine bestimmte Schule anlegen (Betreiber-Werkzeug).',
        'app/Console/Commands/ExportTenant.php' => 'CLI-Export einer ganzen Schule (Vertragsende/Umzug) ohne Request-Mandant.',
        'app/Console/Commands/ImportMobileAccessRules.php' => 'CLI-Import aus der WP-App: Plattform-Katalogkurse und Zugriffsregeln je Schule ohne Request-Mandant.',
        'app/Console/Commands/ImportMobileCourseFiles.php' => 'CLI-Import aus der WP-App: Kursdateien zu Plattform-Katalogkursen ohne Request-Mandant.',
        'app/Console/Commands/ImportMobileEntitlements.php' => 'CLI-Import aus der WP-App: Konten plattformweit per E-Mail finden und Zugänge in der Zielschule anlegen.',
        'app/Console/Commands/ImportMobileFaqs.php' => 'CLI-Import der Kunden-FAQs aus der WP-App ohne Request-Mandant.',
        'app/Console/Commands/RevokeLearningGroup.php' => 'CLI-Befehl ohne Request-Mandant: Lerngruppe beliebiger Schule widerrufen (Betreiber-Werkzeug).',
        'app/Http/Controllers/Api/V1/CouponController.php' => 'Code einlösen in der App: Plattform-Katalogkurse (tenant_id null) zu den Kurscodes laden.',
        'app/Http/Controllers/Api/V1/WebSessionController.php' => 'Einmal-Anmeldung der Webansicht: Mitgliedschaften des Kontos schulübergreifend prüfen (Team-Rollen ausschließen).',
        'app/Http/Controllers/LearningGroupLinkController.php' => 'Hinweisseite zum Beitrittslink ohne Schulkontext: Gruppenname und Schule zum Code nur lesen.',
        'app/Http/Controllers/AppAccountDeletionController.php' => 'Bestätigungsseite der Kontolöschung ohne Anmeldung: Branding der Schule des Antrags laden.',
        'app/Http/Controllers/ProtectedMediaController.php' => 'Signierte Auslieferung privater Dateien (Tipp-PDFs, Videoschritt-Bilder) ohne Mandantenkontext; Berechtigung prüft ProtectedMedia.',
        'app/Http/Controllers/QuestionMediaController.php' => 'Signierte Auslieferung lizenzierter Fragenbilder; Berechtigung prüft QuestionMedia.',
        'app/Http/Middleware/ConsumeMobileWebSession.php' => 'Einlösen des Einmal-Codes der Webansicht vor der Anmeldung: Konto und Mitgliedschaft prüfen.',
        'app/Services/AppAccountDeletion.php' => 'Kontolöschung aus der App per Mail-Link ohne Anmeldung: Antrag, Schule und Konto laden.',
        'app/Services/CourseRelationService.php' => 'Kursbeziehungen (Groß ersetzt Klein) über den Plattform-Katalog (tenant_id null).',
        'app/Services/LearningGroupService.php' => 'Lerngruppen: Plattform-Katalogkurse, Mitgliedschaft der Person und Gruppenzugänge der Schule unter Sperre.',
        'app/Support/NavigationChart.php' => 'Signierte Kartenausschnitte ohne Mandantenkontext: zugehörigen Kurs zur Datei finden.',
        'app/Support/ProtectedMedia.php' => 'Berechtigung für signierte private Dateien: Konto, Schule und Mitgliedschaft aus dem signierten Link laden.',
        'app/Support/QuestionMedia.php' => 'Fragenbilder: Medien-Datensatz zur signierten Adresse ohne Mandantenkontext laden.',
        'app/Support/TipPdf.php' => 'Tipp-PDFs: Medien-Datensatz zur signierten Adresse ohne Mandantenkontext laden.',
        'app/Support/VideoStepImage.php' => 'Videoschritt-Bilder: Kurs zur Lektion ohne Mandantenkontext finden und Dateien zuordnen.',
    ];

    private const PATTERNS = [
        'Owner-Verbindung "pgsql_admin"' => '/pgsql_admin/',
        'withoutGlobalScopes()' => '/(->|::)\s*withoutGlobalScopes\s*\(/',
        'OwnerConnection::run()' => '/\bOwnerConnection::/',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, list<string>> Datei => gefundene Muster */
    private static function usages(): array
    {
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root().'/app', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = self::codeWithoutComments((string) file_get_contents($file->getPathname()));
            $hits = array_keys(array_filter(self::PATTERNS, fn (string $regex) => preg_match($regex, $code) === 1));
            if ($hits !== []) {
                $found[str_replace('\\', '/', substr($file->getPathname(), strlen(self::root()) + 1))] = $hits;
            }
        }
        ksort($found);

        return $found;
    }

    private static function codeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    public function test_owner_connection_and_scope_bypass_only_in_allowlisted_files(): void
    {
        $unexpected = array_diff_key(self::usages(), self::ALLOWLIST);

        $this->assertSame([], $unexpected, "Neue Verwendung der Owner-Verbindung bzw. von withoutGlobalScopes():\n"
            .implode("\n", array_map(fn ($file, $hits) => "  {$file}: ".implode(', ', $hits), array_keys($unexpected), $unexpected))
            ."\nBitte prüfen, ob das wirklich nötig ist (docs/architektur/owner-verbindung.md), und dann mit Begründung in "
            .'tests/Unit/OwnerConnectionUsageTest.php::ALLOWLIST eintragen.');
    }

    public function test_allowlist_has_no_stale_entries(): void
    {
        $stale = array_keys(array_diff_key(self::ALLOWLIST, self::usages()));

        $this->assertSame([], $stale, 'Diese Dateien nutzen die Owner-Verbindung nicht mehr (oder existieren nicht) -- bitte aus der Allowlist entfernen: '.implode(', ', $stale));
    }

    public function test_every_allowlist_entry_has_a_reason(): void
    {
        foreach (self::ALLOWLIST as $file => $reason) {
            $this->assertMatchesRegularExpression('/\S{3,}/', $reason, "Begründung fehlt für {$file}");
            $this->assertStringNotContainsString("\n", $reason, "Begründung für {$file} bitte in einer Zeile");
        }
    }

    public function test_scanner_ignores_comments_but_finds_code(): void
    {
        $this->assertDoesNotMatchRegularExpression(self::PATTERNS['Owner-Verbindung "pgsql_admin"'], self::codeWithoutComments("<?php\n// DB::connection('pgsql_admin')\n/** pgsql_admin */\n\$x = 1;"));
        $this->assertMatchesRegularExpression(self::PATTERNS['Owner-Verbindung "pgsql_admin"'], self::codeWithoutComments("<?php\nDB::connection('pgsql_admin');"));
        $this->assertMatchesRegularExpression(self::PATTERNS['withoutGlobalScopes()'], self::codeWithoutComments("<?php\nFoo::withoutGlobalScopes()->get();"));
        $this->assertMatchesRegularExpression(self::PATTERNS['OwnerConnection::run()'], self::codeWithoutComments("<?php\nOwnerConnection::run(fn () => 1);"));
    }
}
