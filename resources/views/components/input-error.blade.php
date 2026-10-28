@props(['messages'])

@if ($messages)
    {{-- Bug-Review (Fehlermeldungen erscheinen doppelt): meldet die hier
         angezeigten Meldungen an ShownFormErrors, damit die globale
         Fehlerbox in layouts/app.blade.php sie nicht noch einmal zeigt. --}}
    @php(\App\Support\ShownFormErrors::register((array) $messages))
    <ul {{ $attributes->merge(['class' => 'text-sm text-red-600 dark:text-red-400 space-y-1']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
