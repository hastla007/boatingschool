@extends('errors::minimal')

@section('title', 'Eingabe nicht möglich')
@section('code', '422')
@section('message', 'Eingabe nicht möglich')
{{-- Review-Runde 13: eigene, deutsche Begründung aus abort(422, 'Text') (z. B. „Bitte zuerst die Musterantwort anzeigen.“) statt Symfonys englischer Seite. --}}
@php
    $reason = isset($exception) ? trim((string) $exception->getMessage()) : '';
    $reason = $reason !== '' && preg_match('/[äöüßÄÖÜ]|\\b(der|die|das|du|bitte|nicht|ist|eine|antwort)\\b/iu', $reason) ? $reason : null;
@endphp
@section('hint', $reason ?? 'Diese Eingabe ließ sich nicht verarbeiten. Bitte lade die Seite neu und versuche es erneut.')
