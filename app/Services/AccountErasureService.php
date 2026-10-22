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
use App\Models\TenantUser;
use App\Models\User;
use App\Models\VideoProgress;
use App\Models\WillkommensboxOrder;
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
    public function leaveTenant(User $user, Tenant $tenant): void
    {
        DB::transaction(function () use ($user, $tenant) {
            foreach ($this->tenantScopedModels() as $model) {
                $model::where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();
            }

            $this->pseudonymizeAuditLogFor($tenant, $user);

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
     * Behält den Audit-Trail (Aktion, Zeitpunkt, Entitätstyp) als
     * betrieblichen Nachweis, entfernt aber personenbezogene Daten aus dem
     * JSON (z. B. die E-Mail-Adresse bei participant.invite) -- bislang
     * wurde bei einer Löschung nur actor_user_id auf NULL gesetzt, die
     * Nutzdaten blieben aber im JSON stehen.
     */
    private function pseudonymizeAuditLogFor(Tenant $tenant, User $user): void
    {
        AuditLog::where('tenant_id', $tenant->id)
            ->where(function ($query) use ($user) {
                $query->where('actor_user_id', $user->id)
                    ->orWhere(function ($inner) use ($user) {
                        $inner->where('entity_type', 'app_user')->where('entity_id', $user->id);
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
