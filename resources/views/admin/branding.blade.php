<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Branding-Einstellungen</h2>
    </x-slot>

    <p class="text-slate-500 mb-6">Passen Sie das Erscheinungsbild Ihrer Bootsschule an.</p>

    <div class="max-w-lg bg-white dark:bg-slate-800 rounded-xl shadow-sm p-6">
        <form method="POST" action="{{ route('admin.branding.update') }}" class="space-y-4">
            @csrf
            @method('PATCH')

            <div>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Name</label>
                <input type="text" value="{{ $tenant->name }}" disabled class="w-full rounded-lg border-slate-200 bg-slate-50 dark:bg-slate-700 text-sm text-slate-400">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Domain</label>
                <input type="text" value="{{ $tenant->slug }}.{{ config('app.central_domain') }}" disabled class="w-full rounded-lg border-slate-200 bg-slate-50 dark:bg-slate-700 text-sm text-slate-400">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Primärfarbe</label>
                    <input type="color" name="primary_color" value="{{ old('primary_color', $branding->primary_color) }}" class="w-full h-10 rounded-lg border-slate-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Sekundärfarbe</label>
                    <input type="color" name="secondary_color" value="{{ old('secondary_color', $branding->secondary_color) }}" class="w-full h-10 rounded-lg border-slate-300">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Support E-Mail</label>
                <input type="email" name="support_email" value="{{ old('support_email', $branding->support_email) }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-1">Rechtlicher Name</label>
                <input type="text" name="legal_name" value="{{ old('legal_name', $branding->legal_name) }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 text-sm">
            </div>

            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium" style="background-color: var(--brand-primary, #005FD7)">
                Änderungen speichern
            </button>
        </form>
    </div>
</x-app-layout>
