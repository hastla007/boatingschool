<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendLearningReminders;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\TenantUser;
use App\Services\LearnerOverview;
use App\Support\InstructorScope;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Lernende mit aktivem Kurszugang, die seit X Tagen nicht gelernt haben,
 * und Erinnerungsmail (einzeln oder an alle). Höchstens eine Erinnerung je
 * Person und 7 Tage (tenant_user.reminded_at).
 */
class InactiveLearnerController extends Controller
{
    /** Bis zu so vielen Empfängern wird direkt (mit Rückmeldung) verschickt. */
    private const SYNC_LIMIT = 10;

    public const DAY_OPTIONS = [7, 14, 30, 60];

    private const REMINDER_COOLDOWN_DAYS = SendLearningReminders::REMINDER_COOLDOWN_DAYS;

    public function index(Request $request, TenantContext $tenantContext, LearnerOverview $overview): View
    {
        $days = $this->days($request);

        return view('admin.inactive.index', [
            'days' => $days,
            'rows' => $this->inactive($tenantContext, $overview, $days),
            'cooldownDays' => self::REMINDER_COOLDOWN_DAYS,
        ]);
    }

    public function remind(Request $request, TenantContext $tenantContext, LearnerOverview $overview): RedirectResponse
    {
        $request->validate(['user_id' => ['nullable', 'uuid'], 'days' => ['nullable', 'integer']]);
        $days = $this->days($request);
        $tenant = $tenantContext->tenant();

        $rows = $this->inactive($tenantContext, $overview, $days)
            ->when($request->filled('user_id'), fn ($c) => $c->where('membership.user_id', $request->input('user_id')))
            ->reject(fn ($row) => $row['recently_reminded'])
            ->take(200);

        $items = $rows->map(fn ($row) => [
            'user_id' => $row['membership']->user_id,
            'days_since' => $row['last_activity'] ? (int) $row['last_activity']->diffInDays(now()) : null,
        ])->values()->all();

        // Review-Runde 11: viele Empfänger nicht im Request nacheinander
        // anschreiben (bei echtem SMTP sonst Zeitüberschreitung) -- der
        // Versand läuft als Queue-Job je Paket im Worker (Mandantenkontext
        // über TenantAware, Wiederholung bei Mailfehlern).
        if (count($items) > self::SYNC_LIMIT) {
            foreach (array_chunk($items, SendLearningReminders::CHUNK) as $chunk) {
                SendLearningReminders::dispatch($chunk);
            }
            AuditLog::record($tenant->id, $request->user()->id, 'participants.remind_inactive', 'tenant_user', null, null, ['days' => $days, 'queued' => count($items)]);

            return redirect()->route('admin.inactive.index', ['days' => $days])
                ->with('status', count($items).' Erinnerungen werden jetzt verschickt.');
        }

        [$sent, $failed] = SendLearningReminders::sendBatch($tenant, $items);
        AuditLog::record($tenant->id, $request->user()->id, 'participants.remind_inactive', 'tenant_user', $request->input('user_id'), null, ['days' => $days, 'sent' => $sent]);

        return redirect()->route('admin.inactive.index', ['days' => $days])
            ->with('status', ($sent === 1 ? 'Eine Erinnerung verschickt.' : "{$sent} Erinnerungen verschickt.")
                .($failed > 0 ? " {$failed} konnten nicht verschickt werden und lassen sich erneut versuchen." : ''));
    }

    /** @return Collection<int, array{membership: TenantUser, last_activity: ?Carbon, recently_reminded: bool}> */
    private function inactive(TenantContext $tenantContext, LearnerOverview $overview, int $days): Collection
    {
        $tenant = $tenantContext->tenant();
        $withAccess = Entitlement::currentlyActive()->where('tenant_id', $tenant->id)->distinct()->pluck('user_id');
        $visible = InstructorScope::learnerIds($tenant, request()->user());
        $members = TenantUser::with('user')->where('tenant_id', $tenant->id)->where('role', 'learner')->where('status', 'active')
            ->whereIn('user_id', $withAccess)->when($visible !== null, fn ($q) => $q->whereIn('user_id', $visible))->get()->filter(fn ($m) => $m->user && $m->user->status === 'active');
        $last = $overview->lastActivities($tenant, $members->pluck('user_id'));
        $cutoff = now()->subDays($days);

        return $members->map(fn (TenantUser $m) => [
            'membership' => $m,
            'last_activity' => isset($last[$m->user_id]) ? Carbon::parse($last[$m->user_id]) : null,
            'recently_reminded' => $m->reminded_at !== null && $m->reminded_at->gt(now()->subDays(self::REMINDER_COOLDOWN_DAYS)),
        ])->filter(fn ($row) => $row['last_activity'] === null || $row['last_activity']->lt($cutoff))
            ->sortBy(fn ($row) => $row['last_activity']?->timestamp ?? 0)->values();
    }

    private function days(Request $request): int
    {
        $days = (int) $request->input('days', 14);

        return in_array($days, self::DAY_OPTIONS, true) ? $days : 14;
    }
}
