<?php

namespace Tests\Feature\Workflow;

use App\Models\CouponBatch;
use App\Models\LearningGroup;
use App\Models\LearningGroupMember;
use App\Models\Tenant;
use App\Notifications\LearningGroupInvitation;
use App\Notifications\WorkflowSchoolAdminNotice;
use App\Services\LearningGroupService;
use App\Workflow\ActionRegistry;
use App\Workflow\Actions\WorkflowAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * WF-CUST1-01, -06, -07, -09: Befehle nur im Rahmen einer serverseitig
 * ermittelten Schule, mit RLS-Kontext, Idempotenz, Tagesgrenze und
 * Rollenprüfung; Codezustellung, Sperren und Kontofreigabe nie.
 */
class WorkflowCommandTest extends WorkflowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('workflow-key:workflow');
    }

    public function test_a_school_admin_notice_goes_to_the_admins_of_the_resolved_school_once_per_idempotency_key(): void
    {
        Notification::fake();
        [$tenant, $admin, , $account] = $this->linkedAccount();
        $learner = $this->createTenantUser($tenant, 'learner');
        $command = $this->command(['tenant_id' => $tenant->id, 'subject' => ['type' => 'shop_account', 'id' => $account->id]]);

        $this->workflow('commands', $command)->assertOk()
            ->assertJsonPath('result', ['recipients' => 1, 'template' => 'codes_delivered'])->assertJsonPath('repeated', false);
        Notification::assertSentTo($admin, WorkflowSchoolAdminNotice::class);
        Notification::assertNotSentTo($learner, WorkflowSchoolAdminNotice::class);

        // Wiederholung des Workflow-Schritts (Timeout nach erfolgreichem Aufruf): kein zweiter Hinweis.
        $this->workflow('commands', $command)->assertOk()->assertJsonPath('repeated', true)->assertJsonPath('result.recipients', 1);
        Notification::assertSentToTimes($admin, WorkflowSchoolAdminNotice::class, 1);

        // Gleicher Schlüssel, anderer Inhalt: Konflikt.
        $this->workflow('commands', ['params' => ['template' => 'codes_revoked']] + $command)->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        // Unbekannte Vorlage oder freier Text: abgelehnt.
        $this->workflow('commands', $this->command(['tenant_id' => $tenant->id, 'subject' => ['type' => 'shop_account', 'id' => $account->id],
            'params' => ['template' => 'codes_delivered', 'text' => 'Klick hier']]))->assertStatus(422)->assertJsonPath('code', 'PARAMS_INVALID');
    }

    public function test_tenant_ids_in_a_command_are_only_references_checked_against_the_subject(): void
    {
        Notification::fake();
        [$tenantA, , , $accountA] = $this->linkedAccount();
        $tenantB = $this->createTestTenant('Andere Schule');
        $adminB = $this->createTenantUser($tenantB, 'admin');

        $this->workflow('commands', $this->command(['tenant_id' => $tenantB->id, 'subject' => ['type' => 'shop_account', 'id' => $accountA->id]]))
            ->assertStatus(403)->assertJsonPath('code', 'TENANT_MISMATCH');
        $this->workflow('commands', $this->command(['tenant_id' => $tenantB->id, 'subject' => ['type' => 'shop_account', 'id' => (string) Str::uuid()]]))
            ->assertStatus(404)->assertJsonPath('code', 'SUBJECT_NOT_FOUND');
        $this->workflow('commands', $this->command(['tenant_id' => $tenantA->id, 'subject' => ['type' => 'learning_group', 'id' => $accountA->id]]))
            ->assertStatus(422);
        Notification::assertNothingSent();

        // Schule B direkt als Gegenstand: geht nur an B.
        $this->workflow('commands', $this->command(['tenant_id' => $tenantB->id, 'subject' => ['type' => 'tenant', 'id' => $tenantB->id]]))->assertOk();
        Notification::assertSentTo($adminB, WorkflowSchoolAdminNotice::class);

        // Inaktive Schule: kein Befehl.
        $this->onAdmin(fn () => Tenant::whereKey($tenantB->id)->update(['status' => 'suspended']));
        $this->workflow('commands', $this->command(['tenant_id' => $tenantB->id, 'subject' => ['type' => 'tenant', 'id' => $tenantB->id]]))
            ->assertStatus(409)->assertJsonPath('code', 'TENANT_INACTIVE');
    }

    public function test_commands_run_under_the_rls_context_of_exactly_that_school(): void
    {
        [$tenantA] = $this->linkedAccount();
        $tenantB = $this->createTestTenant('Andere Schule');
        $this->createTenantUser($tenantB, 'learner');
        app(ActionRegistry::class)->register(new class implements WorkflowAction
        {
            public function name(): string
            {
                return 'test.rls_probe';
            }

            public function subjectTypes(): array
            {
                return ['tenant'];
            }

            public function validate(array $params): array
            {
                return [];
            }

            public function execute(Tenant $tenant, array $subject, array $params): array
            {
                return [
                    'context' => DB::selectOne("SELECT current_setting('app.current_tenant_id', true) AS t")->t,
                    'role' => DB::selectOne('SELECT current_user AS r')->r,
                    // Ohne Anwendungsfilter: nur RLS entscheidet, was sichtbar ist.
                    'foreign_members' => DB::table('tenant_user')->where('tenant_id', '!=', $tenant->id)->count(),
                    'own_members' => DB::table('tenant_user')->where('tenant_id', $tenant->id)->count(),
                ];
            }
        });
        $this->actionConfig('test.rls_probe', ['enabled' => true, 'daily_limit' => 0]);

        $result = $this->workflow('commands', $this->command(['action' => 'test.rls_probe', 'tenant_id' => $tenantA->id,
            'subject' => ['type' => 'tenant', 'id' => $tenantA->id], 'params' => []]))->assertOk()->json('result');

        $this->assertSame($tenantA->id, $result['context']);
        $this->assertSame(config('database.connections.pgsql.username'), $result['role']);
        $this->assertSame(0, $result['foreign_members']);
        $this->assertSame(1, $result['own_members']);
        // Danach ist der Kontext wieder leer (Nil-UUID bzw. zurückgesetzt), nicht mehr Schule A.
        $this->assertContains(DB::selectOne("SELECT current_setting('app.current_tenant_id', true) AS t")->t, ['', null, '00000000-0000-0000-0000-000000000000']);
    }

    public function test_platform_scope_and_actions_owned_by_the_woocommerce_connector_are_refused(): void
    {
        [$tenant, , $customerId, $account] = $this->linkedAccount();
        $batchId = $this->commerce('POST', '/orders/lines', ['shop_customer_id' => $customerId, 'order_id' => '8100', 'order_line_id' => '1', 'kind' => 'course', 'course_code' => 'SRC', 'quantity' => 1])
            ->assertCreated()->json('data.batch_id');

        $this->workflow('commands', $this->command(['scope' => 'platform', 'tenant_id' => $tenant->id, 'subject' => ['type' => 'tenant', 'id' => $tenant->id]]))
            ->assertStatus(403)->assertJsonPath('code', 'SCOPE_NOT_GRANTED');
        config(['workflow.keys.workflow.scopes' => ['tenant', 'platform']]);
        $this->workflow('commands', $this->command(['scope' => 'platform', 'tenant_id' => $tenant->id, 'subject' => ['type' => 'tenant', 'id' => $tenant->id]]))
            ->assertStatus(403)->assertJsonPath('code', 'PLATFORM_ACTION_UNAVAILABLE');
        $this->workflow('commands', $this->command(['scope' => null, 'tenant_id' => $tenant->id, 'subject' => ['type' => 'tenant', 'id' => $tenant->id]]))
            ->assertStatus(403)->assertJsonPath('code', 'SCOPE_NOT_GRANTED');

        // WF-CUST1-01: Zustellung, Wiederholung, Sperre und Kontofreigabe führt nur der WooCommerce-Connector aus.
        foreach (['code_batch.deliver', 'code_batch.void', 'order.refund', 'order.retry', 'shop_account.link', 'shop_account.approve', 'entitlement.grant'] as $action) {
            $this->workflow('commands', $this->command(['action' => $action, 'tenant_id' => $tenant->id, 'subject' => ['type' => 'code_batch', 'id' => $batchId]]))
                ->assertStatus(403)->assertJsonPath('code', 'ACTION_FORBIDDEN');
        }
        $this->assertSame('delivered', $this->onAdmin(fn () => CouponBatch::withoutGlobalScopes()->find($batchId)->status));
        $this->workflow('commands', $this->command(['action' => 'gibt.es_nicht', 'tenant_id' => $tenant->id, 'subject' => ['type' => 'tenant', 'id' => $tenant->id]]))
            ->assertStatus(404)->assertJsonPath('code', 'ACTION_UNKNOWN');

        // Auch eine spätere Registrierung eines solchen Befehls scheitert.
        $this->expectException(\LogicException::class);
        app(ActionRegistry::class)->register(new class implements WorkflowAction
        {
            public function name(): string
            {
                return 'code_batch.void';
            }

            public function subjectTypes(): array
            {
                return ['code_batch'];
            }

            public function validate(array $params): array
            {
                return [];
            }

            public function execute(Tenant $tenant, array $subject, array $params): array
            {
                return [];
            }
        });
    }

    public function test_daily_limit_per_school_and_action(): void
    {
        Notification::fake();
        $this->actionConfig('school_admin.notice', ['daily_limit' => 2]);
        [$tenant, , , $account] = $this->linkedAccount();
        $subject = ['tenant_id' => $tenant->id, 'subject' => ['type' => 'shop_account', 'id' => $account->id]];
        $this->workflow('commands', $this->command($subject))->assertOk();
        $this->workflow('commands', $this->command($subject))->assertOk();
        $limited = $this->command($subject);
        $this->workflow('commands', $limited)->assertStatus(429)->assertJsonPath('code', 'DAILY_LIMIT_REACHED');
        // Abgelehnt heißt nicht ausgeführt: derselbe Schlüssel bleibt frei.
        $this->assertFalse($this->onAdmin(fn () => DB::table('workflow_command')->where('idempotency_key', $limited['idempotency_key'])->exists()));
    }

    public function test_the_optional_group_invite_checks_role_membership_and_returns_no_token(): void
    {
        Notification::fake();
        [$tenant] = $this->linkedAccount();
        $owner = $this->createTenantUser($tenant, 'learner');
        $invitee = $this->createTenantUser($tenant, 'learner');
        $unconfirmed = $this->createTenantUser($tenant, 'learner', ['email_verified_at' => null]);
        $foreign = $this->createTestTenant('Fremd');
        $outsider = $this->createTenantUser($foreign, 'learner');
        $group = $this->onAdmin(fn () => LearningGroup::create([
            'tenant_id' => $tenant->id, 'owner_user_id' => $owner->id, 'name' => 'Segelverein', 'status' => 'active', 'seats' => 3,
            'scopes' => [['course_code' => 'SRC', 'feature' => 'course']], 'valid_from' => now()->subMinute(), 'valid_until' => now()->addYear(),
            'join_code' => LearningGroupService::newJoinCode(),
        ]));
        $invite = fn (array $params) => $this->command(['action' => 'learning_group.invite', 'tenant_id' => $tenant->id,
            'subject' => ['type' => 'learning_group', 'id' => $group->id], 'params' => $params]);

        $this->workflow('commands', $invite(['user_id' => $invitee->id, 'acting_user_id' => $owner->id]))->assertStatus(403)->assertJsonPath('code', 'ACTION_DISABLED');
        $this->actionConfig('learning_group.invite', ['enabled' => true]);

        $this->workflow('commands', $invite(['user_id' => $invitee->id, 'acting_user_id' => $invitee->id]))->assertStatus(403)->assertJsonPath('code', 'ROLE_NOT_ALLOWED');
        $this->workflow('commands', $invite(['user_id' => $unconfirmed->id, 'acting_user_id' => $owner->id]))->assertStatus(422)->assertJsonPath('code', 'INVITEE_NOT_ELIGIBLE');
        $this->workflow('commands', $invite(['user_id' => $outsider->id, 'acting_user_id' => $owner->id]))->assertStatus(422)->assertJsonPath('code', 'INVITEE_NOT_ELIGIBLE');
        $this->workflow('commands', $invite(['user_id' => $invitee->id, 'acting_user_id' => $owner->id, 'email' => 'x@example.test']))->assertStatus(422);

        $response = $this->workflow('commands', $invite(['user_id' => $invitee->id, 'acting_user_id' => $owner->id]))->assertOk()
            ->assertJsonPath('result', ['invited' => 1, 'skipped' => 0]);
        $token = $this->onAdmin(fn () => LearningGroupMember::where('group_id', $group->id)->value('invite_token'));
        $this->assertNotEmpty($token);
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringNotContainsString($invitee->email, $response->getContent());
        Notification::assertSentOnDemand(LearningGroupInvitation::class);
        // Gruppe einer anderen Schule mit der eigenen Schule als Referenz: abgelehnt.
        $this->workflow('commands', $this->command(['action' => 'learning_group.invite', 'tenant_id' => $foreign->id,
            'subject' => ['type' => 'learning_group', 'id' => $group->id], 'params' => ['user_id' => $invitee->id, 'acting_user_id' => $owner->id]]))->assertStatus(403);
    }

    public function test_the_shipped_configuration_enables_the_notice_and_keeps_the_invite_off(): void
    {
        // Gefunden im Workflow-E2E-Test (tests/workflow-e2e): mit den Schlüsseln aus config/workflow.php selbst.
        config(['workflow.actions' => (require config_path('workflow.php'))['actions']]);
        $registry = app(ActionRegistry::class);
        $this->assertTrue($registry->enabled('school_admin.notice'));
        $this->assertSame(20, $registry->dailyLimit('school_admin.notice'));
        $this->assertFalse($registry->enabled('learning_group.invite'));
        $this->assertSame(50, $registry->dailyLimit('learning_group.invite'));
    }
}
