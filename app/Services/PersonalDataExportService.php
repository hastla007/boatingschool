<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\FavoriteCollection;
use App\Models\FavoriteCollectionItem;
use App\Models\InstructorCourse;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DSGVO-Review D3: Auskunfts-/Mitnahmerecht (Art. 15/20) -- bislang gab es
 * keinen Weg, die bei einer Bootsschule über einen selbst gespeicherten
 * personenbezogenen Daten strukturiert zu exportieren. Scoped auf genau
 * den aktuellen Mandanten, analog zu AccountErasureService: jede
 * Bootsschule ist ein eigener datenschutzrechtlich Verantwortlicher.
 */
class PersonalDataExportService
{
    /**
     * @param  bool  $forSchool  Export an die Schule (Vertragsende): ohne Sitzungen
     *                           (gelten plattformweit, auch für andere Schulen) und ohne
     *                           das Protokoll, das die Schule ohnehin selbst hat.
     */
    public function export(User $user, Tenant $tenant, bool $forSchool = false): array
    {
        $data = $this->collect($user, $tenant, $forSchool);
        if ($forSchool) {
            unset($data['sessions'], $data['app_devices'], $data['audit_log']);
        }

        return $data;
    }

    private function collect(User $user, Tenant $tenant, bool $forSchool): array
    {
        $membership = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();

        return [
            'exported_at' => now()->toIso8601String(),
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
            'profile' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'display_name' => $user->display_name,
                'email' => $user->email,
                // Bug-Review (Datenexport unvollständig): eine noch nicht
                // bestätigte neue E-Mail-Adresse (siehe Migration 000454,
                // ProfileController) ist ebenso ein über den Nutzer
                // gespeichertes personenbezogenes Datum wie die aktuelle.
                'pending_email' => $user->pending_email,
                'phone' => $user->phone,
                // Sicherheitsreview S3: diese Felder liegen seit Migration
                // 000469 je Mitgliedschaft auf tenant_user, nicht mehr
                // kontoweit auf app_user -- sonst hätte dieser PER-MANDANT
                // gedachte Export (siehe Klassenkommentar) genau die
                // kontoweit geteilten Werte einer ANDEREN Bootsschule
                // exportiert.
                'street' => $membership?->street,
                'postal_code' => $membership?->postal_code,
                'city' => $membership?->city,
                'country' => $membership?->country,
                'birthdate' => $membership?->birthdate?->toDateString(),
                'locale' => $user->locale,
                'account_created_at' => $user->created_at?->toIso8601String(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                // Löschfrist für inaktive Konten (app:prune-inactive-learners).
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'inactivity_deletion_warned_at' => $user->inactivity_warned_at?->toIso8601String(),
            ],
            'membership' => $membership ? [
                'role' => $membership->role,
                'status' => $membership->status,
                'member_since' => $membership->created_at?->toIso8601String(),
                'reminded_at' => $membership->reminded_at?->toIso8601String(),
                'instructor_courses' => InstructorCourse::where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('course_id')->all(),
            ] : null,
            'entitlements' => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->with('course')->orderBy('valid_from')->get()
                ->map(fn (Entitlement $e) => [
                    'course' => $e->course->name ?? null,
                    'status' => $e->status,
                    'source_type' => $e->source_type,
                    'source_reference' => $e->source_reference,
                    'valid_from' => $e->valid_from?->toIso8601String(),
                    'valid_until' => $e->valid_until?->toIso8601String(),
                    'expiry_reminded_at' => $e->expiry_reminded_at?->toIso8601String(),
                ])->all(),
            'redeemed_codes' => Coupon::where('redeemed_tenant_id', $tenant->id)->where('redeemed_by_user_id', $user->id)
                ->orderBy('redeemed_at')->get()
                ->map(fn (Coupon $c) => ['code' => $c->code, 'redeemed_at' => $c->redeemed_at?->toIso8601String()])->all(),
            // Shopkonten (B2B-Shop), über die für diese Schule eingekauft wird.
            'shop_accounts' => DB::table('commerce_shop_account')->where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->orderBy('created_at')->get()
                ->map(fn ($a) => [
                    'shop_customer_id' => $a->shop_customer_id, 'status' => $a->status, 'email' => $a->email,
                    'contact_name' => $a->contact_name, 'company_name' => $a->company_name, 'billing_country' => $a->billing_country,
                    'vat_id' => $a->vat_id, 'linked_at' => $a->linked_at, 'created_at' => $a->created_at,
                ])->all(),
            'product_purchases' => ProductPurchase::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->with('product')->orderBy('created_at')->get()
                ->map(fn (ProductPurchase $p) => [
                    'product' => $p->product->name ?? null,
                    'source_type' => $p->source_type,
                    'source_reference' => $p->source_reference,
                    'created_at' => $p->created_at?->toIso8601String(),
                ])->all(),
            'learning_attempts' => Attempt::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->orderBy('created_at')->get()
                ->map(fn (Attempt $a) => [
                    'question_id' => $a->question_id,
                    'course_id' => $a->course_id,
                    'context' => $a->context,
                    'correct' => $a->correct,
                    'selected_answer_id' => $a->selected_answer_id,
                    'selected_answer_ids' => $a->selected_answer_ids,
                    'response_time_ms' => $a->response_time_ms,
                    'created_at' => $a->created_at?->toIso8601String(),
                ])->all(),
            'learning_progress' => Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get()
                ->map(fn (Progress $p) => [
                    'question_id' => $p->question_id,
                    'learning_state' => $p->learning_state,
                    'current_streak' => $p->current_streak,
                    'attempt_count' => $p->attempt_count,
                    'correct_count' => $p->correct_count,
                    'incorrect_count' => $p->incorrect_count,
                    'mastery_score' => $p->mastery_score,
                    'first_seen_at' => $p->first_seen_at?->toIso8601String(),
                    'last_seen_at' => $p->last_seen_at?->toIso8601String(),
                ])->all(),
            'exam_sessions' => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->orderBy('created_at')->get()
                ->map(fn (ExamSession $s) => [
                    'course_id' => $s->course_id,
                    'status' => $s->status,
                    'score' => $s->score,
                    'passed' => $s->passed,
                    'started_at' => $s->started_at?->toIso8601String(),
                    'submitted_at' => $s->submitted_at?->toIso8601String(),
                    'navigation_points' => $s->navigation_points,
                    'navigation_answers' => $s->navigationAnswers()->get()->map(fn ($a) => [
                        'question_number' => $a->number(), 'question' => $a->questionText(), 'answer' => $a->answer, 'self_assessed_known' => $a->self_assessed_known, 'correct' => $a->correct,
                    ])->all(),
                    'answers' => $s->questions()->orderBy('position')->get()->map(fn ($q) => [
                        'position' => $q->position,
                        'question_id' => $q->question_id,
                        'selected_answer_id' => $q->selected_answer_id,
                        'selected_answer_ids' => $q->selected_answer_ids,
                        'self_assessed_known' => $q->self_assessed_known,
                        'correct' => $q->correct,
                        'answered_at' => $q->answered_at?->toIso8601String(),
                    ])->all(),
                ])->all(),
            'favorites' => Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get()
                ->map(fn (Favorite $f) => [
                    'question_id' => $f->question_id,
                    'context' => $f->context,
                    'created_at' => $f->created_at?->toIso8601String(),
                ])->all(),
            // N8/D3: selbst benannte Favoriten-Sammlungen kamen nach der
            // ersten Fassung dieses Exports hinzu.
            'favorite_collections' => FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->orderBy('sort_order')->get()
                ->map(fn (FavoriteCollection $c) => [
                    'name' => $c->name,
                    'course_id' => $c->course_id,
                    'is_default' => $c->is_default,
                ])->all(),
            // Bug-Review (BB7: Datenexport unvollständig): favorite_collections
            // listet bisher nur die selbst benannten Sammlungen, nicht aber,
            // welche Fragen der Lerner tatsächlich in sie einsortiert hat
            // (favorite_collection_item) -- genau diese Zuordnung ist der
            // eigentliche, selbst erstellte Inhalt einer Sammlung.
            'favorite_collection_items' => FavoriteCollectionItem::where('tenant_id', $tenant->id)
                ->whereIn('favorite_collection_id', FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('id'))
                ->get()
                ->map(fn (FavoriteCollectionItem $i) => [
                    'favorite_collection_id' => $i->favorite_collection_id,
                    'question_id' => $i->question_id,
                    'context' => $i->context,
                ])->all(),
            'praxis_form_submissions' => PraxisFormSubmission::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->orderBy('created_at')->get()
                ->map(fn (PraxisFormSubmission $s) => [
                    'data' => $s->data,
                    'created_at' => $s->created_at?->toIso8601String(),
                ])->all(),
            // N8/D3: Praxis-/Prüfungstermine (siehe PraxisExamAppointment) sind
            // über die Anmeldung mit dem Nutzer verknüpft, nicht über eine
            // eigene user_id-Spalte -- diese Funktion kam nach der ersten
            // Fassung dieses Exports hinzu und wurde hier nachgezogen.
            'praxis_exam_appointments' => PraxisExamAppointment::where('tenant_id', $tenant->id)
                ->whereHas('submission', fn ($q) => $q->where('user_id', $user->id))
                ->orderBy('exam_date')->get()
                ->map(fn (PraxisExamAppointment $a) => [
                    'label' => $a->label,
                    'description' => $a->description,
                    'round' => $a->round,
                    'is_online' => $a->is_online,
                    'online_meeting_url' => $a->online_meeting_url,
                    'exam_date' => $a->exam_date?->toIso8601String(),
                    'ends_at' => $a->ends_at?->toIso8601String(),
                    'response' => $a->response,
                    'response_comment' => $a->response_comment,
                    'responded_at' => $a->responded_at?->toIso8601String(),
                ])->all(),
            'praxis_progress' => PraxisProgress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get()
                ->map(fn (PraxisProgress $p) => [
                    'praxis_task_id' => $p->praxis_task_id,
                    'completed' => $p->completed,
                ])->all(),
            'navigation_progress' => NavigationProgress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get()
                ->map(fn (NavigationProgress $p) => [
                    'navigation_question_id' => $p->navigation_question_id,
                    'answer' => $p->answer,
                    'correct' => $p->correct,
                    'updated_at' => $p->updated_at?->toIso8601String(),
                ])->all(),
            'video_progress' => VideoProgress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get()
                ->map(fn (VideoProgress $p) => [
                    'video_lesson_id' => $p->video_lesson_id,
                    'completed' => $p->completed,
                    'last_position_seconds' => $p->last_position_seconds,
                ])->all(),
            // Bug-Review (Funk-Training fehlt im Datenexport): answers enthält
            // die zuletzt ausgewerteten freien Eingaben/Mitschriften des
            // Lerners (siehe Migration 2024_02_01_000479) -- die Tabelle kam
            // nach diesem Export hinzu und fehlte darin bisher vollständig.
            'radio_training_progress' => RadioTrainingProgress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->orderBy('item_type')->orderBy('item_key')->get()
                ->map(fn (RadioTrainingProgress $p) => [
                    'item_type' => $p->item_type,
                    'item_key' => $p->item_key,
                    'completed' => $p->completed,
                    'streak' => $p->streak,
                    'answers' => $p->answers,
                    'updated_at' => $p->updated_at?->toIso8601String(),
                ])->all(),
            'willkommensbox_orders' => WillkommensboxOrder::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->orderBy('created_at')->get()
                ->map(fn (WillkommensboxOrder $o) => [
                    'street' => $o->street,
                    'postal_code' => $o->postal_code,
                    'city' => $o->city,
                    // Bug-Review (Datenexport unvollständig): Land, freie
                    // Anmerkung des Lerners sowie Versanddatum und
                    // Sendungsnummer (siehe Migration 000442) fehlten hier
                    // bisher, obwohl sie zur selben Bestellung gehören.
                    'country' => $o->country,
                    'note' => $o->note,
                    'shipped_at' => $o->shipped_at?->toIso8601String(),
                    'tracking_number' => $o->tracking_number,
                    'created_at' => $o->created_at?->toIso8601String(),
                ])->all(),
            // DSGVO-Review D3 (Folgefehler): eine Einladung an eine bereits
            // existierende Adresse (siehe ParticipantController::invite(),
            // Sicherheitsreview H2) legt eine tenant_invitation-Zeile mit der
            // eingeladenen E-Mail-Adresse an -- über sie gespeicherte Daten
            // fehlten im Auskunfts-Export bisher vollständig.
            'tenant_invitations' => TenantInvitation::where('tenant_id', $tenant->id)->where('email', $user->email)
                ->orderBy('created_at')->get()
                ->map(fn (TenantInvitation $i) => [
                    'role' => $i->role,
                    'created_at' => $i->created_at?->toIso8601String(),
                    'accepted_at' => $i->accepted_at?->toIso8601String(),
                ])->all(),
            // Angemeldete Geräte (IP-Adresse, Browser) dieses Kontos.
            'sessions' => $forSchool ? [] : DB::connection('pgsql_admin')->table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get()
                ->map(fn ($row) => [
                    'ip_address' => $row->ip_address,
                    'user_agent' => $row->user_agent,
                    'last_activity' => date(DATE_ATOM, (int) $row->last_activity),
                ])->all(),
            // Angemeldete Geräte der Mobile-App (ohne Tokens, nur deren Hashes liegen vor).
            'app_devices' => $forSchool ? [] : DB::connection('pgsql_admin')->table('api_device_session')->where('user_id', $user->id)->orderByDesc('created_at')->get()
                ->map(fn ($row) => [
                    'device_name' => $row->device_name,
                    'device_platform' => $row->device_platform,
                    'app_version' => $row->app_version,
                    'created_at' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
                    'last_used_at' => $row->last_used_at ? Carbon::parse($row->last_used_at)->toIso8601String() : null,
                    'revoked_at' => $row->revoked_at ? Carbon::parse($row->revoked_at)->toIso8601String() : null,
                ])->all(),
            // Bekannte Anmelde-Geräte (Team/Superadmin, Hinweis-Mail bei neuem
            // Gerät): gespeichert ist nur ein Fingerabdruck-Hash, keine IP.
            'known_login_devices' => $forSchool ? [] : DB::connection('pgsql_admin')->table('known_login_device')->where('user_id', $user->id)->orderBy('first_seen_at')->get()
                ->map(fn ($row) => [
                    'first_seen_at' => Carbon::parse($row->first_seen_at)->toIso8601String(),
                    'last_seen_at' => Carbon::parse($row->last_seen_at)->toIso8601String(),
                ])->all(),
            // Protokolleinträge dieser Bootsschule, die die Person betreffen.
            'audit_log' => $forSchool ? [] : AuditLog::where('tenant_id', $tenant->id)
                ->where(fn ($q) => $q->where('actor_user_id', $user->id)
                    ->orWhere(fn ($i) => $i->where('entity_type', 'app_user')->where('entity_id', $user->id))
                    ->orWhereRaw("before_data->>'user_id' = ?", [$user->id])
                    ->orWhereRaw("after_data->>'user_id' = ?", [$user->id]))
                ->orderBy('created_at')->get()
                ->map(fn (AuditLog $l) => [
                    'action' => $l->action,
                    'by_this_person' => $l->actor_user_id === $user->id,
                    'created_at' => $l->created_at?->toIso8601String(),
                ])->all(),
        ];
    }
}
