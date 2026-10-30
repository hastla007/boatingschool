<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\RadioTrainingProgress;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Kontolöschung hinterlässt Prüfungstermine, Superadmin-
 * Protokoll und Funk-Training): pseudonymizeAuditLogFor() kannte die
 * Protokollzeilen zu praxis_exam_appointment (inkl. response_comment des
 * Lerners) und die plattformweiten superadmin.user.create/update-Zeilen
 * (tenant_id NULL, mit E-Mail-Adresse) nicht, und radio_training_progress
 * fehlte in den beim Verlassen einer Bootsschule gelöschten Tabellen.
 */
class AccountErasureCoversAppointmentAuditPlatformAuditAndRadioTrainingTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    private array $createdAuditLogIds = [];

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            AuditLog::whereIn('id', $this->createdAuditLogIds)->delete();
            PraxisFormTemplate::whereIn('id', $this->createdTemplateIds)->delete();
        });

        parent::tearDown();
    }

    public function test_deleting_the_account_scrubs_the_exam_appointment_audit_log(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $appointment = $this->onAdmin(function () use ($tenant, $learner, $course) {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Termin-Löschtest '.Str::random(8),
                'min_progress_percent' => 0,
                'course_ids' => [$course->id],
                'fields' => [],
                'active' => false,
            ]);
            $this->createdTemplateIds[] = $template->id;

            $submission = PraxisFormSubmission::create([
                'tenant_id' => $tenant->id,
                'course_id' => $course->id,
                'user_id' => $learner->id,
                'praxis_form_template_id' => $template->id,
                'data' => [],
            ]);

            return PraxisExamAppointment::create([
                'tenant_id' => $tenant->id,
                'praxis_form_submission_id' => $submission->id,
                'slot' => 0,
                'round' => 1,
                'label' => 'Prüfungstermin',
                'exam_date' => now()->addWeek(),
                'response' => 'accepted',
                'response_comment' => 'Ich bin ab 14 Uhr beim Arzt, Tel. 0170 1234567',
                'responded_at' => now(),
            ]);
        });

        // Exakt wie Admin\PraxisExamAppointmentController::cancel() es
        // protokolliert: der komplette Termin samt response_comment.
        $auditLogId = $this->onAdmin(fn () => AuditLog::record(
            $tenant->id, $admin->id, 'praxis-exam-appointment.cancel',
            'praxis_exam_appointment', $appointment->id, $appointment->toArray(), $appointment->toArray()
        )->id);

        $this->actingAsInTenant($learner, $tenant)->delete('/profile', ['password' => 'password']);

        $log = $this->onAdmin(fn () => AuditLog::find($auditLogId));
        $this->assertNotNull($log, 'Die Protokollzeile selbst bleibt als Nachweis bestehen.');
        $this->assertNull($log->before_data);
        $this->assertNull($log->after_data);
    }

    public function test_fully_deleting_the_account_scrubs_the_platform_wide_superadmin_audit_log(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant();

        $email = 'loeschtest-'.Str::lower(Str::random(8)).'@example.test';
        $this->actingAs($superadmin)->post('/superadmin/users', [
            'first_name' => 'Lösch',
            'last_name' => 'Test',
            'email' => $email,
        ])->assertRedirect();

        $learner = $this->onAdmin(fn () => User::where('email', $email)->firstOrFail());
        $this->createdUserIds[] = $learner->id;

        $this->actingAs($superadmin)->patch("/superadmin/users/{$learner->id}", [
            'first_name' => 'Lösch',
            'last_name' => 'Test',
            'email' => $email,
            'status' => 'active',
        ])->assertRedirect();

        $this->onAdmin(fn () => TenantUser::create([
            'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'role' => 'learner', 'status' => 'active',
        ]));
        $this->onAdmin(fn () => $learner->forceFill(['password' => bcrypt('password'), 'email_verified_at' => now()])->save());

        $platformLogIds = $this->onAdmin(fn () => AuditLog::whereNull('tenant_id')
            ->where('entity_type', 'app_user')->where('entity_id', $learner->id)
            ->whereIn('action', ['superadmin.user.create', 'superadmin.user.update'])
            ->pluck('id')->all());
        $this->createdAuditLogIds = $platformLogIds;
        $this->assertCount(2, $platformLogIds);

        // Protokoll der Schul-Anlage mit der E-Mail des (späteren) Inhabers.
        $tenantCreateLog = $this->onAdmin(fn () => AuditLog::record($tenant->id, $superadmin->id, 'superadmin.tenant.create', 'tenant', $tenant->id, null, [
            'slug' => $tenant->slug, 'name' => $tenant->name, 'status' => $tenant->status, 'admin_email' => $email,
        ]));
        $this->createdAuditLogIds[] = $tenantCreateLog->id;

        $this->actingAsInTenant($learner->fresh(), $tenant)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        $this->onAdmin(function () use ($learner, $platformLogIds, $email, $tenantCreateLog, $tenant) {
            $this->assertEquals(['slug' => $tenant->slug, 'name' => $tenant->name, 'status' => $tenant->status], AuditLog::find($tenantCreateLog->id)->after_data);

            $this->assertNull(User::find($learner->id), 'Das Konto sollte vollständig gelöscht sein.');

            foreach (AuditLog::whereIn('id', $platformLogIds)->get() as $log) {
                $this->assertNull($log->before_data, $log->action);
                $this->assertNull($log->after_data, $log->action);
            }

            $this->assertFalse(AuditLog::whereRaw('after_data::text ILIKE ?', ['%'.$email.'%'])
                ->orWhereRaw('before_data::text ILIKE ?', ['%'.$email.'%'])->exists());
        });
    }

    public function test_leaving_one_tenant_keeps_the_platform_wide_audit_log_while_the_account_remains(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $learner = $this->createTenantUser($tenantA, 'learner');
        $this->onAdmin(fn () => TenantUser::create([
            'tenant_id' => $tenantB->id, 'user_id' => $learner->id, 'role' => 'learner', 'status' => 'active',
        ]));

        $logId = $this->onAdmin(fn () => AuditLog::record(null, null, 'superadmin.user.update', 'app_user', $learner->id,
            ['email' => $learner->email], ['email' => $learner->email])->id);
        $this->createdAuditLogIds[] = $logId;

        $this->actingAsInTenant($learner, $tenantA)->delete('/profile', ['password' => 'password']);

        $this->assertSame(['email' => $learner->email], $this->onAdmin(fn () => AuditLog::find($logId))->after_data);
    }

    public function test_leaving_a_tenant_removes_that_tenants_radio_training_progress_but_not_a_second_tenants(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $learner = $this->createTenantUser($tenantA, 'learner');
        $this->onAdmin(fn () => TenantUser::create([
            'tenant_id' => $tenantB->id, 'user_id' => $learner->id, 'role' => 'learner', 'status' => 'active',
        ]));

        $this->onAdmin(function () use ($tenantA, $tenantB, $learner) {
            foreach ([$tenantA, $tenantB] as $tenant) {
                RadioTrainingProgress::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $learner->id,
                    'item_type' => RadioTrainingProgress::TYPE_TEXT,
                    'item_key' => 'SRC-07',
                    'completed' => true,
                    'answers' => ['transcript' => 'Mayday Mayday Mayday, hier ist Segelyacht Test'],
                ]);
            }
        });

        $this->actingAsInTenant($learner, $tenantA)->delete('/profile', ['password' => 'password']);

        $this->onAdmin(function () use ($tenantA, $tenantB, $learner) {
            $this->assertFalse(RadioTrainingProgress::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->where('user_id', $learner->id)->exists());
            $this->assertTrue(RadioTrainingProgress::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->where('user_id', $learner->id)->exists());
        });
    }
}
