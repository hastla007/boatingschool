<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\GermanTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CouponController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $coupons = $this->filteredCoupons($filters)
            ->with('course', 'product', 'optionProducts', 'tenant', 'redeemedBy', 'redeemedTenant')
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('superadmin.coupons.index', [
            'coupons' => $coupons,
            'courses' => CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->orderBy('name')->get(),
            'products' => Product::where('active', true)->orderBy('name')->get(),
            'tenants' => Tenant::orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'target' => ['required', 'string'],
            'option_product_ids' => ['nullable', 'array'],
            'option_product_ids.*' => ['uuid', 'exists:product,id'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenant,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'batch_label' => ['nullable', 'string', 'max:255'],
        ]);

        [$type, $targetId] = array_pad(explode(':', $validated['target'], 2), 2, null);

        if ($type === 'course' || $type === 'renewal') {
            if (! $targetId || ! CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->whereKey($targetId)->exists()) {
                throw ValidationException::withMessages(['target' => 'Ungültiger Kurs.']);
            }
        } elseif ($type === 'product') {
            if (! $targetId || ! Product::where('active', true)->whereKey($targetId)->exists()) {
                throw ValidationException::withMessages(['target' => 'Ungültiges Produkt.']);
            }
        } else {
            throw ValidationException::withMessages(['target' => 'Bitte einen Kurs, ein Produkt oder einen Verlängerungscode wählen.']);
        }

        // Optionen (z. B. "Lernplattform inkl. Willkommensbox" + "Kleine
        // Praxiseinheit") können nur einem Kurs-Code mitgegeben werden, nicht
        // einem Produkt- oder Verlängerungscode.
        $optionProductIds = $type === 'course' ? array_values($validated['option_product_ids'] ?? []) : [];

        $quantity = $validated['quantity'];
        // Bug-Review B30: das Label soll die deutsche Ortszeit zeigen, nicht
        // die intern genutzte UTC-Zeit.
        $batchLabel = ($validated['batch_label'] ?? null) ?: ($quantity > 1 ? 'Batch vom '.GermanTime::format(now(), 'd.m.Y H:i') : null);

        $codes = [];
        for ($i = 0; $i < $quantity; $i++) {
            $coupon = Coupon::create([
                'code' => Coupon::generateUniqueCode(),
                'course_id' => $type === 'product' ? null : $targetId,
                'product_id' => $type === 'product' ? $targetId : null,
                'is_renewal' => $type === 'renewal',
                'tenant_id' => $validated['tenant_id'] ?? null,
                'batch_label' => $batchLabel,
                'created_by_user_id' => $request->user()->id,
            ]);

            if (! empty($optionProductIds)) {
                $coupon->optionProducts()->attach($optionProductIds);
            }

            $codes[] = $coupon->code;
        }

        // Sicherheitsreview S4: die Erzeugung von Coupon-Codes (potenziell
        // kostenlose Freischaltungen, bis zu 500 pro Aufruf) wurde bisher
        // gar nicht protokolliert -- ein einziger Eintrag pro Batch statt
        // einem je Code, da ein Batch bis zu 500 Zeilen erzeugen könnte.
        //
        // Bug-Review (Codes im Klartext im Log): die erzeugten Codes
        // selbst standen hier bisher unverschlüsselt im after_data-JSON --
        // ist tenant_id gesetzt, erscheint dieser Eintrag über
        // AdminDashboardController auch im "Letzte Aktivitäten"-Feed GENAU
        // dieser Bootsschule. Die Codes stehen bereits unveränderlich in
        // der coupon-Tabelle (abrufbar über diese Seite selbst); der
        // Audit-Log-Eintrag braucht sie nicht zusätzlich, ungeschützt
        // duplizieren.
        AuditLog::record($validated['tenant_id'] ?? null, $request->user()->id, 'superadmin.coupon.create', 'coupon', null, null, [
            'target' => $validated['target'],
            'quantity' => $quantity,
            'batch_label' => $batchLabel,
        ]);

        $message = $quantity === 1 ? 'Coupon-Code erzeugt.' : "{$quantity} Coupon-Codes erzeugt.";

        return back()->with('status', $message)->with('generatedCodes', $codes);
    }

    /**
     * Codes als .txt herunterladen -- entweder die per Checkbox einzeln
     * ausgewählten (ids[]) oder, bei "Alle auswählen", der komplette
     * aktuell gefilterte/gesuchte Bestand über alle Seiten hinweg (dieselben
     * Filter wie index(), da sie als Hidden-Felder mitgesendet werden).
     */
    public function export(Request $request): Response
    {
        $validated = $request->validate([
            'select_all' => ['nullable', 'boolean'],
            // Bug-Review B26: required_if:select_all,0 griff nur, wenn
            // select_all tatsächlich den Wert "0" trug -- bei einem
            // Request ganz ohne select_all (z. B. manuell manipuliert)
            // bestand die Regel nicht, $validated['ids'] existierte dann
            // nicht, und whereIn('id', null) schlug mit einem
            // ungefangenen TypeError (HTTP 500) fehl. required_unless
            // greift dagegen immer außer bei select_all=1.
            'ids' => ['required_unless:select_all,1', 'array'],
            'ids.*' => ['uuid'],
            'tenant_id' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
        ]);

        if ($request->boolean('select_all')) {
            $filters = $this->filters($request);
            $codes = $this->filteredCoupons($filters)->orderByDesc('created_at')->pluck('code');
        } else {
            $codes = Coupon::whereIn('id', $validated['ids'])->orderByDesc('created_at')->pluck('code');
        }

        // Bug-Review (Superadmin-Audit-Lücken): der Export von Coupon-Codes
        // als Klartext-Download wurde bisher gar nicht protokolliert --
        // anders als store() (Sicherheitsreview S4) wird hier bewusst NUR
        // die Anzahl vermerkt, nicht die Codes selbst (die stehen bereits
        // unveränderlich in der coupon-Tabelle; ein Audit-Log-Eintrag soll
        // kein zweiter, ungeschützter Fundort für Klartext-Codes werden).
        AuditLog::record($validated['tenant_id'] ?? null, $request->user()->id, 'superadmin.coupon.export', 'coupon', null, null, [
            'count' => $codes->count(),
            'select_all' => $request->boolean('select_all'),
            'filters' => $this->filters($request),
        ]);

        $filename = 'coupon-codes-'.now()->format('Y-m-d_His').'.txt';

        return response($codes->implode("\n"), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Bug-Review (Array-Werte in der Adresszeile): nur Zeichenketten
     * übernehmen -- ?search[]=x landete sonst als Array im Suchfeld der
     * Ansicht (htmlspecialchars(), HTTP 500).
     *
     * @return array{tenant_id?: string, status?: string, search?: string}
     */
    private function filters(Request $request): array
    {
        return array_filter($request->only(['tenant_id', 'status', 'search']), 'is_string');
    }

    /** @param  array{tenant_id?: string, status?: string, search?: string}  $filters */
    private function filteredCoupons(array $filters): Builder
    {
        $term = trim($filters['search'] ?? '');

        // Bug-Review (Query-Parameter-Validierung): tenant_id kam ungeprüft
        // aus der Query-String direkt in ein where('tenant_id', ...) gegen
        // die uuid-Spalte coupon.tenant_id -- ein manipulierter, nicht-UUID-
        // förmiger Wert ließ Postgres mit "invalid input syntax for type
        // uuid" scheitern (HTTP 500) statt den Filter zu ignorieren.
        $tenantIdFilter = is_string($filters['tenant_id'] ?? null) && Str::isUuid($filters['tenant_id']) ? $filters['tenant_id'] : null;

        return Coupon::when($tenantIdFilter, fn ($q, $tenantId) => $q->where('tenant_id', $tenantId))
            ->when(($filters['status'] ?? null) === 'redeemed', fn ($q) => $q->whereNotNull('redeemed_at'))
            ->when(($filters['status'] ?? null) === 'open', fn ($q) => $q->whereNull('redeemed_at'))
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.$term.'%';
                $q->where(function ($q2) use ($like) {
                    $q2->where('code', 'ilike', $like)
                        ->orWhereHas('course', fn ($q3) => $q3->where('name', 'ilike', $like))
                        ->orWhereHas('product', fn ($q3) => $q3->where('name', 'ilike', $like))
                        ->orWhereHas('optionProducts', fn ($q3) => $q3->where('name', 'ilike', $like))
                        ->orWhereHas('tenant', fn ($q3) => $q3->where('name', 'ilike', $like))
                        ->orWhereHas('redeemedBy', fn ($q3) => $q3->where('display_name', 'ilike', $like));
                });
            });
    }
}
