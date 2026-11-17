<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\FormCaptainLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Lead-Inbox (BS-09, docs/integrations/form-captain-leads.md): Interessenten
 * aus den Form-Captain-Formularen der Website ansehen, bearbeiten, ablehnen,
 * löschen. Bewusst ohne „Konto anlegen“: aus einem Lead entsteht hier weder
 * ein Benutzer noch eine Schule, ein Shopkonto oder ein Code. Wer daraus
 * eine Schule macht, legt sie wie bisher unter „Bootsschulen“ an.
 */
class LeadController extends Controller
{
    public function index(Request $request): View
    {
        $status = is_string($request->query('status')) ? $request->query('status') : 'open';
        $search = is_string($request->query('q')) ? trim($request->query('q')) : '';
        $purpose = is_string($request->query('purpose')) && array_key_exists($request->query('purpose'), FormCaptainLead::PURPOSE_LABELS) ? $request->query('purpose') : '';

        $leads = FormCaptainLead::query()
            ->when($status === 'open', fn ($q) => $q->whereIn('status', [FormCaptainLead::NEW, FormCaptainLead::IN_PROGRESS]))
            ->when(array_key_exists($status, FormCaptainLead::STATUS_LABELS), fn ($q) => $q->where('status', $status))
            ->when($purpose !== '', fn ($q) => $q->where('purpose', $purpose))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $q->where(fn ($w) => $w->whereRaw('lower(contact_email) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(contact_name, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(contact_organisation, \'\')) like ?', [$like])
                    ->orWhere('public_id', $search));
            })
            ->orderByDesc('created_at')->paginate(30)->withQueryString();

        return view('superadmin.leads.index', [
            'leads' => $leads,
            'status' => $status,
            'search' => $search,
            'purpose' => $purpose,
            'keys' => $this->keys(),
        ]);
    }

    public function show(FormCaptainLead $lead): View
    {
        return view('superadmin.leads.show', ['lead' => $lead->load('handledBy')]);
    }

    /** Status und interne Notiz ändern. */
    public function update(Request $request, FormCaptainLead $lead): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(FormCaptainLead::STATUS_LABELS))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'rejection_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $this->apply($request, $lead, $validated['status'], $validated['notes'] ?? null, $validated['rejection_reason'] ?? $lead->rejection_reason);

        return redirect()->route('superadmin.leads.show', $lead)->with('status', 'Lead gespeichert.');
    }

    public function reject(Request $request, FormCaptainLead $lead): RedirectResponse
    {
        $validated = $request->validate(['rejection_reason' => ['nullable', 'string', 'max:500']]);
        $this->apply($request, $lead, FormCaptainLead::REJECTED, $lead->notes, $validated['rejection_reason'] ?? null);

        return redirect()->route('superadmin.leads.show', $lead)->with('status', 'Lead abgelehnt.');
    }

    public function destroy(FormCaptainLead $lead): RedirectResponse
    {
        $lead->delete();

        return redirect()->route('superadmin.leads.index')->with('status', 'Lead gelöscht. Der Eintrag in Form Captain bleibt unverändert.');
    }

    private function apply(Request $request, FormCaptainLead $lead, string $status, ?string $notes, ?string $reason): void
    {
        $changed = $status !== $lead->status;
        $lead->forceFill([
            'status' => $status,
            'notes' => $notes,
            'rejection_reason' => $status === FormCaptainLead::REJECTED ? $reason : null,
            'handled_by_user_id' => $request->user()->id,
            'status_changed_at' => $changed ? now() : $lead->status_changed_at,
            // Aufbewahrung läuft ab der letzten Statusänderung neu.
            'retain_until' => $changed ? FormCaptainLead::retainUntil() : $lead->retain_until,
        ])->save();
    }

    /**
     * Konfigurierte Schlüssel mit „zuletzt benutzt“ (Rotation, Vertrag A.2). Nie das Geheimnis.
     *
     * @return list<array{id: string, last_used: ?string, revoked: bool}>
     */
    private function keys(): array
    {
        $revoked = (array) config('form_captain.revoked_key_ids', []);

        return collect(array_keys((array) config('form_captain.keys', [])))
            ->merge($revoked)->unique()->values()
            ->map(fn (string $id) => ['id' => $id, 'last_used' => Cache::get('form-captain-key-last-used:'.$id), 'revoked' => in_array($id, $revoked, true)])
            ->all();
    }
}
