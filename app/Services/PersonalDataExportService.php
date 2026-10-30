<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\FavoriteCollection;
use App\Models\FavoriteCollectionItem;
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

/**
 * DSGVO-Review D3: Auskunfts-/Mitnahmerecht (Art. 15/20) -- bislang gab es
 * keinen Weg, die bei einer Bootsschule über einen selbst gespeicherten
 * personenbezogenen Daten strukturiert zu exportieren. Scoped auf genau
 * den aktuellen Mandanten, analog zu AccountErasureService: jede
 * Bootsschule ist ein eigener datenschutzrechtlich Verantwortlicher.
 */
class PersonalDataExportService
{
    public function export(User $user, Tenant $tenant): array
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
            ],
            'membership' => $membership ? [
                'role' => $membership->role,
                'status' => $membership->status,
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
                    'response_time_ms' => $a->response_time_ms,
                    'created_at' => $a->created_at?->toIso8601String(),
                ])->all(),
            'learning_progress' => Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get()
                ->map(fn (Progress $p) => [
                    'question_id' => $p->question_id,
                    'learning_state' => $p->learning_state,
                    'current_streak' => $p->current_streak,
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
        ];
    }
}
