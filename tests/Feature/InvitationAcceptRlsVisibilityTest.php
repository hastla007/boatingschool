<?php

namespace Tests\Feature;

use App\Models\TenantInvitation;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Sicherheitsreview R3-1: $this->actingAs() in den bestehenden H2-Tests
 * (AccountSecurityTest) setzt den authentifizierten Nutzer direkt auf dem
 * Auth-Guard, ohne ihn während des Requests tatsächlich über die
 * RLS-beschränkte App-Verbindung aus app_user nachzuladen -- ein
 * realistischer Login-Flow (POST /login, dann ein SEPARATER Request mit
 * der echten Session) deckt deshalb eine andere Klasse von Fehlern auf:
 * app_user_select_visibility (Migration 000461) macht eine Zeile nur
 * sichtbar, wenn kein echter Tenant-Kontext gesetzt ist ODER der Nutzer
 * bereits tenant_user der aktuell aufgelösten Bootsschule ist. Ruft ein
 * bereits eingeloggter Nutzer (noch KEIN Mitglied der einladenden Schule)
 * den Einladungs-Link auf deren Subdomain auf, löst
 * ResolveTenant::resolveViaSubdomain() den REALEN Tenant-Kontext auf
 * (/invitations/... steht nicht auf der tenant-agnostischen Liste) --
 * die anschließende Nutzer-Rückladung aus der Session
 * (EnsureAccountIsActive/Auth::user(), läuft nach ResolveTenant) sieht die
 * eigene app_user-Zeile dann selbst nicht mehr, weil er dort noch kein
 * Mitglied ist. InvitationAcceptController::accept() behandelt das wie
 * "nicht eingeloggt" und schickt ihn zu /login zurück, wo er sich erneut
 * (erfolglos) mit genau diesem Konto anmelden soll -- eine Einladungs-
 * Annahme für ein bestehendes Konto ist damit faktisch nie möglich.
 */
class InvitationAcceptRlsVisibilityTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    public function test_an_already_logged_in_existing_account_can_actually_accept_an_invitation(): void
    {
        $homeTenant = $this->createTestTenant('Heimatschule');
        $victim = $this->createTenantUser($homeTenant, 'learner', [
            'email' => 'bestandskonto-r3@example.test',
            'email_verified_at' => now(),
        ]);

        $invitingTenant = $this->createTestTenant('Einladende Schule');
        $admin = $this->createTenantUser($invitingTenant, 'owner');

        $this->actingAsInTenant($admin, $invitingTenant)->post('/admin/participants/invite', [
            'name' => 'Bestandskonto',
            'email' => 'bestandskonto-r3@example.test',
        ]);

        $invitation = $this->asTenant($invitingTenant, fn () => TenantInvitation::where('tenant_id', $invitingTenant->id)->where('email', 'bestandskonto-r3@example.test')->firstOrFail());

        $url = URL::temporarySignedRoute('invitations.accept', now()->addDays(7), [
            'invitation' => $invitation->id,
            'hash' => sha1('bestandskonto-r3@example.test'),
        ]);

        // actingAs() (via actingAsInTenant() oben) setzt den Nutzer direkt
        // auf dem Auth-Guard und bleibt dort "kleben", bis sich jemand real
        // einloggt oder ausloggt -- ein nachfolgender echter Login-Request
        // würde sonst von diesem Zustand verdeckt. Explizit ausloggen, um
        // wirklich den realen Session-Login-Pfad zu prüfen (der Pfad, den
        // actingAs() umgeht).
        $this->post('/logout');

        $this->withSession(['dev_tenant_slug' => $homeTenant->slug])->post('/login', [
            'email' => 'bestandskonto-r3@example.test',
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        // Jeder echte Request bootet eine frische Application mit einem
        // frischen Auth-Guard, der den Nutzer erst bei Bedarf per Session-ID
        // neu aus app_user nachlädt. Innerhalb EINES Testlaufs teilen sich
        // alle ->get()/->post()-Aufrufe dieselbe App-Instanz -- der
        // Session-Guard cacht den gerade eingeloggten Nutzer deshalb über
        // das Testende hinaus, was genau die Nachladung verdeckt, die
        // dieser Test prüfen soll. Auth::forgetGuards() zwingt eine frische
        // Guard-Instanz und damit eine echte Neuauflösung für den nächsten
        // Request, wie bei einem echten, separaten HTTP-Request.
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $response = $this->withSession(['dev_tenant_slug' => $invitingTenant->slug])->get($url);

        $response->assertRedirect(route('dashboard'));
        $this->asTenant($invitingTenant, function () use ($invitingTenant, $victim, $invitation) {
            $this->assertDatabaseHas('tenant_user', [
                'tenant_id' => $invitingTenant->id,
                'user_id' => $victim->id,
            ]);
            $this->assertNotNull(TenantInvitation::find($invitation->id)->accepted_at);
        });
    }
}
