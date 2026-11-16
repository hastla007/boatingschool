@props(['disabled' => false, 'invalid' => false])

{{-- Barrierefreiheit (WCAG 3.3.1/1.3.1): :invalid="$errors->has('feld')" markiert
     das Feld als fehlerhaft und verknüpft es mit der Meldung von
     <x-input-error for="{id}">, damit Screenreader sie beim Fokus vorlesen. --}}
@php($errorAttributes = $invalid ? array_filter(['aria-invalid' => 'true', 'aria-describedby' => $attributes->get('id') ? $attributes->get('id').'-error' : null]) : [])
<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm'] + $errorAttributes) }}>
