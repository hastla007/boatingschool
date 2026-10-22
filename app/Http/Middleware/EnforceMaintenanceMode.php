<?php

namespace App\Http\Middleware;

use App\Models\PlatformSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sicherheitsreview N9: platform_setting.maintenance_mode wurde bislang
 * gespeichert, aber nirgends durchgesetzt. Login/Logout und der gesamte
 * Superadmin-Bereich bleiben erreichbar, damit sich der Superadmin
 * überhaupt anmelden und den Modus wieder ausschalten kann.
 */
class EnforceMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExempt($request)) {
            return $next($request);
        }

        // Einfaches first() statt PlatformSetting::current(): die App-Rolle
        // hat seit Sicherheitsreview M7 nur noch SELECT auf platform_setting
        // -- firstOrCreate() würde dort crashen, solange noch kein Superadmin
        // die Einstellungen einmal gespeichert hat.
        $settings = PlatformSetting::query()->first();

        if ($settings && $settings->maintenance_mode) {
            // Bug-Review B16: site_name/support_email wurden gespeichert,
            // aber nie irgendwo angezeigt -- die Wartungsseite ist der
            // einzige Ort, an dem ein Lerner/Bootsschul-Admin je mit
            // plattformweiten (nicht mandantenbezogenen) Texten in Kontakt
            // kommt, und passt thematisch zur bereits dort gezeigten
            // maintenance_message.
            return response()->view('maintenance', [
                'message' => $settings->maintenance_message,
                'siteName' => $settings->site_name ?: config('app.name'),
                'supportEmail' => $settings->support_email,
            ], 503);
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        return $request->user()?->is_superadmin
            || $request->is('superadmin')
            || $request->is('superadmin/*')
            || $request->is('login')
            || $request->is('logout');
    }
}
