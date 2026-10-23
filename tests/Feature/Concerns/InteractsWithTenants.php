<?php

namespace Tests\Feature\Concerns;

use App\Models\AuditLog;
use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Feature-Tests laufen über die reale App-Rolle (RLS aktiv), damit die
 * Tenant-Isolation end-to-end geprüft wird -- nicht nur der App-Scope.
 * Fixtures (Tenant/Nutzer) werden deshalb über die Owner-Verbindung
 * "pgsql_admin" angelegt und committed (keine Transaktion, da Owner- und
 * App-Rolle getrennte DB-Sessions sind); tearDown räumt sie wieder auf.
 * Globaler Content (Module/Kurse/Fragen) wird aus dem geseedeten Katalog
 * wiederverwendet statt pro Test dupliziert.
 */
trait InteractsWithTenants
{
    protected array $createdTenantIds = [];

    protected array $createdUserIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        parent::tearDown();
    }

    protected function cleanUpCreatedTenantsAndUsers(): void
    {
        $this->cleanUpCreatedTenants();
        $this->cleanUpCreatedUsers();
    }

    /** Kaskadiert tenant_user/entitlement/attempt/progress/favorite/exam_session weg. */
    protected function cleanUpCreatedTenants(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdTenantIds as $id) {
                // Bug-Review Testisolation: audit_log.tenant_id ist bewusst
                // nullOnDelete() (ein echtes Audit-Log soll eine gelöschte
                // Bootsschule überleben) -- für die hier angelegten
                // Test-Tenants heißt das aber, dass jede protokollierte
                // Aktion (Teilnehmer bearbeiten, Freischalten, ...)
                // dauerhaft als verwaister Datensatz (tenant_id = NULL)
                // stehen bleibt, statt mit dem Tenant zu verschwinden.
                AuditLog::where('tenant_id', $id)->delete();

                Tenant::withoutGlobalScopes()->where('id', $id)->delete();
            }
        });

        $this->createdTenantIds = [];
    }

    /** Erst aufrufen, nachdem alles, was per FK auf den Nutzer zeigt (z.B. exam_rule_set.verified_by), weg ist. */
    protected function cleanUpCreatedUsers(): void
    {
        $this->onAdmin(function () {
            foreach ($this->createdUserIds as $id) {
                $user = User::find($id);

                // Bug-Review Testisolation: password_reset_tokens hat gar
                // keinen Fremdschlüssel (die Tabelle ist nur per E-Mail-
                // String geschlüsselt, Laravel-Standardschema) -- ein
                // Test, der einen Reset-Link auslöst (z. B. Registrierung,
                // Einladung, siehe BestEffortMail/Password::sendResetLink),
                // hinterließ die Zeile deshalb dauerhaft, unabhängig vom
                // Löschen des Nutzers selbst.
                if ($user) {
                    DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                }

                User::where('id', $id)->delete();
            }
        });

        $this->createdUserIds = [];
    }

    protected function onAdmin(callable $callback): mixed
    {
        $original = config('database.default');
        config(['database.default' => 'pgsql_admin']);

        try {
            return $callback();
        } finally {
            config(['database.default' => $original]);
        }
    }

    /**
     * Sicherheitsreview M5: app.current_tenant_id wird per SET LOCAL
     * gesetzt und gilt deshalb nur innerhalb der Transaktion, in der es
     * gesetzt wurde (siehe TenantContext, ResolveTenant) -- eine über
     * actingAsInTenant() ausgelöste HTTP-Anfrage setzt den Kontext also
     * nicht mehr über das Ende dieser Anfrage hinaus. Eine direkte
     * Eloquent-Abfrage auf die App-Rolle (RLS aktiv) NACH einem solchen
     * Request braucht deshalb ihren eigenen, frischen Tenant-Kontext --
     * genau das stellt dieser Helfer her.
     */
    protected function asTenant(Tenant $tenant, callable $callback): mixed
    {
        return DB::transaction(function () use ($tenant, $callback) {
            app(TenantContext::class)->set($tenant);

            return $callback();
        });
    }

    protected function createTestTenant(string $namePrefix = 'Test Schule'): Tenant
    {
        return $this->onAdmin(function () use ($namePrefix) {
            $slug = Str::slug($namePrefix).'-'.Str::random(8);

            $tenant = Tenant::create(['slug' => $slug, 'name' => $namePrefix, 'status' => 'active']);
            TenantBranding::create(['tenant_id' => $tenant->id, 'primary_color' => '#123456']);

            $this->createdTenantIds[] = $tenant->id;

            return $tenant;
        });
    }

    protected function createTenantUser(Tenant $tenant, string $role = 'learner', array $attributes = []): User
    {
        return $this->onAdmin(function () use ($tenant, $role, $attributes) {
            // forceCreate statt create(): is_superadmin ist bewusst nicht
            // $fillable (Sicherheitsreview N4), Testfixtures dürfen es trotzdem
            // gezielt setzen können (siehe NavigationMenuTest).
            $user = User::forceCreate(array_merge([
                'email' => Str::uuid().'@example.test',
                'display_name' => 'Test '.Str::ucfirst($role),
                'password' => bcrypt('password'),
                'status' => 'active',
                'email_verified_at' => now(),
            ], $attributes));

            TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);

            $this->createdUserIds[] = $user->id;

            return $user;
        });
    }

    /**
     * Füllt Adresse + Geburtsdatum, damit der User das Profil-Gate vor der
     * Praxisformular-Anmeldung passiert (siehe
     * User::hasCompleteRegistrationProfile, PraxisPruefungController).
     */
    protected function completeRegistrationProfile(User $user): User
    {
        return $this->onAdmin(function () use ($user) {
            $user->update([
                'street' => 'Teststraße 1',
                'postal_code' => '12345',
                'city' => 'Teststadt',
                'country' => 'Deutschland',
                'birthdate' => '1990-01-01',
            ]);

            return $user->fresh();
        });
    }

    protected function grantEntitlement(Tenant $tenant, User $user, CourseDefinition $course): Entitlement
    {
        return $this->onAdmin(fn () => Entitlement::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'active',
            'source_type' => 'manual',
            'valid_from' => now()->subMinute(),
        ]));
    }

    protected function createSuperAdmin(array $attributes = []): User
    {
        return $this->onAdmin(function () use ($attributes) {
            $user = User::forceCreate(array_merge([
                'email' => Str::uuid().'@platform.test',
                'display_name' => 'Test Superadmin',
                'password' => bcrypt('password'),
                'status' => 'active',
                'email_verified_at' => now(),
                'is_superadmin' => true,
            ], $attributes));

            $this->createdUserIds[] = $user->id;

            return $user;
        });
    }

    protected function existingCourse(string $code): CourseDefinition
    {
        return $this->onAdmin(fn () => CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', $code)->firstOrFail());
    }

    protected function existingModule(string $code): Module
    {
        return $this->onAdmin(fn () => Module::where('code', $code)->firstOrFail());
    }

    /** @return array{0: ContentQuestion, 1: ContentQuestionRevision} */
    protected function anyPublishedQuestionIn(Module $module): array
    {
        return $this->onAdmin(function () use ($module) {
            $question = $module->questions()
                ->whereHas('revisions', fn ($q) => $q->where('editorial_status', 'published'))
                ->orderBy('content_question.content_id')
                ->firstOrFail();

            $revision = $question->revisions()->where('editorial_status', 'published')->orderByDesc('revision_no')->firstOrFail();
            $revision->load('answers');

            return [$question, $revision];
        });
    }

    protected function actingAsInTenant(User $user, Tenant $tenant): static
    {
        $this->withSession(['dev_tenant_slug' => $tenant->slug]);

        return $this->actingAs($user);
    }
}
