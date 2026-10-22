<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisProgress;
use App\Models\ProductPurchase;
use App\Models\Progress;
use App\Models\Tenant;
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
                'phone' => $user->phone,
                'street' => $user->street,
                'postal_code' => $user->postal_code,
                'city' => $user->city,
                'country' => $user->country,
                'birthdate' => $user->birthdate?->toDateString(),
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
            'praxis_form_submissions' => PraxisFormSubmission::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->orderBy('created_at')->get()
                ->map(fn (PraxisFormSubmission $s) => [
                    'data' => $s->data,
                    'created_at' => $s->created_at?->toIso8601String(),
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
            'willkommensbox_orders' => WillkommensboxOrder::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->orderBy('created_at')->get()
                ->map(fn (WillkommensboxOrder $o) => [
                    'street' => $o->street,
                    'postal_code' => $o->postal_code,
                    'city' => $o->city,
                    'created_at' => $o->created_at?->toIso8601String(),
                ])->all(),
        ];
    }
}
