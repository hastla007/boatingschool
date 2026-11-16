<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DateInput;
use App\Support\TenantPicker;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Protokoll durchsuchen: wer hat wann was geändert (plattformweit, alle Schulen). */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        // Schulfilter per Suchfeld (components/superadmin/tenant-picker):
        // tenant=<uuid> wie bisher, ohne JavaScript tenant_slug (Kürzel/Name,
        // TenantPicker::resolveQuery()); „Nur Plattform“ als eigenes
        // Kästchen (platform_only), tenant=platform gilt weiterhin.
        $tenantParam = $this->stringParam($request, 'tenant');
        $picked = TenantPicker::resolveQuery($request, 'tenant');
        $platformOnly = $tenantParam === 'platform' || $request->query('platform_only') === '1';
        $filters = [
            'tenant' => $platformOnly ? 'platform' : $picked['id'],
            'actor' => $this->stringParam($request, 'actor'),
            'action' => $this->stringParam($request, 'action'),
            'entity' => $this->stringParam($request, 'entity'),
            'from' => $this->stringParam($request, 'from'),
            'to' => $this->stringParam($request, 'to'),
        ];

        $tenantFilterError = $platformOnly ? null : $picked['error'];

        $query = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id');
        // Nicht auflösbarer Schulfilter (z. B. Teil eines Namens ohne Auswahl):
        // keine Einträge statt aller Schulen -- wie die Codeliste
        // (Superadmin\CouponController::index()).
        if ($tenantFilterError !== null) {
            $query->whereRaw('false');
        }
        if ($filters['tenant'] === 'platform') {
            $query->whereNull('tenant_id');
        } elseif ($filters['tenant'] && Str::isUuid($filters['tenant'])) {
            $query->where('tenant_id', $filters['tenant']);
        }
        if ($filters['actor']) {
            $query->whereIn('actor_user_id', User::where('email', 'ilike', '%'.$filters['actor'].'%')->select('id'));
        }
        if ($filters['action']) {
            $query->where('action', 'ilike', '%'.$filters['action'].'%');
        }
        if ($filters['entity'] && Str::isUuid($filters['entity'])) {
            $query->where('entity_id', $filters['entity']);
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (DateInput::isDate($filters[$key])) {
                $query->where('created_at', $operator, $key === 'from' ? $filters[$key].' 00:00:00' : $filters[$key].' 23:59:59');
            }
        }

        $entries = $query->paginate(50)->withQueryString();
        // Lesbare Texte (AuditLog::description()) ohne Einzelabfragen je Zeile.
        AuditLog::preloadFor($entries->getCollection());
        $actors = User::whereIn('id', $entries->pluck('actor_user_id')->filter()->unique())->get(['id', 'email', 'display_name'])->keyBy('id');
        // Nur die Namen der Schulen auf dieser Seite, nicht aller (Schulfilter: Suchfeld).
        $tenantNames = Tenant::whereIn('id', $entries->pluck('tenant_id')->filter()->unique()->values())->pluck('name', 'id');
        $selectedTenant = $filters['tenant'] && $filters['tenant'] !== 'platform' ? Tenant::find($filters['tenant']) : null;

        return view('superadmin.audit.index', [
            'entries' => $entries,
            'actors' => $actors,
            'tenantNames' => $tenantNames,
            'selectedTenant' => $selectedTenant,
            'tenantFilterError' => $tenantFilterError,
            'filters' => $filters,
        ]);
    }

    private function stringParam(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
