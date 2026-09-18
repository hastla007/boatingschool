<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CourseDefinition;
use App\Models\CourseWebshopLink;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\ProductPurchase;
use App\Models\TenantCourseDisabled;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\View\View;

/**
 * Ein einziger Bootsschul-Admin-Bereich mit Tabs (Dashboard, Teilnehmer,
 * Branding, Webshop-Links, Kursauswahl, Gutschein-Codes) statt sechs
 * separater Seiten -- alle Daten werden hier gesammelt geladen, die Tabs
 * selbst sind reine Client-Umschaltung (siehe admin/dashboard.blade.php).
 * Die schreibenden Aktionen (Teilnehmer einladen, Branding/Webshop-Links/
 * Kursauswahl speichern, Kurs manuell freischalten) bleiben in ihren
 * jeweiligen Controllern, nur die Anzeige ist hier zusammengeführt.
 */
class AdminDashboardController extends Controller
{
    public function __invoke(TenantContext $tenantContext): View
    {
        $tenant = $tenantContext->tenant();

        $learnerIds = TenantUser::where('tenant_id', $tenant->id)->where('role', 'learner')->pluck('user_id');
        $learnerCount = $learnerIds->count();
        $activeEntitlements = Entitlement::where('tenant_id', $tenant->id)->where('status', 'active')->count();
        $recentActivity = AuditLog::where('tenant_id', $tenant->id)->latest('created_at')->take(10)->get();

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

        $disabledCourseIds = TenantCourseDisabled::where('tenant_id', $tenant->id)->pluck('course_id');

        $coupons = Coupon::with('course', 'product', 'redeemedBy')
            ->where('tenant_id', $tenant->id)
            ->orWhere('redeemed_tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->paginate(50);

        return view('admin.dashboard', [
            'tenant' => $tenant,
            'learnerCount' => $learnerCount,
            'activeEntitlements' => $activeEntitlements,
            'recentActivity' => $recentActivity,
            'weeklyActivityRate' => $weeklyActivityRate,
            'recentExamsCount' => $recentExams->count(),
            'examPassRate' => $examPassRate,
            'participants' => $participants,
            'courses' => $courses,
            'productPurchases' => $productPurchases,
            'branding' => $branding,
            'webshopLinks' => $webshopLinks,
            'disabledCourseIds' => $disabledCourseIds,
            'coupons' => $coupons,
        ]);
    }
}
