{{-- Abgelaufener Zugang aus einer Lerngruppe (auch WP-Gruppenplatz ohne Namen): Hinweis auf die Schule statt Code/Kauf. --}}
Dein Zugang {{ ($withCourse ?? false) ? 'zu „'.$entitlement->course?->name.'“ ' : '' }}über {{ $groupName !== null ? 'die Lerngruppe „'.$groupName.'“' : 'deine Lerngruppe' }} ist am {{ \App\Support\GermanTime::format($entitlement->valid_until, 'd.m.Y') }} abgelaufen.
Für eine Verlängerung wende dich an deine Bootsschule.
