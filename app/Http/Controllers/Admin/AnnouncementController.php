<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\TenantAnnouncement;
use App\Models\TenantUser;
use App\Notifications\AnnouncementPublished;
use App\Support\BestEffortMail;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Mitteilungen an Lernende: im Lern-Dashboard (alle oder nur ein Kurs) bis
 * zum Ablaufdatum, optional zusätzlich per Mail an die Empfänger mit
 * aktivem Kurszugang.
 */
class AnnouncementController extends Controller
{
    private const MAX_MAILS = 500;

    /** Höchstens so viele Mitteilungen mit Mailversand je Schule und Tag (Schutz vor Missbrauch/Sperrlisten). */
    private const MAILED_PER_DAY = 3;

    public function index(TenantContext $tenantContext): View
    {
        return view('admin.announcements.index', [
            'announcements' => TenantAnnouncement::with('course:id,name', 'author:id,display_name,email')->orderByDesc('created_at')->paginate(20),
            'courses' => $this->courses($tenantContext),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext): RedirectResponse
    {
        $tenant = $tenantContext->tenant();
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'course_id' => ['nullable', 'uuid', Rule::in($this->courses($tenantContext)->pluck('id'))],
            'visible_until' => ['bail', 'nullable', 'date', 'after_or_equal:'.now('Europe/Berlin')->toDateString()],
            'send_mail' => ['nullable', 'boolean'],
        ]);

        $mailedToday = TenantAnnouncement::whereNotNull('emailed_at')->where('emailed_at', '>=', now('Europe/Berlin')->startOfDay()->utc())->count();
        if ($request->boolean('send_mail') && $mailedToday >= self::MAILED_PER_DAY) {
            return back()->withInput()->withErrors(['send_mail' => 'Heute wurden bereits '.self::MAILED_PER_DAY.' Mitteilungen per Mail verschickt. '
                .'Bitte morgen erneut versuchen oder die Mitteilung ohne Mail veröffentlichen.']);
        }

        // Audit ARC-2/QA-D-1: emailed_at wird schon beim Anlegen gesetzt
        // (Claim vor dem Versand, zählt sofort fürs Tageslimit), die Mails
        // gehen je Empfänger als Job über die Queue statt im Request.
        $sendMail = $request->boolean('send_mail');
        $announcement = TenantAnnouncement::create([
            'course_id' => $validated['course_id'] ?? null,
            'title' => trim($validated['title']),
            'body' => trim($validated['body']),
            'visible_until' => $validated['visible_until'] ?? null,
            'created_by_user_id' => $request->user()->id,
            'emailed_at' => $sendMail ? now() : null,
        ]);

        $queued = 0;
        $omitted = 0;
        if ($sendMail) {
            $recipients = $this->recipients($tenant->id, $announcement->course_id);
            $omitted = max($recipients->count() - self::MAX_MAILS, 0);
            foreach ($recipients->take(self::MAX_MAILS) as $membership) {
                if (BestEffortMail::send(fn () => $membership->user->notify(new AnnouncementPublished($tenant, $announcement)), 'Mitteilung '.$announcement->id)) {
                    $queued++;
                }
            }
            $announcement->update(['emailed_count' => $queued]);
        }

        AuditLog::record($tenant->id, $request->user()->id, 'announcement.create', 'tenant_announcement', $announcement->id, null, [
            'title' => $announcement->title, 'course_id' => $announcement->course_id, 'emailed' => $queued,
        ] + ($omitted > 0 ? ['omitted' => $omitted] : []));

        return redirect()->route('admin.announcements.index')
            ->with('status', 'Mitteilung veröffentlicht'.($sendMail ? self::mailStatus($queued, $omitted, self::MAX_MAILS) : '.'));
    }

    /** Rückmeldung zum Mailversand, nennt eine Kürzung bei der Höchstgrenze. */
    public static function mailStatus(int $queued, int $omitted, int $max): string
    {
        $status = $queued === 1
            ? ', die Mail an 1 Person wird im Hintergrund verschickt.'
            : ", die Mails an {$queued} Personen werden im Hintergrund verschickt.";
        if ($omitted > 0) {
            $status .= " Höchstens {$max} Mails je Mitteilung: {$omitted} ".($omitted === 1 ? 'weitere Person wurde' : 'weitere Personen wurden').' nicht angeschrieben.';
        }

        return $status;
    }

    public function destroy(Request $request, TenantContext $tenantContext, TenantAnnouncement $announcement): RedirectResponse
    {
        abort_unless($announcement->tenant_id === $tenantContext->id(), 404);
        $announcement->delete();
        AuditLog::record($tenantContext->id(), $request->user()->id, 'announcement.delete', 'tenant_announcement', $announcement->id, ['title' => $announcement->title], null);

        return back()->with('status', 'Mitteilung gelöscht.');
    }

    /** Lernende mit aktivem Zugang (zu dem Kurs, falls angegeben). */
    private function recipients(string $tenantId, ?string $courseId)
    {
        $userIds = Entitlement::currentlyActive()->where('tenant_id', $tenantId)
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))->distinct()->pluck('user_id');

        return TenantUser::with('user')->where('tenant_id', $tenantId)->where('role', 'learner')->where('status', 'active')
            ->whereIn('user_id', $userIds)->get()->filter(fn ($m) => $m->user && $m->user->status === 'active');
    }

    private function courses(TenantContext $tenantContext)
    {
        $ids = Entitlement::where('tenant_id', $tenantContext->id())->distinct()->pluck('course_id');

        return CourseDefinition::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }
}
