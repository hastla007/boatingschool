{{-- Gerätesimulation Funk, Stufe 1 (Beta): Icom IC-M505 mit Lektionen.
     Gerät und Lektionsablauf laufen im Browser (resources/js/funkgeraet),
     gesprochene Funksprüche bewertet der Server. --}}
<x-app-layout>
    <x-slot name="header">
        @include('funk._header', ['title' => 'Gerätesimulation', 'icon' => 'signal'])
    </x-slot>

    <div class="space-y-4">
        <x-funk-info>
            <span class="inline-block rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 text-xs font-semibold px-2 py-0.5 mr-1">Beta</span>
            Übe am Funkgerät {{ $config['device'] }}: Einschalten, Kanäle, DSC-Menü, Notalarm und Funksprüche. Wähle eine Lektion
            und eine Stufe &ndash; <strong>Mit Hilfe</strong> zeigt dir die Taste, <strong>Nur Hinweis</strong> nur den Text,
            <strong>Allein</strong> nichts. Die Sprechtaste hältst du mit der Maus oder der Leertaste; sprich auf Englisch.
            Ohne Mikrofon kannst du den Funkspruch auch eintippen.
        </x-funk-info>

        <div data-geraetesimulation class="min-h-[28rem]">
            <noscript>Die Gerätesimulation braucht JavaScript.</noscript>
        </div>
        <script type="application/json" data-geraetesimulation-config>@json($config)</script>
    </div>

    @vite(['resources/js/funkgeraet.js'])
</x-app-layout>
