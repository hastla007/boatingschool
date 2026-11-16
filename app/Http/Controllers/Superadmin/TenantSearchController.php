<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\StatusLabels;
use App\Support\TenantPicker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Schulsuche (JSON) für die Schulauswahl im Superadmin-Bereich
 * (components/superadmin/tenant-picker, App\Support\TenantPicker): Suche in
 * Name und Slug ab zwei Zeichen, ohne Suchbegriff die ersten Schulen nach
 * Name -- jeweils höchstens TenantPicker::SEARCH_LIMIT Treffer. Ein einzelnes
 * Zeichen liefert nichts (zu viele, zu ungenaue Treffer).
 */
class TenantSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $search = is_string($request->query('q')) ? trim($request->query('q')) : '';
        if (mb_strlen($search) === 1) {
            return response()->json(['data' => []]);
        }
        $like = '%'.addcslashes(mb_substr($search, 0, 100), '\\%_').'%';

        $tenants = Tenant::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'ilike', $like)->orWhere('slug', 'ilike', $like)))
            ->orderByRaw('lower(name)')->orderBy('id')
            ->limit(TenantPicker::SEARCH_LIMIT)
            ->get(['id', 'name', 'slug', 'status']);

        return response()->json([
            'data' => $tenants->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'status_label' => StatusLabels::tenantStatus((string) $tenant->status),
            ])->values(),
        ]);
    }
}
