<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DateInput;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Protokoll durchsuchen: wer hat wann was geändert (plattformweit, alle Schulen). */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'tenant' => $this->stringParam($request, 'tenant'),
            'actor' => $this->stringParam($request, 'actor'),
            'action' => $this->stringParam($request, 'action'),
            'entity' => $this->stringParam($request, 'entity'),
            'from' => $this->stringParam($request, 'from'),
            'to' => $this->stringParam($request, 'to'),
        ];

        $query = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id');
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
        $actors = User::whereIn('id', $entries->pluck('actor_user_id')->filter()->unique())->get(['id', 'email', 'display_name'])->keyBy('id');
        $tenants = Tenant::orderBy('name')->get(['id', 'name']);

        return view('superadmin.audit.index', [
            'entries' => $entries,
            'actors' => $actors,
            'tenants' => $tenants,
            'tenantNames' => $tenants->pluck('name', 'id'),
            'filters' => $filters,
        ]);
    }

    private function stringParam(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
