<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformAnnouncement;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Notifications\PlatformAnnouncementPublished;
use App\Support\BestEffortMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Mitteilung an alle (oder ausgewählte) Bootsschulen: erscheint im
 * Admin-Bereich der Schulen, wahlweise auch im Lern-Dashboard, und geht auf
 * Wunsch per Mail an die Inhaber und Admins aktiver Schulen (je Person nur
 * eine Mail, auch wenn sie in mehreren Schulen Admin ist).
 */
class PlatformAnnouncementController extends Controller
{
    private const MAX_MAILS = 1000;

    public function index(): View
    {
        return view('superadmin.announcements.index', [
            'announcements' => PlatformAnnouncement::with('author:id,display_name,email')->orderByDesc('created_at')->paginate(20),
            'tenants' => Tenant::orderBy('name')->get(['id', 'name', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', Rule::in(['team', 'all'])],
            'target' => ['required', Rule::in(['all', 'selected'])],
            'tenant_ids' => ['required_if:target,selected', 'array', 'max:1000'],
            'tenant_ids.*' => ['uuid', Rule::exists('tenant', 'id')],
            'visible_until' => ['nullable', 'date', 'after_or_equal:today'],
            'send_mail' => ['nullable', 'boolean'],
        ], [
            'tenant_ids.required_if' => 'Bitte mindestens eine Bootsschule auswählen.',
        ]);

        $tenantIds = $validated['target'] === 'selected' ? array_values(array_unique($validated['tenant_ids'])) : null;

        $announcement = PlatformAnnouncement::create([
            'title' => trim($validated['title']),
            'body' => trim($validated['body']),
            'audience' => $validated['audience'],
            'tenant_ids' => $tenantIds,
            'visible_until' => $validated['visible_until'] ?? null,
            'created_by_user_id' => $request->user()->id,
        ]);

        $sent = 0;
        if ($request->boolean('send_mail')) {
            foreach ($this->recipients($tenantIds)->take(self::MAX_MAILS) as $membership) {
                if (BestEffortMail::send(fn () => $membership->user->notify(new PlatformAnnouncementPublished($announcement, $membership->tenant)), 'Plattform-Mitteilung '.$announcement->id)) {
                    $sent++;
                }
            }
            $announcement->update(['emailed_at' => now(), 'emailed_count' => $sent]);
        }

        AuditLog::record(null, $request->user()->id, 'superadmin.platform_announcement.create', 'platform_announcement', $announcement->id, null, [
            'title' => $announcement->title, 'audience' => $announcement->audience, 'tenant_ids' => $tenantIds, 'emailed' => $sent,
        ]);

        return redirect()->route('superadmin.announcements.index')
            ->with('status', 'Mitteilung veröffentlicht'.($request->boolean('send_mail') ? " und an {$sent} ".($sent === 1 ? 'Person' : 'Personen').' gemailt.' : '.'));
    }

    public function destroy(Request $request, PlatformAnnouncement $announcement): RedirectResponse
    {
        $announcement->delete();
        AuditLog::record(null, $request->user()->id, 'superadmin.platform_announcement.delete', 'platform_announcement', $announcement->id, ['title' => $announcement->title], null);

        return back()->with('status', 'Mitteilung gelöscht.');
    }

    /** Inhaber und Admins aktiver Schulen, je Person einmal (erste Schule nach Name). */
    private function recipients(?array $tenantIds): Collection
    {
        return TenantUser::with(['user', 'tenant'])
            ->whereIn('role', ['owner', 'admin'])->where('status', 'active')
            ->whereHas('tenant', fn ($q) => $q->where('status', 'active'))
            ->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            ->get()
            ->filter(fn ($m) => $m->user && $m->user->status === 'active' && $m->tenant)
            ->sortBy(fn ($m) => $m->tenant->name)
            ->unique('user_id')
            ->values();
    }
}
