{{-- Segment-Reiter für das Funk-Training. $tabs: [Label => URL], $active: aktives Label.
     Barrierefreiheit (WCAG 4.1.2): die Reiter sind Links auf eigene Seiten,
     kein Tab-Widget -- daher Navigation mit aria-current statt role="tab"
     (das ein Pfeiltasten-Verhalten und Tab-Bereiche verspräche). --}}
@if (count($tabs) > 1)
    <nav class="flex rounded-xl bg-slate-100 dark:bg-slate-800 p-1 gap-1" aria-label="Bereiche des Funk-Trainings">
        @foreach ($tabs as $label => $url)
            <a href="{{ $url }}" @if ($label === $active) aria-current="page" @endif
               class="flex-1 text-center rounded-lg py-2 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 {{ $label === $active ? 'bg-white dark:bg-slate-700 shadow-sm font-semibold text-slate-800 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200' }}">
                {{ $label }}
            </a>
        @endforeach
    </nav>
@endif
