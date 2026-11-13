<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\ProductPurchase;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Meine Produkte“: Zusatzleistungen (Fahrstunden, Praxiseinheiten,
 * Willkommensbox …), die der Lerner per Coupon-Code eingelöst oder von
 * seiner Bootsschule bekommen hat -- je Eintrag mit dem nächsten Schritt.
 * Nach dem Einlösen eines Produkt-Codes landet der Lerner hier statt im
 * Profil, damit er sieht, was er bekommen hat und wie es weitergeht.
 */
class ProductsController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        $purchases = ProductPurchase::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->with('product')->orderByDesc('created_at')->get();

        // Gebündelte Zusatzprodukte (z. B. Praxiseinheit zu einem Kurscode):
        // der eingelöste Code nennt den Kurs, zu dem „Praxis & Prüfung“ gehört.
        $codes = $purchases->where('source_type', 'coupon')->pluck('source_reference')->filter()->unique()->values();
        $courseIdByCode = $codes->isEmpty() ? collect() : Coupon::whereIn('code', $codes)->whereNotNull('course_id')->pluck('course_id', 'code');
        // Nur Kurse mit aktuell gültigem Zugang verlinken.
        $courses = $entitlements->activeCourses($tenant, $user)->keyBy('id');

        $items = $purchases->map(function (ProductPurchase $purchase) use ($courseIdByCode, $courses) {
            $code = $purchase->product?->code ?? '';
            // Ohne Kurs am Code: der einzige aktive Kurs, sonst kein direkter Link.
            $course = $courses->get($courseIdByCode->get($purchase->source_reference ?? ''))
                ?? ($courses->count() === 1 ? $courses->first() : null);

            return [
                'name' => $purchase->product->name ?? '—',
                'date' => $purchase->created_at,
                'source' => $purchase->source_type === 'coupon'
                    ? 'Eingelöst mit Code '.$purchase->source_reference
                    : 'Von deiner Bootsschule gebucht',
                ...$this->nextStep($code, $course),
            ];
        });

        return view('products.index', ['items' => $items]);
    }

    /** @return array{next: string, link: ?string, link_label: ?string} */
    private function nextStep(string $productCode, ?CourseDefinition $course): array
    {
        if (str_starts_with($productCode, 'PRAXISEINHEIT')) {
            return $course
                ? ['next' => 'Deine Praxistermine stimmst du über „Praxis & Prüfung“ in deinem Kurs ab. Bei Fragen meldet sich deine Bootsschule bei dir.', 'link' => route('praxis-pruefung.index', $course), 'link_label' => 'Zu Praxis & Prüfung']
                : ['next' => 'Deine Bootsschule meldet sich bei dir, um die Praxistermine mit dir abzustimmen.', 'link' => null, 'link_label' => null];
        }

        return match ($productCode) {
            'LERNPLATTFORM-WB' => ['next' => 'Deine Willkommensbox kannst du bestellen, sobald du im Kurs weit genug bist.', 'link' => route('willkommensbox.index'), 'link_label' => 'Zur Willkommensbox'],
            'LERNPLATTFORM' => ['next' => 'Dein Zugang zur Lernplattform ist freigeschaltet – du kannst direkt loslegen.', 'link' => route('dashboard'), 'link_label' => 'Zum Lernen'],
            default => ['next' => 'Deine Bootsschule meldet sich bei dir, um einen Termin mit dir zu vereinbaren.', 'link' => null, 'link_label' => null],
        };
    }
}
