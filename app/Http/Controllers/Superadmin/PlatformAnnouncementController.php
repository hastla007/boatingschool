<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPlatformAnnouncementMails;
use App\Models\AuditLog;
use App\Models\PlatformAnnouncement;
use App\Models\Tenant;
use App\Models\TenantUser;
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
            'visible_until' => ['bail', 'nullable', 'date', 'after_or_equal:'.now('Europe/Berlin')->toDateString()],
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

        // Versand im Queue-Worker (ein Job je Paket); emailed_count zählen die Jobs hoch.
        $queued = 0;
        if ($request->boolean('send_mail')) {
            $recipients = $this->recipients($tenantIds)->take(self::MAX_MAILS)
                ->map(fn ($m) => ['user_id' => $m->user_id, 'tenant_id' => $m->tenant_id])->values()->all();
            $queued = count($recipients);
            $announcement->update(['emailed_at' => now(), 'emailed_count' => 0]);
            foreach (array_chunk($recipients, SendPlatformAnnouncementMails::CHUNK) as $chunk) {
                SendPlatformAnnouncementMails::dispatch($announcement->id, $chunk);
            }
        }

        AuditLog::record(null, $request->user()->id, 'superadmin.platform_announcement.create', 'platform_announcement', $announcement->id, null, [
            'title' => $announcement->title, 'audience' => $announcement->audience, 'tenant_ids' => $tenantIds, 'emailed' => $queued,
        ]);

        return redirect()->route('superadmin.announcements.index')
            ->with('status', 'Mitteilung veröffentlicht'.($request->boolean('send_mail') ? " und wird an {$queued} ".($queued === 1 ? 'Person' : 'Personen').' verschickt.' : '.'));
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
