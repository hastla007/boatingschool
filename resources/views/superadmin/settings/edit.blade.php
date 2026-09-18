<x-superadmin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Website-Einstellungen</h2>
    </x-slot>

    <div class="max-w-lg bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <form method="POST" action="{{ route('superadmin.settings.update') }}" class="space-y-5">
            @csrf
            @method('PATCH')

            <div>
                <x-input-label for="site_name" value="Name der Plattform" />
                <x-text-input id="site_name" name="site_name" type="text" class="mt-1 block w-full" :value="old('site_name', $settings->site_name)" required />
                <x-input-error :messages="$errors->get('site_name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="support_email" value="Zentrale Support-E-Mail" />
                <x-text-input id="support_email" name="support_email" type="email" class="mt-1 block w-full" :value="old('support_email', $settings->support_email)" />
                <x-input-error :messages="$errors->get('support_email')" class="mt-2" />
            </div>

            <div class="pt-2 border-t border-slate-100 dark:border-slate-700">
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="maintenance_mode" value="1" @checked(old('maintenance_mode', $settings->maintenance_mode)) class="rounded border-slate-300">
                    Wartungsmodus aktiv
                </label>
                <div class="mt-3">
                    <x-input-label for="maintenance_message" value="Wartungshinweis" />
                    <textarea id="maintenance_message" name="maintenance_message" rows="3"
                              class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">{{ old('maintenance_message', $settings->maintenance_message) }}</textarea>
                </div>
            </div>

            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                Speichern
            </button>
        </form>
    </div>
</x-superadmin-layout>
