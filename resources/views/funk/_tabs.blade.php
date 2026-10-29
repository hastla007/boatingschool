{{-- Segment-Reiter für das Funk-Training. $tabs: [Label => URL], $active: aktives Label. --}}
@if (count($tabs) > 1)
    <div class="flex rounded-xl bg-slate-100 dark:bg-slate-800 p-1 gap-1" role="tablist">
        @foreach ($tabs as $label => $url)
            <a href="{{ $url }}" role="tab" aria-selected="{{ $label === $active ? 'true' : 'false' }}"
               class="flex-1 text-center rounded-lg py-2 text-sm transition {{ $label === $active ? 'bg-white dark:bg-slate-700 shadow-sm font-semibold text-slate-800 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>
@endif
