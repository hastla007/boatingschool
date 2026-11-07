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
