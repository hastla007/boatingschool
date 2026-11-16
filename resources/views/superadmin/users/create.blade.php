<x-superadmin-layout>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Neuen Nutzer anlegen</h1>
    </x-slot>

    <div class="max-w-lg bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <form method="POST" action="{{ route('superadmin.users.store') }}" class="space-y-5">
            @csrf

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="first_name" value="Vorname" />
                    <x-text-input :invalid="$errors->has('first_name')" id="first_name" name="first_name" type="text" class="mt-1 block w-full" :value="old('first_name')" required />
                    <x-input-error for="first_name" :messages="$errors->get('first_name')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="last_name" value="Nachname" />
                    <x-text-input :invalid="$errors->has('last_name')" id="last_name" name="last_name" type="text" class="mt-1 block w-full" :value="old('last_name')" required />
                    <x-input-error for="last_name" :messages="$errors->get('last_name')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="email" value="E-Mail" />
                <x-text-input :invalid="$errors->has('email')" id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required />
                <x-input-error for="email" :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="pt-4 border-t border-slate-100 dark:border-slate-700">
                <p class="text-sm font-medium text-slate-600 dark:text-slate-300 mb-3">Optional: direkt einer Bootsschule zuordnen</p>
                <div class="grid grid-cols-2 gap-4">
                    {{-- Suchfeld statt Liste aller Schulen; leer = keine Zuordnung. --}}
                    <x-superadmin.tenant-picker name="tenant_id" hint="Leer lassen: keiner Bootsschule zuordnen." />
                    <div>
                        <x-input-label for="role" value="Rolle" />
                        <select id="role" name="role" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                            {{-- Bug-Review (englische Optionen in Superadmin-Dropdowns): angezeigt wird das deutsche Label (StatusLabels), übermittelt weiterhin der rohe Wert. --}}
                            @foreach (\App\Support\StatusLabels::tenantRoleOptions() as $role => $label)
                                <option value="{{ $role }}" @selected(old('role') === $role)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                Nutzer anlegen
            </button>
        </form>
    </div>
</x-superadmin-layout>
