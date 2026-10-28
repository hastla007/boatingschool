<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MediaAsset;
use App\Support\Countries;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class BrandingController extends Controller
{
    public function update(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();

        $whatsappEnabled = $request->boolean('whatsapp_enabled');

        $validated = $request->validate([
            'support_email' => ['nullable', 'email'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'contact_first_name' => ['nullable', 'string', 'max:255'],
            'contact_last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', Rule::in(Countries::OPTIONS)],
            // Sicherheitsreview N1: auf http/https eingeschränkt, sonst
            // ließe die bloße "url"-Regel auch javascript:/data:-Schemata durch.
            'website' => ['nullable', 'url:http,https', 'max:255'],
            // Kein SVG: eine SVG-Datei kann <script> enthalten und wird vom
            // Browser als HTML/XML statt als reines Bild interpretiert --
            // über die Logo-URL wäre das gespeichertes XSS im Ursprung der
            // Plattform (Sicherheitsreview H4). "image" lässt SVG in
            // Laravel 11 sonst durch, daher zusätzlich "mimes" auf eine
            // feste Positivliste einschränken.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            // Bug-Review Oberfläche U4: favicon_asset_id existierte in der
            // Tabelle bereits (siehe TenantBranding), hatte aber weder ein
            // Formularfeld noch eine Upload-Verarbeitung -- dasselbe
            // SVG-Verbot wie beim Logo (Sicherheitsreview H4) gilt auch hier.
            'favicon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512'],
            // Bug-Review Oberfläche U4: primary_color/secondary_color
            // existierten in der Tabelle bereits, wurden aber nie validiert
            // oder gespeichert -- das Formularfeld "Farben" hatte dadurch
            // keine Wirkung. HTML <input type="color"> liefert immer genau
            // dieses #rrggbb-Format.
            'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'exam_readiness_threshold_percent' => ['required', 'integer', 'min:0', 'max:100'],
            // Nullable statt required wie beim Prüfungs-Pendant: dieses
            // Feld kam nach exam_readiness_threshold_percent dazu, ältere
            // Formular-Submits ohne dieses Feld sollen weiterhin
            // funktionieren und den bisherigen Wert unverändert lassen.
            'willkommensbox_threshold_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'whatsapp_phone' => [
                $whatsappEnabled ? 'required' : 'nullable', 'string', 'max:20', 'regex:/^[1-9][0-9]{5,14}$/',
            ],
            'whatsapp_greeting' => ['nullable', 'string', 'max:500'],
        ], [
            'whatsapp_phone.regex' => 'Bitte die WhatsApp-Nummer im internationalen Format ohne Leerzeichen oder "+" eingeben, z. B. 491701234567.',
        ]);

        $validated['willkommensbox_threshold_percent'] = $validated['willkommensbox_threshold_percent'] ?? $tenant->branding->willkommensbox_threshold_percent;
        $validated['whatsapp_enabled'] = $whatsappEnabled;
        $validated['email_support_enabled'] = $request->boolean('email_support_enabled');
        $validated['phone_support_enabled'] = $request->boolean('phone_support_enabled');
        $validated['label_size_a4'] = $request->boolean('label_size_a4');
        $validated['label_size_a5'] = $request->boolean('label_size_a5');
        $validated['label_size_a6'] = $request->boolean('label_size_a6');

        $before = $tenant->branding->toArray();
        unset($validated['logo'], $validated['favicon']);

        $supportEmailChanged = $tenant->branding->support_email !== ($validated['support_email'] ?? null);
        if ($supportEmailChanged) {
            $validated['support_email_verified_at'] = null;
        }

        // DSGVO-Review D2: das bisherige Logo/Favicon (Datei +
        // media_asset-Zeile) wurde beim Ersetzen nie entfernt und sammelte
        // sich unbegrenzt an.
        $oldLogoAssetId = $tenant->branding->logo_asset_id;
        $oldFaviconAssetId = $tenant->branding->favicon_asset_id;

        if ($request->hasFile('logo')) {
            $asset = $this->storeUploadedImage($request->file('logo'), 'branding-logos', $tenant->id, 'branding-logo-'.$tenant->id);

            if (! $asset) {
                return back()->withErrors(['logo' => 'Diese Bilddatei konnte nicht gelesen werden.'])->withInput();
            }

            $validated['logo_asset_id'] = $asset->id;
        }

        // Bug-Review Oberfläche U4: favicon_asset_id existierte bereits in
        // der Tabelle, hatte aber weder ein Formularfeld noch eine
        // Upload-Verarbeitung -- das Favicon blieb dadurch für jede
        // Bootsschule leer.
        if ($request->hasFile('favicon')) {
            $asset = $this->storeUploadedImage($request->file('favicon'), 'branding-favicons', $tenant->id, 'branding-favicon-'.$tenant->id);

            if (! $asset) {
                return back()->withErrors(['favicon' => 'Diese Bilddatei konnte nicht gelesen werden.'])->withInput();
            }

            $validated['favicon_asset_id'] = $asset->id;
        }

        $emailChangedToVerify = $supportEmailChanged && ! empty($validated['support_email']);

        $tenant->branding->update($validated);

        if ($request->hasFile('logo') && $oldLogoAssetId && $oldLogoAssetId !== $validated['logo_asset_id']) {
            $this->deleteMediaAsset($oldLogoAssetId);
        }

        if ($request->hasFile('favicon') && $oldFaviconAssetId && $oldFaviconAssetId !== $validated['favicon_asset_id']) {
            $this->deleteMediaAsset($oldFaviconAssetId);
        }

        // Bug-Review F9: siehe SupportEmailVerificationController::
        // sendVerificationMail() -- ein SMTP-Fehler darf das bereits
        // erfolgreich gespeicherte Branding-Update nicht nachträglich als
        // HTTP 500 melden.
        $mailSent = $emailChangedToVerify
            ? SupportEmailVerificationController::sendVerificationMail($tenant->fresh())
            : true;

        AuditLog::record($tenant->id, $request->user()->id, 'branding.update', 'tenant_branding', $tenant->id, $before, $validated);

        $status = $mailSent
            ? 'Branding aktualisiert.'
            : 'Branding aktualisiert, die Bestätigungs-Mail für die Support-Adresse konnte aber nicht versendet werden -- bitte über "Bestätigungslink erneut senden" noch einmal versuchen.';

        return back()->with('status', $status);
    }

    /**
     * Statt eine hochgeladene Datei unverändert abzulegen, wird sie über GD
     * neu kodiert (Sicherheitsreview H4): GD liest nur die tatsächlichen
     * Bilddaten ein und erzeugt daraus ein komplett neues PNG -- ein als
     * Bild getarntes Polyglot (z. B. mit eingebettetem HTML/JS) übersteht
     * das nicht, auch wenn es die Mime-Validierung oben passiert hätte.
     * Gemeinsam genutzt von Logo- und Favicon-Upload (Bug-Review
     * Oberfläche U4).
     */
    private function storeUploadedImage($file, string $directory, string $tenantId, string $assetKeyPrefix): ?MediaAsset
    {
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));

        if ($image === false) {
            return null;
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        ob_start();
        imagepng($image);
        $pngData = ob_get_clean();
        imagedestroy($image);

        $path = $directory.'/'.$tenantId.'-'.Str::random(8).'.png';
        Storage::disk('public')->put($path, $pngData);

        return MediaAsset::create([
            'asset_key' => $assetKeyPrefix.'-'.Str::random(8),
            'media_type' => 'image',
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'rights_status' => 'licensed',
            'status' => 'published',
        ]);
    }

    private function deleteMediaAsset(string $assetId): void
    {
        $asset = MediaAsset::find($assetId);

        if (! $asset) {
            return;
        }

        // O2: storage_path liefert über den Model-Accessor jetzt die volle
        // URL -- für Storage::delete() wird der rohe, relative Pfad
        // gebraucht, wie er tatsächlich in der DB steht.
        Storage::disk('public')->delete($asset->getRawOriginal('storage_path'));

        $asset->delete();
    }
}
