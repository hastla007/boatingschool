<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\WillkommensboxOrder;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Kontolöschung hinterlässt personenbezogene Daten im Admin-
 * Protokoll): WillkommensboxOrderController::ship() und
 * PraxisFormSubmissionController protokollieren die Lieferadresse bzw. die
 * Formulardaten vollständig als JSON in audit_log, mit actor_user_id des
 * handelnden Admins -- AccountErasureService::pseudonymizeAuditLogFor()
 * kannte diese beiden Entitäten bisher nicht und ließ die Daten beim
 * Löschen des Lerner-Kontos unverändert stehen.
 */
class AccountDeletionPseudonymizesWillkommensboxAndPraxisFormAuditLogTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    protected function tearDown(): void
    {
        if ($this->createdTemplateIds !== []) {
            $this->onAdmin(fn () => PraxisFormTemplate::whereIn('id', $this->createdTemplateIds)->delete());
        }

        parent::tearDown();
    }

    public function test_deleting_the_account_scrubs_the_willkommensbox_order_audit_log(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $admin = $this->createTenantUser($tenant, 'owner');

        $order = $this->onAdmin(fn () => WillkommensboxOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => $learner->id,
            'street' => 'Teststraße 1',
            'postal_code' => '12345',
            'city' => 'Teststadt',
            'country' => 'Deutschland',
        ]));

        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.willkommensbox-orders.ship', $order));

        $auditLogId = $this->onAdmin(fn () => AuditLog::where('tenant_id', $tenant->id)
            ->where('entity_type', 'willkommensbox_order')->where('entity_id', $order->id)->value('id'));
        $this->assertNotNull($auditLogId, 'Erwartet einen Audit-Log-Eintrag für den Versand.');

        $this->actingAsInTenant($learner, $tenant)->delete('/profile', ['password' => 'password']);

        $log = $this->onAdmin(fn () => AuditLog::find($auditLogId));
        $this->assertNull($log->before_data);
        $this->assertNull($log->after_data);
    }

    public function test_deleting_the_account_scrubs_the_praxis_form_submission_audit_log(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $admin = $this->createTenantUser($tenant, 'owner');
        $course = $this->existingCourse('SRC');

        $template = $this->onAdmin(function () use ($course) {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Löschtest-Formular '.Str::random(8),
                'min_progress_percent' => 0,
                'course_ids' => [$course->id],
                'fields' => [],
                'active' => true,
            ]);
            $this->createdTemplateIds[] = $template->id;

            return $template;
        });

        $submission = $this->onAdmin(fn () => PraxisFormSubmission::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $learner->id,
            'praxis_form_template_id' => $template->id,
            'data' => ['notiz' => 'Persönliche Angabe des Lerners'],
        ]));

        // Die Anmeldung muss zum Zeitpunkt der Kontolöschung noch existieren
        // -- leaveTenant() löscht PraxisFormSubmission selbst (siehe
        // tenantScopedModels()) und sammelt ihre ID dafür vorher ein, um den
        // Audit-Log-Eintrag anschließend zu bereinigen. Eine vorab vom Admin
        // gelöschte Anmeldung würde das hier nicht mehr testen.
        $this->actingAsInTenant($admin, $tenant)->patch(route('admin.praxis-form-submissions.update', $submission), []);

        $auditLogId = $this->onAdmin(fn () => AuditLog::where('tenant_id', $tenant->id)
            ->where('entity_type', 'praxis_form_submission')->where('entity_id', $submission->id)
            ->where('action', 'praxis-form-submission.update')->value('id'));
        $this->assertNotNull($auditLogId, 'Erwartet einen Audit-Log-Eintrag für die Aktualisierung der Anmeldung.');

        $this->actingAsInTenant($learner, $tenant)->delete('/profile', ['password' => 'password']);

        $log = $this->onAdmin(fn () => AuditLog::find($auditLogId));
        $this->assertNull($log->before_data);
        $this->assertNull($log->after_data);
    }
}
