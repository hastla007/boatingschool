<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Neue Lerngruppe</h2>
    </x-slot>

    <a href="{{ route('admin.dashboard', ['tab' => 'learning-groups']) }}" class="text-sm text-slate-600 dark:text-slate-400 hover:underline">&larr; Zurück zu den Lerngruppen</a>

    <form method="POST" action="{{ route('admin.learning-groups.store') }}" class="mt-4 max-w-2xl bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 space-y-4"
          x-data="{ seats: {{ (int) old('seats', 0) }} }">
        @csrf
        <p class="text-sm text-slate-600 dark:text-slate-400">
            Der Verwalter lädt in der App bis zur Platzzahl ein; wer beitritt, erhält die gewählten Kurse bzw.
            Funktionen bis zum Ende der Gruppe.
        </p>
        <p class="text-sm text-slate-600 dark:text-slate-400">
            Abrechnung: Jeder Beitritt verbraucht je Kurs einen freien Kurscode aus Ihrem
            <a href="{{ route('admin.dashboard', ['tab' => 'coupons']) }}" class="underline hover:no-underline">Codebestand</a>
            (auch bei einzelnen Funktionen). Personen mit eigenem Kurszugang verbrauchen keinen Code. Die Platzzahl ist
            frei wählbar; ohne freien Code ist kein Beitritt möglich.
            Weitere Codes erhalten Sie im verknüpften Shop.
        </p>
        <div>
            <x-input-label for="name" value="Name" />
            <x-text-input id="name" name="name" type="text" maxlength="{{ \App\Services\LearningGroupService::MAX_NAME_LENGTH }}" class="mt-1 block w-full" :value="old('name')" required placeholder="z. B. Segelverein Musterstadt" />
            <x-input-error :messages="$errors->get('name')" class="mt-1" />
        </div>
        <div>
            <x-input-label for="owner_user_id" value="Verwalter (aktiver Teilnehmer)" />
            <select id="owner_user_id" name="owner_user_id" required class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <option value="">Bitte wählen</option>
                @foreach ($managers as $manager)
                    <option value="{{ $manager->id }}" @selected(old('owner_user_id') === $manager->id)>{{ $manager->name }} ({{ $manager->email }})</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('owner_user_id')" class="mt-1" />
            @if ($managers->isEmpty())
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Ihre Bootsschule hat noch keine aktiven Teilnehmer.</p>
            @endif
        </div>
        <div class="grid sm:grid-cols-2 gap-3">
            <div>
                <x-input-label for="seats" value="Plätze" />
                <x-text-input id="seats" name="seats" type="number" min="1" max="{{ \App\Services\LearningGroupService::MAX_SEATS }}" class="mt-1 block w-full" :value="old('seats')" required x-model.number="seats" />
                <x-input-error :messages="$errors->get('seats')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="valid_until" value="Gültig bis (leer = unbefristet)" />
                <input id="valid_until" name="valid_until" type="date" value="{{ old('valid_until') }}" min="{{ now('Europe/Berlin')->addDay()->toDateString() }}" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                <x-input-error :messages="$errors->get('valid_until')" class="mt-1" />
            </div>
        </div>
        <fieldset>
            <legend class="block font-medium text-sm text-slate-700 dark:text-slate-300">Kurse und Funktionsumfang</legend>
            <p class="text-xs text-slate-600 dark:text-slate-400 mb-2">Nur Kurse, die Ihre Bootsschule anbietet. „Vollzugang“ schaltet den ganzen Kurs frei, sonst nur die gewählte Funktion.</p>
            <div class="space-y-2">
                @forelse ($courses as $course)
                    @php($free = $freeCodes[$course->id] ?? 0)
                    <div class="flex flex-wrap items-center justify-between gap-2" x-data="{ selected: @js((string) old('courses.'.$course->code, '')) }">
                        <label for="course-{{ $course->code }}" class="text-sm text-slate-700 dark:text-slate-200">
                            {{ $course->name }}
                            <span class="block text-xs text-slate-500 dark:text-slate-400" data-free-codes="{{ $course->code }}">Freie Kurscodes: {{ $free }}</span>
                            <span class="block text-xs text-amber-700 dark:text-amber-400" style="display: none" x-show="selected && seats > {{ $free }}">Mehr Plätze als freie Kurscodes &ndash; nicht alle Plätze können beitreten.</span>
                        </label>
                        <select id="course-{{ $course->code }}" name="courses[{{ $course->code }}]" x-model="selected" class="rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                            <option value="">nicht enthalten</option>
                            @foreach (\App\Services\LearningGroupService::FEATURE_OPTIONS as $feature => $label)
                                <option value="{{ $feature }}" @selected(old('courses.'.$course->code) === $feature)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @empty
                    <p class="text-sm text-slate-600 dark:text-slate-400">Ihre Bootsschule bietet derzeit keine Kurse an.</p>
                @endforelse
            </div>
            <x-input-error :messages="$errors->get('courses')" class="mt-1" />
            <x-input-error :messages="collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'courses.'))->flatten()->unique()->all()" class="mt-1" />
        </fieldset>
        <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: var(--brand-primary, #005FD7)">Lerngruppe anlegen</button>
    </form>
</x-app-layout>
