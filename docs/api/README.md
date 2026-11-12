# Mobile-API v1 (Lernenden-App)

Schnittstelle für die geplanten Handy-Apps (iOS/Android). Die App nutzt
**dieselben Daten und dieselbe Lern- und Prüfungslogik** wie die Web-App:
Fortschritt, Favoriten, Prüfungsversuche und Zugänge sind auf beiden Wegen
dieselben.

| Datei | Inhalt |
|---|---|
| `docs/api/openapi-mobile-v1.yaml` | Vertrag (OpenAPI 3.1), alle 82 Endpunkte |
| `routes/api.php` | Routen (Präfix `/api/v1`) |
| `app/Http/Controllers/Api/V1/` | Controller je Bereich |
| `app/Api/` | Tokens, Fehlerformat, Ausgabeformate |
| `app/Http/Middleware/Api/` | Anmeldung, Schule, Idempotenz |
| `config/mobile_api.php` | Laufzeiten (Tokens, Export, Idempotenz) |
| `tests/Feature/Api/` | Tests inkl. Abgleich Routen ↔ OpenAPI |


### Kunden-FAQs aus WordPress übertragen

Der WP-Export liefert veröffentlichte Kunden-FAQs, Kategorien und Kurszuordnungen
im JSON-Format des Laravel-Imports. Nach dem Export die Datei ins Laravel-Projekt
übertragen und dort importieren:

\`\`\`sh
wp bootspruefung export customer-faqs > mobile-faqs.json
php artisan mobile-api:import-faqs storage/app/import/mobile-faqs.json
\`\`\`

Der Import aktualisiert Einträge über stabile Quellschlüssel, erhält gefilterte
HTML-Antworten und löscht keine Datensätze, die im Export fehlen. Die FAQ-API
gibt nur veröffentlichte Fragen für freigeschaltete Kurse aus.

`GET /tips` liefert aktive Tipps und Tricks nach Kategorie. Die Inhalte sind plattformweit, erfordern aber den angemeldeten Schulkontext; ein einzelner Kurszugang ist nicht nötig. HTML, Text und sichere Links stehen gemeinsam zur Verfügung.

`GET /courses/{id}/mistakes` listet falsch beantwortete, noch gültige Kursfragen samt Favoriten- und Sammlungsstatus. Ein Kurszugang ist erforderlich.

`/courses/{courseId}/radio/*` stellt SRC-/UBI-Funktexte, Mitschrift-Auswertung, Vokabeltraining und Funkpraxis über dieselben nativen Inhalte und Lernstandsdatensätze wie der Laravel-Webtrainer bereit. `GET …/samples` liefert die Musterlösungen ausdrücklich als eigenen Abruf.

`GET /courses/{id}/exam-registration` liefert die Prüfungsanleitung, Prüfungsformen, Prüfungsteile, FAQ und allgemeine DSV/DMYV-Kontakte. Die Terminbuchung selbst bleibt beim Prüfungsausschuss. Individuelle regionale Ausschüsse sind im nativen Laravel-Datenbestand derzeit nicht gepflegt.

* **Praxistrainer:** `GET /courses/{id}/praxis` liefert Karten, freigegebene Medien und Status. `PUT /courses/{id}/praxis/{cardId}` speichert `known`, `practice` oder `open`; die Webansicht und App teilen den Status `known`/`open`.

### Health und synchronisierte App-Einstellungen

* `GET /health` ist ohne Anmeldung erreichbar und liefert API-Version,
  Status und Serverzeit.
* `POST /auth/web-session` erstellt für die eingebettete Webansicht eine einmalige, 60 Sekunden gültige Anmeldung. Die WebView muss die URL mit dem mitgelieferten Verifier-Header laden; erlaubt sind nur interne Lernseiten. Die Bestätigungsseite für Kontolöschung ist über `MOBILE_API_DELETION_CONFIRMATION_PATH` auf den Universal-Link der App zu legen.

* `GET /me/settings` und `PUT /me/settings` synchronisieren
  benutzerspezifische App-Einstellungen über Geräte hinweg. Antworten
  enthalten eine monoton steigende `version` und den passenden `ETag`.
  Schreibzugriffe können `If-Match: "<version>"` oder
  `expected_version` mitsenden. Bei einem Versionskonflikt antwortet die API
  mit 409 `VERSION_CONFLICT` und dem aktuellen Stand.

---

## 1. Ergebnis der Prüfung der App-Unterlagen

Geprüft wurden das Konzept (`Mobile-Lernapp-Konzept-BoatingSchool.md`), der
OpenAPI-Entwurf (`openapi-boatingschool-mobile-v1.yaml`) und die
Design-Übersicht. Grundlage war jeweils der tatsächliche Stand der Plattform.

### Übernommen wie im Konzept

* Bearer-Token mit kurzer Laufzeit und rotierendem Refresh-Token.
* Schulwahl über `X-Tenant-Slug`, nur Teilnehmer-Mitgliedschaften.
* Fehler als `application/problem+json` mit stabilem `code` und `request_id`.
* `Idempotency-Key` für schreibende Aktionen.
* Prüfung ohne Lösung vor der Abgabe, Zeitlimit auf dem Server.
* Platzhalter-Medien bleiben sichtbar, haben aber nie eine abspielbare URL.
* Kauf nur im externen Webshop, Freischaltung per Code in der App.
* DSGVO-Export mit kurzlebigem Link.

### Korrigiert oder ergänzt

| Thema | Entwurf | Umgesetzt | Grund |
|---|---|---|---|
| Smarttrainer „erledigt“ | 3 Versuche = erledigt | **Wie Web:** gefestigt = 3× richtig in Folge; ein Fehler setzt die Serie zurück. Nach einem Fehler zählt die festigende dritte Antwort erst mit ≥ 10 Min. Abstand. | Entscheidung: eine Lernlogik für Web und App |
| Prüfungsantworten | jederzeit änderbar, `marked_for_review` | **Wie Web:** eine Antwort ist festgeschrieben (`EXAM_ANSWER_LOCKED`). Dieselbe Antwort erneut senden ist harmlos (200). Kein `marked_for_review`. | Entscheidung „Festschreiben wie Web“ |
| Ungültige Codes | getrennte Codes | **Eine Meldung:** `COUPON_INVALID` für unbekannt, gesperrt, abgelaufen und von anderen eingelöst. Nur die eigene, schon erfolgte Einlösung liefert das Ergebnis erneut. | Entscheidung; schützt vor Durchprobieren |
| Zwei-Faktor-Anmeldung | fehlte | `two_factor_required` + `challenge_token`, dann `POST /auth/two-factor` (Code oder Wiederherstellungscode) | Konten mit aktivem 2FA hätten sich sonst nicht anmelden können |
| Login mit Schule | Schule beim Login nötig | Login ohne Schule; die Antwort listet die Mitgliedschaften | Ein Konto kann bei mehreren Schulen lernen |
| Passwortlänge | mind. 12 | mind. 10 (`Password::defaults()` der Plattform) | Gleiche Regel wie im Web |
| Favoriten | eine Sammlung je Favorit | `collection_ids` (mehrere Sammlungen) plus nicht löschbare Standard-Sammlung (`COLLECTION_NOT_DELETABLE`) | So ist das Datenmodell im Web |
| Offene Fragen (FKN/SKN) | fehlten | `answer_selection = self_assessment`, `answer_token` in `GET …/trainer/next`, `POST …/trainer/reveal`, danach `known` true/false speichern | Antwort bleibt bis zum ausdrücklichen Aufdecken verborgen |
| Navigationsaufgabe in der Prüfung (SBF See) | fehlte | `PUT /exams/{id}/navigation` und `POST …/navigation/assessment`; eigenes Minimum 7/9 | Prüfung wäre sonst nie bestanden |
| Navigationstrainer | nur Lesen | `PUT …/parts/{id}/answer` plus WP-kompatibles `POST …/parts/{id}` mit `correct`/`partly`/`wrong`, Antwortspeicherung und automatischer Prüfung; gesperrt, solange die Aufgabe in einer laufenden Prüfung ist | Gleicher Funktionsumfang und zusätzlicher Routen-Alias für die App |
| Prüfungsstart | `mode: official_simulation` | entfällt; `paper_id` bei Kursen mit festen Bögen, sonst Zufallsprüfung | Bögen sind die amtlichen Fragebögen |
| Prüfungsübersicht | einfache Liste | `group` (Teilprüfung im Kombikurs), `parts`, `navigation`, `running_exam_id`, `last_result` | Kombikurse (SRC+UBI, SBF See+Binnen) |
| Mitgliedschaft | Logo, Primärfarbe | zusätzlich `secondary_color`, Support-Adresse (nur bestätigt), Links zu Impressum/Datenschutz | Pflichtangaben je Schule |
| Kursliste | nur Zugänge | zusätzlich buchbare Kurse mit `webshop_url`, `meta.renewal_webshop_url` | Wie das Dashboard im Web |
| Datenexport | Warteschlange | wird sofort erzeugt (`status: ready`), Link 10 Min., Datei 60 Min. | Export dauert < 1 s; keine Warteschlange nötig |
| Schul-Kennung | nur Kleinbuchstaben | `^[A-Za-z0-9][A-Za-z0-9-]{0,119}$` | Bestehende Kennungen |
| Fehlercodes | Grundliste | ergänzt, siehe Abschnitt 11 | fachliche Fälle oben |

### Hinweise zu den Unterlagen

* **Konto löschen (Apple-Richtlinie 5.1.1 v):** Die App legt keine Konten an
  (Konten entstehen über die Schule oder einen Code). Eine Löschfunktion in
  der App ist damit nicht vorgeschrieben. Die App verlinkt dafür auf das
  Profil im Web (`memberships[].legal.account_url`, dort „Konto löschen“;
  Datenrechte unter `legal.data_rights_url`).
* **Regelwerk `ADD-FKN-TO-SKN`** ist weiterhin ein Entwurf. Die API bietet
  dafür keine Prüfung an (`startable: false` bzw. `EXAM_NOT_AVAILABLE`), bis
  das Regelwerk freigegeben ist.
* **Videos:** Die Seeder-Videos sind Platzhalter. Die API liefert dafür
  `kind: placeholder`, `url: null`, Beschriftung „Video folgt“.
* **Funkgeräte-Simulator und Praxistrainer** sind nicht Teil von v1. Sie
  laufen weiter im Web (`capabilities` enthält `praxis`, wenn vorhanden).

---

## 2. Grundregeln

* **Basis-URL:** `https://<plattform-domain>/api/v1` (zentrale Domain,
  nicht die Schul-Adresse).
* **Header:**
  * `Authorization: Bearer <access_token>`
  * `X-Tenant-Slug: <tenant_slug>` (schulbezogene Endpunkte)
  * `Accept: application/json`
  * `Idempotency-Key: <uuid>` (wo im Vertrag angegeben)
  * `X-App-Platform: ios|android` und `X-App-Version: 1.4.0` (empfohlen; für
    die Mindestversion)
* **Antworten** stehen immer in `data` (Listen zusätzlich `meta`), Fehler
  nicht (siehe Abschnitt 11).
* **Mindestversion und Wartung:** `GET /app/config` (ohne Anmeldung) nennt
  die Mindestversion je Plattform, die Store-Links und ob gerade Wartung ist.
  * Zu alte App: 426 `APP_UPDATE_REQUIRED` mit `minimum_version` und
    `store_url`.
  * Wartung: 503 `MAINTENANCE` mit `Retry-After`.
* **Zeit:** ISO 8601 in UTC. Für Countdowns `server_time`, `expires_at` und
  `remaining_seconds` der Prüfung verwenden, nicht die Gerätezeit.
* **Seiten:** `page`, `per_page` (max. 100), Antwort in `meta.pagination`.
* **Caching:** Alle Antworten sind `Cache-Control: no-store, private`.
* **Request-ID:** Jede Antwort hat `X-Request-Id`, Fehler zusätzlich
  `request_id`. Für Support-Anfragen mitschicken; die ID steht auch im
  Server-Log und im Fehlertracking.
* **Nur zentrale Domain:** Unter einer Schul-Adresse oder einem Schul-Pfad
  (`/{schule}/api/…`) antwortet die API mit 404.

### Idempotenz

Für `POST`-Aktionen mit Pflicht-Header `Idempotency-Key`: **erfolgreiche**
Antworten werden 24 Stunden je Konto gespeichert.

* Gleicher Schlüssel mit gleichem Inhalt liefert dieselbe Antwort; nichts
  passiert doppelt. Eine solche Antwort trägt `Idempotent-Replayed: true`.
* Fehlerantworten (4xx/5xx) werden nicht gespeichert: nach dem Beheben des
  Grundes (z. B. Code eingelöst, dann Prüfung starten) gilt derselbe
  Schlüssel wieder.
* Läuft dieselbe Anfrage noch: 409 `CONFLICT` mit `Retry-After`.
* Gleicher Schlüssel mit anderem Inhalt ergibt 409 `IDEMPOTENCY_KEY_REUSED`.
* Je fachlicher Aktion einen neuen Schlüssel erzeugen und bei automatischen
  Wiederholungen beibehalten.

`PUT`-Endpunkte sind von sich aus wiederholbar.

### Begrenzungen (HTTP 429, `RATE_LIMITED`, `Retry-After`)

| Bereich | Grenze |
|---|---|
| Login | 10/Min. je IP, 5 Fehlversuche je E-Mail+IP und Minute, 20 je Konto in 15 Min. |
| 2FA | 10/Min., dazu 5 Versuche je Konto in 5 Min. (gemeinsam mit dem Web) |
| Refresh | 10/Min. je Refresh-Token, 300/Min. je IP |
| Passwort vergessen/zurücksetzen | je 6/Min. |
| Bestätigungsmail | 6/Min. |
| App-Konfiguration | 60/Min. |
| Schulbezogene Endpunkte | 240/Min. je Konto |
| Codes einlösen | 10/Min. |
| Datenexport | 5/Std., Download 20/Min. |

---

## 3. Anmeldung und Sitzungen

```
POST /auth/login {email, password, device_name, device_platform?, app_version?}
  → 200 {data: {access_token, refresh_token, …, user, memberships}}
  → oder 200 {data: {two_factor_required: true, challenge_token}}
       → POST /auth/two-factor {challenge_token, code | recovery_code}
```

* **Access-Token** (`bsa_…`) gilt 15 Minuten. Es ist undurchsichtig; die App
  liest nichts daraus.
* **Refresh-Token** (`bsr_…`) wird bei jedem `POST /auth/refresh` ersetzt.
  * Gültig bis 30 Tage ohne Nutzung, höchstens 90 Tage ab Anmeldung.
  * Das **unmittelbar vorherige**, also schon ersetzte Refresh-Token noch
    einmal benutzt beendet die ganze Gerätesitzung (Diebstahl-Schutz).
    Ältere Tokens ergeben nur 401.
  * Bei zwei gleichzeitigen Erneuerungen nur eine abwarten.
* **Speicherung in der App:** Keychain (iOS) bzw. EncryptedSharedPreferences
  oder Keystore (Android). Auf dem Server liegen nur SHA-256-Hashes.
* **Abmelden:** `POST /auth/logout` (dieses Gerät),
  `DELETE /me/sessions/{id}` (ein anderes Gerät) oder `DELETE /me/sessions`
  (alle anderen Geräte).
* **Alle App-Sitzungen enden automatisch**, wenn:
  * das Passwort geändert oder zurückgesetzt wird,
  * die E-Mail-Adresse geändert wird (auch durch den Superadmin),
  * die Zwei-Faktor-Anmeldung ein- oder ausgeschaltet oder vom Support
    zurückgesetzt wird,
  * das Konto gesperrt wird (sofort 401; auch nach einer Entsperrung bleiben
    die alten Sitzungen beendet).
* **E-Mail nicht bestätigt:** Anmeldung geht, schulbezogene Endpunkte
  antworten 403 `EMAIL_NOT_VERIFIED`. Neue Mail mit
  `POST /auth/email/verification-notification`.
* **Passwort vergessen:** `POST /auth/password/forgot` antwortet immer 202.
  Der Link in der Mail führt ins Web. Für einen Deep-Link in die App kann die
  App `POST /auth/password/reset` mit `email`, `token` und neuem Passwort
  aufrufen. `invite: true` gilt für Einladungs-Links.

## 4. Schule wählen

`memberships[]` aus Login bzw. `GET /me`.

* Nur `status: active` ist nutzbar.
* Den `tenant_slug` als `X-Tenant-Slug` mitsenden.
* Branding (Logo, Farben) und Links kommen aus der Mitgliedschaft
  (`legal`: Impressum, Datenschutz, Profil im Web mit „Konto löschen“,
  Datenrechte).

Fehler beim Schul-Kontext:

* 403 `TENANT_MEMBERSHIP_REQUIRED`: nicht Teilnehmer dieser Schule.
* 403 `TENANT_MEMBERSHIP_SUSPENDED`: Mitgliedschaft gesperrt.
* 403 `TENANT_UNAVAILABLE`: Schule nicht aktiv.
* 422 `VALIDATION_FAILED`: Header fehlt.

## 5. Kurse und Zugang

`GET /courses` liefert zuerst die freigeschalteten Kurse, dann buchbare Kurse.

* `access.status`: `active`, `expired`, `suspended`, `revoked` oder
  `not_granted`.
* `capabilities` (z. B. `smarttrainer`, `exam`, `video`, `navigation`)
  steuert, welche Kacheln die App zeigt.
* `last_activity_at` (letzte Antwort im Kurs) sortiert „Weiterlernen“ über
  alle Kurse. `GET /courses/{id}/overview` nennt in `continue_learning` das
  Modul und das Thema der letzten Frage („Du warst bei …“).

Kursinhalte ohne gültigen Zugang ergeben 403 `ENTITLEMENT_REQUIRED` oder
`ENTITLEMENT_EXPIRED`. Die App zeigt dann `webshop_url` bzw.
`meta.renewal_webshop_url` und die Code-Eingabe.

Die WP-App verwendet `default_access=logged_in`. Das native Original ist aus
Sicherheitsgründen standardmäßig auf `entitlement` eingestellt. Für denselben
Zugangs-Default muss die Betriebsumgebung vor dem Rollout ausdrücklich
`MOBILE_API_DEFAULT_ACCESS=logged_in` setzen. Das gewährt eingeloggten Nutzern
die Vollzugriffe der Kursfeatures; die Zugangsentscheidung muss daher zum
Geschäftsmodell passen.

## 6. Smarttrainer und Lernmodi

`GET /courses/{id}/trainer/next?mode=…` holt die nächste Frage.

| `mode` | Auswahl | 204 bedeutet |
|---|---|---|
| `smarttrainer` | Standard; gefestigte Fragen kommen zur Wiederholung | (praktisch nie; „alles geschafft“ = `progress.percent` 100) |
| `new` | neue Fragen | alles schon gesehen |
| `wrong` | falsch beantwortete Fragen | keine Fehler offen |
| `topic` | mit `topic` | Thema erledigt |
| `favorites` | Favoriten; `collection_id` = eine Sammlung, `question_ids` = genau diese | keine (passenden) Favoriten |

* `module_id` begrenzt `smarttrainer`, `new`, `wrong` und `topic` auf ein Modul.
* `question_ids` außerhalb von `favorites` wirkt wie „Fehler üben“ im Web:
  Fragen mit laufender richtiger Serie fallen heraus, am Ende kommt 204.
* Die Antworten einer Einfachauswahl sind je Person gemischt (wie im Web);
  `key` ist der Buchstabe der Anzeigeposition.
* Fragen einer laufenden Prüfung sind gesperrt, auch bei einer anderen
  Schule (409 `EXAM_QUESTION_LOCKED`).

`POST /courses/{id}/attempts` (mit Idempotency-Key) gibt eine Antwort ab:

* Einfachauswahl: `selected_answer_ids` mit genau einer ID.
* Mehrfachauswahl: alle gewählten IDs.
* Offene Frage: Musterantwort aus `question.model_answer` aufdecken, dann
  `known: true/false` senden.

Die Antwort enthält Richtig/Falsch, Lösung, Erklärung, `learning_state`,
`streak` (x von 3) und `completed` (= gefestigt). Es gelten die Regeln der
Web-App (`MasteryCalculator`).

## 7. Favoriten

* Favoriten gelten je Kurs. `GET …/favorites` enthält je Eintrag eine
  Kurzfassung der Frage (`question`: Text, Thema, Lernstand, zuletzt geübt).
* `PUT …/favorites/{questionId}` ist wiederholbar.
  * Ohne `collection_ids`: die Frage kommt in die Standard-Sammlung.
  * Mit `collection_ids`: die Frage liegt genau in diesen Sammlungen
    (mindestens eine eigene Sammlung, sonst 422; entfernen über `DELETE`).
* Üben: `trainer/next?collection_id=…` (eine Sammlung) oder
  `?mode=favorites&question_ids=<id>` (genau eine Frage).
* Sammlungen: anlegen, umbenennen, löschen (max. 60 Zeichen, eindeutiger
  Name). Die Standard-Sammlung bleibt.
* `context: exam` gilt für Favoriten aus der Prüfung.

## 8. Prüfungssimulation

```
GET  /courses/{id}/exams                → Bögen/Optionen, Bestehensregel, laufend?, letztes Ergebnis
GET  /courses/{id}/exam-sessions        → bisherige Versuche (Verlauf)
POST /courses/{id}/exams {paper_id}     → 201 neu / 200 laufende fortsetzen
GET  /exams/{id}                        → Fragen ohne Lösung, gegebene Antworten, phase, Zeit
PUT  /exams/{id}/answers/{questionId}   → festschreiben
POST /exams/{id}/questions/{qid}/reveal → Musterantwort (offene Fragen)
PUT /exams/{id}/answers/{questionId}/flag {flagged} → Markierung zur Überprüfung speichern (Position oder Fragen-ID)
  * WP-kompatibler Alias: `POST /exams/{id}/answers/{questionId}/reveal`.
PUT  /exams/{id}/navigation             → Navigationsaufgabe abgeben (SBF See)
POST /exams/{id}/navigation/assessment  → Selbsteinschätzung → Auswertung
POST /exams/{id}/submit                 → abgeben → Ergebnis (Navigation fehlt: 409, außer without_navigation)
GET  /exams/{id}/result                 → Ergebnis mit Lösungen
```

* **Phasen (`phase`):**
  * `questions`: es sind noch Fragen offen.
  * `navigation`: alle Fragen beantwortet (oder die Zeit ist um), die
    Navigation fehlt noch.
  * `navigation_assessment`: Navigation abgegeben, Selbsteinschätzung
    steht aus.
  * `ready_to_submit`: alles beantwortet.
  * `evaluated`: ausgewertet.
* **Reihenfolge:** Fragen dürfen in beliebiger Reihenfolge beantwortet
  werden; jede Antwort ist danach festgeschrieben. Die Navigation kommt wie
  im Web erst nach dem Fragebogen (sonst 409 `EXAM_QUESTIONS_OPEN`).
* **Zeit:** Nach Ablauf (`expires_at`) zählt keine Antwort mehr (410
  `EXAM_EXPIRED`).
  * Mit Navigationsteil bleibt die Prüfung für die Navigation offen: Abgabe
    bis `navigation_submit_until` (1 Min. nach Ende, nur für technische Verzögerungen), Selbsteinschätzung bis
    `navigation_assessment_until` (15 Min. nach Ende). Danach wird
    ausgewertet; eine spätere Selbsteinschätzung zählt nicht (Web und App
    gleich).
  * Ohne Navigationsteil wird beim nächsten Aufruf ausgewertet.
* **Abgabe:** Fehlt die Navigation noch, lehnt `submit` mit 409
  `EXAM_NAVIGATION_NOT_SUBMITTED` ab. `{"without_navigation": true}` gibt
  ohne sie ab (Navigation 0 Punkte).
* **Bestanden:** Gesamtquote **und** alle Teilgrenzen (`parts`, z. B. SBF
  See Basis 5/7 und See 18/23) **und** die Navigation (7/9).
* **Kombikurse:** Jeder Bogen läuft nach dem Regelwerk seiner Teilprüfung
  (`group`).
* **Lösungen** gibt es nur im Ergebnis. Die laufende Prüfung enthält keine
  Kennzeichnung richtiger Antworten und keine Erklärungen, die Antworten
  sind je Person gemischt (durch Tests abgesichert).
* `name` und `pass_rule` stehen auch in der laufenden Prüfung.
* **Navigationsaufgabe der laufenden Prüfung:** Sie ist im Übungsbereich
  gesperrt (409 `NAVIGATION_TASK_IN_EXAM`).

## 9. Videokurs

* `GET /courses/{id}/videos` liefert die Kapitel mit Lektionen.
  * `standalone: true` markiert eigene Kapitel (z. B. „Knoten“), die im Web
    eine eigene Kachel haben.
* `GET /videos/{id}` liefert eine Lektion mit Schritt-Galerie.
* `PUT /videos/{id}/progress` speichert den Fortschritt:
  * `position_seconds` (Wiedergabeposition) und `completed`.
  * Eine abgeschlossene Lektion bleibt abgeschlossen.
* Platzhalter-Videos haben `media.kind = placeholder` und `url = null`.

## 10. Navigationstrainer, Codes, Datenexport

* **Navigationstrainer:**
  * Musterlösungen in der Aufgabe nur mit `show_solution=true`; die
    Antwort auf `PUT …/parts/{id}/answer` enthält sie immer.
  * Eigene Antworten mit Messwerten (Kurs, Zeit, Distanz, Position) prüft
    der Server mit Toleranz (`auto_checked`).
  * Sonst bewertet sich die Person selbst (`self_assessment`).
* **Code einlösen:** `POST /coupons/redeem {code}`.
  * Groß-/Kleinschreibung und Leerzeichen am Rand sind egal; beim
    16-stelligen Standardformat auch Leerzeichen statt oder fehlende
    Bindestriche.
  * Danach `GET /courses` neu laden.
  * `type`: `course`, `course_renewal` oder `product`.
* **Datenexport:**
  * `POST /profile/export` erzeugt eine JSON-Datei mit allen Daten bei
    dieser Schule, inkl. der App-Geräte (`status: ready`, bei einem Fehler
    `failed`). Ohne Idempotency-Key: jeder Aufruf erzeugt einen frischen
    Export.
  * `GET /profile/export/{id}` liefert `download_url`: signiert, 10 Minuten
    gültig, ohne Token nutzbar (z. B. Teilen-Menü).
  * Nach 60 Minuten ist der Export abgelaufen; die Datei löscht das
    stündliche Aufräumen.

## 11. Fehlercodes

| HTTP | `code` | Bedeutung / App-Reaktion |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Token fehlt/abgelaufen → einmal `refresh`, sonst Login |
| 403 | `EMAIL_NOT_VERIFIED` | Bestätigungsmail anbieten |
| 403 | `TENANT_MEMBERSHIP_REQUIRED` / `_SUSPENDED`, `TENANT_UNAVAILABLE` | Schulwahl / Support |
| 403 | `ENTITLEMENT_REQUIRED` / `ENTITLEMENT_EXPIRED` | Webshop-Link, Code-Eingabe |
| 403 | `FORBIDDEN` | keine Berechtigung |
| 403 | `LINK_INVALID` | Download-Link manipuliert oder abgelaufen → Status neu laden |
| 404 | `RESOURCE_NOT_FOUND` | auch für fremde Daten (keine Aussage über Existenz) |
| 405 | `METHOD_NOT_ALLOWED` | Programmierfehler in der App |
| 409 | `CONFLICT` | allgemeiner Konflikt bzw. dieselbe Anfrage läuft noch (`Retry-After`) |
| 409 | `IDEMPOTENCY_KEY_REUSED` | neuen Schlüssel erzeugen |
| 409 | `COLLECTION_NOT_DELETABLE` | Standard-Sammlung |
| 409 | `EXAM_NOT_AVAILABLE` | Regelwerk nicht freigegeben |
| 409 | `EXAM_ANSWER_LOCKED` | Antwort bereits festgeschrieben |
| 409 | `EXAM_QUESTION_LOCKED` | Frage gerade in einer laufenden Prüfung (Lernmodus) |
| 409 | `EXAM_QUESTIONS_OPEN` | erst den Fragebogen beenden, dann Navigation |
| 409 | `EXAM_ALREADY_SUBMITTED` / `EXAM_NOT_SUBMITTED` | Ergebnis bzw. Prüfung laden |
| 409 | `EXAM_NO_NAVIGATION_PART`, `EXAM_NAVIGATION_ALREADY_SUBMITTED`, `EXAM_NAVIGATION_NOT_SUBMITTED` | Phase neu laden / nachfragen |
| 409 | `NAVIGATION_TASK_IN_EXAM` | erst Prüfung beenden (`exam_id` im Fehler) |
| 410 | `EXAM_EXPIRED` | Prüfung bzw. Ergebnis neu laden |
| 410 | `EXPORT_EXPIRED` | neuen Export anfordern |
| 410 | `GONE` | allgemein: nicht mehr verfügbar |
| 422 | `VALIDATION_FAILED` | Eingaben prüfen, Details in `errors` |
| 422 | `TWO_FACTOR_INVALID` | 2FA-Code falsch |
| 422 | `PASSWORD_RESET_INVALID_OR_EXPIRED` | neuen Link anfordern |
| 422 | `COUPON_INVALID` | „Code ungültig, abgelaufen oder bereits eingelöst“ |
| 422 | `COUPON_NOT_APPLICABLE` | Meldung aus `detail` anzeigen |
| 426 | `APP_UPDATE_REQUIRED` | Update-Hinweis mit `store_url` |
| 429 | `RATE_LIMITED` | `Retry-After` abwarten |
| 4xx | `REQUEST_FAILED` | sonstiger Fehler; `detail` anzeigen |
| 503 | `MAINTENANCE` | Wartungshinweis (`detail`), `Retry-After` |
| 5xx | `SERVER_ERROR` / `SERVICE_UNAVAILABLE` | später erneut; `request_id` an Support |

Texte in `title`/`detail` sind deutsch und für Lernende formuliert. Die App
entscheidet aber nur anhand von `code`.

## 12. Sicherheit und Datenschutz

* **Mandantentrennung:** Jede schulbezogene Abfrage läuft mit
  Row-Level-Security der Schule aus `X-Tenant-Slug`. Fremde Prüfungen,
  Versuche oder Sammlungen ergeben 404 (getestet).
* **Tokens:** zufällig (über 256 Bit), nur Hashes gespeichert. Der Kontostatus
  wird bei jedem Aufruf geprüft.
* **Protokoll:** Datenexporte werden protokolliert (`audit_log`, Kanal
  `app`).
* **Löschfristen** (`php artisan app:prune-personal-data --mobile-api-only`,
  stündlich; dazu der tägliche Gesamtlauf):
  * beendete oder abgelaufene App-Sitzungen nach 30 Tagen,
  * Idempotenz-Schlüssel nach 24 Stunden (gelesen werden sie ohnehin nur so
    lange),
  * Exporte und ihre Dateien nach Ablauf.
* **Konto löschen** entfernt App-Sitzungen, Exporte samt Dateien und
  gespeicherte Antworten sofort. **Eine Schule verlassen** entfernt die
  Exporte dieser Schule samt Dateien und die gespeicherten Antworten; die
  App-Sitzungen gelten kontoweit und bleiben (für andere Schulen).
* **Datenschutzerklärung:** Die zentrale Vorlage nennt die Lern-App
  (Geräteangaben, Speicherfristen). Schulen mit eigener Vorlage müssen den
  Absatz selbst ergänzen.
* **Bekannte Grenze:** Ein gültiger, aber für das eigene Konto nicht
  anwendbarer Code ergibt `COUPON_NOT_APPLICABLE` statt `COUPON_INVALID`
  (wie im Web). Bei 80 Bit je Code und 10 Versuchen pro Minute lässt sich
  das nicht zum Durchprobieren nutzen.

## 13. Betrieb

* **Konfiguration** über `.env` (Vorlage in `.env.example`); ohne Eintrag
  gelten 15 / 30 / 90 / 60:
  * `MOBILE_API_ACCESS_TTL_MINUTES`
  * `MOBILE_API_REFRESH_IDLE_DAYS`
  * `MOBILE_API_REFRESH_MAX_DAYS`
  * `MOBILE_API_EXPORT_TTL_MINUTES`
  * `MOBILE_API_WEB_SESSION_ENABLED` und `MOBILE_API_DELETION_CONFIRMATION_PATH`
  * `MOBILE_API_MIN_VERSION_IOS` / `_ANDROID` und `MOBILE_API_STORE_URL_IOS`
    / `_ANDROID` (leer = keine Mindestversion)
* **Wartungsmodus** der Plattform (Superadmin-Einstellungen) gilt auch für
  die API (503 `MAINTENANCE`); `GET /app/config` bleibt erreichbar.
* **Keine CORS-Freigabe nötig** (native Apps). Die API nutzt keine Cookies
  und keine Sessions.
* **Migrationen:** Die Basis beginnt mit `2024_02_01_000591_create_mobile_api_tables`; die späteren Migrationen legen WebView-SSO, Kontolöschung, Zugangsregeln und den Dateizugriff für Downloads an.
* **Limited-/Free-/Drip-Zugänge:** Die Access-Regeln aus WordPress müssen einmalig in das Original übernommen werden. Der Import erwartet eine normalisierte JSON-Datei:
  ```json
  {
    "rules": [
      {"tenant_slug":"schule-a","course_code":"SBF-SEE","feature_code":"question_bank","access_type":"free_limited","limit_value":5,"source_key":"wp:rule:123"},
      {"tenant_slug":"schule-a","course_code":"SBF-SEE","feature_code":"question_bank","access_type":"drip","module_code":"SEE-01","limit_value":14,"source_key":"wp:drip:123"}
    ]
  }
  ```
  Danach mit `php artisan app:import-mobile-access-rules /pfad/export.json` importieren. `tenant_slug` und `course_code` müssen auf die IDs im Original zeigen. Drip-Module verwenden den nativen Modulcode. Für WordPress-Videokapitel muss `module_code` dem `video_module.source_id` entsprechen; der Kapitel-Titel ist nur ein Rückfallwert für bereits native Inhalte ohne Quell-ID. Doppelte Regeln desselben Typs brauchen verschiedene `source_key`-Werte.
* **Downloads und Abschnittsdateien:** WordPress-Dateien zuerst in ein staging-Verzeichnis exportieren und dann mit einem Manifest registrieren:
  ```json
  {
    "files": [
      {"tenant_slug":"schule-a","course_code":"SBF-SEE","source_key":"wp:attachment:22","file":"uploads/2024/karte.pdf","category":"download","title":"Kartenmaterial","sort_order":1}
    ]
  }
  ```
  `php artisan app:import-mobile-course-files /pfad/files.json /pfad/zum/wordpress-media-export` kopiert die Dateien in den geschützten Laravel-Storage und legt die `course_file`-Einträge idempotent an. `category` ist `download`, `section` oder `chart`; Abschnittsdateien und Seekarten können einen `section_key` nutzen. Die exportierten Quelldateien selbst müssen vorher bereitgestellt werden.
* **Tests:** `vendor/bin/phpunit tests/Feature/Api`.
  `OpenApiContractTest` schlägt fehl, wenn Routen und OpenAPI-Datei
  auseinanderlaufen.

## 14. Nicht in v1

* Offline-Modus und Synchronisation.
* Push-Benachrichtigungen.
* In-App-Käufe (Kauf bleibt im externen Webshop).
* Registrierung in der App.
* Der Gerätesimulator öffnet sich als Browseransicht und benötigt die native Freigabe-Konfiguration.
* Untertitel und Vorschaubilder für Videos. Navigationsaufgaben ohne passende Seekarte liefern `chart: null`; dann wird die Papierkarte benötigt.
