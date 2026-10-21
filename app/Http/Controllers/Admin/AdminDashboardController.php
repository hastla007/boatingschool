<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AddonTemplate;
use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\CoursePromoBanner;
use App\Models\CourseWebshopLink;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\PraxisExamAppointment;
use App\Models\PraxisFormSubmission;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\Pruefungsausschuss;
use App\Models\TenantAddon;
use App\Models\TenantCourseDisabled;
use App\Models\TenantCourseLocation;
use App\Models\TenantUser;
use App\Services\CourseProgressService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ein einziger Bootsschul-Admin-Bereich mit Tabs (Dashboard, Teilnehmer,
 * Branding, Webshop-Links, Kursauswahl, Coupon-Codes) statt sechs
 * separater Seiten -- alle Daten werden hier gesammelt geladen, die Tabs
 * selbst sind reine Client-Umschaltung (siehe admin/dashboard.blade.php).
 * Die schreibenden Aktionen (Teilnehmer einladen, Branding/Webshop-Links/
 * Kursauswahl speichern, Kurs manuell freischalten, Coupon-Codes
 * importieren/zuweisen) bleiben in ihren jeweiligen Controllern, nur die
 * Anzeige ist hier zusammengeführt.
 */
class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenantContext, CourseProgressService $courseProgress): View
    {
        $tenant = $tenantContext->tenant();

        $learnerIds = TenantUser::where('tenant_id', $tenant->id)->where('role', 'learner')->pluck('user_id');
        $learnerCount = $learnerIds->count();
        $activeEntitlements = Entitlement::where('tenant_id', $tenant->id)->where('status', 'active')->count();
        $recentActivity = AuditLog::where('tenant_id', $tenant->id)->latest('created_at')->take(10)->get();

        // Info-Hinweis "Kürzlich gebuchte Kurse & Produkte" auf dem
        // Dashboard -- über alle Teilnehmer der Bootsschule hinweg, damit
        // neue Buchungen nicht untergehen (anders als die Benachrichtigungsbox
        // auf der Teilnehmer-Detailseite, die nur dort anstehende Aktionen
        // zeigt, nicht einfach jede Buchung).
        $recentBookingsCutoff = now()->subDays(7);
        $recentProductPurchases = ProductPurchase::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $recentBookingsCutoff)
            ->with(['product', 'user'])->get();

        // Optionen, die ein Kurs-Coupon zusätzlich bündelt (z. B. "Kleine
        // Praxiseinheit"), sollen hier wie auf der Teilnehmer-Detailseite
        // unter dem jeweiligen Kurs erscheinen statt als eigene Zeile
        // (siehe ParticipantController::show).
        $optionsByCode = $recentProductPurchases->where('source_type', 'coupon')->groupBy('source_reference');
        $bundledProductIds = [];

        $recentCourseBookings = Entitlement::where('tenant_id', $tenant->id)
            ->where('valid_from', '>=', $recentBookingsCutoff)
            ->with(['course', 'user'])->get()
            ->map(function (Entitlement $e) use ($optionsByCode, &$bundledProductIds) {
                $options = $e->source_type === 'coupon' ? ($optionsByCode->get($e->source_reference) ?? collect()) : collect();
                $bundledProductIds = [...$bundledProductIds, ...$options->pluck('id')->all()];

                return [
                    'user' => $e->user->name ?? '—',
                    'label' => $e->course->name ?? '—',
                    'date' => $e->valid_from,
                    'options' => $options->map(fn (ProductPurchase $p) => $p->product->name ?? '—')->values(),
                ];
            });

        $recentProductBookings = $recentProductPurchases
            ->reject(fn (ProductPurchase $p) => in_array($p->id, $bundledProductIds, true))
            ->map(fn (ProductPurchase $p) => [
                'user' => $p->user->name ?? '—',
                'label' => $p->product->name ?? '—',
                'date' => $p->created_at,
                'options' => collect(),
            ]);

        $recentBookings = $recentCourseBookings->concat($recentProductBookings)->sortByDesc('date')->values();

        $activeThisWeek = $learnerCount > 0
            ? Attempt::where('tenant_id', $tenant->id)
                ->whereIn('user_id', $learnerIds)
                ->where('created_at', '>=', now()->subDays(7))
                ->distinct('user_id')
                ->count('user_id')
            : 0;
        $weeklyActivityRate = $learnerCount > 0 ? (int) round($activeThisWeek / $learnerCount * 100) : 0;

        $recentExams = ExamSession::where('tenant_id', $tenant->id)
            ->where('status', 'evaluated')
            ->where('created_at', '>=', now()->subDays(30))
            ->get();
        $examPassRate = $recentExams->isNotEmpty()
            ? (int) round($recentExams->where('passed', true)->count() / $recentExams->count() * 100)
            : 0;
        $recentExamsNext7DaysCount = $recentExams->where('created_at', '>=', now()->subDays(7))->count();

        // Anstehende Termine (Prüfungstermin + alle frei angelegten
        // Praxistermine, siehe PraxisExamAppointment) -- nur bereits vom
        // Teilnehmer angenommene zählen hier, ein noch offener Vorschlag
        // ist schließlich noch nicht fix verabredet.
        $now = now();
        $upcomingAppointments = PraxisExamAppointment::where('tenant_id', $tenant->id)
            ->where('response', 'accepted')
            ->where('exam_date', '>=', $now)
            ->where('exam_date', '<=', $now->copy()->addDays(30))
            ->get(['exam_date']);
        $upcomingAppointmentsNext7Days = $upcomingAppointments->where('exam_date', '<=', $now->copy()->addDays(7))->count();
        $upcomingAppointmentsNext30Days = $upcomingAppointments->count();

        // Info-Chart "Teilnehmer je Phase" -- der jeweils am weitesten
        // fortgeschrittene Status je Lerner über alle seine Kurse/
        // Anmeldungen hinweg, nicht je Kurs einzeln.
        $activeEntitlementUserIds = Entitlement::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereIn('user_id', $learnerIds)
            ->pluck('user_id')
            ->unique();
        $submissionsByUser = PraxisFormSubmission::where('tenant_id', $tenant->id)
            ->whereIn('user_id', $learnerIds)
            ->get(['user_id', 'confirmed_exam_date'])
            ->groupBy('user_id');
        $passedExamUserIds = ExamSession::where('tenant_id', $tenant->id)
            ->where('status', 'evaluated')
            ->where('passed', true)
            ->whereIn('user_id', $learnerIds)
            ->pluck('user_id')
            ->unique();

        $phaseCounts = [
            'Geprüft' => 0,
            'Prüfungstermin fixiert' => 0,
            'Praxis angemeldet' => 0,
            'Lernen' => 0,
            'Nicht gestartet' => 0,
        ];
        $learningPhaseLearnerIds = [];
        foreach ($learnerIds as $learnerId) {
            if ($passedExamUserIds->contains($learnerId)) {
                $phaseCounts['Geprüft']++;
            } elseif ($submissionsByUser->get($learnerId)?->contains(fn ($s) => $s->confirmed_exam_date !== null)) {
                $phaseCounts['Prüfungstermin fixiert']++;
            } elseif ($submissionsByUser->get($learnerId)?->isNotEmpty()) {
                $phaseCounts['Praxis angemeldet']++;
            } elseif ($activeEntitlementUserIds->contains($learnerId)) {
                $phaseCounts['Lernen']++;
                $learningPhaseLearnerIds[] = $learnerId;
            } else {
                $phaseCounts['Nicht gestartet']++;
            }
        }
        // Die Tailwind-Klassen je Phase stehen bewusst in dashboard.blade.php
        // (nicht hier) -- Tailwinds Build-Scan durchsucht nur
        // resources/views/**/*.blade.php nach Klassennamen, ein hier als
        // PHP-String zusammengesetzter Klassenname würde beim Build
        // stillschweigend wegfallen (ungenutzt aussehen) und im Browser
        // unsichtbar bleiben.
        $learnerPhases = collect($phaseCounts)->map(fn ($count, $label) => [
            'label' => $label,
            'count' => $count,
            'percent' => $learnerCount > 0 ? round($count / $learnerCount * 100) : 0,
        ])->values();

        // Zweites Chart, drillt in die Phase "Lernen" hinein: wie viele
        // dieser Lerner stehen bei welchem Kursfortschritt (in 20%-
        // Schritten)? Bei mehreren aktiven Kurszugängen zählt der am
        // weitesten fortgeschrittene (derselbe Fortschrittswert wie auf
        // der Kursübersicht, siehe CourseProgressService::overallPercent).
        $learningEntitlementsByUser = Entitlement::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereIn('user_id', $learningPhaseLearnerIds)
            ->with(['course', 'user'])
            ->get()
            ->groupBy('user_id');
        $progressBuckets = ['0–20%' => 0, '21–40%' => 0, '41–60%' => 0, '61–80%' => 0, '81–100%' => 0];
        foreach ($learningPhaseLearnerIds as $learnerId) {
            $entitlementsForLearner = $learningEntitlementsByUser->get($learnerId) ?? collect();
            $user = $entitlementsForLearner->first()?->user;
            if (! $user) {
                continue;
            }
            $percent = $entitlementsForLearner
                ->filter(fn (Entitlement $e) => $e->course !== null)
                ->map(fn (Entitlement $e) => $courseProgress->overallPercent($e->course, $tenant, $user))
                ->max() ?? 0;
            $bucket = match (true) {
                $percent <= 20 => '0–20%',
                $percent <= 40 => '21–40%',
                $percent <= 60 => '41–60%',
                $percent <= 80 => '61–80%',
                default => '81–100%',
            };
            $progressBuckets[$bucket]++;
        }
        $learningPhaseLearnerCount = count($learningPhaseLearnerIds);
        $progressDistribution = collect($progressBuckets)->map(fn ($count, $label) => [
            'label' => $label,
            'count' => $count,
            'percent' => $learningPhaseLearnerCount > 0 ? round($count / $learningPhaseLearnerCount * 100) : 0,
        ])->values();

        $participants = TenantUser::with(['user.entitlements' => function ($q) use ($tenant) {
            $q->where('tenant_id', $tenant->id)->with('course');
        }])
            ->where('tenant_id', $tenant->id)
            ->where('role', 'learner')
            ->get();

        // Sitewide deaktivierte Kurse (course_definition.site_enabled = false)
        // tauchen konsequent in keinem Tab auf -- weder zum manuellen
        // Freischalten noch für Webshop-Links noch in der Kursauswahl selbst.
        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('site_enabled', true)
            ->orderBy('name')->get();

        $productPurchases = ProductPurchase::where('tenant_id', $tenant->id)->with('product')->get()
            ->groupBy('user_id');

        $branding = $tenant->branding;

        $webshopLinks = CourseWebshopLink::where('tenant_id', $tenant->id)->pluck('url', 'course_id');

        $addonTemplates = AddonTemplate::where('active', true)->orderBy('sort_order')->orderBy('title')->get();
        $tenantAddons = TenantAddon::where('tenant_id', $tenant->id)->get()->keyBy('addon_template_id');

        $coursePromoBanners = CoursePromoBanner::where('tenant_id', $tenant->id)->orderBy('created_at')->get();

        $praxisFormSubmissions = PraxisFormSubmission::where('tenant_id', $tenant->id)
            ->with(['user', 'course', 'template'])
            ->orderByDesc('created_at')
            ->get();

        $tenantCourseLocations = TenantCourseLocation::where('tenant_id', $tenant->id)->with('pruefungsausschuss')->orderBy('sort_order')->get();
        $pruefungsausschuesse = Pruefungsausschuss::orderBy('sort_order')->get();

        $disabledCourseIds = TenantCourseDisabled::where('tenant_id', $tenant->id)->pluck('course_id');

        $couponFilters = $request->only(['coupon_search', 'coupon_status', 'coupon_type']);

        $coupons = Coupon::with('course', 'product', 'redeemedBy')
            ->where(fn ($q) => $q->where('tenant_id', $tenant->id)->orWhere('redeemed_tenant_id', $tenant->id))
            ->when(trim((string) ($couponFilters['coupon_search'] ?? '')) !== '', function ($q) use ($couponFilters) {
                $term = '%'.trim($couponFilters['coupon_search']).'%';
                $q->where(function ($q2) use ($term) {
                    $q2->where('code', 'ilike', $term)
                        ->orWhereHas('course', fn ($q3) => $q3->where('name', 'ilike', $term))
                        ->orWhereHas('product', fn ($q3) => $q3->where('name', 'ilike', $term))
                        ->orWhereHas('redeemedBy', fn ($q3) => $q3->where('display_name', 'ilike', $term));
                });
            })
            ->when(($couponFilters['coupon_status'] ?? null) === 'open', fn ($q) => $q->whereNull('redeemed_at'))
            ->when(($couponFilters['coupon_status'] ?? null) === 'redeemed', fn ($q) => $q->whereNotNull('redeemed_at'))
            ->when(($couponFilters['coupon_type'] ?? null) === 'course', fn ($q) => $q->whereNotNull('course_id'))
            ->when(($couponFilters['coupon_type'] ?? null) === 'product', fn ($q) => $q->whereNotNull('product_id'))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        // Bestand je Kurs/Produkt aus den Codes, die dieser Bootsschule
        // gehören (übernommen per Import oder vom Superadmin zugeteilt) --
        // Codes, die nur eingelöst, aber keiner Bootsschule zugeordnet
        // wurden, zählen hier bewusst nicht mit (das ist kein Bestand, den
        // sie verwaltet).
        $couponSummary = Coupon::where('tenant_id', $tenant->id)
            ->selectRaw('course_id, product_id, count(*) filter (where redeemed_at is null) as free_count, count(*) filter (where redeemed_at is not null) as used_count')
            ->groupBy('course_id', 'product_id')
            ->get()
            ->map(function ($row) {
                $course = $row->course_id ? CourseDefinition::withoutGlobalScopes()->find($row->course_id) : null;
                $product = $row->product_id ? Product::find($row->product_id) : null;

                return (object) [
                    'name' => $course->name ?? $product->name ?? '—',
                    'free' => (int) $row->free_count,
                    'used' => (int) $row->used_count,
                ];
            })
            ->sortBy('name')
            ->values();

        // Für den "Kurs freischalten"-Dropdown im Teilnehmer-Tab: wie viele
        // eigene, noch nicht eingelöste Codes die Bootsschule je Kurs hat.
        $freeCourseCouponCounts = Coupon::where('tenant_id', $tenant->id)
            ->whereNotNull('course_id')
            ->whereNull('redeemed_at')
            ->selectRaw('course_id, count(*) as free_count')
            ->groupBy('course_id')
            ->pluck('free_count', 'course_id');

        return view('admin.dashboard', [
            'tenant' => $tenant,
            'learnerCount' => $learnerCount,
            'activeEntitlements' => $activeEntitlements,
            'recentActivity' => $recentActivity,
            'recentBookings' => $recentBookings,
            'weeklyActivityRate' => $weeklyActivityRate,
            'recentExamsCount' => $recentExams->count(),
            'recentExamsNext7DaysCount' => $recentExamsNext7DaysCount,
            'examPassRate' => $examPassRate,
            'upcomingAppointmentsNext7Days' => $upcomingAppointmentsNext7Days,
            'upcomingAppointmentsNext30Days' => $upcomingAppointmentsNext30Days,
            'learnerPhases' => $learnerPhases,
            'progressDistribution' => $progressDistribution,
            'learningPhaseLearnerCount' => $learningPhaseLearnerCount,
            'participants' => $participants,
            'courses' => $courses,
            'productPurchases' => $productPurchases,
            'branding' => $branding,
            'webshopLinks' => $webshopLinks,
            'addonTemplates' => $addonTemplates,
            'tenantAddons' => $tenantAddons,
            'coursePromoBanners' => $coursePromoBanners,
            'praxisFormSubmissions' => $praxisFormSubmissions,
            'tenantCourseLocations' => $tenantCourseLocations,
            'pruefungsausschuesse' => $pruefungsausschuesse,
            'disabledCourseIds' => $disabledCourseIds,
            'coupons' => $coupons,
            'couponSummary' => $couponSummary,
            'couponFilters' => $couponFilters,
            'freeCourseCouponCounts' => $freeCourseCouponCounts,
        ]);
    }
}
