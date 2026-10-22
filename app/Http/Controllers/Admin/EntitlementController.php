<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manuelle Freischaltung laut Umsetzungskonzept: Verlängerung, Sperrung und
 * Widerruf sind eigene, auditierte Aktionen. Lernfortschritt/Attempts werden
 * dabei nie gelöscht -- nur der Entitlement-Status ändert sich.
 */
class EntitlementController extends Controller
{
    public function store(Request $request, TenantContext $tenantContext, EntitlementService $entitlements): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
            'course_id' => ['required', 'uuid'],
            // Bug-Review B14 (Folgefehler): endOfValidityDay() normalisiert
            // nur auf das Ende des gewählten Tages -- ein tatsächlich in der
            // Vergangenheit liegendes Datum (nicht nur "heute") lag damit
            // immer noch vor valid_from (jetzt) und verletzte weiterhin den
            // DB-Check-Constraint (HTTP 500). after_or_equal:today lässt
            // "heute" bewusst zu (siehe endOfValidityDay), weist aber jedes
            // frühere Datum schon in der Validierung zurück.
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $user = User::findOrFail($validated['user_id']);
        $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->findOrFail($validated['course_id']);

        // Sicherheitsreview M3: ohne diese beiden Prüfungen ließ sich eine
        // beliebige User-UUID (z. B. aus einer anderen Bootsschule) als
        // Teilnehmer dieser Bootsschule freischalten, und ein sitewide oder
        // mandantenseitig deaktivierter Kurs trotzdem freischalten.
        $isMember = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists();
        abort_unless($isMember, 404, 'Dieser Nutzer gehört nicht zu eurer Bootsschule.');
        abort_unless($entitlements->isOfferable($tenant, $course), 404, 'Dieser Kurs ist für eure Bootsschule nicht verfügbar.');

        $validUntil = $this->endOfValidityDay($validated['valid_until'] ?? null);

        // Bug-Review B14: zwischen der Prüfung "noch nicht gebucht" und dem
        // eigentlichen INSERT lag kein Lock -- ein Doppelklick auf
        // "Freischalten" konnte zwei Entitlement-Zeilen für denselben Kurs
        // anlegen. Der Advisory-Lock serialisiert das; existiert bereits
        // eine Zeile für diese Tenant/Nutzer/Kurs-Kombination (egal welchen
        // Status, z. B. eine zuvor widerrufene), wird sie reaktiviert statt
        // dupliziert.
        return DB::transaction(function () use ($tenant, $user, $course, $validUntil, $request) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$tenant->id.':'.$user->id.':'.$course->id]);

            $entitlement = Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->first();
            $before = $entitlement?->toArray();

            $attributes = [
                'valid_from' => now(),
                'valid_until' => $validUntil,
                'status' => 'active',
                'source_type' => 'manual',
                'source_reference' => 'admin:'.$request->user()->id,
            ];

            if ($entitlement) {
                $entitlement->update($attributes);
            } else {
                $entitlement = Entitlement::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    ...$attributes,
                ]);
            }

            AuditLog::record($tenant->id, $request->user()->id, 'entitlement.create', 'entitlement', $entitlement->id, $before, $entitlement->fresh()->toArray());

            return back()->with('status', 'Kurs freigeschaltet.');
        });
    }

    public function update(Request $request, TenantContext $tenantContext, Entitlement $entitlement): Response
    {
        abort_unless($entitlement->tenant_id === $tenantContext->id(), 404);

        $validated = $request->validate([
            'action' => ['required', 'in:extend,suspend,revoke,reactivate'],
            // Bug-Review B14 (Folgefehler): siehe store() -- ohne diese
            // Grenze führte "Verlängern" mit einem vergangenen Datum
            // weiterhin zum CHECK-Constraint-Verstoß (HTTP 500).
            'valid_until' => ['required_if:action,extend', 'nullable', 'date', 'after_or_equal:today'],
        ]);

        $before = $entitlement->toArray();

        match ($validated['action']) {
            // Bug-Review B14: ein reines Datum ("gültig bis heute") wurde
            // bisher unverändert als valid_until gespeichert -- das
            // entspricht Mitternacht, also dem BEGINN des Tages, nicht
            // seinem Ende. Der Zugang endete damit schon am gewählten Tag
            // selbst statt erst danach, und ein "bis heute" konnte sogar vor
            // valid_from (jetzt) liegen und am CHECK-Constraint der
            // Datenbank scheitern (HTTP 500). endOfValidityDay() verschiebt
            // auf das Ende des gewählten Tages.
            'extend' => $entitlement->update(['valid_until' => $this->endOfValidityDay($validated['valid_until'])]),
            'suspend' => $entitlement->update(['status' => 'suspended']),
            'revoke' => $entitlement->update(['status' => 'revoked']),
            'reactivate' => $entitlement->update(['status' => 'active']),
        };

        AuditLog::record(
            $tenantContext->id(), $request->user()->id, 'entitlement.'.$validated['action'],
            'entitlement', $entitlement->id, $before, $entitlement->fresh()->toArray()
        );

        return back()->with('status', 'Entitlement aktualisiert.');
    }

    /**
     * Ein über ein Datumsfeld gewähltes "gültig bis" bezeichnet den letzten
     * Tag, an dem der Zugang noch bestehen soll -- gespeichert wird deshalb
     * das Ende dieses Tages (23:59:59), nicht sein Beginn (Mitternacht).
     * Siehe B14.
     */
    private function endOfValidityDay(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->endOfDay() : null;
    }
}
