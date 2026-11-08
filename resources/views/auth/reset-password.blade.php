<x-guest-layout>
    <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-6">{{ $request->boolean('invite') ? 'Willkommen! Lege dein Passwort fest' : 'Neues Passwort vergeben' }}</h2>

    @if ($linkInvalid ?? false)
        <div role="alert" class="mb-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 dark:bg-amber-900/30 dark:border-amber-800 dark:text-amber-100 px-4 py-3 text-sm">
            Dieser Link ist abgelaufen oder wurde schon benutzt.
            {{ $request->boolean('invite') ? 'Hast du dein Passwort schon festgelegt, melde dich einfach an. Sonst fordere über „Passwort vergessen“ einen neuen Link an oder bitte deine Bootsschule um eine neue Einladung.' : 'Fordere über „Passwort vergessen“ einen neuen Link an.' }}
            <div class="mt-2 flex flex-wrap gap-4">
                <a href="{{ route('login') }}" class="underline font-medium">Anmelden</a>
                <a href="{{ route('password.request') }}" class="underline font-medium">Passwort vergessen</a>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('password.store') }}" class="space-y-4">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        @if ($request->boolean('invite'))
            <input type="hidden" name="invite" value="1">
        @endif

        <div>
            <x-input-label for="email" value="E-Mail-Adresse" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" value="Neues Passwort" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" value="Passwort bestätigen" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <button type="submit" class="w-full px-4 py-2.5 rounded-lg font-semibold text-white transition hover:opacity-90" style="background-color: var(--brand-primary, #005FD7)">
            {{ $request->boolean('invite') ? 'Passwort festlegen' : 'Passwort zurücksetzen' }}
        </button>
    </form>
</x-guest-layout>
