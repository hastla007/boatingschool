<?php

/**
 * Funkscheine, Seite "Prüfung buchen": Anmeldung, Gebühren, Ablauf und
 * häufige Fragen je Teil (SRC/UBI). Eigene Texte; Gebühren und
 * Anmeldeweg laut den Verwaltungsportalen des Bundes/der Länder
 * (Leistungen "Zulassung zur Prüfung und Erteilung des SRC/UBI",
 * Stand 2024) -- bei Änderungen hier anpassen. Die Theorie-Eckdaten
 * (Fragen, Bestehensgrenze, Dauer) müssen zu den amtlichen Regelwerken
 * passen (ExamRuleSetSeeder); FunkTrainingTest prüft das.
 */
$documents = [
    'Unterschriebener Antrag auf Zulassung zur Prüfung',
    'Aktuelles Passbild (per Post an den Verband)',
    'Nachweis über die bezahlte Prüfungsgebühr',
    'Falls vorhanden: Kopien bereits erworbener Funkzeugnisse',
    'Bei Minderjährigen: Einverständnis eines Erziehungsberechtigten',
];

$providers = [
    [
        'name' => 'DSV',
        'full' => 'Deutscher Segler-Verband',
        'url' => 'https://www.dsv.org',
        'text' => 'Prüfungsausschüsse des DSV in ganz Deutschland; Termine und Orte findest du auf der Website des Verbands.',
    ],
    [
        'name' => 'DMYV',
        'full' => 'Deutscher Motoryachtverband',
        'url' => 'https://www.dmyv.de/fuehrerschein/-funk/pruefungen/pruefungsausschuesse/',
        'text' => 'Prüfungsausschüsse des DMYV mit Kontaktdaten und Sprechzeiten; den Termin stimmst du direkt mit dem Ausschuss ab.',
    ],
];

$commonFaq = fn (string $name, string $fee) => [
    'Was kostet die Prüfung?' => "Die Prüfungsgebühr für das {$name} beträgt ca. {$fee} €. Du zahlst sie direkt an den Verband (DSV oder DMYV), nicht an die Bootsschule. Für Sonderleistungen, z. B. eine Prüfung im Ausland, können Zusatzkosten anfallen.",
    'Kann ich die Prüfung online ablegen?' => 'Nein. Online stellst du nur den Antrag; die Prüfung selbst legst du vor Ort vor einem Prüfungsausschuss ab.',
    'Welche Unterlagen brauche ich zur Anmeldung?' => 'Den unterschriebenen Antrag, ein aktuelles Passbild, den Nachweis über die bezahlte Gebühr und, falls vorhanden, Kopien deiner bisherigen Funkzeugnisse. Am Prüfungstag bringst du deinen Personalausweis oder Reisepass mit.',
    'Ab welchem Alter darf ich die Prüfung machen?' => 'Das Mindestalter ist 15 Jahre. Bist du noch nicht volljährig, stellt ein Erziehungsberechtigter den Antrag für dich bzw. stimmt ihm zu.',
    'Was passiert, wenn ich nicht bestehe?' => 'Du kannst die Prüfung wiederholen. Ob du nur den nicht bestandenen Teil wiederholen musst und bis wann, sagt dir dein Prüfungsausschuss – frag am besten direkt nach der Prüfung nach.',
];

return [
    'SRC' => [
        'title' => 'SRC-Prüfung buchen',
        'intro' => 'Das Short Range Certificate (beschränkt gültiges Funkbetriebszeugnis) berechtigt dich, auf Sportbooten eine UKW-Seefunkanlage mit DSC zu bedienen. Abgenommen wird die Prüfung von den Prüfungsausschüssen des DSV und des DMYV.',
        'fee' => '127,88',
        'apply' => 'Den Antrag auf Zulassung zur Prüfung und Erteilung des SRC stellst du online im Bundesportal (verwaltung.bund.de) oder schriftlich beim DSV bzw. DMYV. Dabei wählst du auch Prüfungstermin und -ort.',
        'providers' => $providers,
        'documents' => $documents,
        'theory' => ['questions' => 24, 'catalog' => 180, 'min_correct' => 19, 'minutes' => 30],
        'parts' => [
            'theory' => 'Fragebogen mit Antworten zur Auswahl aus dem amtlichen Fragenkatalog, je Frage genau eine richtige Antwort.',
            'oral' => 'Ein englischer Seefunktext wird dir vorgelesen – du schreibst ihn mit und übersetzt ihn ins Deutsche. Außerdem übersetzt du einen deutschen Funktext ins Englische.',
            'praxis' => 'Bedienung einer UKW-Seefunkanlage mit DSC: Notalarm senden und aufheben, Not-, Dringlichkeits-, Sicherheits- und Routineverkehr per DSC und Sprechfunk abwickeln.',
        ],
        'faq' => [
            'Wie läuft der Prüfungstag ab?' => 'Du legst den theoretischen Fragebogen ab, übersetzt die englischen Texte und zeigst im praktischen Teil am Funkgerät, dass du DSC-Anrufe und Sprechfunk sicher abwickeln kannst. Die genaue Reihenfolge legt dein Prüfungsausschuss fest.',
            'Wann habe ich bestanden?' => 'Wenn du alle Teile bestehst. Im Fragebogen brauchst du mindestens 19 von 24 richtigen Antworten (also höchstens 5 Fehler).',
        ] + $commonFaq('SRC', '127,88') + [
            'Was brauche ich nach der Prüfung für mein Boot?' => 'Für eine eigene Seefunkanlage an Bord beantragst du bei der Bundesnetzagentur die Frequenzzuteilung; dort bekommst du auch Rufzeichen und MMSI für den DSC-Controller.',
        ],
    ],
    'UBI' => [
        'title' => 'UBI-Prüfung buchen',
        'intro' => 'Das UKW-Sprechfunkzeugnis für den Binnenschifffahrtsfunk (UBI) brauchst du, um auf Binnenschifffahrtsstraßen am Sprechfunk teilzunehmen. Auch diese Prüfung nehmen die Prüfungsausschüsse des DSV und des DMYV ab.',
        'fee' => '108,71',
        'apply' => 'Den Antrag auf Zulassung zur Prüfung und Erteilung des UBI stellst du online im Bundesportal (verwaltung.bund.de) oder schriftlich beim DSV bzw. DMYV. Dabei wählst du auch Prüfungstermin und -ort.',
        'providers' => $providers,
        'documents' => $documents,
        'theory' => ['questions' => 22, 'catalog' => 130, 'min_correct' => 17, 'minutes' => 60],
        'addon_theory' => ['questions' => 10, 'catalog' => 79, 'min_correct' => 8, 'minutes' => 30],
        'parts' => [
            'theory' => 'Fragebogen mit Antworten zur Auswahl aus dem amtlichen Fragenkatalog. Wer schon das SRC (oder LRC) hat, legt nur eine verkürzte Ergänzungsprüfung ab.',
            'oral' => 'Auf Deutsch: Textaufnahme (ein Funktext wird vorgelesen, du schreibst mit) und Textabgabe (du liest einen Funktext vor und buchstabierst die markierten Wörter mit der internationalen Buchstabiertafel).',
            'praxis' => 'Bedienung einer Binnenfunkanlage: Verkehrskreise und Kanäle wählen, Not-, Dringlichkeits-, Sicherheits- und Routineverkehr abwickeln. Für SRC-Inhaber ist auch der praktische Teil verkürzt.',
        ],
        'faq' => [
            'Ich habe schon das SRC – muss ich alles wiederholen?' => 'Nein. Mit SRC oder LRC legst du eine verkürzte Ergänzungsprüfung ab: 10 Fragen aus dem Ergänzungskatalog (mindestens 8 richtig) und einen kürzeren praktischen Teil.',
            'Wie läuft der Prüfungstag ab?' => 'Du legst den theoretischen Fragebogen ab, nimmst einen Funktext auf, gibst einen Funktext ab und wickelst im praktischen Teil Funkgespräche an der Binnenfunkanlage ab. Die genaue Reihenfolge legt dein Prüfungsausschuss fest.',
            'Wann habe ich bestanden?' => 'Wenn du alle Teile bestehst. Im Fragebogen brauchst du mindestens 17 von 22 richtigen Antworten (also höchstens 5 Fehler), in der Ergänzungsprüfung mindestens 8 von 10.',
        ] + $commonFaq('UBI', '108,71') + [
            'Was brauche ich nach der Prüfung für mein Boot?' => 'Für eine eigene Binnenfunkanlage an Bord beantragst du bei der Bundesnetzagentur die Frequenzzuteilung mit der ATIS-Kennung deines Fahrzeugs.',
        ],
    ],
];
