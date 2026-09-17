<?php

namespace Tests\Feature\Concerns;

use App\Models\ContentQuestion;
use App\Models\ContentQuestionRevision;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantUser;
use App\Models\User;
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
            $user = User::create(array_merge([
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
