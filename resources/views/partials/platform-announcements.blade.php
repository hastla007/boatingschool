{{-- Mitteilungen des Plattformbetreibers (Superadmin → Mitteilung an alle Schulen). --}}
@foreach ($platformAnnouncements as $announcement)
    <div role="status" class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 dark:bg-amber-900/30 dark:border-amber-800 px-4 py-3">
        <div class="flex items-start gap-2">
            <x-icon name="envelope" class="w-4 h-4 mt-0.5 shrink-0 text-amber-700 dark:text-amber-300" />
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-amber-800 dark:text-amber-300">Mitteilung von {{ \App\Models\PlatformSetting::siteName() }}</p>
                <p class="font-medium text-amber-900 dark:text-amber-100">{{ $announcement->title }}</p>
                <p class="text-sm text-amber-900 dark:text-amber-100 whitespace-pre-line">{{ $announcement->body }}</p>
                <p class="text-xs text-amber-800 dark:text-amber-300 mt-1">{{ $announcement->created_at->timezone('Europe/Berlin')->format('d.m.Y') }}</p>
            </div>
        </div>
    </div>
@endforeach
