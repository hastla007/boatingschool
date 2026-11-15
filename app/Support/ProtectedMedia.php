<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Gemeinsamer Unterbau für geschützte Dateien der privaten Disk, die nur
 * über kurzlebige, signierte Adressen ausgeliefert werden: Fragen- und
 * Praxistrainer-Bilder (QuestionMedia), Kartenausschnitte
 * (NavigationChart), Tipp-PDFs (TipPdf) und Bilder der Videoschritte
 * (VideoStepImage). Jede Art bringt ihre eigene Zugangsregel mit; hier
 * liegen Signatur (Person u, Schule t), Grundprüfung der Mitgliedschaft,
 * Pfadprüfung und die Antwort-Header.
 *
 * Adressen werden nur bei der Ausgabe erzeugt und nie zwischengespeichert.
 */
class ProtectedMedia
{
    public const DISK = 'local';

    public const PUBLIC_DISK = 'public';

    /** Bilder, Audio/Video und Anhänge: direkt aufgerufen wird nichts ausgeführt. */
    public const SANDBOX_CSP = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    /**
     * Inline-PDFs ohne „sandbox“: Chrome lädt seinen PDF-Betrachter in einem
     * sandboxed Dokument nicht (Plugins sind dort gesperrt) und prüft ihn
     * gegen object-src der PDF-Antwort selbst -- daher object-src 'self'.
     * default-src 'none' erlaubt weiterhin kein Skript, keine Verbindungen
     * und keine Nachladeressourcen der Origin; frame-ancestors 'self' lässt
     * das Einbetten nur auf der eigenen Seite zu (kein Clickjacking von
     * fremden Seiten). Firefox (pdf.js) zeigt das PDF damit ebenfalls inline.
     */
    public const PDF_CSP = "default-src 'none'; object-src 'self'; frame-ancestors 'self'";

    /**
     * Signierte Adresse für die angemeldete Person im aktuellen
     * Schulkontext (bzw. die übergebenen). Ohne Person: null.
     *
     * @param  array<string, string>  $parameters
     */
    public static function signedUrl(string $route, int $minutes, array $parameters, ?User $user = null, ?Tenant $tenant = null): ?string
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return null;
        }
        $tenant ??= app(TenantContext::class)->tenant();

        return URL::temporarySignedRoute($route, now()->addMinutes($minutes), array_filter($parameters + [
            'u' => $user->id,
            't' => $tenant?->id,
        ]));
    }

    /**
     * Grundprüfung aller Arten: nur aktive Personen; Superadmin immer; sonst
     * aktive Mitgliedschaft in einer laufenden Schule und die Zugangsregel
     * der Art ($inTenant, läuft im gesetzten Mandantenkontext).
     *
     * @param  Closure(): bool  $inTenant
     */
    public static function gate(User $user, ?Tenant $tenant, Closure $inTenant): bool
    {
        if ($user->status !== 'active') {
            return false;
        }
        if ($user->is_superadmin) {
            return true;
        }
        if (! $tenant || ! in_array($tenant->status, ['active', 'trial'], true)) {
            return false;
        }
        $member = TenantUser::on('pgsql_admin')->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'active')->exists();

        return $member && $inTenant();
    }

    /**
     * Abruf über die signierte Adresse prüfen: Person (u) und Schule (t)
     * laden, Mandantenkontext setzen und die Zugangsregel erneut anwenden.
     * Ohne Zugang 404 (keine Auskunft, ob es die Datei gibt).
     *
     * @param  Closure(User, ?Tenant): bool  $allows
     */
    public static function authorizeRequest(Request $request, Closure $allows): void
    {
        $context = app(TenantContext::class);
        $user = is_string($request->query('u')) ? User::on('pgsql_admin')->find($request->query('u')) : null;
        $tenantId = $request->query('t');
        $tenant = is_string($tenantId) ? Tenant::on('pgsql_admin')->find($tenantId) : null;
        abort_unless($user, 404);
        abort_if(is_string($tenantId) && ! $tenant, 404);

        if ($tenant) {
            $context->set($tenant);
        }
        try {
            abort_unless($allows($user, $tenant), 404);
        } finally {
            $context->clear();
        }
    }

    /** Relativer Pfad in der Disk; null bei leeren Werten, absoluten Fremd-URLs und Pfad-Traversierung. */
    public static function relativePath(?string $raw): ?string
    {
        $raw = (string) $raw;
        if ($raw === '' || preg_match('#^https?://#i', $raw)) {
            return null;
        }

        return self::safePath($raw);
    }

    /** Pfad ohne Traversierung und ohne führenden Schrägstrich (sonst null). */
    public static function safePath(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $path;
    }

    /**
     * Gespeicherte Datei: privat, übergangsweise auch noch auf der
     * public-Disk (Altbestand vor der Migration, meldet der Go-live-Check).
     *
     * @return array{0: string, 1: string}|null [Disk, absoluter Pfad]
     */
    public static function locate(?string $path): ?array
    {
        $path = $path === null ? null : self::safePath($path);
        if ($path === null) {
            return null;
        }
        foreach ([self::DISK, self::PUBLIC_DISK] as $disk) {
            $root = realpath(Storage::disk($disk)->path(''));
            $file = realpath(Storage::disk($disk)->path($path));
            // Keine Pfad-Traversierung (auch nicht über Symlinks): die Datei muss unterhalb der Disk liegen.
            if ($root && $file && is_file($file) && str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
                return [$disk, $file];
            }
        }

        return null;
    }

    /** Datei auf beiden Disks löschen. */
    public static function delete(?string $path): void
    {
        $path = self::relativePath($path);
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
            Storage::disk(self::PUBLIC_DISK)->delete($path);
        }
    }

    /**
     * Antwort für eine geprüfte Datei: nur privat zwischenspeichern, kein
     * MIME-Sniffing, direkt aufgerufen nichts ausführen (CSP sandbox; bei
     * Inline-PDFs PDF_CSP). Erlaubte Typen inline, alles andere als Anhang.
     *
     * @param  list<string>  $inlineTypes  Regex-Muster der MIME-Typen, die inline angezeigt werden
     */
    public static function response(string $file, ?string $mime, array $inlineTypes, ?string $downloadName = null): BinaryFileResponse
    {
        $mime = $mime && preg_match('#^[a-z]+/[A-Za-z0-9.+-]+$#', $mime) ? $mime : (mime_content_type($file) ?: 'application/octet-stream');
        $inline = collect($inlineTypes)->contains(fn (string $pattern) => preg_match($pattern, $mime) === 1);
        if (! $inline) {
            $mime = 'application/octet-stream';
        }

        $disposition = $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT;
        if ($downloadName !== null && $downloadName !== '') {
            $fallback = Str::ascii($downloadName);
            $fallback = (string) preg_replace('/[^A-Za-z0-9._ -]+/', '', $fallback);
            $disposition = HeaderUtils::makeDisposition($disposition, $downloadName, trim($fallback) !== '' ? $fallback : 'datei');
        }

        // public: false -- sonst setzt BinaryFileResponse „Cache-Control: public“.
        return new BinaryFileResponse($file, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => $disposition,
            'Content-Security-Policy' => $inline && $mime === 'application/pdf' ? self::PDF_CSP : self::SANDBOX_CSP,
        ], false);
    }

    /** Dateiname für den Download aus einem Titel (z. B. „Knoten üben.pdf“). */
    public static function fileName(?string $title, string $extension, string $fallback = 'datei'): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\"%]+/u', ' ', html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $name = Str::limit((string) preg_replace('/\s+/u', ' ', $name), 120, '');

        return ($name !== '' ? $name : $fallback).'.'.$extension;
    }
}
