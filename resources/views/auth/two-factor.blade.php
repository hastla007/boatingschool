{{-- Zwei-Faktor-Anmeldung einrichten bzw. abschalten (TwoFactorController). --}}
<x-guest-layout>
    <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-1">Zwei-Faktor-Anmeldung</h2>
    <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
        Zusätzlich zum Passwort fragt die Anmeldung nach einem Code aus einer Authenticator-App
        (z. B. Google Authenticator, Microsoft Authenticator, 1Password). Das schützt dein Konto, auch wenn jemand dein Passwort kennt.
    </p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    @if ($recoveryCodes)
        <div class="mb-4 p-3 rounded-lg bg-amber-50 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100 text-sm">
            <p class="font-semibold mb-1">Wiederherstellungscodes – jetzt sicher aufbewahren</p>
            <p class="mb-2">Jeder Code gilt einmal, falls das Handy fehlt. Sie werden nur jetzt angezeigt.</p>
            <ul class="grid grid-cols-2 gap-1 font-mono">
                @foreach ($recoveryCodes as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($enabled)
        <p class="text-sm mb-4"><span class="inline-block px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-xs font-semibold">aktiv</span> Deine Anmeldung ist mit einem zweiten Faktor geschützt.</p>
        <form method="POST" action="{{ route('two-factor.disable') }}" class="space-y-3">
            @csrf
            @method('DELETE')
            <div>
                <x-input-label for="password" value="Zum Ausschalten dein Passwort" />
                <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 text-sm">Zwei-Faktor-Anmeldung ausschalten</button>
        </form>
    @elseif ($pendingSecret)
        <ol class="list-decimal pl-5 text-sm text-slate-700 dark:text-slate-200 space-y-2 mb-4">
            <li>QR-Code mit der Authenticator-App scannen.</li>
            <li>Oder den Schlüssel von Hand eingeben: <span class="font-mono break-all">{{ trim(chunk_split($pendingSecret, 4, ' ')) }}</span></li>
            <li>Den angezeigten 6-stelligen Code unten eingeben.</li>
        </ol>
        <div class="flex justify-center mb-4 bg-white p-2 rounded-lg">{!! $qrSvg !!}</div>
        <form method="POST" action="{{ route('two-factor.confirm') }}" class="space-y-3">
            @csrf
            <div>
                <x-input-label for="code" value="Code aus der App" />
                <x-text-input id="code" class="block mt-1 w-full font-mono tracking-widest" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />
            </div>
            <button type="submit" class="w-full px-4 py-2.5 rounded-lg font-semibold text-white" style="background-color: var(--brand-primary, #005FD7)">Bestätigen und einschalten</button>
        </form>
    @else
        <form method="POST" action="{{ route('two-factor.enable') }}" class="space-y-3">
            @csrf
            <div>
                <x-input-label for="enable-password" value="Zum Einrichten dein Passwort" />
                <x-text-input id="enable-password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>
            <button type="submit" class="w-full px-4 py-2.5 rounded-lg font-semibold text-white" style="background-color: var(--brand-primary, #005FD7)">Zwei-Faktor-Anmeldung einrichten</button>
        </form>
    @endif

    <p class="mt-6 text-sm"><a href="{{ url('/') }}" class="underline text-slate-500 dark:text-slate-400">Zurück</a></p>
</x-guest-layout>
