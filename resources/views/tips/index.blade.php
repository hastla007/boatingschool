<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 dark:text-slate-200">Tipps & Tricks</h2>
    </x-slot>

    @if ($tips->isEmpty())
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-6 text-center text-slate-500">
            Noch keine Tipps & Tricks verfügbar.
        </div>
    @else
        <div class="space-y-8">
            @foreach ($tips as $category => $categoryTips)
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-400 mb-3">{{ $category }}</h3>
                    <div class="grid sm:grid-cols-2 gap-5">
                        @foreach ($categoryTips as $tip)
                            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm p-5">
                                <div class="flex items-start gap-3">
                                    <x-icon name="light-bulb" class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
                                    <div class="flex-1 min-w-0">
                                        <h4 class="font-medium text-slate-800 dark:text-slate-200">{{ $tip->title }}</h4>
                                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 whitespace-pre-line">{{ $tip->body }}</p>
                                        @if ($tip->pdfAsset)
                                            <a href="{{ $tip->pdfAsset->storage_path }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 mt-3 text-sm text-blue-600 hover:underline">
                                                <x-icon name="download" class="w-4 h-4" /> PDF herunterladen
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-app-layout>
