<?php

namespace Tests\Feature;

use App\Models\FavoriteCollection;
use App\Models\FavoriteCollectionItem;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * DSGVO-Review D3: Auskunfts-/Mitnahmerecht (Art. 15/20) -- bislang gab es
 * keinen Export-Endpoint.
 */
class PersonalDataExportTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdTemplateIds = [];

    protected function tearDown(): void
    {
        // Muss vor dem Löschen der Vorlage laufen: cleanUpCreatedTenants()
        // kaskadiert die Anmeldung (praxis_form_submission) weg, die sonst
        // per Fremdschlüssel noch auf die Vorlage zeigt.
        $this->cleanUpCreatedTenantsAndUsers();

        $this->onAdmin(function () {
            foreach ($this->createdTemplateIds as $id) {
                PraxisFormTemplate::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_a_learner_can_export_their_data_at_the_current_tenant(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get('/profile/export-data');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('Content-Disposition');

        $data = json_decode($response->getContent(), true);

        $this->assertSame($tenant->id, $data['tenant']['id']);
        $this->assertSame($learner->email, $data['profile']['email']);
        $this->assertSame('learner', $data['membership']['role']);
        $this->assertCount(1, $data['entitlements']);
        $this->assertSame($course->name, $data['entitlements'][0]['course']);
    }

    public function test_the_export_only_contains_data_from_the_current_tenant(): void
    {
        $tenantA = $this->createTestTenant();
        $tenantB = $this->createTestTenant();
        $user = $this->createTenantUser($tenantA, 'learner');
        $this->onAdmin(fn () => TenantUser::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $user->id,
            'role' => 'learner',
            'status' => 'active',
        ]));

        $courseA = $this->existingCourse('SRC');
        $courseB = $this->existingCourse('UBI');
        $this->grantEntitlement($tenantA, $user, $courseA);
        $this->grantEntitlement($tenantB, $user, $courseB);

        $response = $this->actingAsInTenant($user, $tenantA)->get('/profile/export-data');

        $data = json_decode($response->getContent(), true);

        $this->assertCount(1, $data['entitlements']);
        $this->assertSame($courseA->name, $data['entitlements'][0]['course']);

        $this->createdUserIds[] = $user->id;
    }

    public function test_the_export_requires_authentication(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/profile/export-data');

        $response->assertRedirect('/login');
    }

    /**
     * N8/D3: Praxis-/Prüfungstermine und benannte Favoriten-Sammlungen kamen
     * nach der ersten Fassung dieses Exports hinzu und fehlten deshalb.
     */
    public function test_the_export_includes_praxis_exam_appointments_and_favorite_collections(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(function () use ($tenant, $learner, $course) {
            $template = PraxisFormTemplate::create([
                'title' => 'E2E Export-Formular',
                'min_progress_percent' => 0,
                'course_ids' => [$course->id],
                'fields' => [],
                'active' => true,
            ]);
            $this->createdTemplateIds[] = $template->id;

            $submission = PraxisFormSubmission::create([
                'tenant_id' => $tenant->id,
                'course_id' => $course->id,
                'user_id' => $learner->id,
                'praxis_form_template_id' => $template->id,
                'data' => [],
            ]);

            PraxisExamAppointment::create([
                'tenant_id' => $tenant->id,
                'praxis_form_submission_id' => $submission->id,
                'slot' => 0,
                'round' => 1,
                'label' => 'Prüfungstermin',
                'exam_date' => now()->addWeek(),
                'response' => 'accepted',
            ]);

            $collection = FavoriteCollection::create([
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'course_id' => $course->id,
                'name' => 'Meine schwierigen Fragen',
                'is_default' => false,
                'sort_order' => 1,
            ]);

            $module = $course->modules->first();
            $question = $module->questions()->first();

            FavoriteCollectionItem::create([
                'tenant_id' => $tenant->id,
                'favorite_collection_id' => $collection->id,
                'question_id' => $question->id,
                'context' => FavoriteCollectionItem::CONTEXT_SMART_LEARNING,
            ]);
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get('/profile/export-data');
        $data = json_decode($response->getContent(), true);

        $this->assertCount(1, $data['praxis_exam_appointments']);
        $this->assertSame('Prüfungstermin', $data['praxis_exam_appointments'][0]['label']);
        $this->assertSame('accepted', $data['praxis_exam_appointments'][0]['response']);

        $this->assertCount(1, $data['favorite_collections']);
        $this->assertSame('Meine schwierigen Fragen', $data['favorite_collections'][0]['name']);

        // Bug-Review BB7: nicht nur die Sammlung selbst (ihr Name), sondern
        // auch, welche Fragen tatsächlich in sie einsortiert wurden, gehört
        // zum Auskunftsrecht.
        $this->assertCount(1, $data['favorite_collection_items']);
        $this->assertSame(FavoriteCollectionItem::CONTEXT_SMART_LEARNING, $data['favorite_collection_items'][0]['context']);

        $this->createdUserIds[] = $learner->id;
    }

    /**
     * DSGVO-Review D3 (Folgefehler): eine Einladung an eine bereits
     * existierende Adresse legt eine tenant_invitation-Zeile an (siehe
     * ParticipantController::invite(), Sicherheitsreview H2) -- die fehlte
     * im Auskunfts-Export bisher vollständig.
     */
    public function test_the_export_includes_a_pending_invitation_for_the_learners_email(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $admin = $this->createTenantUser($tenant, 'owner');

        $this->onAdmin(fn () => TenantInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => $learner->email,
            'role' => 'learner',
            'invited_by_user_id' => $admin->id,
        ]));

        $response = $this->actingAsInTenant($learner, $tenant)->get('/profile/export-data');
        $data = json_decode($response->getContent(), true);

        $this->assertCount(1, $data['tenant_invitations']);
        $this->assertSame('learner', $data['tenant_invitations'][0]['role']);
        $this->assertNull($data['tenant_invitations'][0]['accepted_at']);

        $this->createdUserIds[] = $admin->id;
    }
}
