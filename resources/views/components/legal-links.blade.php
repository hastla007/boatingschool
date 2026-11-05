{{-- Links auf Impressum und Datenschutzerklärung der Bootsschule (siehe
     LegalPageController); im Footer und auf der Anmeldeseite. --}}
<p {{ $attributes->merge(['class' => 'flex items-center justify-center gap-2']) }}>
    <a href="{{ route('legal.show', 'impressum') }}" class="underline hover:no-underline">Impressum</a>
    <span aria-hidden="true">·</span>
    <a href="{{ route('legal.show', 'datenschutz') }}" class="underline hover:no-underline">Datenschutz</a>
</p>
