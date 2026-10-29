<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\FavoriteCollection;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisProgress;
use App\Models\ProductPurchase;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\VideoProgress;
use App\Models\WillkommensboxOrder;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * DSGVO-Review D1/D2: eine Kontolöschung wirkte bislang global -- sie löschte
 * das Konto samt aller Mitgliedschaften und Lerndaten bei jeder Bootsschule,
 * nicht nur bei der, von der aus gelöscht wurde. Jede Bootsschule ist aber
 * ein eigener datenschutzrechtlich Verantwortlicher (eigene Aufbewahrungs-
 * pflichten); eine Löschanfrage bei Schule A darf nicht ungefragt auch Daten
 * bei Schule B entfernen. Das Konto selbst wird deshalb erst gelöscht, wenn
 * keine Mitgliedschaft bei irgendeiner Bootsschule mehr übrig ist.
 */
class AccountErasureService
{
    public function __construct(private TenantContext $tenantContext) {}

    public function leaveTenant(User $user, Tenant $tenant): void
    {
        DB::transaction(function () use ($user, $tenant) {
            // Bug-Review (Kontolöschung hinterlässt personenbezogene Daten im
            // Admin-Protokoll): WillkommensboxOrderController::ship() und
            // PraxisFormSubmissionController protokollieren die komplette
            // Lieferadresse bzw. die Formulardaten als JSON in audit_log --
            // mit actor_user_id des handelnden ADMINS, nicht des Lerners, und
            // entity_type/entity_id der Bestellung/Anmeldung selbst. Die
            // bisherige pseudonymizeAuditLogFor() kannte nur actor_user_id,
            // entity_type=app_user und entity_type=tenant_invitation -- diese
            // beiden Entitäten trafen darauf nie zu und überlebten die
            // Löschung der zugehörigen Zeilen (siehe tenantScopedModels())
            // unverändert im Protokoll. Die IDs müssen VOR dem Löschen unten
            // eingesammelt werden, da sie nach dem DELETE nicht mehr über die
            // Tabelle selbst auflösbar sind.
            $willkommensboxOrderIds = WillkommensboxOrder::where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('id')->all();
            $praxisFormSubmissionIds = PraxisFormSubmission::where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('id')->all();

            foreach ($this->tenantScopedModels() as $model) {
                $model::where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();
            }

            $invitationIds = $this->deleteInvitationsFor($tenant, $user);
            $this->pseudonymizeAuditLogFor($tenant, $user, $invitationIds, $willkommensboxOrderIds, $praxisFormSubmissionIds);

            TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();
        });

        // RLS blendet auf der regulären App-Verbindung grundsätzlich alles
        // außer dem aktuell aufgelösten Mandanten aus -- withoutGlobalScopes()
        // hebt nur den Eloquent-Scope auf, nicht die DB-seitige Policy. Ob
        // bei einer ANDEREN Bootsschule noch eine Mitgliedschaft besteht,
        // lässt sich deshalb nur über die RLS-freie Owner-Verbindung prüfen.
        //
        // Sicherheitsreview M5: ResolveTenant kapselt den gesamten Request
        // inzwischen in einer Transaktion auf der regulären Verbindung --
        // die TenantUser::delete() oben läuft also in einer noch nicht
        // committeten (verschachtelten) Transaktion. Die Owner-Verbindung
        // "pgsql_admin" ist eine ANDERE Postgres-Session und sieht diese
        // Löschung deshalb erst nach dem Request-Ende. Ohne den Ausschluss
        // des gerade verlassenen Mandanten hier würde die eigene, gerade
        // gelöschte (aber noch ungesehene) Mitgliedschaft fälschlich als
        // "noch vorhanden" durchgehen und die Kontolöschung stillschweigend
        // überspringen -- der Ausschluss macht die Prüfung unabhängig vom
        // Transaktions-Timing, da er nur nach ANDEREN, von diesem Request
        // nie berührten Mitgliedschaften fragt.
        $hasRemainingMemberships = $this->onAdminConnection(
            fn () => TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->where('tenant_id', '!=', $tenant->id)->exists()
        );

        if (! $hasRemainingMemberships) {
            // Sicherheitsreview M7 (Folgefehler): Postgres lässt UPDATE/DELETE
            // nur auf Zeilen zu, die auch über eine SELECT-Policy sichtbar
            // sind -- die eigene app_user_delete_unrestricted-Policy (USING
            // true) allein reicht nicht, wenn die app_user_select_visibility-
            // Policy die Zeile verdeckt. Genau das passiert hier: die eben
            // gelöschte tenant_user-Mitgliedschaft war die letzte
            // Sichtbarkeits-Grundlage dieser Zeile unter dem noch gesetzten
            // Mandantenkontext. clear() setzt den Kontext auf die Nil-UUID,
            // für die die SELECT-Policy ausdrücklich sichtbar ist (genau wie
            // für mandantenunabhängige Routen) -- ohne die Verbindung zu
            // wechseln und damit ohne das Deadlock-Risiko von M5.
            $this->tenantContext->clear();
            $this->deleteAccountEntirely($user);
        }
    }

    private function onAdminConnection(callable $callback): mixed
    {
        $original = config('database.default');
        config(['database.default' => 'pgsql_admin']);

        try {
            return $callback();
        } finally {
            config(['database.default' => $original]);
        }
    }

    /** @return array<class-string> */
    private function tenantScopedModels(): array
    {
        return [
            Attempt::class,
            Progress::class,
            Favorite::class,
            // Kaskadiert favorite_collection_item weg (ON DELETE CASCADE).
            FavoriteCollection::class,
            // Kaskadiert exam_session_question weg (ON DELETE CASCADE).
            ExamSession::class,
            Entitlement::class,
            ProductPurchase::class,
            PraxisProgress::class,
            VideoProgress::class,
            WillkommensboxOrder::class,
            // Kaskadiert praxis_exam_appointment weg (ON DELETE CASCADE).
            PraxisFormSubmission::class,
        ];
    }

    /**
     * DSGVO-Review D2 (Folgefehler): eine Einladung an eine bereits
     * existierende Adresse (siehe ParticipantController::invite(),
     * Sicherheitsreview H2) legt bewusst noch keine Mitgliedschaft an,
     * sondern nur eine tenant_invitation-Zeile mit der eingeladenen
     * E-Mail-Adresse -- die stand bisher weder in tenantScopedModels()
     * noch sonst irgendwo in dieser Löschung und überlebte sie deshalb
     * unverändert.
     *
     * @return list<string> IDs der gelöschten Einladungen, für
     *                      pseudonymizeAuditLogFor() unten.
     */
    private function deleteInvitationsFor(Tenant $tenant, User $user): array
    {
        $invitationIds = TenantInvitation::where('tenant_id', $tenant->id)
            ->where('email', $user->email)
            ->pluck('id')
            ->all();

        TenantInvitation::where('tenant_id', $tenant->id)->where('email', $user->email)->delete();

        return $invitationIds;
    }

    /**
     * Behält den Audit-Trail (Aktion, Zeitpunkt, Entitätstyp) als
     * betrieblichen Nachweis, entfernt aber personenbezogene Daten aus dem
     * JSON (z. B. die E-Mail-Adresse bei participant.invite) -- bislang
     * wurde bei einer Löschung nur actor_user_id auf NULL gesetzt, die
     * Nutzdaten blieben aber im JSON stehen.
     *
     * DSGVO-Review D2 (Folgefehler): participant.invite_pending (siehe
     * ParticipantController::invite()) protokolliert mit
     * entity_type=tenant_invitation und entity_id=<Einladungs-ID>, nicht
     * mit der User-ID -- die bisherige Prüfung (actor_user_id oder
     * entity_type=app_user+entity_id=User-ID) erfasste diese Zeilen nie,
     * die E-Mail-Adresse im JSON überlebte deshalb auch jede Löschung.
     *
     * @param  list<string>  $invitationIds
     * @param  list<string>  $willkommensboxOrderIds
     * @param  list<string>  $praxisFormSubmissionIds
     */
    private function pseudonymizeAuditLogFor(Tenant $tenant, User $user, array $invitationIds, array $willkommensboxOrderIds = [], array $praxisFormSubmissionIds = []): void
    {
        AuditLog::where('tenant_id', $tenant->id)
            ->where(function ($query) use ($user, $invitationIds, $willkommensboxOrderIds, $praxisFormSubmissionIds) {
                $query->where('actor_user_id', $user->id)
                    ->orWhere(function ($inner) use ($user) {
                        $inner->where('entity_type', 'app_user')->where('entity_id', $user->id);
                    })
                    ->when($invitationIds !== [], function ($query) use ($invitationIds) {
                        $query->orWhere(function ($inner) use ($invitationIds) {
                            $inner->where('entity_type', 'tenant_invitation')->whereIn('entity_id', $invitationIds);
                        });
                    })
                    ->when($willkommensboxOrderIds !== [], function ($query) use ($willkommensboxOrderIds) {
                        $query->orWhere(function ($inner) use ($willkommensboxOrderIds) {
                            $inner->where('entity_type', 'willkommensbox_order')->whereIn('entity_id', $willkommensboxOrderIds);
                        });
                    })
                    ->when($praxisFormSubmissionIds !== [], function ($query) use ($praxisFormSubmissionIds) {
                        $query->orWhere(function ($inner) use ($praxisFormSubmissionIds) {
                            $inner->where('entity_type', 'praxis_form_submission')->whereIn('entity_id', $praxisFormSubmissionIds);
                        });
                    });
            })
            ->update(['before_data' => null, 'after_data' => null]);
    }

    private function deleteAccountEntirely(User $user): void
    {
        // Keine Fremdschlüssel auf diesen Tabellen -- ohne das explizite
        // Löschen blieben Sitzungen und Reset-Tokens als verwaiste
        // personenbezogene Daten stehen.
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        // Sicherheitsreview M5 (Folgefehler): $user->delete() würde die von
        // Eloquent beim Hydrieren/Erstellen fest zugeordnete Verbindung des
        // Modells benutzen (z.B. "pgsql_admin", wenn der Nutzer -- wie in
        // Test-Fixtures via InteractsWithTenants::onAdmin() -- ursprünglich
        // über die Owner-Verbindung angelegt wurde), unabhängig vom
        // aktuellen config('database.default'). Das wäre eine ANDERE
        // Postgres-Session als die, in der ResolveTenant den gesamten
        // Request einschließt -- die kaskadierende Fremdschlüssel-Löschung
        // (z.B. tenant_user) müsste dann auf eine von dieser noch nicht
        // committeten Transaktion gesperrte Zeile warten und blockiert
        // dauerhaft. Die Löschung läuft deshalb bewusst über eine
        // verbindungslose Query auf der aktuellen Default-Verbindung, exakt
        // wie die beiden Löschungen oben.
        DB::table('app_user')->where('id', $user->id)->delete();
    }
}
