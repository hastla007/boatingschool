<?php

namespace Tests\Feature;

use App\Console\Commands\PrunePersonalData;
use App\Models\AuditLog;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Models\WillkommensboxOrder;
use App\Support\PrivacyNotices;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Löschkonzept Nr. 16, 17, 18, 20 und 26: Fristen für Praxis-Anmeldungen,
 * Willkommensbox-Adressen, Gerätewiedererkennung, Shop-Ereignisse
 * (standardmäßig aus, Frist durch den Betreiber) sowie abgelaufene
 * Shop-Verknüpfungscodes (immer aktiv, technisch tote Zeilen).
 */
class PrunePersonalDataRetentionTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdEventIds = [];

    private array $createdTemplateIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Feste Uhrzeit: Grenzen lassen sich dann exakt prüfen.
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
        // Nur die hier geprüften Teile -- nicht nebenbei Konten des gemeinsamen
        // Testbestands anfassen.
        config(['data_retention.unclaimed_accounts.enabled' => false, 'data_retention.sessions.enabled' => false]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->onAdmin(function () {
            DB::table('commerce_event')->whereIn('id', $this->createdEventIds)->delete();
        });
        $this->cleanUpCreatedTenantsAndUsers();
        $this->onAdmin(function () {
            foreach ($this->createdTemplateIds as $id) {
                PraxisFormTemplate::where('id', $id)->delete();
            }
        });

        parent::tearDown();
    }

    public function test_all_new_periods_are_off_by_default(): void
    {
        $this->assertNull(config('data_retention.commerce_events.days'));
        $this->assertNull(config('data_retention.praxis_submissions.months'));
        $this->assertNull(config('data_retention.willkommensbox_addresses.days'));
        $this->assertNull(config('data_retention.known_login_devices.months'));
    }

    public function test_env_values_are_parsed_and_empty_or_non_positive_values_mean_off(): void
    {
        $keys = ['DATA_RETENTION_COMMERCE_EVENT_DAYS', 'DATA_RETENTION_PRAXIS_SUBMISSION_MONTHS', 'DATA_RETENTION_WILLKOMMENSBOX_ADDRESS_DAYS', 'DATA_RETENTION_KNOWN_LOGIN_DEVICE_MONTHS'];
        $load = function (array $values) use ($keys): array {
            foreach ($keys as $i => $key) {
                $_SERVER[$key] = $_ENV[$key] = $values[$i];
            }
            try {
                return require config_path('data_retention.php');
            } finally {
                foreach ($keys as $key) {
                    unset($_SERVER[$key], $_ENV[$key]);
                }
            }
        };

        $config = $load(['90', '12', '30', '6']);
        $this->assertSame(90, $config['commerce_events']['days']);
        $this->assertSame(12, $config['praxis_submissions']['months']);
        $this->assertSame(30, $config['willkommensbox_addresses']['days']);
        $this->assertSame(6, $config['known_login_devices']['months']);

        $config = $load(['', '0', '-5', '']);
        $this->assertNull($config['commerce_events']['days']);
        $this->assertNull($config['praxis_submissions']['months']);
        $this->assertNull($config['willkommensbox_addresses']['days']);
        $this->assertNull($config['known_login_devices']['months']);
    }

    // ---------------------------------------------------------------- Shop-Ereignisse

    private function event(string $status, int $daysAgo, string $type = 'account.linked', string $direction = 'in'): string
    {
        $id = (string) Str::uuid();
        $this->createdEventIds[] = $id;
        $at = now()->subDays($daysAgo);
        $this->onAdmin(fn () => DB::table('commerce_event')->insert([
            'id' => $id, 'direction' => $direction, 'type' => $type, 'payload' => json_encode(['email' => 'shop@example.test']),
            'status' => $status, 'attempts' => 0, 'available_at' => $at, 'created_at' => $at,
            'processed_at' => in_array($status, ['done', 'failed'], true) ? $at : null,
        ]));

        return $id;
    }

    private function eventExists(string $id): bool
    {
        return $this->onAdmin(fn () => DB::table('commerce_event')->where('id', $id)->exists());
    }

    public function test_commerce_events_are_kept_while_no_period_is_set(): void
    {
        $old = $this->event('done', 400);

        $this->artisan('app:prune-personal-data')->expectsOutputToContain('Shop-Ereignisse: keine Frist festgelegt')->assertExitCode(0);

        $this->assertTrue($this->eventExists($old));
    }

    public function test_only_finished_commerce_events_older_than_the_period_are_deleted(): void
    {
        config(['data_retention.commerce_events.days' => 90]);
        $doneOld = $this->event('done', 91);
        $doneOutOld = $this->event('done', 91, 'tenant.provision', 'out');
        $doneAtBoundary = $this->event('done', 89);
        $pending = $this->event('pending', 400, 'tenant.provision', 'out');
        $sending = $this->event('sending', 400, 'tenant.provision', 'out');
        $failed = $this->event('failed', 400, 'tenant.provision', 'out');
        $voided = $this->event('done', 400, 'batch.voided');

        $this->artisan('app:prune-personal-data --dry-run')->assertExitCode(0);
        $this->assertTrue($this->eventExists($doneOld));

        $this->artisan('app:prune-personal-data')->assertExitCode(0);

        $this->assertFalse($this->eventExists($doneOld));
        $this->assertFalse($this->eventExists($doneOutOld));
        $this->assertTrue($this->eventExists($doneAtBoundary));
        // Nie: nicht zugestellt, gerade im Versand, fehlgeschlagen (erneut versuchen), Storno-Nachweis.
        $this->assertTrue($this->eventExists($pending));
        $this->assertTrue($this->eventExists($sending));
        $this->assertTrue($this->eventExists($failed));
        $this->assertTrue($this->eventExists($voided));

        // Zwei Tage später ist auch das Ereignis an der Grenze fällig.
        $this->travel(2)->days();
        $this->artisan('app:prune-personal-data')->assertExitCode(0);
        $this->assertFalse($this->eventExists($doneAtBoundary));
        $this->assertTrue($this->eventExists($pending));
    }

    public function test_commerce_events_are_deleted_in_chunks(): void
    {
        config(['data_retention.commerce_events.days' => 30, 'data_retention.prune_chunk' => 2]);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->event('done', 31);
        }

        $this->artisan('app:prune-personal-data')->assertExitCode(0);

        foreach ($ids as $id) {
            $this->assertFalse($this->eventExists($id));
        }
    }

    // ---------------------------------------------------------------- Praxis-Anmeldungen

    /** @return array{0: Tenant, 1: User, 2: PraxisFormTemplate} */
    private function praxisSetup(): array
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $template = $this->onAdmin(fn () => PraxisFormTemplate::create([
            'title' => 'Löschfrist-Formular', 'min_progress_percent' => 0, 'course_ids' => [], 'fields' => [], 'active' => true,
        ]));
        $this->createdTemplateIds[] = $template->id;

        return [$tenant, $learner, $template];
    }

    private function submission(Tenant $tenant, User $learner, PraxisFormTemplate $template, string $courseCode, array $data, int $monthsAgo, ?int $examMonthsAgo): PraxisFormSubmission
    {
        return $this->onAdmin(function () use ($tenant, $learner, $template, $courseCode, $data, $monthsAgo, $examMonthsAgo) {
            $at = now()->subMonths($monthsAgo);
            $submission = new PraxisFormSubmission([
                'tenant_id' => $tenant->id, 'course_id' => $this->existingCourse($courseCode)->id, 'user_id' => $learner->id,
                'praxis_form_template_id' => $template->id, 'data' => $data,
                'confirmed_exam_date' => $examMonthsAgo === null ? null : now()->subMonths($examMonthsAgo),
            ]);
            $submission->created_at = $submission->updated_at = $at;
            $submission->timestamps = false;
            $submission->save();
            if ($examMonthsAgo !== null) {
                $appointment = new PraxisExamAppointment([
                    'tenant_id' => $tenant->id, 'praxis_form_submission_id' => $submission->id, 'slot' => 0, 'round' => 1,
                    'label' => 'Prüfungstermin', 'exam_date' => now()->subMonths($examMonthsAgo), 'response' => 'accepted',
                    'response_comment' => 'Passt, ich bringe meinen Ausweis mit.', 'responded_at' => $at,
                ]);
                $appointment->created_at = $appointment->updated_at = $at;
                $appointment->timestamps = false;
                $appointment->save();
                AuditLog::record($tenant->id, null, 'praxis-exam-appointment.propose', 'praxis_exam_appointment', $appointment->id, null, $appointment->toArray());
            }
            AuditLog::record($tenant->id, null, 'praxis-form-submission.update', 'praxis_form_submission', $submission->id, ['data' => $data], ['data' => $data]);

            return $submission;
        });
    }

    private function fresh(PraxisFormSubmission $submission): array
    {
        return $this->onAdmin(function () use ($submission) {
            $row = PraxisFormSubmission::withoutGlobalScopes()->with('examAppointments')->findOrFail($submission->id);
            $audit = AuditLog::where('entity_type', 'praxis_form_submission')->where('entity_id', $submission->id)->first();

            return [$row, $audit];
        });
    }

    public function test_praxis_submissions_are_kept_while_no_period_is_set(): void
    {
        [$tenant, $learner, $template] = $this->praxisSetup();
        $old = $this->submission($tenant, $learner, $template, 'SBF-SEE', ['vorname' => 'Erika', 'geburtsdatum' => '2000-01-01'], 30, 30);

        $this->artisan('app:prune-personal-data')->expectsOutputToContain('Praxis-Anmeldungen: keine Frist festgelegt')->assertExitCode(0);

        [$row] = $this->fresh($old);
        $this->assertSame('Erika', $row->data['vorname']);
    }

    public function test_praxis_answers_are_removed_after_the_last_appointment_but_the_registration_stays(): void
    {
        config(['data_retention.praxis_submissions.months' => 12]);
        [$tenant, $learner, $template] = $this->praxisSetup();
        $data = ['vorname' => 'Erika', 'geburtsdatum' => '2000-01-01', 'strasse' => 'Hafenweg 1'];
        // Prüfung vor 13 Monaten: fällig.
        $old = $this->submission($tenant, $learner, $template, 'SBF-SEE', $data, 14, 13);
        // Anmeldung alt, Prüfung erst vor 11 Monaten: noch nicht fällig.
        $recentExam = $this->submission($tenant, $learner, $template, 'UBI', $data, 20, 11);
        // Reiner Praxisstunden-Behälter ohne Angaben bleibt Behälter.
        $practiceOnly = $this->submission($tenant, $learner, $template, 'SRC', [PraxisFormSubmission::PRACTICE_ONLY_KEY => true], 14, 13);

        $this->artisan('app:prune-personal-data --dry-run')->assertExitCode(0);
        [$row] = $this->fresh($old);
        $this->assertSame('Erika', $row->data['vorname']);

        $this->artisan('app:prune-personal-data')->assertExitCode(0);

        [$row, $audit] = $this->fresh($old);
        $this->assertSame([PrunePersonalData::PRAXIS_REMOVED_KEY => '2026-06-15'], $row->data);
        $this->assertNotNull($row->confirmed_exam_date, 'Termin und Zeitpunkte bleiben');
        $this->assertCount(1, $row->examAppointments);
        $this->assertNull($row->examAppointments->first()->response_comment);
        $this->assertSame('accepted', $row->examAppointments->first()->response);
        $this->assertNull($audit->before_data);
        $this->assertNull($audit->after_data);
        $appointmentAudit = $this->onAdmin(fn () => AuditLog::where('entity_type', 'praxis_exam_appointment')->where('entity_id', $row->examAppointments->first()->id)->first());
        $this->assertNull($appointmentAudit->after_data);

        [$recent, $recentAudit] = $this->fresh($recentExam);
        $this->assertSame('Erika', $recent->data['vorname']);
        $this->assertNotNull($recent->examAppointments->first()->response_comment);
        $this->assertNotNull($recentAudit->after_data);

        [$container] = $this->fresh($practiceOnly);
        $this->assertTrue($container->isPracticeOnly());
        $this->assertNull($container->examAppointments->first()->response_comment);

        // Zwei Monate später ist auch die zweite Anmeldung fällig; die erste
        // wird nicht erneut angefasst (Datum des Kennzeichens bleibt).
        $this->travel(2)->months();
        $this->artisan('app:prune-personal-data')->assertExitCode(0);
        [$recent] = $this->fresh($recentExam);
        $this->assertSame([PrunePersonalData::PRAXIS_REMOVED_KEY => '2026-08-15'], $recent->data);
        [$row] = $this->fresh($old);
        $this->assertSame([PrunePersonalData::PRAXIS_REMOVED_KEY => '2026-06-15'], $row->data);
    }

    public function test_an_upcoming_appointment_protects_an_old_praxis_registration(): void
    {
        config(['data_retention.praxis_submissions.months' => 12]);
        [$tenant, $learner, $template] = $this->praxisSetup();
        $submission = $this->submission($tenant, $learner, $template, 'SBF-SEE', ['vorname' => 'Erika'], 24, null);
        $this->onAdmin(fn () => PraxisExamAppointment::create([
            'tenant_id' => $tenant->id, 'praxis_form_submission_id' => $submission->id, 'slot' => 1, 'round' => 1,
            'label' => 'Praxisstunde', 'exam_date' => now()->addWeek(), 'response' => 'pending',
        ]));

        $this->artisan('app:prune-personal-data')->assertExitCode(0);

        [$row] = $this->fresh($submission);
        $this->assertSame('Erika', $row->data['vorname']);
    }

    public function test_an_active_course_access_protects_an_old_praxis_registration(): void
    {
        config(['data_retention.praxis_submissions.months' => 12]);
        [$tenant, $learner, $template] = $this->praxisSetup();
        $protected = $this->submission($tenant, $learner, $template, 'SBF-SEE', ['vorname' => 'Erika'], 14, 13);
        $other = $this->submission($tenant, $learner, $template, 'UBI', ['vorname' => 'Erika'], 14, 13);
        $entitlement = $this->grantEntitlement($tenant, $learner, $this->existingCourse('SBF-SEE'));

        $this->artisan('app:prune-personal-data')->assertExitCode(0);
        $this->assertSame('Erika', $this->fresh($protected)[0]->data['vorname'], 'aktiver Zugang zum Kurs schützt');
        $this->assertSame([PrunePersonalData::PRAXIS_REMOVED_KEY => '2026-06-15'], $this->fresh($other)[0]->data, 'anderer Kurs ohne Zugang');

        // Zugang abgelaufen: danach wird auch diese Anmeldung anonymisiert.
        $this->onAdmin(fn () => DB::table('entitlement')->where('id', $entitlement->id)->update(['status' => 'expired', 'valid_from' => now()->subDays(10), 'valid_until' => now()->subDay()]));
        $this->artisan('app:prune-personal-data')->assertExitCode(0);
        $this->assertSame([PrunePersonalData::PRAXIS_REMOVED_KEY => '2026-06-15'], $this->fresh($protected)[0]->data);
    }

    public function test_removed_praxis_answers_are_explained_where_they_were_shown(): void
    {
        config(['data_retention.praxis_submissions.months' => 12]);
        [$tenant, $learner, $template] = $this->praxisSetup();
        $this->onAdmin(fn () => PraxisFormTemplate::whereKey($template->id)->update([
            'fields' => json_encode([['key' => 'vorname', 'label' => 'Vorname', 'type' => 'text', 'required' => true]]),
        ]));
        $admin = $this->createTenantUser($tenant, 'admin');
        $submission = $this->submission($tenant, $learner, $template, 'SBF-SEE', ['vorname' => 'Erika'], 14, 13);
        $this->artisan('app:prune-personal-data')->assertExitCode(0);
        $this->travelBack();
        $hint = 'Angaben am 15.06.2026 nach Ablauf der Speicherfrist entfernt.';

        $this->actingAsInTenant($admin, $tenant)->get(route('admin.praxis-form-submissions.show', $submission))->assertOk()->assertSee($hint)->assertDontSee('Erika');
        $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}")->assertOk()->assertSee($hint);
        $this->actingAsInTenant($admin, $tenant)->get(route('admin.praxis-form-submissions.edit', $submission))->assertOk()->assertSee($hint);

        // Lernende sehen auf „Praxis & Prüfung“ (Zustand „angemeldet“) denselben Hinweis.
        [$row] = $this->fresh($submission);
        $html = $this->asTenant($tenant, function () use ($learner, $row, $template) {
            $this->actingAs($learner);

            return view('praxis-pruefung.index', ['course' => $this->existingCourse('SBF-SEE'), 'state' => 'submitted', 'template' => $template->fresh(), 'submission' => $row])->render();
        });
        $this->assertStringContainsString($hint, $html);
    }

    // ---------------------------------------------------------------- Schul-Einladungen

    public function test_never_accepted_tenant_invitations_are_deleted_after_ninety_days(): void
    {
        $this->assertSame(90, config('data_retention.tenant_invitations.days'));
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'owner');
        $make = function (int $daysAgo, bool $accepted) use ($tenant, $admin): string {
            return $this->onAdmin(function () use ($tenant, $admin, $daysAgo, $accepted) {
                $invitation = TenantInvitation::create([
                    'tenant_id' => $tenant->id, 'email' => 'frist-'.Str::lower(Str::random(8)).'@example.test', 'role' => 'learner',
                    'invited_by_user_id' => $admin->id, 'accepted_at' => $accepted ? now()->subDays($daysAgo) : null,
                ]);
                DB::table('tenant_invitation')->where('id', $invitation->id)->update(['created_at' => now()->subDays($daysAgo)]);

                return $invitation->id;
            });
        };
        $old = $make(91, false);
        $young = $make(89, false);
        $acceptedOld = $make(400, true);
        $exists = fn (string $id) => $this->onAdmin(fn () => DB::table('tenant_invitation')->where('id', $id)->exists());

        $this->artisan('app:prune-personal-data --dry-run')->expectsOutputToContain('Schul-Einladungen:')->assertExitCode(0);
        $this->assertTrue($exists($old));

        $this->artisan('app:prune-personal-data')->assertExitCode(0);
        $this->assertFalse($exists($old));
        $this->assertTrue($exists($young));
        $this->assertTrue($exists($acceptedOld), 'angenommene Einladungen bleiben');

        // Nie kürzer als die Frist für nie aktivierte Konten (dort Nachweis „kürzlich eingeladen“).
        config(['data_retention.tenant_invitations.days' => 30, 'data_retention.unclaimed_accounts.enabled' => true, 'data_retention.unclaimed_accounts.days' => 90]);
        $this->assertSame(90, PrunePersonalData::tenantInvitationDays());
        config(['data_retention.tenant_invitations.days' => null]);
        $this->assertNull(PrunePersonalData::tenantInvitationDays());
        $this->assertStringNotContainsString('Einladungen einer Bootsschule', PrivacyNotices::retention());
        config(['data_retention.tenant_invitations.days' => 90]);
        $this->assertStringContainsString('Einladungen einer Bootsschule, die du nicht annimmst:** 90 Tage', PrivacyNotices::retention());
    }

    // ---------------------------------------------------------------- Willkommensbox

    private function order(Tenant $tenant, User $user, ?int $shippedDaysAgo): WillkommensboxOrder
    {
        return $this->onAdmin(function () use ($tenant, $user, $shippedDaysAgo) {
            $order = WillkommensboxOrder::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'street' => 'Lieferstraße 1', 'postal_code' => '12345',
                'city' => 'Lieferstadt', 'country' => 'Deutschland', 'note' => 'Bitte beim Nachbarn abgeben',
                'shipped_at' => $shippedDaysAgo === null ? null : now()->subDays($shippedDaysAgo),
                'tracking_number' => $shippedDaysAgo === null ? null : 'TRACK123',
            ]);
            AuditLog::record($tenant->id, null, 'willkommensbox-order.ship', 'willkommensbox_order', $order->id, null, $order->toArray());

            return $order;
        });
    }

    private function freshOrder(WillkommensboxOrder $order): WillkommensboxOrder
    {
        return $this->onAdmin(fn () => WillkommensboxOrder::withoutGlobalScopes()->findOrFail($order->id));
    }

    public function test_willkommensbox_addresses_are_kept_while_no_period_is_set(): void
    {
        $tenant = $this->createTestTenant();
        $order = $this->order($tenant, $this->createTenantUser($tenant), 400);

        $this->artisan('app:prune-personal-data')->expectsOutputToContain('Willkommensbox-Adressen: keine Frist festgelegt')->assertExitCode(0);

        $this->assertSame('Lieferstraße 1', $this->freshOrder($order)->street);
    }

    public function test_willkommensbox_address_is_removed_only_after_shipping_plus_period(): void
    {
        config(['data_retention.willkommensbox_addresses.days' => 90]);
        $tenant = $this->createTestTenant();
        $shippedLongAgo = $this->order($tenant, $this->createTenantUser($tenant), 91);
        $shippedAtBoundary = $this->order($tenant, $this->createTenantUser($tenant), 89);
        $notShipped = $this->onAdmin(function () use ($tenant) {
            $order = $this->order($tenant, $this->createTenantUser($tenant), null);
            DB::table('willkommensbox_order')->where('id', $order->id)->update(['created_at' => now()->subYears(2)]);

            return $order;
        });

        $this->artisan('app:prune-personal-data')->assertExitCode(0);

        $order = $this->freshOrder($shippedLongAgo);
        $this->assertNull($order->street);
        $this->assertNull($order->postal_code);
        $this->assertNull($order->city);
        $this->assertNull($order->country);
        $this->assertNull($order->note);
        $this->assertNull($order->tracking_number);
        $this->assertNotNull($order->shipped_at, 'Bestellung und Versanddatum bleiben');
        $this->assertTrue($order->addressRemoved());
        $audit = $this->onAdmin(fn () => AuditLog::where('entity_type', 'willkommensbox_order')->where('entity_id', $order->id)->first());
        $this->assertNull($audit->after_data);

        $this->assertSame('Lieferstraße 1', $this->freshOrder($shippedAtBoundary)->street);
        $this->assertFalse($this->freshOrder($shippedAtBoundary)->addressRemoved());
        $this->assertSame('Lieferstraße 1', $this->freshOrder($notShipped)->street, 'nie vor dem Versand');

        $this->travel(2)->days();
        $this->artisan('app:prune-personal-data')->assertExitCode(0);
        $this->assertNull($this->freshOrder($shippedAtBoundary)->street);
        $this->assertSame('Lieferstraße 1', $this->freshOrder($notShipped)->street);
    }

    public function test_admin_list_shows_a_removed_address_as_such(): void
    {
        config(['data_retention.willkommensbox_addresses.days' => 30]);
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $this->order($tenant, $this->createTenantUser($tenant), 31);
        $this->artisan('app:prune-personal-data')->assertExitCode(0);
        $this->travelBack();

        $this->actingAsInTenant($admin, $tenant)
            ->get(route('admin.dashboard', ['tab' => 'willkommensbox']))
            ->assertOk()
            ->assertSee('Adresse nach Versand gelöscht (Löschfrist)');
    }

    public function test_participant_page_and_label_show_a_removed_address_as_such(): void
    {
        config(['data_retention.willkommensbox_addresses.days' => 30]);
        $tenant = $this->createTestTenant();
        $admin = $this->createTenantUser($tenant, 'admin');
        $learner = $this->createTenantUser($tenant);
        $order = $this->order($tenant, $learner, 31);
        $this->artisan('app:prune-personal-data')->assertExitCode(0);
        $this->travelBack();

        $page = $this->actingAsInTenant($admin, $tenant)->get("/admin/participants/{$learner->id}")->assertOk();
        $page->assertSee('Adresse nach Versand gelöscht (Löschfrist)')->assertDontSee('Lieferstraße');
        $page->assertDontSee(route('admin.willkommensbox-orders.label', [$order->id, 'a6']), false);

        // Das Label (auch direkt aufgerufen) druckt keine leere Empfängeradresse.
        $order = $this->freshOrder($order)->load('user');
        foreach (['a6', 'a4'] as $size) {
            $html = view('admin.willkommensbox.label', ['order' => $order, 'branding' => $tenant->branding, 'tenant' => $tenant, 'size' => $size])->render();
            $this->assertStringContainsString('Adresse nach Versand gelöscht (Löschfrist)', $html);
        }
    }

    public function test_learner_page_explains_the_removed_address(): void
    {
        config(['data_retention.willkommensbox_addresses.days' => 30]);
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant);
        $this->onAdmin(fn () => ProductPurchase::create([
            'tenant_id' => $tenant->id, 'user_id' => $learner->id, 'source_type' => 'coupon', 'source_reference' => 'LOESCHFRIST-WB',
            'product_id' => Product::where('code', 'LERNPLATTFORM-WB')->firstOrFail()->id,
        ]));
        $this->order($tenant, $learner, 31);
        $this->artisan('app:prune-personal-data')->assertExitCode(0);
        $this->travelBack();

        $this->actingAsInTenant($learner, $tenant)->get('/willkommensbox')
            ->assertOk()
            ->assertSee('Die Lieferadresse haben wir nach Ablauf der Speicherfrist gelöscht.')
            ->assertDontSee('Lieferstraße');
    }

    // ---------------------------------------------------------------- Gerätewiedererkennung

    private function device(User $user, int $monthsAgo): string
    {
        $id = (string) Str::uuid();
        $this->onAdmin(fn () => DB::table('known_login_device')->insert([
            'id' => $id, 'user_id' => $user->id, 'fingerprint' => hash('sha256', $id),
            'first_seen_at' => now()->subMonths($monthsAgo + 1), 'last_seen_at' => now()->subMonths($monthsAgo),
        ]));

        return $id;
    }

    private function deviceExists(string $id): bool
    {
        return $this->onAdmin(fn () => DB::table('known_login_device')->where('id', $id)->exists());
    }

    public function test_known_login_devices_are_kept_while_no_period_is_set(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $old = $this->device($owner, 30);
        $this->device($owner, 1);

        $this->artisan('app:prune-personal-data')->expectsOutputToContain('Gerätewiedererkennung: keine Frist festgelegt')->assertExitCode(0);

        $this->assertTrue($this->deviceExists($old));
    }

    public function test_unused_login_devices_are_forgotten_but_the_last_one_per_account_stays(): void
    {
        config(['data_retention.known_login_devices.months' => 12]);
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $veryOld = $this->device($owner, 14);
        $atBoundary = $this->device($owner, 11);
        $recent = $this->device($owner, 1);
        // Nur alte Geräte: das zuletzt gesehene bleibt als Bezugspunkt.
        $admin = $this->createTenantUser($tenant, 'admin');
        $adminOlder = $this->device($admin, 20);
        $adminNewest = $this->device($admin, 15);

        $this->artisan('app:prune-personal-data')->assertExitCode(0);

        $this->assertFalse($this->deviceExists($veryOld));
        $this->assertTrue($this->deviceExists($atBoundary));
        $this->assertTrue($this->deviceExists($recent));
        $this->assertFalse($this->deviceExists($adminOlder));
        $this->assertTrue($this->deviceExists($adminNewest));

        $this->travel(2)->months();
        $this->artisan('app:prune-personal-data')->assertExitCode(0);
        $this->assertFalse($this->deviceExists($atBoundary));
        $this->assertTrue($this->deviceExists($recent));
        $this->assertTrue($this->deviceExists($adminNewest));
    }

    // ---------------------------------------------------------------- Shop-Verknüpfungscodes

    public function test_expired_shop_link_codes_are_always_removed_after_a_day(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $code = fn (string $expires, ?string $used = null) => $this->onAdmin(function () use ($tenant, $owner, $expires, $used) {
            $id = (string) Str::uuid();
            DB::table('commerce_link_code')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'created_by_user_id' => $owner->id, 'code_hash' => hash('sha256', $id),
                'expires_at' => Carbon::parse($expires), 'used_at' => $used ? Carbon::parse($used) : null, 'created_at' => now()->subDays(3),
            ]);

            return $id;
        });
        $usedLongAgo = $code('-3 days', '-3 days');
        $expiredLongAgo = $code('-25 hours');
        $justExpired = $code('-23 hours');
        $valid = $code('+10 minutes');
        $exists = fn (string $id) => $this->onAdmin(fn () => DB::table('commerce_link_code')->where('id', $id)->exists());

        $this->artisan('app:prune-personal-data')->assertExitCode(0);

        $this->assertFalse($exists($usedLongAgo));
        $this->assertFalse($exists($expiredLongAgo));
        $this->assertTrue($exists($justExpired));
        $this->assertTrue($exists($valid));

        $this->travel(2)->hours();
        $this->artisan('app:prune-personal-data')->assertExitCode(0);
        $this->assertFalse($exists($justExpired));
        $this->assertTrue($exists($valid));
    }

    // ---------------------------------------------------------------- Datenschutzerklärung

    public function test_privacy_notice_lists_the_new_periods_only_when_set(): void
    {
        $text = PrivacyNotices::retention();
        $this->assertStringNotContainsString('Praxis-Anmeldung', $text);
        $this->assertStringNotContainsString('Willkommensbox', $text);

        config(['data_retention.praxis_submissions.months' => 12, 'data_retention.willkommensbox_addresses.days' => 90]);
        $text = PrivacyNotices::retention();
        $this->assertStringContainsString('**Angaben aus der Praxis-Anmeldung:** 12 Monate nach dem letzten Termin', $text);
        $this->assertStringContainsString('**Lieferadresse der Willkommensbox:** 90 Tage nach dem Versand.', $text);
    }
}
