<?php

/**
 * Gerätesimulation Funk, Stufen 1 und 2 (IC-M505, Seefunk): Übungsschiff,
 * Gegenstationen, Lektionen und die Reihen des Prüfungsmodus. Die Lektionen
 * gehen als JSON an den Browser (Lektionssteuerung in
 * resources/js/funkgeraet/lessons.js); dieselbe Datei liefert dem Server die
 * Prüfpunkte für Gesprochenes (Geraetesimulation).
 *
 * Ein Schritt nennt sein Ziel als Ereignis der Gerätelogik ("goal") -- nicht
 * die Tastenfolge. "target" ist das Bedienteil, das die Schritt-Hilfe
 * markiert, "help" der Hilfetext; beides gilt für das IC-M505. "receive" ist
 * ein DSC-Anruf, "voice" Sprechfunk einer Gegenstation (Browserstimme, bis
 * Sprecheraufnahmen vorliegen). Schritte mit "speech" werden serverseitig
 * geprüft: entweder mit eigenen Prüfpunkten ("checks": [Bezeichnung,
 * regulärer Ausdruck, Mindestanzahl] und "solution" als Musterlösung) oder
 * mit den Prüfpunkten der Meldung eines Praxis-Szenarios ("scenario", Format
 * wie database/data/funk_praxis_aufgaben.php, siehe FunkPraxis::scenario()).
 *
 * Namen, Rufzeichen und MMSI der Yachten sind erfunden, ebenso die
 * Küstenfunkstelle „Nordsee Radio“; Bremen Rescue Radio ist die echte
 * Küstenfunkstelle wie in der Praxis-Prüfungssimulation.
 */
$ship = ['vessel' => 'KORMORAN', 'callsign' => 'DH4721', 'mmsi' => '211472100', 'type' => 'sail'];
$position = [54, 10, 'N', 7, 52, 'E'];
$bremenRescue = ['name' => 'BREMEN RESCUE', 'mmsi' => '002111240'];
$nordseeRadio = ['name' => 'NORDSEE RADIO', 'mmsi' => '002119999'];
$moewe = ['name' => 'MOEWE', 'mmsi' => '211839200'];
$seestern = ['name' => 'SEESTERN', 'mmsi' => '211555000'];
$albatros = ['name' => 'ALBATROS', 'mmsi' => '211600700'];
$flotilla = ['name' => 'FLOTILLE', 'mmsi' => '021100001', 'group' => true];
$seesternInDistress = ['vessel' => 'SEESTERN', 'callsign' => 'DJ3388', 'mmsi' => '211555000', 'time' => '1235', 'pos' => [54, 14, 'N', 7, 48, 'E'], 'nature' => 'flooding', 'persons' => 2];
$seesternMayday = 'Mayday, mayday, mayday, this is Seestern, Seestern, Seestern, Delta Juliett Three Three Eight Eight, MMSI 211555000. Mayday Seestern, Delta Juliett Three Three Eight Eight, MMSI 211555000, my position 54 degrees 14 minutes north, 007 degrees 48 minutes east, I am taking in water, I require immediate assistance, 2 persons on board, over.';

// Wiederkehrende Prüfpunkte für eigene Funksprüche.
$over = ['Abschluss OVER', '\b(OVER|KOMMEN)\b', 1];
$out = ['Abschluss OUT', '\b(OUT|ENDE)\b', 1];
$thisIs = ['THIS IS', '\bTHIS IS\b|\bHIER IST\b', 1];
$kormoran = ['Eigener Name Kormoran', '\bKORMORAN\b', 1];
$moeweCheck = ['Gegenstation Möwe', 'MOEWE|MÖWE|MOWE', 1];
$start = ['power' => true, 'vol' => 5, 'sql' => 3, 'channel' => 16];
$startWithPosition = ['position' => [54, 10.0, 'N', 7, 52.0, 'E'], 'utc' => '12:30'] + $start;
$waitForAck = ['text' => 'Warte auf die Bestätigung. Das Gerät stellt dann den Arbeitskanal ein.', 'target' => null, 'help' => 'Nichts drücken.'];

return [
    'ship' => $ship + ['label' => 'Segelyacht Kormoran'],

    'address_book' => [$moewe, $bremenRescue, $nordseeRadio, $flotilla],

    // Bekannte Stationen: Name nach „Add:INDV ID“ und bei Handeingabe der MMSI.
    'directory' => [$moewe, $seestern, $albatros, $bremenRescue, $nordseeRadio],

    // Prüfungsmodus Seefunk: je vier Pflichtaufgaben (1–4) und drei sonstige.
    'exams' => [
        'a' => ['notalarm-senden', 'notmeldung-sprechen', 'notverkehr-beenden', 'dringlichkeit', 'einzelanruf', 'kuestenfunkstelle', 'medico'],
        'b' => ['notalarm-empfangen', 'mayday-relay', 'fehlalarm-aufheben', 'sicherheit', 'einzelanruf-annehmen', 'gruppenanruf', 'position-polling'],
    ],

    'lessons' => [
        // --- 0 Das Gerät -----------------------------------------------------
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
            'intro' => 'Die Yacht Möwe ruft dich gleich auf Kanal 72. Stell das Gerät so ein, dass du sie hörst, und antworte.',
            'start' => ['power' => true, 'vol' => 0, 'sql' => 8, 'channel' => 16],
            'steps' => [
                ['text' => 'Wechsle auf Kanal 72.', 'target' => 'channel', 'help' => 'Kanalwahlknopf drehen, bis 72 im Display steht.', 'goal' => ['type' => 'channel', 'channel' => 72]],
                ['text' => 'Dreh die Lautstärke auf.', 'target' => 'vol', 'help' => 'VOL nach rechts drehen.', 'goal' => ['type' => 'volume', 'level' => ['min' => 4]]],
                ['text' => 'Die Rauschsperre ist zu weit zugedreht, schwache Stationen hörst du so nicht. Dreh sie zurück, bis das Rauschen gerade verschwindet.', 'target' => 'sql', 'help' => 'SQL nach links drehen, bis BUSY kurz erscheint, dann ein kleines Stück zurück.', 'goal' => ['type' => 'squelch', 'state' => 'set']],
                ['text' => 'Hör zu: Die Möwe ruft dich.', 'target' => null, 'help' => 'Nichts drücken, nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'MOEWE'], 'voice' => ['delay_ms' => 1500, 'from' => 'MOEWE', 'channel' => 72, 'text' => 'Kormoran, Kormoran, Kormoran, this is Moewe, Moewe, Moewe, over.']],
                ['text' => 'Antworte der Möwe: Sprechtaste gedrückt halten, sprechen, loslassen.', 'target' => 'ptt', 'help' => '„Moewe, this is Kormoran, over.“', 'goal' => ['type' => 'spoken', 'channel' => 72], 'speech' => ['checks' => [$moeweCheck, $thisIs, $kormoran, $over], 'solution' => 'Moewe, this is Kormoran, over.']],
            ],
        ],

        // --- 1 Gerät im Seefunk ---------------------------------------------
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
            'key' => 'sendeleistung',
            'unit' => 'Gerät im Seefunk',
            'title' => 'Sendeleistung 25 W und 1 W',
            'intro' => 'So wenig Leistung wie möglich, so viel wie nötig: 1 W für kurze Entfernungen, 25 W für Not-, Dringlichkeits- und Sicherheitsmeldungen. Auf manchen Kanälen erzwingt das Gerät 1 W.',
            'start' => ['highPower' => true] + $start,
            'steps' => [
                ['text' => 'Wähle Kanal 17, einen Kanal für den Funkverkehr an Bord.', 'target' => 'channel', 'help' => 'Kanalwahlknopf drehen, bis 17 im Display steht.', 'goal' => ['type' => 'channel', 'channel' => 17]],
                ['text' => 'Versuche, auf 25 W umzuschalten.', 'target' => 'hl', 'help' => 'H/L drücken. Auf den Kanälen 15 und 17 sendet das Gerät immer mit 1 W.', 'goal' => ['type' => 'power_level_blocked', 'channel' => 17]],
                ['text' => 'Wechsle auf Kanal 72 und schalte für ein Gespräch im Hafen auf 1 W.', 'target' => 'hl', 'help' => 'Kanal 72 wählen, dann H/L drücken, bis 1W im Display steht.', 'goal' => ['type' => 'power_level', 'high' => false, 'channel' => 72]],
                ['text' => 'Schalte auf Kanal 16 und stelle 25 W ein, wie für eine Notmeldung.', 'target' => 'hl', 'help' => '16/C drücken, dann H/L, bis 25W im Display steht.', 'goal' => ['type' => 'power_level', 'high' => true, 'channel' => 16]],
            ],
        ],
        [
            'key' => 'mmsi-position-zeit',
            'unit' => 'Gerät im Seefunk',
            'title' => 'MMSI, Position und Uhrzeit',
            'intro' => 'Ohne GPS-Empfänger musst du Position und Uhrzeit von Hand eingeben, damit ein Notalarm sie enthält. Position: 54°10,000\'N 007°52,000\'E, Uhrzeit 12:30 UTC.',
            'start' => $start,
            'steps' => [
                ['text' => 'Öffne das DSC-Menü.', 'target' => 'menu', 'help' => 'MENU drücken.', 'goal' => ['type' => 'screen', 'screen' => 'menu']],
                ['text' => 'Lass dir die eigene MMSI anzeigen.', 'target' => 'channel', 'help' => 'Mit dem Kanalwahlknopf „Set up“ wählen und den Knopf drücken (ENTER), dann „MMSI Check“ wählen und ENTER.', 'goal' => ['type' => 'mmsi_checked']],
                ['text' => 'Gib die Position ein.', 'target' => 'channel', 'help' => 'CLR, dann „Position Input“ wählen und ENTER. Ziffern mit dem Knopf einstellen, mit 16/C zur nächsten Stelle, am Ende ENTER.', 'goal' => ['type' => 'position_set', 'position' => $position]],
                ['text' => 'Gib die Uhrzeit 12:30 UTC ein.', 'target' => 'channel', 'help' => 'Ziffern mit dem Knopf einstellen, mit 16/C weiter, am Ende ENTER.', 'goal' => ['type' => 'utc_set', 'utc' => '12:30']],
            ],
        ],

        // --- 2 Sprechfunk-Grundlagen ----------------------------------------
        [
            'key' => 'funkprobe',
            'unit' => 'Sprechfunk-Grundlagen',
            'title' => 'Funkprobe mit einem Nachbarboot',
            'intro' => 'Die Kormoran liegt im Hafen neben der Möwe. Prüfe mit einer Funkprobe auf Kanal 72, ob dein Gerät sendet.',
            'start' => $start,
            'steps' => [
                ['text' => 'Wechsle auf Kanal 72 und schalte auf 1 W.', 'target' => 'hl', 'help' => 'Kanal 72 wählen, dann H/L drücken.', 'goal' => ['type' => 'power_level', 'high' => false, 'channel' => 72]],
                ['text' => 'Bitte die Möwe um eine Funkprobe.', 'target' => 'ptt', 'help' => '„Moewe, Moewe, Moewe, this is Kormoran, Kormoran, Kormoran, DH4721, radio check, over.“', 'goal' => ['type' => 'spoken', 'channel' => 72], 'speech' => ['checks' => [$moeweCheck, $thisIs, $kormoran, ['Funkprobe', 'RADIO CHECK|FUNKPROBE|HOW DO YOU READ', 1], $over], 'solution' => 'Moewe, Moewe, Moewe, this is Kormoran, Kormoran, Kormoran, Delta Hotel Four Seven Two One, radio check, over.']],
                ['text' => 'Hör dir die Antwort der Möwe an.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'MOEWE'], 'voice' => ['delay_ms' => 2000, 'from' => 'MOEWE', 'channel' => 72, 'text' => 'Kormoran, this is Moewe, I read you loud and clear, over.']],
                ['text' => 'Bedanke dich und beende das Gespräch.', 'target' => 'ptt', 'help' => '„Moewe, this is Kormoran, thank you, out.“', 'goal' => ['type' => 'spoken', 'channel' => 72], 'speech' => ['checks' => [$moeweCheck, $thisIs, $kormoran, $out], 'solution' => 'Moewe, this is Kormoran, thank you, out.']],
            ],
        ],
        [
            'key' => 'anruf-arbeitskanal',
            'unit' => 'Sprechfunk-Grundlagen',
            'title' => 'Anruf auf Kanal 16, Gespräch auf dem Arbeitskanal',
            'intro' => 'Ruf die Möwe auf Kanal 16 an und schlag Kanal 72 als Arbeitskanal vor. Auf Kanal 16 wird nur gerufen, das Gespräch läuft auf dem Arbeitskanal.',
            'start' => $start,
            'steps' => [
                ['text' => 'Ruf die Möwe auf Kanal 16 und schlag Kanal 72 vor.', 'target' => 'ptt', 'help' => '„Moewe, Moewe, Moewe, this is Kormoran, Kormoran, Kormoran, DH4721, channel 72, over.“', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['checks' => [$moeweCheck, $thisIs, $kormoran, ['Arbeitskanal 72', '(?<!\d)72(?!\d)|SEVEN TWO|SEVENTY TWO|SIEBEN ZWO', 1], $over], 'solution' => 'Moewe, Moewe, Moewe, this is Kormoran, Kormoran, Kormoran, Delta Hotel Four Seven Two One, channel seven two, over.']],
                ['text' => 'Hör die Antwort.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'MOEWE'], 'voice' => ['delay_ms' => 2000, 'from' => 'MOEWE', 'channel' => 16, 'text' => 'Kormoran, this is Moewe, channel seven two, over.']],
                ['text' => 'Wechsle auf den Arbeitskanal 72.', 'target' => 'channel', 'help' => 'Kanalwahlknopf auf 72 drehen.', 'goal' => ['type' => 'channel', 'channel' => 72]],
                ['text' => 'Melde dich auf Kanal 72 bei der Möwe.', 'target' => 'ptt', 'help' => '„Moewe, this is Kormoran, over.“', 'goal' => ['type' => 'spoken', 'channel' => 72], 'speech' => ['checks' => [$moeweCheck, $thisIs, $kormoran, $over], 'solution' => 'Moewe, this is Kormoran, over.']],
            ],
        ],
        [
            'key' => 'unbekanntes-schiff',
            'unit' => 'Sprechfunk-Grundlagen',
            'title' => 'Anruf an ein unbekanntes Schiff',
            'intro' => 'Eine Segelyacht mit roten Segeln kreuzt zwei Seemeilen südlich von dir deinen Kurs. Name und MMSI kennst du nicht. Ruf sie auf Kanal 16 an, indem du sie beschreibst.',
            'start' => $start,
            'steps' => [
                ['text' => 'Ruf die Yacht auf Kanal 16 an.', 'target' => 'ptt', 'help' => '„Sailing yacht with red sails two miles south of Kormoran, this is Kormoran, Kormoran, Kormoran, DH4721, over.“', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['checks' => [['Beschreibung: rote Segel', '\bRED\b|\bROT', 1], ['Beschreibung: Segelyacht', 'SAILING|YACHT|SEGEL', 1], ['Ort: Richtung oder Entfernung', 'SOUTH|SÜD|MILE|MEILE|POSITION', 1], $thisIs, $kormoran, $over], 'solution' => 'Sailing yacht with red sails two miles south of Kormoran, this is Kormoran, Kormoran, Kormoran, Delta Hotel Four Seven Two One, over.']],
                ['text' => 'Hör die Antwort.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'SEESTERN'], 'voice' => ['delay_ms' => 2500, 'from' => 'SEESTERN', 'channel' => 16, 'text' => 'Kormoran, this is sailing yacht Seestern, channel seven seven, over.']],
                ['text' => 'Wechsle auf den vorgeschlagenen Kanal 77.', 'target' => 'channel', 'help' => 'Kanalwahlknopf auf 77 drehen.', 'goal' => ['type' => 'channel', 'channel' => 77]],
            ],
        ],

        // --- 3 Kanäle und Wache ---------------------------------------------
        [
            'key' => 'zweikanalwache',
            'unit' => 'Kanäle und Wache',
            'title' => 'Zwei- und Dreikanalwache',
            'intro' => 'Mit der Zweikanalwache hörst du einen Arbeitskanal und zusätzlich Kanal 16 ab. Die Dreikanalwache überwacht außerdem den Anrufkanal.',
            'start' => ['channel' => 72] + $start,
            'steps' => [
                ['text' => 'Starte die Zweikanalwache auf Kanal 72.', 'target' => 'dial', 'help' => 'DIAL eine Sekunde gedrückt halten. Im Display steht DUAL 16.', 'goal' => ['type' => 'watch', 'mode' => 'dual']],
                ['text' => 'Hör mit: Auf Kanal 16 sendet Bremen Rescue Radio.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'BREMEN RESCUE'], 'voice' => ['delay_ms' => 2000, 'from' => 'BREMEN RESCUE', 'channel' => 16, 'text' => 'Securite, securite, securite. All stations, all stations, all stations. This is Bremen Rescue Radio. Navigational warning: buoy Elbe One unlit. Out.']],
                ['text' => 'Beende die Zweikanalwache.', 'target' => 'dial', 'help' => 'DIAL kurz drücken.', 'goal' => ['type' => 'watch', 'mode' => 'off']],
                ['text' => 'Stelle im Set-Modus die Dreikanalwache ein.', 'target' => 'power', 'help' => 'POWER eine Sekunde halten (aus), dann 16/C gedrückt halten und POWER drücken. „Dual/Tri“ wählen und ENTER, bis Tri-watch angezeigt wird.', 'goal' => ['type' => 'set_mode', 'item' => 'Dual/Tri', 'value' => 'tri']],
                ['text' => 'Verlasse den Set-Modus und starte die Dreikanalwache.', 'target' => 'dial', 'help' => 'CLR, dann DIAL eine Sekunde halten. Im Display steht TRI 16.', 'goal' => ['type' => 'watch', 'mode' => 'tri']],
            ],
        ],
        [
            'key' => 'suchlauf-tag',
            'unit' => 'Kanäle und Wache',
            'title' => 'Suchlauf und TAG-Kanäle',
            'intro' => 'Der Suchlauf prüft die markierten TAG-Kanäle der Reihe nach (Prioritätssuchlauf: Kanal 16 immer mit) und bleibt stehen, sobald jemand spricht.',
            'start' => ['channel' => 69] + $start,
            'steps' => [
                ['text' => 'Markiere Kanal 69 als TAG-Kanal.', 'target' => 'scan', 'help' => 'SCAN eine Sekunde gedrückt halten. Im Display erscheint TAG.', 'goal' => ['type' => 'tag', 'channel' => 69, 'on' => true]],
                ['text' => 'Markiere auch die Kanäle 72 und 77.', 'target' => 'scan', 'help' => 'Kanal wählen, SCAN eine Sekunde halten; für beide Kanäle.', 'goal' => ['type' => 'tag', 'radio.tags' => [69, 72, 77]]],
                ['text' => 'Starte den Suchlauf.', 'target' => 'scan', 'help' => 'SCAN kurz drücken. Im Display steht PRI-SCAN 16.', 'goal' => ['type' => 'scan', 'running' => true]],
                ['text' => 'Warte: Der Suchlauf hält an, wenn auf einem TAG-Kanal gesprochen wird.', 'target' => null, 'help' => 'Nichts drücken.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'MOEWE'], 'voice' => ['delay_ms' => 3000, 'from' => 'MOEWE', 'channel' => 77, 'text' => 'Seestern, Seestern, this is Moewe, over.']],
                ['text' => 'Der Anruf galt nicht dir. Kehre zu Kanal 16 zurück.', 'target' => 'ch16', 'help' => '16/C drücken.', 'goal' => ['type' => 'channel', 'channel' => 16]],
            ],
        ],

        // --- 4 DSC-Routine ---------------------------------------------------
        [
            'key' => 'einzelanruf',
            'unit' => 'DSC-Routine',
            'title' => 'Einzelanruf an ein Schiff',
            'intro' => 'Ruf die Yacht Möwe per DSC an und verabrede Kanal 72 als Arbeitskanal.',
            'start' => $start,
            'steps' => [
                ['text' => 'Sende einen Einzelanruf (Routine) an die Möwe mit Arbeitskanal 72.', 'target' => 'menu', 'help' => 'MENU, „Individual Call“, ENTER, „MOEWE“ wählen, ENTER, „Routine“, ENTER, Kanal 72 einstellen, ENTER, dann ENTER zum Senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'individual', 'address.name' => 'MOEWE', 'category' => 'Routine', 'channel' => 72]],
                $waitForAck + ['goal' => ['type' => 'channel', 'channel' => 72], 'receive' => ['delay_ms' => 4000, 'call' => ['type' => 'individual_ack', 'from' => $moewe]]],
                ['text' => 'Schalte den Signalton aus und ruf die Möwe auf Kanal 72.', 'target' => 'ptt', 'help' => 'CLR, dann die Sprechtaste: „Moewe, this is Kormoran, over.“', 'goal' => ['type' => 'spoken', 'channel' => 72], 'speech' => ['checks' => [$moeweCheck, $thisIs, $kormoran, $over], 'solution' => 'DSC: Individual Call an MOEWE (211839200), Routine, Kanal 72. Sprechfunk auf Kanal 72: Moewe, this is Kormoran, over.']],
            ],
        ],
        [
            'key' => 'einzelanruf-annehmen',
            'unit' => 'DSC-Routine',
            'title' => 'Einzelanruf annehmen',
            'intro' => 'Die Möwe ruft dich per DSC. Nimm den Anruf mit „Able to Comply“ an; das Gerät schaltet dann auf den Arbeitskanal, den die Möwe vorgeschlagen hat.',
            'start' => $start,
            'steps' => [
                ['text' => 'Warte auf den Anruf und schalte den Signalton aus.', 'target' => 'clr', 'help' => 'Wenn „Received Individual“ erscheint: CLR drücken.', 'goal' => ['type' => 'alarm_stopped', 'call' => 'individual'], 'receive' => ['delay_ms' => 2000, 'call' => ['type' => 'individual', 'from' => $moewe, 'category' => 'Routine', 'channel' => 72]]],
                ['text' => 'Bestätige den Anruf mit „Able to Comply“.', 'target' => 'enter', 'help' => 'ENTER, „Able to Comply“ wählen, ENTER, dann ENTER zum Senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'individual_ack', 'action' => 'able']],
                ['text' => 'Das Gerät schaltet auf Kanal 72. Hör, was die Möwe sagt.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'MOEWE'], 'voice' => ['delay_ms' => 3500, 'from' => 'MOEWE', 'channel' => 72, 'text' => 'Kormoran, this is Moewe, do you have a spare impeller on board? Over.']],
                ['text' => 'Antworte der Möwe.', 'target' => 'ptt', 'help' => '„Moewe, this is Kormoran, yes, I have a spare impeller, over.“', 'goal' => ['type' => 'spoken', 'channel' => 72], 'speech' => ['checks' => [$moeweCheck, $thisIs, $kormoran, ['Antwort ja/nein', '\b(YES|NO|AFFIRMATIVE|NEGATIVE|JA|NEIN)\b', 1], $over], 'solution' => 'DSC: Individual ACK, Able to Comply. Sprechfunk auf Kanal 72: Moewe, this is Kormoran, yes, I have a spare impeller, over.']],
            ],
        ],
        [
            'key' => 'kuestenfunkstelle',
            'unit' => 'DSC-Routine',
            'title' => 'Anruf an eine Küstenfunkstelle',
            'intro' => 'Du möchtest von der Küstenfunkstelle Nordsee Radio (MMSI 002119999) den Wetterbericht für die Deutsche Bucht erfragen. Ruf sie per DSC (Routine) und schlag Kanal 26 vor.',
            'start' => $start,
            'steps' => [
                ['text' => 'Sende einen Einzelanruf (Routine) an Nordsee Radio mit Kanal 26.', 'target' => 'menu', 'help' => 'MENU, „Individual Call“, „NORDSEE RADIO“, „Routine“, Kanal 26, ENTER zum Senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'individual', 'address.mmsi' => '002119999', 'category' => 'Routine', 'channel' => 26]],
                $waitForAck + ['goal' => ['type' => 'channel', 'channel' => 26], 'receive' => ['delay_ms' => 4000, 'call' => ['type' => 'individual_ack', 'from' => $nordseeRadio]]],
                ['text' => 'Ruf Nordsee Radio auf Kanal 26 und frag nach dem Wetterbericht.', 'target' => 'ptt', 'help' => '„Nordsee Radio, this is Kormoran, DH4721, request weather forecast for the German Bight, over.“', 'goal' => ['type' => 'spoken', 'channel' => 26], 'speech' => ['checks' => [['Küstenfunkstelle Nordsee Radio', 'NORDSEE', 1], $thisIs, $kormoran, ['Anliegen Wetterbericht', 'WEATHER|WETTER|FORECAST', 1], $over], 'solution' => 'DSC: Individual Call an NORDSEE RADIO (002119999), Routine, Kanal 26. Sprechfunk auf Kanal 26: Nordsee Radio, this is Kormoran, Delta Hotel Four Seven Two One, request weather forecast for the German Bight, over.']],
                ['text' => 'Hör die Antwort.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'NORDSEE RADIO'], 'voice' => ['delay_ms' => 2000, 'from' => 'NORDSEE RADIO', 'channel' => 26, 'text' => 'Kormoran, this is Nordsee Radio. German Bight: west four to five, sea state three, good visibility. Over.']],
            ],
        ],
        [
            'key' => 'gruppenanruf',
            'unit' => 'DSC-Routine',
            'title' => 'Gruppenanruf',
            'intro' => 'Du segelst in einer Flottille. Ruf alle Yachten der Gruppe FLOTILLE per DSC und schlag Kanal 69 vor.',
            'start' => $start,
            'steps' => [
                ['text' => 'Sende einen Gruppenanruf (Routine) an FLOTILLE mit Kanal 69.', 'target' => 'menu', 'help' => 'MENU, „Group Call“, „FLOTILLE“, „Routine“, Kanal 69, ENTER zum Senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'group', 'address.name' => 'FLOTILLE', 'category' => 'Routine', 'channel' => 69]],
                ['text' => 'Warte, bis das Gerät auf Kanal 69 schaltet.', 'target' => null, 'help' => 'Nach etwa 2 Sekunden oder mit CLR.', 'goal' => ['type' => 'channel', 'channel' => 69]],
                ['text' => 'Sprich die Yachten der Flottille auf Kanal 69 an.', 'target' => 'ptt', 'help' => '„All yachts of flotilla, this is Kormoran, we meet in Helgoland at 1700 UTC, over.“', 'goal' => ['type' => 'spoken', 'channel' => 69], 'speech' => ['checks' => [['Anruf an die Flottille', 'FLOTILL|ALL YACHTS', 1], $thisIs, $kormoran, $over], 'solution' => 'DSC: Group Call an FLOTILLE (021100001), Routine, Kanal 69. Sprechfunk auf Kanal 69: All yachts of flotilla, this is Kormoran, we meet in Helgoland at 1700 UTC, over.']],
            ],
        ],
        [
            'key' => 'adressbuch',
            'unit' => 'DSC-Routine',
            'title' => 'Adressbuch füllen und daraus rufen',
            'intro' => 'Die Yacht Seestern (MMSI 211555000) segelt mit dir. Nimm sie ins Adressbuch auf und ruf sie dann aus dem Adressbuch an (Routine, Kanal 77).',
            'start' => $start,
            'steps' => [
                ['text' => 'Nimm die Seestern ins Adressbuch auf.', 'target' => 'menu', 'help' => 'MENU, „Set up“, „Add:INDV ID“, die 9 Ziffern eingeben (Knopf drehen, 16/C weiter), ENTER.', 'goal' => ['type' => 'address_added', 'mmsi' => '211555000']],
                ['text' => 'Ruf die Seestern aus dem Adressbuch an: Routine, Kanal 77.', 'target' => 'menu', 'help' => 'MENU, „Individual Call“, „SEESTERN“, „Routine“, Kanal 77, ENTER zum Senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'individual', 'address.mmsi' => '211555000', 'category' => 'Routine', 'channel' => 77]],
                $waitForAck + ['goal' => ['type' => 'channel', 'channel' => 77], 'receive' => ['delay_ms' => 4000, 'call' => ['type' => 'individual_ack', 'from' => $seestern]]],
            ],
        ],
        [
            'key' => 'position-polling',
            'unit' => 'DSC-Routine',
            'title' => 'Positionsabfrage und Polling',
            'intro' => 'Frag per DSC die Position der Möwe ab. Danach prüft Bremen Rescue per Polling, ob du erreichbar bist: Beantworte die Abfrage.',
            'start' => $startWithPosition,
            'steps' => [
                ['text' => 'Sende eine Positionsabfrage an die Möwe.', 'target' => 'menu', 'help' => 'MENU, „Position Request“, „MOEWE“, ENTER, ENTER zum Senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'position_request', 'address.name' => 'MOEWE']],
                ['text' => 'Die Antwort kommt gleich. Schalte den Ton aus und lass dir die Position anzeigen.', 'target' => 'enter', 'help' => 'CLR schaltet den Ton aus, ENTER zeigt die Position.', 'goal' => ['type' => 'position_info'], 'receive' => ['delay_ms' => 3000, 'call' => ['type' => 'position_reply', 'from' => $moewe, 'position' => [54, 12, 'N', 7, 40, 'E']]]],
                ['text' => 'Bremen Rescue sendet ein Polling. Beantworte es.', 'target' => 'menu', 'help' => 'CLR (Ton aus), CLR, dann MENU, „Polling Reply“, ENTER zum Senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'polling_reply'], 'receive' => ['delay_ms' => 1500, 'call' => ['type' => 'polling_request', 'from' => $bremenRescue]]],
            ],
        ],

        // --- 5 Notverkehr ----------------------------------------------------
        [
            'key' => 'notalarm-senden',
            'unit' => 'Notverkehr',
            'title' => 'Notalarm senden',
            'pruefung' => '1.a',
            'intro' => 'Die Kormoran hat einen starken Wassereinbruch, die Pumpen kommen nicht nach. Position 54°10,000\'N 007°52,000\'E, 12:30 UTC. Sende einen Notalarm mit der Art der Not „Flooding“.',
            'start' => $start,
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
            'start' => $startWithPosition,
            'steps' => [
                ['text' => 'Halte die Sprechtaste gedrückt und sprich die Notmeldung.', 'target' => 'ptt', 'help' => 'MAYDAY, MAYDAY, MAYDAY, this is Kormoran (dreimal), DH4721, MMSI 211472100. MAYDAY Kormoran, DH4721, MMSI 211472100, my position …, I am taking in water, I require immediate assistance, 3 persons on board, over.', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1230', 'kind' => 'distress', 'nature' => 'flooding', 'persons' => 3]]],
                ['text' => 'Hör die Antwort von Bremen Rescue.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'BREMEN RESCUE'], 'voice' => ['delay_ms' => 2500, 'from' => 'BREMEN RESCUE', 'channel' => 16, 'text' => 'Mayday Kormoran, Kormoran, Kormoran, this is Bremen Rescue Radio, received mayday. A rescue boat is on the way, estimated time of arrival twenty minutes. Over.']],
            ],
        ],
        [
            'key' => 'notalarm-empfangen',
            'unit' => 'Notverkehr',
            'title' => 'Notalarm empfangen und per Sprechfunk bestätigen',
            'pruefung' => '1.b',
            'intro' => 'Dein Gerät empfängt einen Notalarm der Yacht Seestern. Hör auf Kanal 16 mit. Bestätigt keine Küstenfunkstelle, bestätigst du selbst per Sprechfunk – am IC-M505 gibt es keine DSC-Bestätigung. Du bist 4 Seemeilen entfernt.',
            'start' => ['channel' => 72] + $startWithPosition,
            'steps' => [
                ['text' => 'Warte auf den Alarm und schalte den Alarmton aus.', 'target' => 'clr', 'help' => 'Das Gerät schaltet selbst auf Kanal 16. CLR schaltet den Ton aus.', 'goal' => ['type' => 'alarm_stopped', 'call' => 'distress'], 'receive' => ['delay_ms' => 1500, 'call' => ['type' => 'distress', 'from' => $seestern, 'nature' => 'Flooding', 'position' => [54, 14, 'N', 7, 48, 'E']]]],
                ['text' => 'Hör die Notmeldung der Seestern auf Kanal 16.', 'target' => null, 'help' => 'Nur zuhören und mitschreiben: Name, Position, Art der Not, Personen.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'SEESTERN'], 'voice' => ['delay_ms' => 2000, 'from' => 'SEESTERN', 'channel' => 16, 'text' => $seesternMayday]],
                ['text' => 'Bremen Rescue bestätigt nicht. Bestätige du den Empfang auf Kanal 16.', 'target' => 'ptt', 'help' => 'MAYDAY, Seestern (dreimal), this is Kormoran (dreimal), DH4721, received MAYDAY, my position …, I am 4 nautical miles from your position and proceeding to assist, over.', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1236', 'kind' => 'acknowledge', 'distressed' => $seesternInDistress, 'distance' => 4]]],
            ],
        ],
        [
            'key' => 'mayday-relay',
            'unit' => 'Notverkehr',
            'title' => 'Notmeldung weiterleiten (MAYDAY RELAY)',
            'pruefung' => '2.b',
            'intro' => 'Du hörst die Notmeldung der Seestern. Niemand bestätigt, auch nicht Bremen Rescue. Du kannst selbst nicht helfen: Leite die Notmeldung per Sprechfunk an Bremen Rescue weiter – am IC-M505 gibt es kein DSC-Relay.',
            'start' => $startWithPosition,
            'steps' => [
                ['text' => 'Hör die Notmeldung der Seestern.', 'target' => null, 'help' => 'Nur zuhören und mitschreiben.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'SEESTERN'], 'voice' => ['delay_ms' => 1500, 'from' => 'SEESTERN', 'channel' => 16, 'text' => $seesternMayday]],
                ['text' => 'Leite die Notmeldung auf Kanal 16 an Bremen Rescue weiter.', 'target' => 'ptt', 'help' => 'MAYDAY RELAY (dreimal), Bremen Rescue Radio (dreimal), this is Kormoran (dreimal), DH4721, MMSI 211472100, following received from Seestern … at 1235 UTC, MAYDAY Seestern …, position …, taking in water, requires immediate assistance, 2 persons on board, over.', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1237', 'kind' => 'relay', 'distressed' => $seesternInDistress]]],
                ['text' => 'Hör die Antwort von Bremen Rescue.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'BREMEN RESCUE'], 'voice' => ['delay_ms' => 2500, 'from' => 'BREMEN RESCUE', 'channel' => 16, 'text' => 'Mayday relay Kormoran, this is Bremen Rescue Radio, received mayday relay concerning Seestern. Over.']],
            ],
        ],
        [
            'key' => 'funkstille',
            'unit' => 'Notverkehr',
            'title' => 'Funkstille gebieten',
            'intro' => 'Die Kormoran ist in Not und führt auf Kanal 16 Notverkehr mit Bremen Rescue. Eine Yacht stört mit einem Routinegespräch. Gebiete ihr Funkstille.',
            'start' => $startWithPosition,
            'steps' => [
                ['text' => 'Hör, wer auf Kanal 16 stört.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'ALBATROS'], 'voice' => ['delay_ms' => 1500, 'from' => 'ALBATROS', 'channel' => 16, 'text' => 'Moewe, Moewe, this is Albatros, are you coming to Helgoland tonight? Over.']],
                ['text' => 'Gebiete Funkstille.', 'target' => 'ptt', 'help' => '„Albatros, this is Kormoran, seelonce mayday.“', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['checks' => [['SEELONCE MAYDAY', 'SEELONCE MAYDAY|SILENCE MAYDAY|SEE LONCE MAYDAY', 1], $kormoran], 'solution' => 'Albatros, this is Kormoran, seelonce mayday.']],
            ],
        ],
        [
            'key' => 'notverkehr-beenden',
            'unit' => 'Notverkehr',
            'title' => 'Notverkehr beenden',
            'pruefung' => '3.a',
            'intro' => 'Die Seenotretter haben die Kormoran in Schlepp genommen, niemand ist mehr in Gefahr. Du hast den Notverkehr geleitet. Beende ihn um 13:15 UTC auf Kanal 16.',
            'start' => $startWithPosition,
            'steps' => [
                ['text' => 'Beende den Notverkehr an alle Funkstellen.', 'target' => 'ptt', 'help' => 'MAYDAY, all stations (dreimal), this is Kormoran (dreimal), DH4721, 1315 UTC, Kormoran, seelonce feenee, out.', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['checks' => [
                    ['MAYDAY', '\bMAYDAY\b', 1],
                    ['ALL STATIONS dreimal', 'ALL STATIONS', 3],
                    $thisIs,
                    ['Name dreimal (Kormoran)', '\bKORMORAN\b', 3],
                    ['Uhrzeit (13:15 UTC)', '1315|13 15|ONE THREE ONE FIVE|THIRTEEN FIFTEEN', 1],
                    ['SEELONCE FEENEE', 'SEELONCE FEENEE|SILENCE FINI|SEELONCE FINI', 1],
                    $out,
                ], 'solution' => 'Mayday, all stations, all stations, all stations, this is Kormoran, Kormoran, Kormoran, Delta Hotel Four Seven Two One, one three one five UTC, Kormoran, seelonce feenee, out.']],
            ],
        ],
        [
            'key' => 'fehlalarm-aufheben',
            'unit' => 'Notverkehr',
            'title' => 'Fehlalarm aufheben',
            'pruefung' => '3.b',
            'intro' => 'Beim Reinigen der Funkanlage hast du um 12:41 UTC versehentlich einen Notalarm ausgelöst. Das Gerät wartet auf eine Bestätigung und würde den Alarm wiederholen.',
            'start' => ['position' => [54, 10.0, 'N', 7, 52.0, 'E'], 'utc' => '12:41', 'distressWaiting' => 'Undesignated'] + $start,
            'steps' => [
                ['text' => 'Brich die automatische Wiederholung des Notalarms ab. Schalte das Gerät dabei nicht aus.', 'target' => 'clr', 'help' => 'CLR drücken. Am IC-M505 gibt es keinen DSC-Widerruf; aufgehoben wird per Sprechfunk.', 'goal' => ['type' => 'distress_repeat_cancelled']],
                ['text' => 'Hebe den Fehlalarm auf Kanal 16 an alle Funkstellen auf.', 'target' => 'ptt', 'help' => 'All stations (dreimal), this is Kormoran (dreimal), DH4721, MMSI 211472100, position …, cancel my distress alert of 12:41 UTC, over.', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1241', 'kind' => 'false_alert', 'alert_time' => '1241']]],
            ],
        ],

        // --- 6 Dringlichkeit und Sicherheit ---------------------------------
        [
            'key' => 'dringlichkeit',
            'unit' => 'Dringlichkeit und Sicherheit',
            'title' => 'Dringlichkeitsmeldung (PAN PAN)',
            'pruefung' => '4.a',
            'intro' => 'Ruderbruch: Die Kormoran ist manövrierunfähig, aber nicht in unmittelbarer Gefahr. 2 Personen an Bord, 13:00 UTC. Kündige die Dringlichkeitsmeldung per DSC an alle an und sprich sie auf Kanal 16 mit 25 W.',
            'start' => ['utc' => '13:00'] + $startWithPosition,
            'steps' => [
                ['text' => 'Sende einen DSC-Anruf an alle Funkstellen mit der Kategorie „Urgency“.', 'target' => 'menu', 'help' => 'MENU, „All Ships Call“, ENTER, „Urgency“, ENTER, ENTER zum Senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'all_ships', 'category' => 'Urgency']],
                ['text' => 'Sprich die Dringlichkeitsmeldung auf Kanal 16 mit 25 W.', 'target' => 'ptt', 'help' => 'PAN PAN (dreimal), all stations (dreimal), this is Kormoran (dreimal), DH4721, MMSI 211472100, my position … at 1300 UTC, my rudder is broken, I am not under command, I require towing assistance, 2 persons on board, over.', 'goal' => ['type' => 'spoken', 'channel' => 16, 'highPower' => true], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1300', 'kind' => 'urgency', 'problem' => 'rudder', 'persons' => 2]]],
            ],
        ],
        [
            'key' => 'dringlichkeit-aufheben',
            'unit' => 'Dringlichkeit und Sicherheit',
            'title' => 'Dringlichkeitsmeldung aufheben',
            'intro' => 'Um 13:00 UTC hast du eine Dringlichkeitsmeldung wegen Maschinenausfalls gesendet. Inzwischen läuft die Maschine wieder. Hebe die Meldung auf: Ankündigung per DSC wie bei der Meldung, dann Sprechfunk auf Kanal 16.',
            'start' => ['utc' => '13:20'] + $startWithPosition,
            'steps' => [
                ['text' => 'Kündige die Aufhebung per DSC an alle Funkstellen an (Kategorie „Urgency“).', 'target' => 'menu', 'help' => 'MENU, „All Ships Call“, „Urgency“, ENTER zum Senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'all_ships', 'category' => 'Urgency']],
                ['text' => 'Hebe die Dringlichkeitsmeldung auf Kanal 16 auf.', 'target' => 'ptt', 'help' => 'PAN PAN (dreimal), all stations (dreimal), this is Kormoran (dreimal), DH4721, MMSI 211472100, cancel my urgency message of 1300 UTC, my engine is running again, out.', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1320', 'kind' => 'urgency_cancel', 'problem' => 'engine', 'reason' => 'engine', 'urgency_time' => '1300']]],
            ],
        ],
        [
            'key' => 'sicherheit',
            'unit' => 'Dringlichkeit und Sicherheit',
            'title' => 'Sicherheitsmeldung (SECURITE)',
            'pruefung' => '4.b',
            'intro' => 'Um 13:10 UTC sichtest du einen treibenden Container auf Position 54°12\'N 007°45\'E, eine Gefahr für die Schifffahrt. Kündige die Sicherheitsmeldung per DSC an alle an und sprich sie auf Kanal 16.',
            'start' => ['utc' => '13:10'] + $startWithPosition,
            'steps' => [
                ['text' => 'Sende einen DSC-Anruf an alle Funkstellen mit der Kategorie „Safety“.', 'target' => 'menu', 'help' => 'MENU, „All Ships Call“, „Safety“, ENTER zum Senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'all_ships', 'category' => 'Safety']],
                ['text' => 'Sprich die Sicherheitsmeldung auf Kanal 16.', 'target' => 'ptt', 'help' => 'SECURITE (dreimal), all stations (dreimal), this is Kormoran (dreimal), DH4721, MMSI 211472100, navigational warning, drifting container in position … at 1310 UTC, danger to navigation, out.', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1310', 'kind' => 'safety', 'hazard' => 'container', 'hazard_pos' => [54, 12, 'N', 7, 45, 'E']]]],
            ],
        ],
        [
            'key' => 'dringlichkeit-empfangen',
            'unit' => 'Dringlichkeit und Sicherheit',
            'title' => 'Dringlichkeit und Sicherheit empfangen',
            'intro' => 'Du arbeitest auf Kanal 72. Bremen Rescue kündigt per DSC eine Dringlichkeitsmeldung an alle an. Geh auf Kanal 16 und hör sie mit.',
            'start' => ['channel' => 72] + $startWithPosition,
            'steps' => [
                ['text' => 'Warte auf den Anruf und schalte den Alarmton aus.', 'target' => 'clr', 'help' => 'Bei „Received All Ships“: CLR drücken.', 'goal' => ['type' => 'alarm_stopped', 'call' => 'all_ships'], 'receive' => ['delay_ms' => 1500, 'call' => ['type' => 'all_ships', 'from' => $bremenRescue, 'category' => 'Urgency', 'channel' => 16]]],
                ['text' => 'Geh auf den angekündigten Kanal 16.', 'target' => 'enter', 'help' => 'ENTER drücken: Das Gerät schaltet auf Kanal 16.', 'goal' => ['type' => 'call_accepted', 'channel' => 16]],
                ['text' => 'Hör die Dringlichkeitsmeldung.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'BREMEN RESCUE'], 'voice' => ['delay_ms' => 2000, 'from' => 'BREMEN RESCUE', 'channel' => 16, 'text' => 'Pan pan, pan pan, pan pan. All stations, all stations, all stations. This is Bremen Rescue Radio. Motor yacht Albatros with engine failure drifting in position 54 degrees 08 minutes north, 007 degrees 55 minutes east. Vessels in the vicinity keep a sharp lookout and report to Bremen Rescue Radio. Out.']],
            ],
        ],

        // --- 7 Sonderfälle ---------------------------------------------------
        [
            'key' => 'medico',
            'unit' => 'Sonderfälle',
            'title' => 'Funkärztliche Beratung (MEDICO)',
            'intro' => 'Ein Crewmitglied hat hohes Fieber und starke Bauchschmerzen, keine akute Lebensgefahr. Bitte über Bremen Rescue um funkärztliche Beratung: DSC-Einzelanruf mit Kategorie „Urgency“, Folgekanal 16, dann Dringlichkeitsmeldung. 2 Personen an Bord, 13:30 UTC.',
            'start' => ['utc' => '13:30'] + $startWithPosition,
            'steps' => [
                ['text' => 'Sende einen Einzelanruf an Bremen Rescue mit der Kategorie „Urgency“ und Kanal 16.', 'target' => 'menu', 'help' => 'MENU, „Individual Call“, „BREMEN RESCUE“, „Urgency“, Kanal 16, ENTER zum Senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'individual', 'address.mmsi' => '002111240', 'category' => 'Urgency', 'channel' => 16]],
                $waitForAck + ['goal' => ['type' => 'dsc_received', 'call.type' => 'individual_ack'], 'receive' => ['delay_ms' => 3000, 'call' => ['type' => 'individual_ack', 'from' => $bremenRescue]]],
                ['text' => 'Sprich die Dringlichkeitsmeldung an Bremen Rescue auf Kanal 16.', 'target' => 'ptt', 'help' => 'PAN PAN (dreimal), Bremen Rescue Radio (dreimal), this is Kormoran (dreimal), DH4721, MMSI 211472100, my position … at 1330 UTC, one crew member with high fever and severe abdominal pain, I require medical advice, 2 persons on board, over.', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1330', 'kind' => 'urgency', 'problem' => 'advice', 'persons' => 2]]],
                ['text' => 'Hör die Antwort von Bremen Rescue.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'BREMEN RESCUE'], 'voice' => ['delay_ms' => 2500, 'from' => 'BREMEN RESCUE', 'channel' => 16, 'text' => 'Kormoran, this is Bremen Rescue Radio. I will connect you with the medical advice service. Stand by on channel one six. Over.']],
            ],
        ],
        [
            'key' => 'gebietsanruf',
            'unit' => 'Sonderfälle',
            'title' => 'Gebietsanruf einer Küstenfunkstelle empfangen',
            'intro' => 'Nordsee Radio ruft per DSC alle Schiffe in der Deutschen Bucht (Gebietsanruf, Kategorie Safety) und kündigt eine Sturmwarnung auf Kanal 26 an.',
            'start' => $startWithPosition,
            'steps' => [
                ['text' => 'Warte auf den Anruf und schalte den Ton aus.', 'target' => 'clr', 'help' => 'Bei „Received Geographic“: CLR drücken.', 'goal' => ['type' => 'alarm_stopped', 'call' => 'geographic'], 'receive' => ['delay_ms' => 1500, 'call' => ['type' => 'geographic', 'from' => $nordseeRadio, 'category' => 'Safety', 'channel' => 26]]],
                ['text' => 'Geh auf den angekündigten Kanal 26.', 'target' => 'enter', 'help' => 'ENTER drücken.', 'goal' => ['type' => 'call_accepted', 'channel' => 26]],
                ['text' => 'Hör die Sturmwarnung.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'NORDSEE RADIO'], 'voice' => ['delay_ms' => 2000, 'from' => 'NORDSEE RADIO', 'channel' => 26, 'text' => 'Securite, securite, securite. All ships in the German Bight, this is Nordsee Radio. Gale warning: west seven to eight, increasing nine later. Out.']],
            ],
        ],

        // --- 8 Prüfungsszenarien (ganze Gespräche) ---------------------------
        [
            'key' => 'szenario-notverkehr',
            'unit' => 'Prüfungsszenarien',
            'title' => 'Eigener Notverkehr komplett',
            'intro' => 'Explosion im Motorraum, danach Feuer. 3 Personen an Bord, Position 54°10,000\'N 007°52,000\'E, 12:30 UTC. Notalarm mit Art der Not, Bestätigung abwarten, Notmeldung sprechen.',
            'start' => $start,
            'steps' => [
                ['text' => 'Wähle die Art der Not „Explosion“ und gib Position und Uhrzeit ein.', 'target' => 'menu', 'help' => 'MENU, „Distress Setting“, „Explosion“, ENTER; Position und 12:30 eingeben.', 'goal' => ['type' => 'utc_set', 'utc' => '12:30']],
                ['text' => 'Sende den Notalarm.', 'target' => 'distress', 'help' => 'Klappe öffnen, DISTRESS fünf Sekunden halten.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'distress', 'nature' => 'Explosion']],
                ['text' => 'Warte auf die Bestätigung und schalte den Alarmton aus.', 'target' => 'clr', 'help' => 'Nach „Received DistressACK“ CLR drücken.', 'goal' => ['type' => 'alarm_stopped', 'call' => 'distress_ack'], 'receive' => ['delay_ms' => 5000, 'call' => ['type' => 'distress_ack', 'from' => $bremenRescue]]],
                ['text' => 'Sprich die Notmeldung auf Kanal 16.', 'target' => 'ptt', 'help' => 'MAYDAY (dreimal), this is Kormoran (dreimal), DH4721, MMSI 211472100, MAYDAY Kormoran …, my position … at 1230 UTC, explosion in the engine room, I am on fire, I require immediate assistance, 3 persons on board, over.', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1230', 'kind' => 'distress', 'nature' => 'explosion', 'persons' => 3]]],
                ['text' => 'Hör die Antwort von Bremen Rescue.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'BREMEN RESCUE'], 'voice' => ['delay_ms' => 2500, 'from' => 'BREMEN RESCUE', 'channel' => 16, 'text' => 'Mayday Kormoran, this is Bremen Rescue Radio, received mayday. Rescue vessel proceeding, estimated time of arrival fifteen minutes. Over.']],
            ],
        ],
        [
            'key' => 'szenario-relay',
            'unit' => 'Prüfungsszenarien',
            'title' => 'Notalarm empfangen und weiterleiten',
            'intro' => 'Dein Gerät empfängt einen Notalarm der Seestern, danach hörst du ihre Notmeldung. Niemand bestätigt, und du kannst nicht helfen.',
            'start' => ['channel' => 72] + $startWithPosition,
            'steps' => [
                ['text' => 'Schalte den Alarmton aus, das Gerät steht auf Kanal 16.', 'target' => 'clr', 'help' => 'CLR drücken.', 'goal' => ['type' => 'alarm_stopped', 'call' => 'distress'], 'receive' => ['delay_ms' => 1500, 'call' => ['type' => 'distress', 'from' => $seestern, 'nature' => 'Flooding', 'position' => [54, 14, 'N', 7, 48, 'E']]]],
                ['text' => 'Hör die Notmeldung.', 'target' => null, 'help' => 'Nur zuhören und mitschreiben.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'SEESTERN'], 'voice' => ['delay_ms' => 2000, 'from' => 'SEESTERN', 'channel' => 16, 'text' => $seesternMayday]],
                ['text' => 'Leite die Notmeldung an Bremen Rescue weiter.', 'target' => 'ptt', 'help' => 'MAYDAY RELAY (dreimal), Bremen Rescue Radio (dreimal), this is Kormoran …, following received from Seestern …, over.', 'goal' => ['type' => 'spoken', 'channel' => 16], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1237', 'kind' => 'relay', 'distressed' => $seesternInDistress]]],
            ],
        ],
        [
            'key' => 'szenario-yacht',
            'unit' => 'Prüfungsszenarien',
            'title' => 'Gespräch mit einer Yacht',
            'intro' => 'Du möchtest mit der Möwe einen gemeinsamen Liegeplatz in Helgoland absprechen. Ruf sie per DSC (Routine, Kanal 77) und führ das Gespräch bis zum Ende.',
            'start' => $start,
            'steps' => [
                ['text' => 'Ruf die Möwe per DSC: Routine, Kanal 77.', 'target' => 'menu', 'help' => 'MENU, „Individual Call“, „MOEWE“, „Routine“, Kanal 77, senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'individual', 'address.name' => 'MOEWE', 'category' => 'Routine', 'channel' => 77]],
                $waitForAck + ['goal' => ['type' => 'channel', 'channel' => 77], 'receive' => ['delay_ms' => 4000, 'call' => ['type' => 'individual_ack', 'from' => $moewe]]],
                ['text' => 'Schalte auf 1 W und sprich die Möwe auf Kanal 77 an.', 'target' => 'ptt', 'help' => '„Moewe, Moewe, this is Kormoran, Kormoran, DH4721, I will arrive in Helgoland at about 1700 UTC, can I moor alongside you? Over.“', 'goal' => ['type' => 'spoken', 'channel' => 77, 'highPower' => false], 'speech' => ['scenario' => $ship + ['pos' => $position, 'time' => '1400', 'kind' => 'routine', 'called' => ['vessel' => 'MOEWE', 'callsign' => 'DK5512', 'mmsi' => '211839200'], 'topic' => 'berth', 'channel' => 77]]],
                ['text' => 'Hör die Antwort.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'MOEWE'], 'voice' => ['delay_ms' => 2500, 'from' => 'MOEWE', 'channel' => 77, 'text' => 'Kormoran, this is Moewe, yes, you are welcome, we are in the south harbour, over.']],
                ['text' => 'Bedanke dich und beende das Gespräch.', 'target' => 'ptt', 'help' => '„Moewe, this is Kormoran, thank you, see you later, out.“', 'goal' => ['type' => 'spoken', 'channel' => 77], 'speech' => ['checks' => [$moeweCheck, $thisIs, $kormoran, $out], 'solution' => 'Moewe, this is Kormoran, thank you, see you later, out.']],
            ],
        ],
        [
            'key' => 'szenario-kuestenfunkstelle',
            'unit' => 'Prüfungsszenarien',
            'title' => 'Gespräch mit einer Küstenfunkstelle',
            'intro' => 'Melde Nordsee Radio deine voraussichtliche Ankunftszeit in Helgoland (1700 UTC): DSC-Einzelanruf (Routine, Kanal 26), Gespräch, Ende.',
            'start' => $start,
            'steps' => [
                ['text' => 'Ruf Nordsee Radio per DSC: Routine, Kanal 26.', 'target' => 'menu', 'help' => 'MENU, „Individual Call“, „NORDSEE RADIO“, „Routine“, Kanal 26, senden.', 'goal' => ['type' => 'dsc_sent', 'kind' => 'individual', 'address.mmsi' => '002119999', 'category' => 'Routine', 'channel' => 26]],
                $waitForAck + ['goal' => ['type' => 'channel', 'channel' => 26], 'receive' => ['delay_ms' => 4000, 'call' => ['type' => 'individual_ack', 'from' => $nordseeRadio]]],
                ['text' => 'Melde dich auf Kanal 26 und nenne dein Anliegen.', 'target' => 'ptt', 'help' => '„Nordsee Radio, this is Kormoran, DH4721, my estimated time of arrival in Helgoland is 1700 UTC, over.“', 'goal' => ['type' => 'spoken', 'channel' => 26], 'speech' => ['checks' => [['Küstenfunkstelle Nordsee Radio', 'NORDSEE', 1], $thisIs, $kormoran, ['Ankunftszeit', 'ARRIVAL|\bETA\b|ANKUNFT', 1], ['Uhrzeit 1700', '1700|17 00|ONE SEVEN ZERO ZERO|SEVENTEEN HUNDRED', 1], $over], 'solution' => 'Nordsee Radio, this is Kormoran, Delta Hotel Four Seven Two One, my estimated time of arrival in Helgoland is one seven zero zero UTC, over.']],
                ['text' => 'Hör die Antwort.', 'target' => null, 'help' => 'Nur zuhören.', 'goal' => ['type' => 'voice', 'heard' => true, 'from' => 'NORDSEE RADIO'], 'voice' => ['delay_ms' => 2000, 'from' => 'NORDSEE RADIO', 'channel' => 26, 'text' => 'Kormoran, this is Nordsee Radio, received, your estimated time of arrival Helgoland one seven zero zero. Out.']],
            ],
        ],
    ],
];
