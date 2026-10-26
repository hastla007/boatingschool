<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Session Driver
    |--------------------------------------------------------------------------
    |
    | This option determines the default session driver that is utilized for
    | incoming requests. Laravel supports a variety of storage options to
    | persist session data. Database storage is a great default choice.
    |
    | Supported: "file", "cookie", "database", "apc",
    |            "memcached", "redis", "dynamodb", "array"
    |
    */

    'driver' => env('SESSION_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime
    |--------------------------------------------------------------------------
    |
    | Here you may specify the number of minutes that you wish the session
    | to be allowed to remain idle before it expires. If you want them
    | to expire immediately when the browser is closed then you may
    | indicate that via the expire_on_close configuration option.
    |
    */

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),

    /*
    |--------------------------------------------------------------------------
    | Session Encryption
    |--------------------------------------------------------------------------
    |
    | This option allows you to easily specify that all of your session data
    | should be encrypted before it's stored. All encryption is performed
    | automatically by Laravel and you may use the session like normal.
    |
    */

    'encrypt' => env('SESSION_ENCRYPT', false),

    /*
    |--------------------------------------------------------------------------
    | Session File Location
    |--------------------------------------------------------------------------
    |
    | When utilizing the "file" session driver, the session files are placed
    | on disk. The default storage location is defined here; however, you
    | are free to provide another location where they should be stored.
    |
    */

    'files' => storage_path('framework/sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Database Connection
    |--------------------------------------------------------------------------
    |
    | When using the "database" or "redis" session drivers, you may specify a
    | connection that should be used to manage these sessions. This should
    | correspond to a connection in your database configuration options.
    |
    */

    'connection' => env('SESSION_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Session Database Table
    |--------------------------------------------------------------------------
    |
    | When using the "database" session driver, you may specify the table to
    | be used to store sessions. Of course, a sensible default is defined
    | for you; however, you're welcome to change this to another table.
    |
    */

    'table' => env('SESSION_TABLE', 'sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Cache Store
    |--------------------------------------------------------------------------
    |
    | When using one of the framework's cache driven session backends, you may
    | define the cache store which should be used to store the session data
    | between requests. This must match one of your defined cache stores.
    |
    | Affects: "apc", "dynamodb", "memcached", "redis"
    |
    */

    'store' => env('SESSION_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Session Sweeping Lottery
    |--------------------------------------------------------------------------
    |
    | Some session drivers must manually sweep their storage location to get
    | rid of old sessions from storage. Here are the chances that it will
    | happen on a given request. By default, the odds are 2 out of 100.
    |
    */

    'lottery' => [2, 100],

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Name
    |--------------------------------------------------------------------------
    |
    | Here you may change the name of the session cookie that is created by
    | the framework. Typically, you should not need to change this value
    | since doing so does not grant a meaningful security improvement.
    |
    */

    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_session'
    ),

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Path
    |--------------------------------------------------------------------------
    |
    | The session cookie path determines the path for which the cookie will
    | be regarded as available. Typically, this will be the root path of
    | your application, but you're free to change this when necessary.
    |
    */

    'path' => env('SESSION_PATH', '/'),

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Domain
    |--------------------------------------------------------------------------
    |
    | This value determines the domain and subdomains the session cookie is
    | available to. By default, the cookie will be available to the root
    | domain and all subdomains. Typically, this shouldn't be changed.
    |
    */

    // Bug-Review R3-1 (Folgefehler): ohne einen geteilten Domain-Scope
    // bindet der Browser das Session-Cookie an genau den Host, der es
    // gesetzt hat (Standardverhalten ohne explizites Domain-Attribut) --
    // ein Login auf schule-a.{CENTRAL_DOMAIN} setzt das Cookie also NUR
    // für schule-a.{CENTRAL_DOMAIN}, nicht für schule-b.{CENTRAL_DOMAIN}.
    // Ein bereits eingeloggter Nutzer, der einen Einladungs-Link für eine
    // ANDERE Bootsschule (andere Subdomain) öffnet, schickt dieses Cookie
    // deshalb nie mit und sieht sich selbst dort als nicht eingeloggt --
    // unabhängig vom app.current_user_id-RLS-Fix in ResolveTenant (R3-1),
    // der nur innerhalb EINES Session-Cookies wirkt. SESSION_DOMAIN auf
    // ".<CENTRAL_DOMAIN>" lässt den Browser das Cookie für die zentrale
    // Domain UND alle ihre Subdomains senden -- fachlich korrekt für diese
    // Plattform: ein app_user ist plattformweit eine einzige Identität,
    // die bei mehreren Bootsschulen Mitglied sein kann, die Session also
    // bewusst kein Pro-Tenant-Geheimnis (RLS/Mitgliedschaft entscheiden
    // ohnehin bei jedem Zugriff erneut, unabhängig vom Cookie-Scope).
    // Für localhost/127.0.0.1 (lokale Entwicklung, siehe
    // ResolveTenant::resolveViaDevOverride()) bleibt es beim bisherigen,
    // unveränderten Verhalten (kein Domain-Attribut) -- ein führender
    // Punkt vor "localhost" wird von Browsern ohnehin nicht zuverlässig
    // als Subdomain-Wildcard behandelt. SESSION_DOMAIN bleibt weiterhin
    // explizit überschreibbar -- siehe App\Support\SessionCookieDomain für
    // die (direkt testbare) Ableitungslogik: env() liefert für einen
    // EXPLIZIT gesetzten Wert (auch die leere Zeichenkette oder das zu
    // PHP-null konvertierte literale "null" in .env) immer diesen Wert
    // zurück, nie einen übergebenen Default -- die Ableitung muss deshalb
    // selbst sowohl "gar nicht gesetzt" als auch "explizit leer/null
    // gesetzt" gleich behandeln.
    'domain' => \App\Support\SessionCookieDomain::resolve(
        env('SESSION_DOMAIN'),
        env('CENTRAL_DOMAIN', 'localhost')
    ),

    /*
    |--------------------------------------------------------------------------
    | HTTPS Only Cookies
    |--------------------------------------------------------------------------
    |
    | By setting this option to true, session cookies will only be sent back
    | to the server if the browser has a HTTPS connection. This will keep
    | the cookie from being sent to you when it can't be done securely.
    |
    | Bug-Review M9 (Folgefehler): ohne gesetztes SESSION_SECURE_COOKIE fiel
    | das bisher auf null (automatisch, je nach aktuellem Request-Schema)
    | zurück -- eine produktive Umgebung, die diese .env-Variable vergisst,
    | bekam also KEINEN erzwungenen Secure-Flag. Der Code-Default erzwingt
    | ihn jetzt selbst, sobald APP_ENV=production, unabhängig davon, ob die
    | Variable je gesetzt wurde; env() gewinnt weiterhin, wenn sie explizit
    | gesetzt ist (z. B. um sie in einer Nicht-Produktivumgebung bewusst
    | abzuschalten). app()->environment() ist an dieser Stelle (Config-Load,
    | vor dem "env"-Container-Binding) noch nicht sicher aufrufbar --
    | deshalb ein reiner env('APP_ENV')-Vergleich statt dessen.
    |
    */

    'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Access Only
    |--------------------------------------------------------------------------
    |
    | Setting this value to true will prevent JavaScript from accessing the
    | value of the cookie and the cookie will only be accessible through
    | the HTTP protocol. It's unlikely you should disable this option.
    |
    */

    'http_only' => env('SESSION_HTTP_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Same-Site Cookies
    |--------------------------------------------------------------------------
    |
    | This option determines how your cookies behave when cross-site requests
    | take place, and can be used to mitigate CSRF attacks. By default, we
    | will set this value to "lax" to permit secure cross-site requests.
    |
    | See: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie#samesitesamesite-value
    |
    | Supported: "lax", "strict", "none", null
    |
    */

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    /*
    |--------------------------------------------------------------------------
    | Partitioned Cookies
    |--------------------------------------------------------------------------
    |
    | Setting this value to true will tie the cookie to the top-level site for
    | a cross-site context. Partitioned cookies are accepted by the browser
    | when flagged "secure" and the Same-Site attribute is set to "none".
    |
    */

    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),

];
