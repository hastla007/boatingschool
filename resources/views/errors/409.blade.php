@extends('errors::minimal')

@section('title', 'Aktion nicht möglich')
@section('code', '409')
@section('message', 'Aktion nicht möglich')
@section('hint', 'Diese Aktion ist in diesem Zustand nicht mehr möglich -- der Inhalt hat sich inzwischen geändert. Bitte lade die Seite neu und versuche es erneut.')
