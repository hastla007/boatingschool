<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">

        <title>@yield('title') – {{ config('app.name', 'Laravel') }}</title>

        {{--
            Bewusst vollständig eigenständig (kein @extends einer App-Layout-
            Komponente, kein $currentTenant/$branding): eine Fehlerseite muss
            auch dann noch sicher rendern, wenn genau das fehlgeschlagen ist,
            was eine normale Seite voraussetzt (DB down für 500, kein
            aufgelöster Mandant für eine beliebige 404-URL, abgelaufene
            Session für 419). Kein Branding-abhängiger Code, kein Risiko
            einer zweiten, kaputten Seite beim Rendern der Fehlerseite selbst.
        --}}
        <style>
            :root { --brand-primary: #005FD7; }
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
        </style>
    </head>
    <body>
        <div class="card">
            <p class="code">@yield('code')</p>
            <p class="message">@yield('message')</p>
            <p class="hint">@yield('hint')</p>
            <a class="back-link" href="{{ url('/') }}">Zur Startseite</a>
        </div>
    </body>
</html>
