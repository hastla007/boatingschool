<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            Profildaten
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Aktualisiere deine Profildaten und deine E-Mail-Adresse.
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    @php
        $nameParts = explode(' ', $user->display_name ?? '', 2);
    @endphp

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <x-input-label for="first_name" value="Vorname" />
                <x-text-input id="first_name" name="first_name" type="text" class="mt-1 block w-full" :value="old('first_name', $user->first_name ?: ($nameParts[0] ?? ''))" required autofocus autocomplete="given-name" />
                <x-input-error class="mt-2" :messages="$errors->get('first_name')" />
            </div>
            <div>
                <x-input-label for="last_name" value="Nachname" />
                <x-text-input id="last_name" name="last_name" type="text" class="mt-1 block w-full" :value="old('last_name', $user->last_name ?: ($nameParts[1] ?? ''))" required autocomplete="family-name" />
                <x-input-error class="mt-2" :messages="$errors->get('last_name')" />
            </div>
        </div>

        <div>
            <x-input-label for="email" value="E-Mail-Adresse" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user->pending_email)
                <p class="text-sm mt-2 text-amber-600 dark:text-amber-400">
                    Neue Adresse <strong>{{ $user->pending_email }}</strong> wartet auf Bestätigung über den zugeschickten Link.
                </p>
            @endif

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="text-sm mt-2 text-gray-800 dark:text-gray-200">
                        Deine E-Mail-Adresse ist noch nicht bestätigt.

                        <button form="send-verification" class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800">
                            Bestätigungsmail erneut senden
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-green-600 dark:text-green-400">
                            Ein neuer Bestätigungslink wurde an deine E-Mail-Adresse gesendet.
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div>
            <x-input-label for="phone" value="Telefonnummer" />
            <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" :value="old('phone', $user->phone)" autocomplete="tel" />
            <x-input-error class="mt-2" :messages="$errors->get('phone')" />
        </div>

        <div>
            <x-input-label for="birthdate" value="Geburtsdatum" />
            <x-text-input id="birthdate" name="birthdate" type="date" class="mt-1 block w-full"
                          :value="old('birthdate', $membership->birthdate?->format('Y-m-d'))" autocomplete="bday" />
            <p class="text-xs text-gray-400 mt-1">Wird u. a. für die Anmeldung zu Praxis &amp; Prüfung benötigt.</p>
            <x-input-error class="mt-2" :messages="$errors->get('birthdate')" />
        </div>

        <div>
            <x-input-label value="Adresse" />
            <div class="grid sm:grid-cols-[2fr_1fr] gap-4 mt-1">
                <div>
                    <x-input-label for="street" value="Straße &amp; Hausnummer" class="sr-only" />
                    <x-text-input id="street" name="street" type="text" placeholder="Straße & Hausnummer" class="block w-full" :value="old('street', $membership->street)" autocomplete="street-address" />
                    <x-input-error class="mt-2" :messages="$errors->get('street')" />
                </div>
                <div>
                    <x-input-label for="postal_code" value="PLZ" class="sr-only" />
                    <x-text-input id="postal_code" name="postal_code" type="text" placeholder="PLZ" class="block w-full" :value="old('postal_code', $membership->postal_code)" autocomplete="postal-code" />
                    <x-input-error class="mt-2" :messages="$errors->get('postal_code')" />
                </div>
            </div>
            <div class="grid sm:grid-cols-2 gap-4 mt-2">
                <div>
                    <x-input-label for="city" value="Wohnort" class="sr-only" />
                    <x-text-input id="city" name="city" type="text" placeholder="Wohnort" class="block w-full" :value="old('city', $membership->city)" autocomplete="address-level2" />
                    <x-input-error class="mt-2" :messages="$errors->get('city')" />
                </div>
                <div>
                    <x-input-label for="country" value="Land" class="sr-only" />
                    <select id="country" name="country" class="block w-full border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm">
                        <option value="">Land wählen&hellip;</option>
                        @foreach (\App\Support\Countries::OPTIONS as $countryOption)
                            <option value="{{ $countryOption }}" @selected(old('country', $membership->country) === $countryOption)>{{ $countryOption }}</option>
                        @endforeach
                    </select>
                    <x-input-error class="mt-2" :messages="$errors->get('country')" />
                </div>
            </div>
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>Speichern</x-primary-button>
        </div>
    </form>
</section>
