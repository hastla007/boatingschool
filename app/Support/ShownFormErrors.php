<?php

namespace App\Support;

/**
 * Bug-Review (Fehlermeldungen erscheinen doppelt): layouts/app.blade.php
 * zeigte oben eine globale Fehlerbox mit $errors->all(), unabhängig
 * davon, ob eine Meldung bereits direkt neben ihrem Feld angezeigt wird
 * (z. B. <x-input-error> bei Adresse/Coupon-Zuweisung). Damit die
 * globale Box nur noch Meldungen zeigt, die sonst nirgends auf der
 * Seite auftauchen, meldet jede feldspezifische Fehleranzeige ihre
 * Meldungen hier an.
 *
 * Die Registrierung hängt bewusst am aktuellen Request (statt an einer
 * statischen Klassen-Eigenschaft), weil sowohl echte Requests als auch
 * Laravels HTTP-Testhelfer ($this->get()/$this->post()) für jeden
 * Aufruf ein frisches Request-Objekt binden -- der Zustand damit
 * automatisch request-gebunden bleibt, ohne einen manuellen Reset an
 * einer zentralen Stelle (Middleware o. Ä.) zu brauchen.
 */
class ShownFormErrors
{
    private const ATTRIBUTE_KEY = 'shown_form_errors';

    public static function register(iterable $messages): void
    {
        $request = request();

        if (! $request) {
            return;
        }

        $shown = $request->attributes->get(self::ATTRIBUTE_KEY, []);

        foreach ($messages as $message) {
            $shown[$message] = true;
        }

        $request->attributes->set(self::ATTRIBUTE_KEY, $shown);
    }

    public static function wasShown(string $message): bool
    {
        $request = request();

        if (! $request) {
            return false;
        }

        return isset($request->attributes->get(self::ATTRIBUTE_KEY, [])[$message]);
    }
}
