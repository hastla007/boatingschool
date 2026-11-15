<?php

namespace App\Services;

use App\Models\ApiAccountDeletionRequest;
use App\Models\ApiDataExport;
use App\Models\ApiMobileWebSession;
use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\FavoriteCollection;
use App\Models\InstructorCourse;
use App\Models\LearningGroup;
use App\Models\LearningGroupMember;
use App\Models\NavigationProgress;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisProgress;
use App\Models\ProductPurchase;
use App\Models\Progress;
use App\Models\RadioTrainingProgress;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\VideoProgress;
use App\Models\WillkommensboxOrder;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
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

    /**
     * @param  bool  $forgetOtherSchoolsInvitations  false, wenn eine Schule löscht
     *                                               (Review-Runde 11: deren Aktion
     *                                               räumt nicht die offenen Einladungen
     *                                               anderer Schulen ab)
     */
    public function leaveTenant(User $user, Tenant $tenant, bool $forgetOtherSchoolsInvitations = true): void
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
            // Bug-Review (Kontolöschung hinterlässt Prüfungstermine im
            // Admin-Protokoll): PraxisExamAppointmentController protokolliert
            // propose/withdraw/cancel mit entity_type=praxis_exam_appointment
            // und dem vollständigen Termin als JSON -- inklusive des freien
            // response_comment des Lerners, sobald er auf den Vorschlag
            // geantwortet hat. Die Termine verschwinden per ON DELETE CASCADE
            // mit der Anmeldung (siehe tenantScopedModels()), ihre IDs müssen
            // deshalb ebenfalls vorher eingesammelt werden.
            $praxisExamAppointmentIds = $praxisFormSubmissionIds === [] ? [] : PraxisExamAppointment::where('tenant_id', $tenant->id)
                ->whereIn('praxis_form_submission_id', $praxisFormSubmissionIds)->pluck('id')->all();

            ApiDataExport::deleteFilesAfterCommit(ApiDataExport::where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('path'));
            foreach ($this->tenantScopedModels() as $model) {
                $model::where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();
            }
            $this->leaveLearningGroups($tenant, $user);
            $this->detachRedeemedCoupons($tenant, $user);

            $invitationIds = $this->deleteInvitationsFor($tenant, $user);
            $this->pseudonymizeAuditLogFor($tenant, $user, $invitationIds, $willkommensboxOrderIds, $praxisFormSubmissionIds, $praxisExamAppointmentIds);

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

        // QA-D-6: verknüpfte Shopkonten bei dieser Schule (Kontaktdaten aus dem Shop).
        $this->revokeShopAccountsAt($tenant, $user);

        if (! $hasRemainingMemberships) {
            // QA-D-6: gespeicherte App-Antworten (Idempotenz) haben keine
            // tenant_id -- nur mit der letzten Mitgliedschaft löschen, sonst
            // träfe es auch die Antworten der übrigen Schulen.
            DB::table('api_idempotency_key')->where('user_id', $user->id)->delete();

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
            try {
                $this->pseudonymizePlatformAuditLogFor($user, $tenant);
                if ($forgetOtherSchoolsInvitations) {
                    $this->forgetOpenInvitations($user, $tenant->id);
                }
                $this->deleteAccountEntirely($user);
            } finally {
                // Review-Runde 8: der restliche Request (z. B. der
                // Protokolleintrag der Schule nach „Teilnehmer löschen“)
                // läuft wieder im Kontext der Schule.
                $this->tenantContext->set($tenant);
            }
        }
    }

    /**
     * Konto vollständig löschen (Superadmin, z. B. auf DSGVO-Anfrage beim
     * Plattformbetreiber): verlässt jede Bootsschule wie leaveTenant()
     * (Lerndaten, Einladungen, Protokoll-Pseudonymisierung je Schule); mit
     * der letzten Mitgliedschaft verschwindet das Konto. Konten ohne
     * Mitgliedschaft werden direkt gelöscht.
     */
    public function deleteAccount(User $user): void
    {
        $tenantIds = $this->onAdminConnection(fn () => TenantUser::withoutGlobalScopes()->where('user_id', $user->id)->pluck('tenant_id')->all());

        foreach ($tenantIds as $tenantId) {
            $tenant = $this->onAdminConnection(fn () => Tenant::find($tenantId));
            if ($tenant) {
                $this->tenantContext->set($tenant);
                $this->leaveTenant($user, $tenant);
            }
        }
        $this->tenantContext->clear();

        $this->forgetOpenInvitations($user);

        if ($tenantIds === []) {
            $this->eraseOrphanAccount($user);
        }
    }

    /**
     * Konto ohne (weitere) Mitgliedschaft löschen -- gemeinsamer Lösch-Pfad
     * für deleteAccount() und das Vertragsende einer Schule
     * (TenantOffboardingService, QA-D-5): plattformweites Protokoll
     * (Kontoeinträge, E-Mail bei der Schul-Anlage), Sitzungen/Tokens,
     * Shopkonten samt Shop-Ereignissen, dann das Konto. Läuft auf der
     * Owner-Verbindung; in einer dort schon offenen Transaktion (Offboarding)
     * dieselbe Postgres-Session.
     */
    public function eraseOrphanAccount(User $user, bool $auditAlreadyCleared = false): void
    {
        $this->onAdminConnection(function () use ($user, $auditAlreadyCleared) {
            if (! $auditAlreadyCleared) {
                $this->forgetOrphanAuditData(collect([$user]));
            }
            DB::table('api_idempotency_key')->where('user_id', $user->id)->delete();
            $this->deleteAccountEntirely($user);
        });
    }

    /**
     * Offene Einladungen an diese Adresse in allen (anderen) Schulen samt
     * den Protokolldaten dazu (enthalten die E-Mail-Adresse). Die gerade
     * verlassene Schule räumt leaveTenant() selbst auf (gleiche
     * Transaktion), deshalb hier ausgenommen.
     */
    private function forgetOpenInvitations(User $user, ?string $exceptTenantId = null): void
    {
        $this->onAdminConnection(function () use ($user, $exceptTenantId) {
            $invitationIds = TenantInvitation::withoutGlobalScopes()->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])
                ->when($exceptTenantId, fn ($q) => $q->where('tenant_id', '!=', $exceptTenantId))->pluck('id');
            if ($invitationIds->isNotEmpty()) {
                AuditLog::where('entity_type', 'tenant_invitation')->whereIn('entity_id', $invitationIds)->update(['before_data' => null, 'after_data' => null]);
                TenantInvitation::withoutGlobalScopes()->whereIn('id', $invitationIds)->delete();
            }
            // Offene Einladungen in Lerngruppen (enthalten die Adresse).
            LearningGroupMember::withoutGlobalScopes()->where('status', 'invited')->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])
                ->when($exceptTenantId, fn ($q) => $q->where('tenant_id', '!=', $exceptTenantId))->delete();
        });
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
            NavigationProgress::class,
            // Datenexporte aus der App (Datei räumt app:prune-personal-data nach Ablauf weg).
            ApiDataExport::class,
            // Offener Löschantrag und Einmal-Codes der Webansicht aus der App.
            ApiAccountDeletionRequest::class,
            ApiMobileWebSession::class,
            // Bug-Review (Funk-Training fehlt in Löschung): answers enthält
            // freie Mitschriften/Eingaben des Lerners (siehe Migration
            // 2024_02_01_000479) -- die Tabelle kam nach dieser Liste hinzu
            // und blieb beim Verlassen einer Bootsschule bisher stehen (nur
            // die vollständige Kontolöschung räumte sie per FK-Kaskade ab).
            RadioTrainingProgress::class,
            // Kurszuordnung als Ausbilder: sonst käme sie bei einer späteren
            // erneuten Einladung in dieselbe Schule ungewollt wieder.
            InstructorCourse::class,
            WillkommensboxOrder::class,
            // Kaskadiert praxis_exam_appointment weg (ON DELETE CASCADE).
            PraxisFormSubmission::class,
        ];
    }

    /**
     * Lerngruppen dieser Schule: Mitgliedschaften und offene Einladungen an
     * die Adresse verschwinden (die Gruppenzugänge selbst löscht
     * tenantScopedModels()), der Platz wird frei. Verwaltet die Person
     * Gruppen, bleiben diese ohne Verwalter bestehen -- die übrigen
     * Mitglieder behalten ihren bezahlten Zugang.
     */
    private function leaveLearningGroups(Tenant $tenant, User $user): void
    {
        LearningGroupMember::where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereRaw('lower(email) = ?', [mb_strtolower($user->email)]))
            ->delete();
        LearningGroup::where('tenant_id', $tenant->id)->where('owner_user_id', $user->id)->update(['owner_user_id' => null]);
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
            ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])
            ->pluck('id')
            ->all();

        TenantInvitation::whereIn('id', $invitationIds)->delete();

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
     * @param  list<string>  $praxisExamAppointmentIds
     */
    private function pseudonymizeAuditLogFor(Tenant $tenant, User $user, array $invitationIds, array $willkommensboxOrderIds = [], array $praxisFormSubmissionIds = [], array $praxisExamAppointmentIds = []): void
    {
        AuditLog::where('tenant_id', $tenant->id)
            ->where(function ($query) use ($user, $invitationIds, $willkommensboxOrderIds, $praxisFormSubmissionIds, $praxisExamAppointmentIds) {
                $query->where('actor_user_id', $user->id)
                    ->orWhere(function ($inner) use ($user) {
                        $inner->where('entity_type', 'app_user')->where('entity_id', $user->id);
                    })
                    // Einträge, deren Daten die Person enthalten (z. B. eine
                    // vorher gelöschte Praxis-Anmeldung, Kurszugänge, Termine):
                    // die IDs sind dann nicht mehr über die Tabellen auffindbar,
                    // die user_id steht aber im JSON.
                    ->orWhereRaw("before_data->>'user_id' = ?", [$user->id])
                    ->orWhereRaw("after_data->>'user_id' = ?", [$user->id])
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
                    })
                    ->when($praxisExamAppointmentIds !== [], function ($query) use ($praxisExamAppointmentIds) {
                        $query->orWhere(function ($inner) use ($praxisExamAppointmentIds) {
                            $inner->where('entity_type', 'praxis_exam_appointment')->whereIn('entity_id', $praxisExamAppointmentIds);
                        });
                    });
            })
            ->update(['before_data' => null, 'after_data' => null]);
    }

    /**
     * Bug-Review (Kontolöschung hinterlässt E-Mail im Superadmin-Protokoll):
     * Superadmin\UserController protokolliert superadmin.user.create (ohne
     * Bootsschul-Zuordnung) und superadmin.user.update mit tenant_id NULL
     * und der E-Mail-Adresse im JSON -- pseudonymizeAuditLogFor() oben
     * arbeitet bewusst nur auf dem verlassenen Mandanten und erfasste diese
     * plattformweiten Zeilen nie, die Adresse überlebte also auch die
     * vollständige Löschung des Kontos. Nur bei der vollständigen Löschung
     * relevant: solange das Konto bei einer anderen Bootsschule weiter
     * besteht, bleibt auch sein plattformweiter Audit-Trail berechtigt.
     *
     * RLS (audit_log_update_isolation, siehe 000459/000483) lässt Zeilen
     * ohne tenant_id auf der App-Verbindung grundsätzlich nicht zu -- das
     * Update läuft deshalb über die Owner-Verbindung. Der verlassene
     * Mandant ist dabei ausdrücklich ausgeschlossen: dessen Zeilen hat
     * pseudonymizeAuditLogFor() bereits in der noch offenen Request-
     * Transaktion gesperrt, die Owner-Verbindung (andere Postgres-Session)
     * müsste darauf warten -- derselbe Deadlock wie in Sicherheitsreview M5.
     * Aus demselben Grund muss dieser Aufruf VOR deleteAccountEntirely()
     * laufen: dessen app_user-DELETE setzt per ON DELETE SET NULL
     * actor_user_id auf audit_log-Zeilen und sperrt sie damit ebenfalls.
     */
    private function pseudonymizePlatformAuditLogFor(User $user, Tenant $leftTenant): void
    {
        $this->onAdminConnection(fn () => AuditLog::where('entity_type', 'app_user')
            ->where('entity_id', $user->id)
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', '!=', $leftTenant->id))
            ->update(['before_data' => null, 'after_data' => null]));

        $this->onAdminConnection(fn () => $this->forgetAdminEmailInTenantCreateEntries($user));
    }

    /**
     * Bug-Review (E-Mail im Protokoll der Schul-Anlage): beim Anlegen
     * einer Bootsschule protokolliert Superadmin\TenantController die
     * E-Mail des Inhabers (entity_type "tenant") -- dieser Eintrag
     * betrifft nicht das Nutzerkonto und blieb deshalb stehen.
     */
    /**
     * Protokolldaten zu Konten, die gelöscht werden: Einträge zum Konto selbst
     * leeren und die Adresse aus superadmin.tenant.create entfernen -- je
     * Block eine Abfrage statt einer je Konto (Audit ARC-3, Offboarding).
     *
     * @param  Collection<int, User>  $users
     */
    public function forgetOrphanAuditData(Collection $users): void
    {
        $this->onAdminConnection(function () use ($users) {
            foreach ($users->chunk(1000) as $chunk) {
                AuditLog::where('entity_type', 'app_user')->whereIn('entity_id', $chunk->pluck('id')->all())
                    ->update(['before_data' => null, 'after_data' => null]);
                $emails = $chunk->pluck('email')->filter()->values()->all();
                if ($emails !== []) {
                    AuditLog::where('action', 'superadmin.tenant.create')
                        ->whereIn(DB::raw("after_data->>'admin_email'"), $emails)
                        ->update(['after_data' => DB::raw("after_data - 'admin_email'")]);
                }
            }
        });
    }

    private function forgetAdminEmailInTenantCreateEntries(User $user): void
    {
        AuditLog::where('action', 'superadmin.tenant.create')
            ->whereRaw("after_data->>'admin_email' = ?", [$user->email])
            ->update(['after_data' => DB::raw("after_data - 'admin_email'")]);
    }

    /**
     * QA-D-6: von der Person bei dieser Schule eingelöste Codes bleiben als
     * Bestellnachweis stehen, verweisen aber nicht mehr auf das Konto.
     */
    private function detachRedeemedCoupons(Tenant $tenant, User $user): void
    {
        DB::table('coupon')->where('redeemed_tenant_id', $tenant->id)->where('redeemed_by_user_id', $user->id)
            ->update(['redeemed_by_user_id' => null]);
    }

    /**
     * QA-D-6: mit der Person verknüpfte Shopkonten dieser Schule wie in
     * deleteAccountEntirely() anonymisieren und widerrufen -- auch wenn das
     * Konto bei anderen Schulen weiterbesteht. Owner-Verbindung (die App-Rolle
     * darf nur den Status ändern); die Request-Transaktion hat diese Zeilen
     * nicht angefasst, es entsteht also kein Warten über zwei Sitzungen.
     */
    private function revokeShopAccountsAt(Tenant $tenant, User $user): void
    {
        $commerce = DB::connection('pgsql_admin');
        $shopAccountIds = $commerce->table('commerce_shop_account')
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('id');
        $this->anonymizeShopAccounts($shopAccountIds->all());
    }

    /**
     * Zuerst die Ereignisse (Owner-Verbindung), dann die Konten -- in dieser
     * Reihenfolge kann kein Warten über zwei Sitzungen hinweg entstehen.
     *
     * @param  list<string>  $shopAccountIds
     */
    private function anonymizeShopAccounts(array $shopAccountIds): void
    {
        if ($shopAccountIds === []) {
            return;
        }
        $commerce = DB::connection('pgsql_admin');
        $commerce->table('commerce_event')->whereIn('shop_account_id', $shopAccountIds)
            ->update(['payload' => DB::raw("payload - 'email' - 'first_name' - 'last_name'")]);
        foreach ($shopAccountIds as $shopAccountId) {
            $commerce->table('commerce_shop_account')->where('id', $shopAccountId)->update([
                'email' => 'geloescht-'.$shopAccountId.'@invalid.invalid', 'contact_name' => null, 'company_name' => null, 'vat_id' => null,
                'user_id' => null, 'status' => DB::raw("CASE WHEN status = 'rejected' THEN 'rejected' ELSE 'revoked' END"), 'revoked_at' => now(),
            ]);
        }
    }

    private function deleteAccountEntirely(User $user): void
    {
        // Keine Fremdschlüssel auf diesen Tabellen -- ohne das explizite
        // Löschen blieben Sitzungen und Reset-Tokens als verwaiste
        // personenbezogene Daten stehen.
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('account_setup_tokens')->where('email', $user->email)->delete();
        // App-Einstellungen gelten fürs Konto (nicht je Schule); der Fremdschlüssel
        // kaskadiert zwar, gelöscht wird aber ausdrücklich wie bei den Sitzungen.
        DB::table('api_user_setting')->where('user_id', $user->id)->delete();

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
        // Shopkonten dieser Person (Kontaktdaten aus dem Shop): anonymisieren
        // statt löschen -- die Bestellhistorie der Schule (coupon_batch)
        // verweist darauf. Ausgehende Ereignisse ohne Adresse/Name.
        // Per E-Mail nur Konten ohne App-Bezug (offene Registrierungen): ein
        // verknüpftes Shopkonto mit dieser Adresse kann einer anderen Person
        // (dem Admin, der den Code erzeugt hat) zugeordnet sein.
        // Owner-Verbindung: Shopkonten unterliegen RLS je Schule, die Person kann
        // aber für mehrere Schulen (oder noch keine) eingetragen sein.
        $commerce = DB::connection('pgsql_admin');
        $shopAccountIds = $commerce->table('commerce_shop_account')
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->orWhere(fn ($e) => $e->whereNull('user_id')->whereIn('status', ['pending_review', 'awaiting_link'])
                    ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])))
            ->pluck('id');
        $this->anonymizeShopAccounts($shopAccountIds->all());

        DB::table('app_user')->where('id', $user->id)->delete();
    }
}
