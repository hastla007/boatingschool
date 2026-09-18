<x-superadmin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Neue Bootsschule anlegen</h2>
    </x-slot>

    <div class="max-w-lg bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <form method="POST" action="{{ route('superadmin.tenants.store') }}" class="space-y-5">
            @csrf

            <div>
                <x-input-label for="name" value="Name der Bootsschule" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="slug" value="Subdomain (Slug)" />
                <x-text-input id="slug" name="slug" type="text" class="mt-1 block w-full" :value="old('slug')" required placeholder="z. B. mueller" />
                <p class="text-xs text-slate-400 mt-1">Erreichbar unter &lt;slug&gt;.{{ config('app.central_domain') }}</p>
                <x-input-error :messages="$errors->get('slug')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="status" value="Status" />
                <select id="status" name="status" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <option value="trial" selected>trial</option>
                    <option value="active">active</option>
                    <option value="suspended">suspended</option>
                    <option value="closed">closed</option>
                </select>
            </div>

            <div class="pt-4 border-t border-slate-100 dark:border-slate-700">
                <p class="text-sm font-medium text-slate-600 dark:text-slate-300 mb-3">Erster Admin-Nutzer dieser Bootsschule</p>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="admin_first_name" value="Vorname" />
                        <x-text-input id="admin_first_name" name="admin_first_name" type="text" class="mt-1 block w-full" :value="old('admin_first_name')" required />
                        <x-input-error :messages="$errors->get('admin_first_name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="admin_last_name" value="Nachname" />
                        <x-text-input id="admin_last_name" name="admin_last_name" type="text" class="mt-1 block w-full" :value="old('admin_last_name')" required />
                        <x-input-error :messages="$errors->get('admin_last_name')" class="mt-2" />
                    </div>
                </div>
                <div class="mt-4">
                    <x-input-label for="admin_email" value="E-Mail" />
                    <x-text-input id="admin_email" name="admin_email" type="email" class="mt-1 block w-full" :value="old('admin_email')" required />
                    <p class="text-xs text-slate-400 mt-1">Erhält den Status "eingeladen" und kann sich per Passwort-Reset ein Passwort setzen.</p>
                    <x-input-error :messages="$errors->get('admin_email')" class="mt-2" />
                </div>
            </div>

            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                Bootsschule anlegen
            </button>
        </form>
    </div>
</x-superadmin-layout>
