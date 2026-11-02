<?php

/**
 * Gerätesimulation Funk, Stufe 1 (IC-M505, Seefunk): Übungsschiff,
 * Gegenstationen und Lektionen. Die Lektionen gehen als JSON an den Browser
 * (Lektionssteuerung in resources/js/funkgeraet/lessons.js); dieselbe Datei
 * liefert dem Server die Prüfpunkte für Gesprochenes (Geraetesimulation).
 *
 * Ein Schritt nennt sein Ziel als Ereignis der Gerätelogik ("goal") -- nicht
 * die Tastenfolge. "target" ist das Bedienteil, das die Schritt-Hilfe
 * markiert, "help" der Hilfetext; beides gilt für das IC-M505. Schritte mit
 * "speech" werden serverseitig geprüft: entweder mit eigenen Prüfpunkten
 * ("checks": [Bezeichnung, regulärer Ausdruck, Mindestanzahl]) oder mit den
 * Prüfpunkten der Meldung eines Praxis-Szenarios ("scenario", Format wie
 * database/data/funk_praxis_aufgaben.php, siehe FunkPraxis::scenario()).
 *
 * Namen, Rufzeichen und MMSI sind erfunden; Bremen Rescue Radio ist die echte
 * Küstenfunkstelle wie in der Praxis-Prüfungssimulation.
 */
$ship = ['vessel' => 'KORMORAN', 'callsign' => 'DH4721', 'mmsi' => '211472100', 'type' => 'sail'];
$position = [54, 10, 'N', 7, 52, 'E'];
$bremenRescue = ['name' => 'BREMEN RESCUE', 'mmsi' => '002111240'];
$moewe = ['name' => 'MOEWE', 'mmsi' => '211839200'];

return [
    'ship' => $ship + ['label' => 'Segelyacht Kormoran'],

    'address_book' => [$moewe, $bremenRescue],

    'lessons' => [
        [
            'key' => 'geraet-bedienen',
            'unit' => 'Das Gerät',
            'title' => 'So bedienst du das Gerät',
            'intro' => 'Du lernst Ein- und Ausschalter, Lautstärke, Rauschsperre und die Kanalwahl kennen.',
            'start' => ['power' => false, 'vol' => 0, 'sql' => 4, 'channel' => 16],
            'steps' => [
                ['text' => 'Schalte das Funkgerät ein.', 'target' => 'power', 'help' => 'POWER kurz drücken.', 'goal' => ['type' => 'power', 'on' => true]],
                ['text' => 'Dreh die Lautstärke auf.', 'target' => 'vol', 'help' => 'Den Regler VOL nach rechts drehen, bis mindestens zur Hälfte.', 'goal' => ['type' => 'volume', 'level' => ['min' => 5]]],
                ['text' => 'Öffne die Rauschsperre ganz, bis du das Rauschen hörst.', 'target' => 'sql', 'help' => 'Den Regler SQL ganz nach links drehen.', 'goal' => ['type' => 'squelch', 'state' => 'noise']],
                ['text' => 'Dreh die Rauschsperre zu, bis das Rauschen gerade verschwindet.', 'target' => 'sql', 'help' => 'SQL langsam nach rechts drehen. Zu weit zugedreht hörst du schwache Stationen nicht mehr.', 'goal' => ['type' => 'squelch', 'state' => 'set']],
                ['text' => 'Wähle Kanal 72.', 'target' => 'channel', 'help' => 'Den großen Kanalwahlknopf drehen, bis 72 im Display steht.', 'goal' => ['type' => 'channel', 'channel' => 72]],
                ['text' => 'Schalte mit einem Tastendruck auf Kanal 16.', 'target' => 'ch16', 'help' => '16/C kurz drücken.', 'goal' => ['type' => 'channel', 'channel' => 16]],
                ['text' => 'Kehre zum vorherigen Kanal zurück.', 'target' => 'dial', 'help' => 'DIAL kurz drücken.', 'goal' => ['type' => 'channel', 'channel' => 72]],
            ],
        ],
        [
            'key' => 'hoeren-senden',
            'unit' => 'Das Gerät',
            'title' => 'Hören und senden',
            'intro' => 'Die Kormoran liegt im Hafen. Mach eine Funkprobe mit der Yacht Möwe auf Kanal 72.',
            'start' => ['power' => true, 'vol' => 5, 'sql' => 3, 'channel' => 16],
            'steps' => [
                ['text' => 'Wechsle auf Kanal 72, einen Kanal für den Verkehr von Schiff zu Schiff.', 'target' => 'channel', 'help' => 'Kanalwahlknopf drehen, bis 72 im Display steht.', 'goal' => ['type' => 'channel', 'channel' => 72]],
                ['text' => 'Für ein Gespräch im Hafen reicht geringe Sendeleistung. Schalte auf 1 W.', 'target' => 'hl', 'help' => 'H/L kurz drücken; im Display steht dann 1W.', 'goal' => ['type' => 'power_level', 'high' => false]],
                ['text' => 'Halte die Sprechtaste gedrückt und bitte die Möwe um eine Funkprobe. Lass die Taste los, wenn du fertig bist.', 'target' => 'ptt', 'help' => 'Zum Beispiel: „Möwe, Möwe, Möwe, this is Kormoran, Kormoran, Kormoran, DH4721, radio check, over.“', 'goal' => ['type' => 'spoken', 'channel' => 72], 'speech' => ['checks' => [
                    ['Gegenstation Möwe', 'MOEWE|MÖWE|MOWE', 1],
                    ['THIS IS', '\bTHIS IS\b|\bHIER IST\b', 1],
                    ['Eigener Name Kormoran', '\bKORMORAN\b', 1],
                    ['Funkprobe', 'RADIO CHECK|FUNKPROBE|HOW DO YOU READ', 1],
                    ['Abschluss OVER', '\b(OVER|KOMMEN)\b', 1],
                ]]],
            ],
        ],
        [
            'key' => 'kanaele-anrufkanal',
            'unit' => 'Gerät im Seefunk',
            'title' => 'Kanal 16, Anrufkanal und Kanal 70',
            'intro' => 'Kanal 16 ist der Not- und Anrufkanal. Kanal 70 ist dem DSC vorbehalten: Dort kann man nicht sprechen.',
            'start' => ['power' => true, 'vol' => 5, 'sql' => 3, 'channel' => 6],
            'steps' => [
                ['text' => 'Schalte auf den Anrufkanal der Kanalgruppe.', 'target' => 'ch16', 'help' => '16/C eine Sekunde gedrückt halten. Im Display erscheint CALL.', 'goal' => ['type' => 'call_channel']],
                ['text' => 'Kehre zum vorherigen Kanal 06 zurück.', 'target' => 'dial', 'help' => 'DIAL kurz drücken.', 'goal' => ['type' => 'channel', 'channel' => 6]],
                ['text' => 'Wähle Kanal 70 und versuche zu senden.', 'target' => 'ptt', 'help' => 'Kanalwahlknopf auf 70 drehen, dann die Sprechtaste drücken. Das Gerät verweigert das Senden.', 'goal' => ['type' => 'tx_blocked', 'channel' => 70]],
                ['text' => 'Schalte wieder auf Kanal 16, um ihn abzuhören.', 'target' => 'ch16', 'help' => '16/C kurz drücken.', 'goal' => ['type' => 'channel', 'channel' => 16]],
            ],
        ],
        [
            'key' => 'mmsi-position-zeit',
            'unit' => 'Gerät im Seefunk',
            'title' => 'MMSI, Position und Uhrzeit',
            'intro' => 'Ohne GPS-Empfänger musst du Position und Uhrzeit von Hand eingeben, damit ein Notalarm sie enthält. Position: 54°10,000\'N 007°52,000\'E, Uhrzeit 12:30 UTC.',
            'start' => ['power' => true, 'vol' => 5, 'sql' => 3, 'channel' => 16],
            'steps' => [
                ['text' => 'Öffne das DSC-Menü.', 'target' => 'menu', 'help' => 'MENU drücken.', 'goal' => ['type' => 'screen', 'screen' => 'menu']],
                ['text' => 'Lass dir die eigene MMSI anzeigen.', 'target' => 'channel', 'help' => 'Mit dem Kanalwahlknopf „Set up“ wählen und den Knopf drücken (ENTER), dann „MMSI Check“ wählen und ENTER.', 'goal' => ['type' => 'mmsi_checked']],
                ['text' => 'Gib die Position ein.', 'target' => 'channel', 'help' => 'CLR, dann „Position Input“ wählen und ENTER. Ziffern mit dem Knopf einstellen, mit 16/C zur nächsten Stelle, am Ende ENTER.', 'goal' => ['type' => 'position_set', 'position' => $position]],
                ['text' => 'Gib die Uhrzeit 12:30 UTC ein.', 'target' => 'channel', 'help' => 'Ziffern mit dem Knopf einstellen, mit 16/C weiter, am Ende ENTER.', 'goal' => ['type' => 'utc_set', 'utc' => '12:30']],
            ],
        ],
        [
            'key' => 'notalarm-senden',
            'unit' => 'Notverkehr',
            'title' => 'Notalarm senden',
            'pruefung' => '1.a',
            'intro' => 'Die Kormoran hat einen starken Wassereinbruch, die Pumpen kommen nicht nach. Position 54°10,000\'N 007°52,000\'E, 12:30 UTC. Sende einen Notalarm mit der Art der Not „Flooding“.',
            'start' => ['power' => true, 'vol' => 5, 'sql' => 3, 'channel' => 16],
            'steps' => [
                ['text' => 'Wähle im DSC-Menü die Art der Not „Flooding“.', 'target' => 'menu', 'help' => 'MENU, „Distress Setting“ wählen und ENTER, dann „Flooding“ und ENTER.', 'goal' => ['type' => 'nature_selected', 'nature' => 'Flooding']],
                ['text' => 'Gib Position und Uhrzeit ein.', 'target' => 'channel', 'help' => 'Ziffern mit dem Kanalwahlknopf, weiter mit 16/C, bestätigen mit ENTER.', 'goal' => ['type' => 'utc_set', 'utc' => '12:30']],
                ['text' => 'Öffne die Schutzklappe der DISTRESS-Taste.', 'target' => 'distressCover', 'help' => 'Die rote Klappe antippen.', 'goal' => ['type' => 'cover', 'open' => true]],
                ['text' => 'Sende den Notalarm.', 'target' => 'distress', 'help' => 'DISTRESS fünf Sekunden gedrückt halten, bis der Alarm gesendet ist.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'distress', 'nature' => 'Flooding']],
                ['text' => 'Warte auf die Bestätigung einer Küstenfunkstelle. Das Gerät hört dazu Kanal 16 ab.', 'target' => null, 'help' => 'Nichts drücken: Die Bestätigung kommt gleich.', 'goal' => ['type' => 'dsc_received', 'call.type' => 'distress_ack'], 'receive' => ['delay_ms' => 5000, 'call' => ['type' => 'distress_ack', 'from' => $bremenRescue]]],
                ['text' => 'Schalte den Alarmton aus.', 'target' => 'clr', 'help' => 'CLR drücken. Danach folgt die Notmeldung auf Kanal 16.', 'goal' => ['type' => 'alarm_stopped']],
            ],
        ],
        [
            'key' => 'notmeldung-sprechen',
            'unit' => 'Notverkehr',
            'title' => 'Notmeldung sprechen',
            'pruefung' => '2.a',
            'intro' => 'Bremen Rescue hat deinen Notalarm bestätigt. Sprich jetzt die Notmeldung auf Kanal 16: Wassereinbruch, 3 Personen an Bord, Position 54°10\'N 007°52\'E, 12:30 UTC.',
            'start' => ['power' => true, 'vol' => 5, 'sql' => 3, 'channel' => 16, 'position' => [54, 10.0, 'N', 7, 52.0, 'E'], 'utc' => '12:30'],
            'steps' => [
                ['text' => 'Halte die Sprechtaste gedrückt und sprich die Notmeldung.', 'target' => 'ptt', 'help' => 'MAYDAY, MAYDAY, MAYDAY, this is Kormoran (dreimal), DH4721, MMSI 211472100. MAYDAY Kormoran, DH4721, MMSI 211472100, my position …, I am taking in water, I require immediate assistance, 3 persons on board, over.', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1230', 'kind' => 'distress', 'nature' => 'flooding', 'persons' => 3]]],
            ],
        ],
        [
            'key' => 'fehlalarm-aufheben',
            'unit' => 'Notverkehr',
            'title' => 'Fehlalarm aufheben',
            'pruefung' => '3.b',
            'intro' => 'Beim Reinigen der Funkanlage hast du um 12:41 UTC versehentlich einen Notalarm ausgelöst. Das Gerät wartet auf eine Bestätigung und würde den Alarm wiederholen.',
            'start' => ['power' => true, 'vol' => 5, 'sql' => 3, 'channel' => 16, 'position' => [54, 10.0, 'N', 7, 52.0, 'E'], 'utc' => '12:41', 'distressWaiting' => 'Undesignated'],
            'steps' => [
                ['text' => 'Brich die automatische Wiederholung des Notalarms ab. Schalte das Gerät dabei nicht aus.', 'target' => 'clr', 'help' => 'CLR drücken. Am IC-M505 gibt es keinen DSC-Widerruf; aufgehoben wird per Sprechfunk.', 'goal' => ['type' => 'distress_repeat_cancelled']],
                ['text' => 'Hebe den Fehlalarm auf Kanal 16 an alle Funkstellen auf.', 'target' => 'ptt', 'help' => 'All stations (dreimal), this is Kormoran (dreimal), DH4721, MMSI 211472100, position …, cancel my distress alert of 12:41 UTC, over.', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1241', 'kind' => 'false_alert', 'alert_time' => '1241']]],
            ],
        ],
        [
            'key' => 'einzelanruf',
            'unit' => 'DSC-Anrufe',
            'title' => 'Einzelanruf an ein Schiff',
            'intro' => 'Ruf die Yacht Möwe per DSC an und verabrede Kanal 72 als Arbeitskanal.',
            'start' => ['power' => true, 'vol' => 5, 'sql' => 3, 'channel' => 16],
            'steps' => [
                ['text' => 'Sende einen Einzelanruf (Routine) an die Möwe mit Arbeitskanal 72.', 'target' => 'menu', 'help' => 'MENU, „Individual Call“, ENTER, „MOEWE“ wählen, ENTER, „Routine“, ENTER, Kanal 72 einstellen, ENTER, dann ENTER zum Senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'individual', 'address.name' => 'MOEWE', 'category' => 'Routine', 'channel' => 72]],
                ['text' => 'Warte auf die Bestätigung. Das Gerät stellt dann Kanal 72 ein.', 'target' => null, 'help' => 'Nichts drücken.', 'goal' => ['type' => 'channel', 'channel' => 72], 'receive' => ['delay_ms' => 4000, 'call' => ['type' => 'individual_ack', 'from' => $moewe]]],
                ['text' => 'Schalte den Signalton aus und ruf die Möwe auf Kanal 72.', 'target' => 'ptt', 'help' => 'CLR, dann die Sprechtaste: „Möwe, this is Kormoran, over.“', 'goal' => ['type' => 'spoken', 'channel' => 72], 'speech' => ['checks' => [
                    ['Gegenstation Möwe', 'MOEWE|MÖWE|MOWE', 1],
                    ['THIS IS', '\bTHIS IS\b|\bHIER IST\b', 1],
                    ['Eigener Name Kormoran', '\bKORMORAN\b', 1],
                    ['Abschluss OVER', '\b(OVER|KOMMEN)\b', 1],
                ]]],
            ],
        ],
    ],
];
