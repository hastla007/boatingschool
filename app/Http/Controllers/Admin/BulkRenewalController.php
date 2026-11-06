<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Services\EntitlementRenewal;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kurszugänge in einem Schritt verlängern: Kurs und Zeitraum wählen,
 * betroffene Zugänge in der Vorschau an- oder abwählen, verlängern. Jede
 * Verlängerung verbraucht wie einzeln einen freien Verlängerungscode;
 * gehen die Codes aus, bleiben die übrigen Zugänge unverändert.
 */
class BulkRenewalController extends Controller
{
    public const WINDOWS = [14, 30, 60, 90];

    public function index(Request $request, TenantContext $tenantContext, EntitlementRenewal $renewal): View
    {
        $tenant = $tenantContext->tenant();
        $courses = $this->courses($tenantContext);
        $courseId = is_string($request->query('course_id')) && $courses->contains('id', $request->query('course_id')) ? $request->query('course_id') : $courses->first()?->id;
        $window = in_array((int) $request->query('window', 30), self::WINDOWS, true) ? (int) $request->query('window', 30) : 30;
        $includeExpired = $request->boolean('include_expired', true);

        return view('admin.renewals.index', [
            'courses' => $courses,
            'courseId' => $courseId,
            'window' => $window,
            'includeExpired' => $includeExpired,
            'entitlements' => $courseId ? $this->candidates($tenantContext->id(), $courseId, $window, $includeExpired) : collect(),
            'freeCodes' => $courseId ? $renewal->freeCodes($tenant, $courseId) : 0,
            'validityDays' => $tenant->branding->course_validity_days,
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext, EntitlementRenewal $renewal): RedirectResponse
    {
        $tenant = $tenantContext->tenant();
        $validated = $request->validate([
            'course_id' => ['required', 'uuid', Rule::in($this->courses($tenantContext)->pluck('id'))],
            'entitlement_ids' => ['required', 'array', 'max:500'],
            'entitlement_ids.*' => ['uuid'],
            'seen' => ['nullable', 'array'],
            'seen.*' => ['integer'],
        ], ['entitlement_ids.required' => 'Bitte mindestens einen Zugang auswählen.']);

        $entitlements = Entitlement::where('tenant_id', $tenant->id)->where('course_id', $validated['course_id'])
            ->whereIn('id', $validated['entitlement_ids'])->whereIn('status', ['active', 'expired'])->whereNotNull('valid_until')
            ->orderBy('valid_until')->get();

        // Laufzeitende aus der Vorschau: hat sich ein Zugang seitdem
        // geändert (Doppelklick, zweiter Admin, Einzel-Verlängerung), wird
        // er übersprungen statt ein zweites Mal verlängert.
        $seen = $validated['seen'] ?? [];
        $extended = 0;
        $changed = 0;
        $withoutCode = 0;
        foreach ($entitlements->values() as $index => $entitlement) {
            $result = DB::transaction(function () use ($entitlement, $tenant, $renewal, $request, $seen) {
                DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$tenant->id.':'.$entitlement->user_id.':'.$entitlement->course_id]);
                $entitlement->refresh();
                if (isset($seen[$entitlement->id]) && (int) $seen[$entitlement->id] !== $entitlement->valid_until?->timestamp) {
                    return 'changed';
                }
                $before = $entitlement->toArray();
                $error = $renewal->extend($entitlement, $tenant);
                if ($error === null) {
                    AuditLog::record($tenant->id, $request->user()->id, 'entitlement.extend', 'entitlement', $entitlement->id, $before, $entitlement->fresh()->toArray());
                }

                return $error;
            });
            if ($result === null) {
                $extended++;
            } elseif (str_contains($result, 'Verlängerungscode')) {
                $withoutCode = $entitlements->count() - $index;
                break;
            } else {
                $changed++;
            }
        }

        $status = $extended === 1 ? 'Ein Zugang verlängert.' : "{$extended} Zugänge verlängert.";
        if ($withoutCode > 0) {
            $status .= " {$withoutCode} nicht verlängert: keine freien Verlängerungscodes mehr.";
        }
        if ($changed > 0) {
            $status .= " {$changed} übersprungen: inzwischen schon verlängert oder geändert.";
        }

        return redirect()->route('admin.renewals.index', ['course_id' => $validated['course_id']])->with('status', $status);
    }

    /** Laufende Zugänge, die im Zeitraum enden, und (optional) in den letzten 90 Tagen abgelaufene. */
    private function candidates(string $tenantId, string $courseId, int $window, bool $includeExpired): Collection
    {
        return Entitlement::where('tenant_id', $tenantId)->where('course_id', $courseId)->whereNotNull('valid_until')
            ->where(function ($q) use ($window, $includeExpired) {
                $q->where(fn ($a) => $a->where('status', 'active')->whereBetween('valid_until', [now(), now()->addDays($window)]));
                if ($includeExpired) {
                    $q->orWhere(fn ($e) => $e->whereIn('status', ['active', 'expired'])->whereBetween('valid_until', [now()->subDays(90), now()]));
                }
            })
            ->with('user:id,display_name,email')->orderBy('valid_until')->get()->filter(fn ($e) => $e->user);
    }

    private function courses(TenantContext $tenantContext): Collection
    {
        $ids = Entitlement::where('tenant_id', $tenantContext->id())->whereNotNull('valid_until')->distinct()->pluck('course_id');

        return CourseDefinition::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }
}
