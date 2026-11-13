<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">

        {{--
            Bewusst vollständig eigenständig (kein @extends einer App-Layout-
            Komponente): eine Fehlerseite muss auch dann noch sicher rendern,
            wenn genau das fehlgeschlagen ist, was eine normale Seite
            voraussetzt (DB down für 500, kein aufgelöster Mandant für eine
            beliebige 404-URL, abgelaufene Session für 419).

            Branding: war die Schule schon aufgelöst (abort() auf einer
            Schulseite), zeigt die Seite deren Namen, Farbe und Logo, sonst
            den Plattformnamen. Alles nur aus bereits geladenen Daten bzw. per
            rescue() -- bei Serverfehlern (5xx) gar keine Datenbankabfrage.
        --}}
        @php
            $errorCode = (int) $__env->yieldContent('code');
            $errorTenant = app()->bound('currentTenant') ? app('currentTenant') : null;
            $errorBranding = isset($branding) && $branding instanceof \App\Models\TenantBranding ? $branding : null;
            // Unbekannte Adresse unter CENTRAL_DOMAIN/<slug>: ohne Route lief
            // ResolveTenant nie -- die Schule aus dem Pfad nachschlagen.
            if (! $errorTenant && $errorCode < 500) {
                $errorTenant = rescue(function () {
                    // Der Router hält die Anfrage mit dem erkannten Pfad-Slug (ResolveTenantPath).
                    $slug = \App\Support\TenantPath::current(app('router')->getCurrentRequest() ?? request());

                    return $slug ? \App\Models\Tenant::where('slug', $slug)->where('status', 'active')->first() : null;
                }, null, false);
                $errorBranding ??= $errorTenant ? rescue(fn () => \App\Models\TenantBranding::on('pgsql_admin')->find($errorTenant->id), null, false) : null;
            }
            $errorSiteName = $errorTenant?->name
                ?? ($errorCode < 500 ? rescue(fn () => \App\Models\PlatformSetting::siteName(), null, false) : null)
                ?? config('app.name', 'Laravel');
            $errorColor = rescue(fn () => $errorBranding?->primaryColorOrDefault(), null, false) ?? '#005FD7';
            $errorLogo = $errorBranding?->logo_asset_id && ($errorCode < 500 || $errorBranding->relationLoaded('logoAsset'))
                ? rescue(fn () => $errorBranding->logoAsset?->storage_path, null, false)
                : null;
        @endphp
        <title>@yield('title') – {{ $errorSiteName }}</title>

        <style>
            :root { --brand-primary: {{ $errorColor }}; }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f8fafc;
                color: #1e293b;
                font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                padding: 1.5rem;
            }
            .card {
                max-width: 28rem;
                text-align: center;
            }
            .code {
                font-size: 3.5rem;
                font-weight: 700;
                color: var(--brand-primary);
                line-height: 1;
                margin: 0 0 0.5rem;
            }
            .message {
                font-size: 1.125rem;
                font-weight: 600;
                margin: 0 0 0.5rem;
            }
            .hint {
                color: #64748b;
                margin: 0 0 1.5rem;
            }
            .back-link {
                display: inline-block;
                padding: 0.625rem 1.25rem;
                border-radius: 0.5rem;
                background: var(--brand-primary);
                color: #fff;
                text-decoration: none;
                font-weight: 600;
            }
            .back-link:hover { opacity: 0.9; }
            .school {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
                margin: 0 0 1.5rem;
                font-weight: 700;
                color: #334155;
            }
            .school img { width: 2rem; height: 2rem; object-fit: contain; }
        </style>
    </head>
    <body>
        <div class="card">
            <p class="school">
                @if ($errorLogo)
                    <img src="{{ $errorLogo }}" alt="">
                @endif
                <span>{{ $errorSiteName }}</span>
            </p>
            <p class="code">@yield('code')</p>
            <p class="message">@yield('message')</p>
            <p class="hint">@yield('hint')</p>
            @hasSection('actions')
                <p class="hint">@yield('actions')</p>
            @endif
            <a class="back-link" href="{{ url('/') }}">Zur Startseite</a>
        </div>
    </body>
</html>
