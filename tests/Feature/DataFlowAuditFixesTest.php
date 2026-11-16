<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CommerceShopAccount;
use App\Models\ContentQuestion;
use App\Models\Coupon;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AccountErasureService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Audit WP6 (Daten- und Datenschutz-Abläufe): Codes beim Vertragsende,
 * Protokoll-Kopien, Suchbegriffe im Protokoll, gemeinsamer Lösch-Pfad,
 * Schule verlassen, Code beim Einladen, Superadmin-Code-Sperre, Import.
 */
class DataFlowAuditFixesTest extends TestCase
{
    use InteractsWithTenants;

    private array $couponIds = [];

    private array $shopAccountIds = [];

    private array $auditLogIds = [];

    private ?string $fixturePath = null;

    protected function tearDown(): void
    {
        $this->onAdmin(function () {
            Coupon::whereIn('id', $this->couponIds)->delete();
            DB::table('commerce_event')->whereIn('shop_account_id', $this->shopAccountIds)->delete();
            CommerceShopAccount::whereIn('id', $this->shopAccountIds)->delete();
            AuditLog::whereIn('id', $this->auditLogIds)->delete();
            $question = ContentQuestion::where('content_id', 'TEST-QA-D-0001')->first();
            if ($question) {
                DB::table('module_content')->where('question_id', $question->id)->delete();
                $question->delete();
            }
        });
        if ($this->fixturePath && file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    private function createCoupon(array $attributes): Coupon
    {
        $coupon = $this->onAdmin(fn () => Coupon::create($attributes + [
            'code' => Coupon::generateUniqueCode(), 'course_id' => $this->existingCourse('SRC')->id,
        ]));
        $this->couponIds[] = $coupon->id;

        return $coupon;
    }

    // -- QA-D-2 ---------------------------------------------------------------

    /**
     * Nicht eingelöste Codes verschwinden per Trigger (Migration 000473) mit
     * der Schule -- der Export sagt das, und danach kann keine andere Schule
     * den Code einlösen oder übernehmen.
     */
    public function test_open_codes_of_a_deleted_school_are_announced_in_the_export_and_unusable_afterwards(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant('Code Lösch Schule');
        $open = $this->createCoupon(['tenant_id' => $tenant->id]);
        $other = $this->createTestTenant('Fremde Schule');
        $otherAdmin = $this->createTenantUser($other, 'owner');
        $otherLearner = $this->createTenantUser($other, 'learner');

        $this->onAdmin(fn () => $tenant->update(['status' => 'closed']));
        $export = json_decode($this->actingAs($superadmin)->get("/superadmin/tenants/{$tenant->id}/export")->streamedContent(), true);
        $this->assertSame('offen, wird beim Löschen der Schule gelöscht', $export['coupons'][0]['status']);

        $this->actingAs($superadmin)->delete("/superadmin/tenants/{$tenant->id}", ['confirm_slug' => $tenant->slug])
            ->assertSessionHasNoErrors()->assertRedirect(route('superadmin.tenants.index'));

        $this->assertNull($this->onAdmin(fn () => Coupon::find($open->id)));
        $this->actingAsInTenant($otherLearner, $other)->post('/coupons/redeem', ['code' => $open->code])->assertSessionHasErrors('code');
        $this->actingAsInTenant($otherAdmin, $other)->post('/admin/coupons/import', ['codes' => $open->code]);
        $this->assertFalse($this->onAdmin(fn () => Coupon::where('code', $open->code)->exists()));
    }

    // -- QA-D-3 ---------------------------------------------------------------

    public function test_deleting_a_praxis_submission_clears_earlier_log_copies_of_it_and_its_appointments(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $learner = $this->createTenantUser($tenant, 'learner');
        [$submission, $appointment, $keep] = $this->onAdmin(function () use ($tenant, $learner) {
            $template = PraxisFormTemplate::query()->value('id');
            $course = $this->existingCourse('SRC')->id;
            $submission = PraxisFormSubmission::create(['tenant_id' => $tenant->id, 'course_id' => $course, 'user_id' => $learner->id,
                'praxis_form_template_id' => $template, 'data' => ['geburtsort' => 'GEHEIM-ORT']]);
            $other = PraxisFormSubmission::create(['tenant_id' => $tenant->id, 'course_id' => $this->existingCourse('UBI')->id, 'user_id' => $learner->id,
                'praxis_form_template_id' => $template, 'data' => ['geburtsort' => 'ANDERER-ORT']]);
            $appointment = PraxisExamAppointment::create(['tenant_id' => $tenant->id, 'praxis_form_submission_id' => $submission->id,
                'slot' => 0, 'round' => 1, 'label' => 'Prüfungstermin', 'exam_date' => now()->addWeek(), 'response' => 'declined', 'response_comment' => 'GEHEIM-KOMMENTAR']);

            return [$submission, $appointment, $other];
        });
        $update = $this->onAdmin(fn () => AuditLog::record($tenant->id, $admin->id, 'praxis-form-submission.update', 'praxis_form_submission', $submission->id, ['data' => ['geburtsort' => 'GEHEIM-ORT']], ['data' => ['geburtsort' => 'GEHEIM-ORT-NEU']]));
        $termin = $this->onAdmin(fn () => AuditLog::record($tenant->id, $admin->id, 'praxis-exam-appointment.withdraw', 'praxis_exam_appointment', $appointment->id, $appointment->toArray(), null));
        $unrelated = $this->onAdmin(fn () => AuditLog::record($tenant->id, $admin->id, 'praxis-form-submission.update', 'praxis_form_submission', $keep->id, ['data' => ['geburtsort' => 'ANDERER-ORT']], null));

        $this->actingAsInTenant($admin, $tenant)->delete("/admin/praxis-form-submissions/{$submission->id}")->assertRedirect();

        $this->onAdmin(function () use ($update, $termin, $unrelated) {
            foreach ([$update, $termin] as $entry) {
                $fresh = AuditLog::find($entry->id);
                $this->assertNotNull($fresh, 'Eintrag bleibt als Nachweis');
                $this->assertNull($fresh->before_data);
                $this->assertNull($fresh->after_data);
            }
            $this->assertNotNull(AuditLog::find($unrelated->id)->before_data, 'andere Anmeldung unberührt');
        });
    }

    // -- QA-D-4 ---------------------------------------------------------------

    public function test_the_participant_export_logs_only_that_a_search_was_used(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');

        $this->actingAsInTenant($admin, $tenant)->get('/admin/participants/export?p_search=Erika+Musterfrau&p_status=active')->assertOk();

        $log = $this->onAdmin(fn () => AuditLog::where('tenant_id', $tenant->id)->where('action', 'participants.export')->firstOrFail());
        $this->assertSame(['p_search' => true, 'p_status' => 'active'], $log->after_data['filters']);
        $this->assertStringNotContainsString('Musterfrau', json_encode($log->after_data));
    }

    public function test_superadmin_code_export_and_void_log_only_that_a_search_was_used(): void
    {
        $superadmin = $this->createSuperAdmin();
        $coupon = $this->createCoupon([]);

        $this->actingAs($superadmin)->post('/superadmin/coupons/export', ['ids' => [$coupon->id], 'search' => 'Erika Musterfrau'])->assertOk();
        $this->actingAs($superadmin)->post('/superadmin/coupons/void', ['ids' => [$coupon->id], 'search' => 'Erika Musterfrau'])->assertRedirect();

        $this->onAdmin(function () use ($superadmin) {
            foreach (['superadmin.coupon.export', 'superadmin.coupon.void'] as $action) {
                $log = AuditLog::where('actor_user_id', $superadmin->id)->where('action', $action)->firstOrFail();
                $this->auditLogIds[] = $log->id;
                $this->assertTrue($log->after_data['filters']['search']);
                $this->assertStringNotContainsString('Musterfrau', json_encode($log->after_data));
            }
        });
    }

    // -- QA-D-5 ---------------------------------------------------------------

    public function test_deleting_a_school_erases_orphaned_accounts_like_an_account_deletion(): void
    {
        $superadmin = $this->createSuperAdmin();
        $tenant = $this->createTestTenant('Orphan Schule');
        $learner = $this->createTenantUser($tenant, 'learner');
        $account = $this->onAdmin(fn () => CommerceShopAccount::create([
            'shop_id' => 'test-'.Str::lower(Str::random(8)), 'shop_customer_id' => '91', 'user_id' => $learner->id,
            'status' => 'pending_review', 'email' => $learner->email, 'contact_name' => 'Erika Musterfrau',
        ]));
        $this->shopAccountIds[] = $account->id;
        $this->onAdmin(fn () => DB::table('commerce_event')->insert([
            'direction' => 'in', 'type' => 'account.upsert', 'shop_account_id' => $account->id, 'status' => 'done',
            'payload' => json_encode(['email' => $learner->email, 'first_name' => 'Erika']),
        ]));
        $createLog = $this->onAdmin(fn () => AuditLog::record(null, $superadmin->id, 'superadmin.tenant.create', 'tenant', (string) Str::uuid(), null, ['slug' => 'irgendwo', 'admin_email' => $learner->email]));
        $this->auditLogIds[] = $createLog->id;
        $this->onAdmin(fn () => $tenant->update(['status' => 'closed']));

        $this->actingAs($superadmin)->delete("/superadmin/tenants/{$tenant->id}", ['confirm_slug' => $tenant->slug])->assertRedirect();

        $this->onAdmin(function () use ($learner, $account, $createLog) {
            $this->assertNull(User::find($learner->id));
            $fresh = CommerceShopAccount::find($account->id);
            $this->assertNull($fresh->contact_name);
            $this->assertSame('revoked', $fresh->status);
            $this->assertStringStartsWith('geloescht-', $fresh->email);
            $payload = json_decode(DB::table('commerce_event')->where('shop_account_id', $account->id)->value('payload'), true);
            $this->assertArrayNotHasKey('email', $payload);
            $this->assertArrayNotHasKey('admin_email', AuditLog::find($createLog->id)->after_data);
        });
    }

    // -- QA-D-6 ---------------------------------------------------------------

    public function test_leaving_one_school_cleans_its_codes_and_shop_accounts_but_keeps_account_wide_replies(): void
    {
        $tenant = $this->createTestTenant();
        $second = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'admin');
        $this->onAdmin(fn () => TenantUser::create(['tenant_id' => $second->id, 'user_id' => $user->id, 'role' => 'learner', 'status' => 'active']));
        $redeemed = $this->createCoupon(['redeemed_by_user_id' => $user->id, 'redeemed_tenant_id' => $tenant->id, 'redeemed_at' => now()]);
        $elsewhere = $this->createCoupon(['redeemed_by_user_id' => $user->id, 'redeemed_tenant_id' => $second->id, 'redeemed_at' => now()]);
        $account = $this->onAdmin(fn () => CommerceShopAccount::create([
            'shop_id' => 'test-'.Str::lower(Str::random(8)), 'shop_customer_id' => '92', 'user_id' => $user->id, 'tenant_id' => $tenant->id,
            'status' => 'linked', 'email' => $user->email, 'contact_name' => 'Erika Musterfrau', 'linked_at' => now(),
        ]));
        $this->shopAccountIds[] = $account->id;
        $this->onAdmin(fn () => DB::table('api_idempotency_key')->insert([
            'user_id' => $user->id, 'idempotency_key' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'status_code' => 200,
        ]));

        $this->asTenant($tenant, fn () => app(AccountErasureService::class)->leaveTenant(User::findOrFail($user->id), $tenant));

        $this->onAdmin(function () use ($user, $redeemed, $elsewhere, $account) {
            $this->assertNotNull(User::find($user->id), 'Konto besteht bei der zweiten Schule weiter');
            $this->assertNull(Coupon::find($redeemed->id)->redeemed_by_user_id);
            $this->assertSame($user->id, Coupon::find($elsewhere->id)->redeemed_by_user_id, 'Codes der anderen Schule unberührt');
            $fresh = CommerceShopAccount::find($account->id);
            $this->assertNull($fresh->user_id);
            $this->assertNull($fresh->contact_name);
            $this->assertSame('revoked', $fresh->status);
            $this->assertTrue(DB::table('api_idempotency_key')->where('user_id', $user->id)->exists(), 'gespeicherte Antworten anderer Schulen bleiben');
        });
    }

    // -- QA-D-7 ---------------------------------------------------------------

    public function test_a_code_without_dashes_is_assigned_when_inviting(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $coupon = $this->createCoupon(['tenant_id' => $tenant->id]);
        $email = 'qa-d7-'.uniqid().'@example.test';

        $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Code Ohne Striche', 'email' => $email, 'code' => strtolower(str_replace('-', '', $coupon->code)),
        ])->assertSessionHasNoErrors();

        $this->onAdmin(function () use ($coupon, $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->createdUserIds[] = $user->id;
            // Bis zur Annahme an der Einladung reserviert (InvitationCoupon), in der gespeicherten Schreibweise.
            $this->assertSame($coupon->code, TenantInvitation::where('email', $email)->value('coupon_code'));
            $this->assertNull(Coupon::find($coupon->id)->redeemed_at);
        });
    }

    public function test_a_voided_code_is_rejected_when_inviting(): void
    {
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $coupon = $this->createCoupon(['tenant_id' => $tenant->id, 'voided_at' => now()]);
        $email = 'qa-d7v-'.uniqid().'@example.test';

        $this->actingAsInTenant($admin, $tenant)->post('/admin/participants/invite', [
            'name' => 'Gesperrter Code', 'email' => $email, 'code' => $coupon->code,
        ])->assertSessionHasErrors(['code' => 'Dieser Code ist nicht mehr gültig.']);

        $this->onAdmin(function () use ($email) {
            $this->createdUserIds[] = User::where('email', $email)->value('id');
        });
    }

    // -- QA-D-8 ---------------------------------------------------------------

    public function test_superadmin_void_with_an_invalid_school_filter_changes_nothing(): void
    {
        $superadmin = $this->createSuperAdmin();
        $coupon = $this->createCoupon([]);

        foreach (['keine-uuid', (string) Str::uuid()] as $tenantId) {
            $this->actingAs($superadmin)->post('/superadmin/coupons/void', ['ids' => [$coupon->id], 'tenant_id' => $tenantId])
                ->assertSessionHasErrors('tenant_id');
            $this->actingAs($superadmin)->post('/superadmin/coupons/export', ['ids' => [$coupon->id], 'tenant_id' => $tenantId])
                ->assertSessionHasErrors('tenant_id');
        }

        $this->assertNull($this->onAdmin(fn () => Coupon::find($coupon->id)->voided_at));
    }

    // -- Import ---------------------------------------------------------------

    public function test_the_import_works_without_the_optional_official_number_column(): void
    {
        $this->existingModule('SBF_BASIS');
        $this->writeFixture(['content_id', 'module', 'question', 'answer_a', 'answer_b', 'answer_c', 'answer_d', 'correct_answer'],
            ['TEST-QA-D-0001', 'Basis', 'Frage ohne Nummer?', 'A1', 'B1', 'C1', 'D1', 'A']);

        $this->assertSame(0, Artisan::call('content:import', ['path' => 'storage/framework/testing/qa-d-import.xlsx']));

        $question = $this->onAdmin(fn () => ContentQuestion::where('content_id', 'TEST-QA-D-0001')->first());
        $this->assertNotNull($question);
        $this->assertNull($question->official_number);
    }

    public function test_the_import_stops_cleanly_when_required_columns_are_missing(): void
    {
        $this->writeFixture(['content_id', 'module', 'question', 'answer_a'], ['TEST-QA-D-0001', 'Basis', 'Frage?', 'A1']);

        $this->assertSame(1, Artisan::call('content:import', ['path' => 'storage/framework/testing/qa-d-import.xlsx']));
        $this->assertStringContainsString('Pflichtspalten fehlen: answer_b', Artisan::output());
        $this->assertNull($this->onAdmin(fn () => ContentQuestion::where('content_id', 'TEST-QA-D-0001')->first()));
    }

    private function writeFixture(array $header, array $row): void
    {
        $this->fixturePath = storage_path('framework/testing/qa-d-import.xlsx');
        @mkdir(dirname($this->fixturePath), recursive: true);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Central Question Pool');
        $sheet->fromArray($header, null, 'A1');
        $sheet->fromArray($row, null, 'A2');
        (new Xlsx($spreadsheet))->save($this->fixturePath);
    }
}
