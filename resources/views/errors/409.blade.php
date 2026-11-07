@extends('errors::minimal')

@section('title', 'Aktion nicht möglich')
@section('code', '409')
@section('message', 'Aktion nicht möglich')
{{-- Eigene, deutsche Begründung aus abort(..., 'Text') anzeigen (z. B. „Die Willkommensbox ist für dich aktuell nicht freigeschaltet.“); Laravels englische Standardtexte nicht. --}}
@php
    $reason = isset($exception) ? trim((string) $exception->getMessage()) : '';
    $reason = $reason !== '' && ! str_starts_with($reason, 'This action') && preg_match('/[äöüßÄÖÜ]|\\b(der|die|das|du|bitte|nicht|ist)\\b/iu', $reason) ? $reason : null;
@endphp
@section('hint', $reason ?? 'Diese Aktion ist in diesem Zustand nicht mehr möglich -- der Inhalt hat sich inzwischen geändert. Bitte lade die Seite neu und versuche es erneut.')
