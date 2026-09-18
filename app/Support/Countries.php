<?php

namespace App\Support;

/**
 * Feste Länderauswahl für Adressfelder (Nutzerprofil & Bootsschul-Stammdaten)
 * -- bewusst keine vollständige ISO-Liste, sondern nur die Märkte, in denen
 * die Plattform aktuell betrieben wird.
 */
class Countries
{
    public const OPTIONS = [
        'Deutschland',
        'Schweiz',
        'Österreich',
        'Spanien',
        'Italien',
        'Frankreich',
    ];
}
