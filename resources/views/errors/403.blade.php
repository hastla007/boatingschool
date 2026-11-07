@extends('errors::minimal')

@section('title', 'Zugriff verweigert')
@section('code', '403')
@section('message', 'Zugriff verweigert')
{{-- Eigene, deutsche Begründung aus abort(..., 'Text') anzeigen (z. B. „Die Willkommensbox ist für dich aktuell nicht freigeschaltet.“); Laravels englische Standardtexte nicht. --}}
@php
    $reason = isset($exception) ? trim((string) $exception->getMessage()) : '';
    $reason = $reason !== '' && ! str_starts_with($reason, 'This action') && preg_match('/[äöüßÄÖÜ]|\\b(der|die|das|du|bitte|nicht|ist)\\b/iu', $reason) ? $reason : null;
@endphp
@section('hint', $reason ?? 'Du hast keine Berechtigung, diese Seite aufzurufen.')
{{-- Abgelaufener oder fehlender Kurszugang: Weg zurück zu den Kursen und zum Code-Einlösen. --}}
@if ($reason && str_contains($reason, 'Zugang zu diesem Kurs') && auth()->check())
    @section('actions')
        <a href="{{ route('courses.index') }}" class="underline">Zu meinen Kursen</a>
        &middot;
        <a href="{{ route('coupons.redeem') }}" class="underline">Code einlösen</a>
    @endsection
@endif
