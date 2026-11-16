> **ENTWURF – vor Verwendung anwaltlich prüfen lassen; keine Rechtsberatung**

# Erklärung zur Barrierefreiheit

[Name der Bootsschule] bzw. [Betreiber] ist bemüht, die Lernplattform
„[Plattformname]“ (Web: [Adresse], Lern-App: [Store-Links]) barrierefrei
zugänglich zu machen. Grundlage sind die Web Content Accessibility Guidelines
(**WCAG) 2.1, Konformitätsstufe AA** bzw. die harmonisierte Norm EN 301 549.

[Anwalt: Prüfen, ob und für wen das Barrierefreiheitsstärkungsgesetz (BFSG,
seit 28.06.2025) gilt – Kleinstunternehmen-Ausnahme, Dienstleistung im
elektronischen Geschäftsverkehr gegenüber Verbrauchern – und wer die Erklärung
abgibt (Schule oder Betreiber).]

## Stand der Vereinbarkeit

Die Plattform ist **teilweise** mit WCAG 2.1 AA vereinbar. Die Bewertung beruht
auf einer **Selbstbewertung** des Betreibers anhand des Quellcodes ([Datum]);
eine Prüfung durch eine unabhängige Stelle steht aus.

Bereits umgesetzt (Auswahl):

- Seitensprache Deutsch ausgezeichnet (`lang="de"`) auf allen Seiten inkl.
  Fehler-, Wartungs- und Schulwahlseiten; Überschriftenstruktur mit genau einer
  sichtbaren H1 je Seite und Zwischenüberschriften ohne übersprungene Ebenen
  (Lernbereich und Admin-Bereich).
- Aussagekräftige Seitentitel aus der Seitenüberschrift, auch auf Anmelde-,
  Registrierungs- und Superadmin-Seiten (WCAG 2.4.2).
- „Zum Inhalt springen“-Link in allen Layouts (Lernplattform, Anmeldeseiten,
  Superadmin, Rechtstexte, Fehlerseiten); das Sprungziel `<main id="inhalt">`
  schließt die Seitenüberschrift ein und nimmt den Fokus an (WCAG 2.4.1).
  Alle Inhalte liegen in Landmarks (Kopf-, Haupt-, Fußbereich, Navigation).
- Beschriftete Formularfelder, beschriftete Symbol-Links (z. B. „Zurück zum
  Kurs“). Fehlermeldungen der Anmelde-, Registrierungs-, Passwort- und
  Profilformulare, der Code-Einlösung und der Teilnehmer-/Team-Einladung sind
  per `aria-invalid`/`aria-describedby` mit dem Feld verknüpft (WCAG 3.3.1).
- Bilder in Fragen und Navigationsaufgaben mit Alternativtext; fehlt ein
  gepflegter Alternativtext, wird das Bild als „Abbildung zur Frage (ohne
  Bildbeschreibung)“ angesagt statt als Dekoration versteckt.
  Kartenausschnitte mit Bildunterschrift; reine Schmuck-Icons sind für
  Screenreader ausgeblendet.
- Fortschrittsbalken und Diagramme (Kursfortschritt, Admin-Statistik,
  Phasenbalken im Admin-Dashboard) stehen neben dem Zahlenwert als Text; der
  Balken selbst ist ausgeblendet bzw. als `progressbar` mit Wert ausgezeichnet.
- Sichtbarer Tastaturfokus für Links, Knöpfe, Reiter und scrollbare Tabellen
  (WCAG 2.4.7). Die Reiter im Admin-Bereich folgen dem WAI-ARIA-Tab-Muster
  (Pfeiltasten, Pos1/Ende, verknüpfte Bereiche); aufklappbare Bereiche melden
  ihren Zustand (`aria-expanded`); Menüs schließen per Escape und geben den
  Fokus an den Auslöser zurück; nach „Musterantwort/-lösung anzeigen“ springt
  der Fokus auf die Antwort. Breite Tabellen sind per Tastatur scrollbar.
- Dialoge: Bestätigungsdialoge halten den Fokus im Dialog, schließen per
  Escape und geben den Fokus zurück; der Einwilligungsdialog zur
  Spracherkennung startet auf „Nein, lieber tippen“.
- Restzeit der Prüfungssimulation wird zusätzlich für Screenreader angesagt
  (5 und 1 Minute vor Ablauf); das Zeitlimit wird vor dem Start angekündigt.
- Systemeinstellung „Bewegung reduzieren“ schaltet Übergänge und Animationen ab.
- Dunkelmodus; Schriftfarbe auf der Markenfarbe der Schule wird nach Kontrast
  automatisch hell oder dunkel gewählt. Kontraste der Standardfarben im
  Hell- und Dunkelmodus automatisiert geprüft (axe-core, Hauptseiten).
- Darstellung auf kleinen Bildschirmen (360 px) ohne waagerechtes Scrollen.
- Spracheingabe in den Funkübungen ist freiwillig; alles lässt sich auch tippen.

## Nicht barrierefreie Inhalte (bekannte Lücken)

1. **Videos ohne Untertitel und ohne Audiodeskription** (WCAG 1.2.2, 1.2.3/1.2.5):
   Für die Lernvideos gibt es derzeit keine Untertitel-Spuren und keine
   Textalternative. Noch nicht produzierte Videos zeigen „Video folgt“.
   [Untertitel müssen erstellt bzw. eingekauft werden – inhaltliche Entscheidung.]
2. **Zeitbegrenzung der Prüfungssimulation** (WCAG 2.2.1): Die Prüfungszeit
   entspricht der amtlichen Prüfung und lässt sich nicht verlängern; die
   Simulation soll die echten Prüfungsbedingungen abbilden (Ausnahme
   „wesentlich“ nach 2.2.1 – [Anwalt/Fachlich prüfen]). Das Zeitlimit wird vor
   dem Start angekündigt, 5 und 1 Minute vor Ablauf gibt es einen Hinweis (auch
   für Screenreader). Die Lernmodi haben keine Zeitbegrenzung. Eine
   Zeitverlängerung (Nachteilsausgleich) je Teilnehmer oder Schule gibt es
   nicht [Produktentscheidung offen].
3. **Funk-Gerätesimulation** (Beta): Bedienung des simulierten Funkgeräts über
   Zeigergesten (Drehen/Ziehen) und Töne; eine gleichwertige Bedienung nur per
   Tastatur und Screenreader ist nicht sichergestellt (WCAG 2.1.1, 2.5.1).
4. **Navigationsaufgaben mit Seekarten**: Die Aufgaben setzen das Arbeiten mit
   einer Papier-Seekarte voraus; der Kartenausschnitt hat einen kurzen
   Alternativtext, aber keine gleichwertige Textbeschreibung (WCAG 1.1.1).
5. **Alternativtexte der Fragenbilder** hängen von der Inhaltspflege ab und sind
   nicht für alle Bilder vorhanden (WCAG 1.1.1); Bilder ohne Beschreibung
   werden als solche angesagt.
6. **Markenfarben der Schulen**: Schulen wählen ihre Farben selbst; Kontraste
   von Links und Flächen in der Markenfarbe werden nicht vollständig geprüft
   (WCAG 1.4.3, 1.4.11).
7. **Statusmeldungen** (z. B. „gespeichert“) werden auf den Hauptseiten als
   Live-Region angesagt, aber nicht in allen per JavaScript aktualisierten
   Bereichen (WCAG 4.1.3), z. B. der Hinweis „Mehr Plätze als freie
   Kurscodes“ beim Anlegen einer Lerngruppe.
8. **Superadmin-Bereich** (nur Betreiber): nicht vollständig geprüft;
   Fehlermeldungen einzelner Formulare sind nicht mit dem Feld verknüpft.
9. **Lern-App**: [Stand der App gesondert bewerten].

Diese Liste wird bei der Behebung einzelner Punkte aktualisiert.

## Erstellung dieser Erklärung

Erstellt am [Datum], zuletzt überprüft am [Datum], Methode: Selbstbewertung.

## Feedback und Kontakt

Sind dir Mängel aufgefallen oder brauchst du einen Inhalt in einer anderen
Form? Schreib uns:

- E-Mail: [Barrierefreiheits-Kontakt, z. B. Support-Adresse der Schule bzw. des Betreibers]
- Telefon: [Telefonnummer]
- Post: [Anschrift]

Wir antworten innerhalb von [zwei Wochen].

## Schlichtungs- und Durchsetzungsverfahren

Wenn du auf deine Rückmeldung keine zufriedenstellende Antwort bekommst, kannst
du dich an folgende Stelle wenden: [Schlichtungsstelle bzw. zuständige
Marktüberwachungsbehörde nach BFSG, z. B. Marktüberwachungsstelle der Länder für
die Barrierefreiheit von Produkten und Dienstleistungen (MLBF), Anschrift, Web]
[Bei öffentlichen Stellen: Schlichtungsstelle nach § 16 BGG bzw. Landesrecht].
