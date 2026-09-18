<x-superadmin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Neuen Kurs anlegen</h2>
    </x-slot>

    <div class="max-w-lg bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6">
        <form method="POST" action="{{ route('superadmin.courses.store') }}" class="space-y-5">
            @csrf

            <div>
                <x-input-label for="code" value="Code" />
                <x-text-input id="code" name="code" type="text" class="mt-1 block w-full" :value="old('code')" required placeholder="z. B. SBF-SEE" />
                <x-input-error :messages="$errors->get('code')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="name" value="Name" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="course_type" value="Kurstyp" />
                <x-text-input id="course_type" name="course_type" type="text" class="mt-1 block w-full" :value="old('course_type', 'full')" required />
            </div>
            <div>
                <x-input-label for="status" value="Status" />
                <select id="status" name="status" class="mt-1 block w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
                    <option value="draft" selected>draft</option>
                    <option value="published">published</option>
                    <option value="archived">archived</option>
                </select>
            </div>
            <div class="pt-2 border-t border-slate-100 dark:border-slate-700">
                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="site_enabled" value="1" checked class="rounded border-slate-300">
                    Sitewide für alle Bootsschulen verfügbar
                </label>
                <p class="text-xs text-slate-400 mt-1">Deaktiviert kann keine Bootsschule diesen Kurs anbieten, auch nicht mit bereits vergebenem Zugang.</p>
            </div>

            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 transition" style="background-color: #005FD7">
                Kurs anlegen
            </button>
        </form>
    </div>
</x-superadmin-layout>
