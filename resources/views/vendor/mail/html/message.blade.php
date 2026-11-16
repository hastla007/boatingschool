{{-- Veröffentlicht aus Laravel (Illuminate/Mail): Kopf und Fußzeile mit dem Namen der Schule bzw. der Plattform (wie Unterschrift und Absendername, MailSchool/PlatformSetting::siteName()) statt APP_NAME. --}}
@php($mailSiteName = \App\Support\MailSchool::current()?->name ?? \App\Models\PlatformSetting::siteName())
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ $mailSiteName }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $mailSiteName }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
