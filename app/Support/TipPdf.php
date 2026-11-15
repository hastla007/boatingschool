<?php

namespace App\Support;

use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\Tip;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * PDF-Anhänge der „Tipps & Tricks“ (tip.pdf_asset_id, Rechte „licensed“).
 * Tipps gelten plattformweit (ohne Schul- und Kursbezug), die PDFs sind
 * aber Lernmaterial für Mitglieder: Sie liegen auf der privaten Disk und
 * werden nur über kurzlebige, an Person und Schule gebundene Adressen
 * ausgeliefert (ProtectedMedia, TipPdfController). Zugang: aktive
 * Mitgliedschaft in einer laufenden Schule und ein aktiver Tipp;
 * Superadmin immer.
 */
class TipPdf
{
    public const DIRECTORY = 'tip-attachments';

    /** Gültigkeit einer Adresse (wie bei den Fragenbildern). */
    public const URL_MINUTES = 120;

    /** Adresse für die Ausgabe (Seite oder API); ohne PDF oder Person: null. */
    public static function url(?Tip $tip, ?User $user = null, ?Tenant $tenant = null): ?string
    {
        $asset = $tip?->pdfAsset;
        if (! $asset) {
            return null;
        }
        $raw = (string) $asset->getRawOriginal('storage_path');
        if (preg_match('#^https?://#i', $raw)) {
            // Alte absolute URLs (fremder Host) unverändert, siehe MediaAsset::storagePath().
            return $asset->storage_path;
        }

        return ProtectedMedia::signedUrl('tip-pdf.file', self::URL_MINUTES, ['tip' => $tip->id], $user, $tenant);
    }

    /** Darf die Person das PDF dieses Tipps sehen? Erwartet den Mandantenkontext der Schule. */
    public static function allows(User $user, ?Tenant $tenant, Tip $tip): bool
    {
        // Superadmin auch bei deaktivierten Tipps (Vorschau im Editor).
        return ProtectedMedia::gate($user, $tenant, fn () => (bool) $tip->active);
    }

    /** Hochgeladenes PDF privat speichern und als media_asset anlegen. */
    public static function storeUpload(UploadedFile $file): MediaAsset
    {
        $path = $file->storeAs(self::DIRECTORY, Str::uuid().'.pdf', ProtectedMedia::DISK);
        if ($path === false) {
            throw new RuntimeException('PDF konnte nicht gespeichert werden.');
        }

        return MediaAsset::create([
            'asset_key' => 'tip-pdf-'.Str::random(12),
            'media_type' => 'document',
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'rights_status' => 'licensed',
            'status' => 'published',
        ]);
    }

    /**
     * Tipp-PDFs, deren Datei noch auf der public-Disk liegt (also anonym
     * unter /storage/... abrufbar ist) -- für den Go-live-Check.
     *
     * @return Collection<int, string> relative Pfade
     */
    public static function filesOnPublicDisk(): Collection
    {
        $public = Storage::disk(ProtectedMedia::PUBLIC_DISK);

        return MediaAsset::on('pgsql_admin')
            ->where(fn ($q) => $q
                ->whereExists(fn ($s) => $s->selectRaw('1')->from('tip')->whereColumn('tip.pdf_asset_id', 'media_asset.id'))
                ->orWhere('asset_key', 'like', 'tip-pdf-%'))
            // Was auch als Logo/Favicon dient, bleibt öffentlich (wie in der Migration).
            ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('tenant_branding')
                ->where(fn ($w) => $w->whereColumn('tenant_branding.logo_asset_id', 'media_asset.id')->orWhereColumn('tenant_branding.favicon_asset_id', 'media_asset.id')))
            ->get()
            ->map(fn (MediaAsset $asset) => ProtectedMedia::relativePath($asset->getRawOriginal('storage_path')))
            ->filter(fn (?string $path) => $path !== null && $public->exists($path))
            ->unique()->values();
    }
}
