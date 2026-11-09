# Mobile-API v1 (Lernenden-App)

Schnittstelle für die geplanten Handy-Apps (iOS/Android). Die App nutzt
**dieselben Daten und dieselbe Lern- und Prüfungslogik** wie die Web-App:
Fortschritt, Favoriten, Prüfungsversuche und Zugänge sind auf beiden Wegen
dieselben.

| Datei | Inhalt |
|---|---|
| `docs/api/openapi-mobile-v1.yaml` | Vertrag (OpenAPI 3.1), alle 44 Endpunkte |
| `routes/api.php` | Routen (Präfix `/api/v1`) |
| `app/Http/Controllers/Api/V1/` | Controller je Bereich |
| `app/Api/` | Tokens, Fehlerformat, Ausgabeformate |
| `app/Http/Middleware/Api/` | Anmeldung, Schule, Idempotenz |
| `config/mobile_api.php` | Laufzeiten (Tokens, Export, Idempotenz) |
| `tests/Feature/Api/` | Tests inkl. Abgleich Routen ↔ OpenAPI |

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
| Offene Fragen (FKN/SKN) | fehlten | `answer_selection = self_assessment`, Musterantwort aufdecken, `known` true/false; in der Prüfung erst `…/reveal`, dann Antwort | Sonst wären FKN/SKN nicht nutzbar |
| Navigationsaufgabe in der Prüfung (SBF See) | fehlte | `PUT /exams/{id}/navigation` und `POST …/navigation/assessment`; eigenes Minimum 7/9 | Prüfung wäre sonst nie bestanden |
| Navigationstrainer | nur Lesen | zusätzlich `PUT …/parts/{id}/answer` (Toleranzprüfung bzw. Selbstbewertung); gesperrt, solange die Aufgabe in einer laufenden Prüfung ist | Gleicher Stand wie im Web |
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
  der App ist damit nicht vorgeschrieben. Empfehlung: in der App auf
  „Profil → Konto löschen“ der Web-App verlinken.
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
* **Zeit:** ISO 8601 in UTC. Für Countdowns `server_time`, `expires_at` und
  `remaining_seconds` der Prüfung verwenden, nicht die Gerätezeit.
* **Seiten:** `page`, `per_page` (max. 100), Antwort in `meta.pagination`.
* **Caching:** Alle Antworten sind `Cache-Control: no-store, private`.
* **Request-ID:** Jede Antwort hat `X-Request-Id`, Fehler zusätzlich
  `request_id`. Für Support-Anfragen mitschicken.

### Idempotenz

Für `POST`-Aktionen mit Pflicht-Header `Idempotency-Key`: Antworten werden
24 Stunden je Konto gespeichert.

* Gleicher Schlüssel mit gleichem Inhalt liefert dieselbe Antwort; nichts
  passiert doppelt.
* Gleicher Schlüssel mit anderem Inhalt ergibt 409 `IDEMPOTENCY_KEY_REUSED`.
* Je fachlicher Aktion einen neuen Schlüssel erzeugen und bei automatischen
  Wiederholungen beibehalten.

`PUT`-Endpunkte sind von sich aus wiederholbar.

### Begrenzungen (HTTP 429, `RATE_LIMITED`, `Retry-After`)

| Bereich | Grenze |
|---|---|
| Login | 10/Min. je IP, 5 Fehlversuche je E-Mail+IP, 20 je Konto (15 Min.) |
| 2FA | 10/Min., zusätzlich je Konto |
| Refresh | 30/Min. |
| Passwort vergessen/zurücksetzen | je 6/Min. |
| Schulbezogene Endpunkte | 240/Min. je Konto |
| Codes einlösen | 10/Min. |
| Datenexport | 5/Std. |

---

## 3. Anmeldung und Sitzungen

```
POST /auth/login {email, password, device_name, device_platform?, app_version?}
  → 200 {access_token, refresh_token, …, user, memberships}
  → oder 200 {two_factor_required: true, challenge_token}
       → POST /auth/two-factor {challenge_token, code | recovery_code}
```

* **Access-Token** (`bsa_…`) gilt 15 Minuten. Es ist undurchsichtig; die App
  liest nichts daraus.
* **Refresh-Token** (`bsr_…`) wird bei jedem `POST /auth/refresh` ersetzt.
  * Gültig bis 30 Tage ohne Nutzung, höchstens 90 Tage ab Anmeldung.
  * Ein **zweites Mal benutztes** Refresh-Token beendet die ganze
    Gerätesitzung (Diebstahl-Schutz).
  * Bei zwei gleichzeitigen Erneuerungen nur eine abwarten.
* **Speicherung in der App:** Keychain (iOS) bzw. EncryptedSharedPreferences
  oder Keystore (Android). Auf dem Server liegen nur SHA-256-Hashes.
* **Abmelden:** `POST /auth/logout` (dieses Gerät) oder
  `DELETE /me/sessions/{id}` (anderes Gerät).
* **Alle App-Sitzungen enden automatisch**, wenn:
  * das Passwort geändert oder zurückgesetzt wird,
  * die E-Mail-Adresse geändert wird,
  * das Konto gesperrt wird (sofort 401).
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
* Branding (Logo, Farben) und Rechtstexte kommen aus der Mitgliedschaft.

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

Kursinhalte ohne gültigen Zugang ergeben 403 `ENTITLEMENT_REQUIRED` oder
`ENTITLEMENT_EXPIRED`. Die App zeigt dann `webshop_url` bzw.
`meta.renewal_webshop_url` und die Code-Eingabe.

## 6. Smarttrainer und Lernmodi

`GET /courses/{id}/trainer/next?mode=…` holt die nächste Frage; 204 heißt,
es gibt keine passende Frage.

| `mode` | Auswahl |
|---|---|
| `smarttrainer` | Standard |
| `new` | neue Fragen |
| `wrong` | falsch beantwortete Fragen |
| `topic` | mit `topic` |
| `favorites` | optional `question_ids` |

Optional lässt sich zusätzlich `module_id` angeben.

`POST /courses/{id}/attempts` (mit Idempotency-Key) gibt eine Antwort ab:

* Einfachauswahl: `selected_answer_ids` mit genau einer ID.
* Mehrfachauswahl: alle gewählten IDs.
* Offene Frage: Musterantwort aus `question.model_answer` aufdecken, dann
  `known: true/false` senden.

Die Antwort enthält Richtig/Falsch, Lösung, Erklärung, `learning_state`,
`streak` (x von 3) und `completed` (= gefestigt). Es gelten die Regeln der
Web-App (`MasteryCalculator`).

## 7. Favoriten

* Favoriten gelten je Kurs.
* `PUT …/favorites/{questionId}` ist wiederholbar.
  * Ohne `collection_ids`: die Frage kommt in die Standard-Sammlung.
  * Mit `collection_ids`: die Frage liegt genau in diesen Sammlungen.
* Sammlungen: anlegen, umbenennen, löschen (max. 60 Zeichen, eindeutiger
  Name). Die Standard-Sammlung bleibt.
* `context: exam` gilt für Favoriten aus der Prüfung.

## 8. Prüfungssimulation

```
GET  /courses/{id}/exams                → Bögen/Optionen, Bestehensregel, laufend?, letztes Ergebnis
POST /courses/{id}/exams {paper_id}     → 201 neu / 200 laufende fortsetzen
GET  /exams/{id}                        → Fragen ohne Lösung, gegebene Antworten, phase, Zeit
PUT  /exams/{id}/answers/{questionId}   → festschreiben
POST /exams/{id}/questions/{qid}/reveal → Musterantwort (offene Fragen)
PUT  /exams/{id}/navigation             → Navigationsaufgabe abgeben (SBF See)
POST /exams/{id}/navigation/assessment  → Selbsteinschätzung → Auswertung
POST /exams/{id}/submit                 → abgeben → Ergebnis
GET  /exams/{id}/result                 → Ergebnis mit Lösungen
```

* **Phasen (`phase`):**
  * `questions`: es sind noch Fragen offen.
  * `navigation`: alle Fragen beantwortet, die Navigation fehlt noch.
  * `navigation_assessment`: Navigation abgegeben, Selbsteinschätzung
    steht aus.
  * `ready_to_submit`: alles beantwortet.
  * `evaluated`: ausgewertet.
* **Reihenfolge:** Fragen dürfen in beliebiger Reihenfolge beantwortet
  werden; jede Antwort ist danach festgeschrieben.
* **Zeit:** Nach Ablauf zählt keine Antwort mehr.
  * Die Prüfung wird beim nächsten Aufruf ausgewertet (410 `EXAM_EXPIRED`
    bzw. `status: evaluated`).
  * Für die Navigation gilt eine Nachfrist von 2 Minuten. Die
    Selbsteinschätzung zählt nicht zur Prüfungszeit (bis 15 Min.).
* **Bestanden:** Gesamtquote **und** alle Teilgrenzen (`parts`, z. B. SBF
  See Basis 5/7 und See 18/23) **und** die Navigation (7/9).
* **Kombikurse:** Jeder Bogen läuft nach dem Regelwerk seiner Teilprüfung
  (`group`).
* **Lösungen** gibt es nur im Ergebnis. Die laufende Prüfung enthält keine
  Kennzeichnung richtiger Antworten und keine Erklärungen (durch einen Test
  abgesichert).
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
  * Musterlösungen nur mit `show_solution=true`.
  * Eigene Antworten mit Messwerten (Kurs, Zeit, Distanz, Position) prüft
    der Server mit Toleranz (`auto_checked`).
  * Sonst bewertet sich die Person selbst (`self_assessment`).
* **Code einlösen:** `POST /coupons/redeem {code}`.
  * Groß-/Kleinschreibung, Leerzeichen und fehlende Bindestriche sind egal.
  * Danach `GET /courses` neu laden.
  * `type`: `course`, `course_renewal` oder `product`.
* **Datenexport:**
  * `POST /profile/export` erzeugt eine JSON-Datei mit allen Daten bei
    dieser Schule, inkl. der App-Geräte.
  * `GET /profile/export/{id}` liefert `download_url`: signiert, 10 Minuten
    gültig, ohne Token nutzbar (z. B. Teilen-Menü).
  * Nach 60 Minuten wird die Datei gelöscht.

## 11. Fehlercodes

| HTTP | `code` | Bedeutung / App-Reaktion |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Token fehlt/abgelaufen → einmal `refresh`, sonst Login |
| 422 | `TWO_FACTOR_INVALID` | 2FA-Code falsch |
| 422 | `VALIDATION_FAILED` | Eingaben prüfen, Details in `errors` |
| 422 | `PASSWORD_RESET_INVALID_OR_EXPIRED` | neuen Link anfordern |
| 429 | `RATE_LIMITED` | `Retry-After` abwarten |
| 403 | `EMAIL_NOT_VERIFIED` | Bestätigungsmail anbieten |
| 403 | `TENANT_MEMBERSHIP_REQUIRED` / `_SUSPENDED`, `TENANT_UNAVAILABLE` | Schulwahl / Support |
| 403 | `ENTITLEMENT_REQUIRED` / `ENTITLEMENT_EXPIRED` | Webshop-Link, Code-Eingabe |
| 403 | `FORBIDDEN` | keine Berechtigung |
| 404 | `RESOURCE_NOT_FOUND` | auch für fremde Daten (keine Aussage über Existenz) |
| 409 | `IDEMPOTENCY_KEY_REUSED` | neuen Schlüssel erzeugen |
| 409 | `COLLECTION_NOT_DELETABLE` | Standard-Sammlung |
| 409 | `EXAM_NOT_AVAILABLE` | Regelwerk nicht freigegeben |
| 409 | `EXAM_ANSWER_LOCKED` | Antwort bereits festgeschrieben |
| 409 | `EXAM_ALREADY_SUBMITTED` / `EXAM_NOT_SUBMITTED` | Ergebnis bzw. Prüfung laden |
| 409 | `EXAM_NO_NAVIGATION_PART`, `EXAM_NAVIGATION_ALREADY_SUBMITTED`, `EXAM_NAVIGATION_NOT_SUBMITTED` | Phase neu laden |
| 409 | `NAVIGATION_TASK_IN_EXAM` | erst Prüfung beenden (`exam_id` im Fehler) |
| 410 | `EXAM_EXPIRED` | Ergebnis laden |
| 410 | `EXPORT_EXPIRED` | neuen Export anfordern |
| 422 | `COUPON_INVALID` | „Code ungültig, abgelaufen oder bereits eingelöst“ |
| 422 | `COUPON_NOT_APPLICABLE` | Meldung aus `detail` anzeigen |
| 409 | `CONFLICT` | allgemeiner Konflikt |
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
* **Löschfristen** (`php artisan app:prune-personal-data`, täglich):
  * beendete oder abgelaufene App-Sitzungen nach 30 Tagen,
  * Idempotenz-Schlüssel nach 24 Stunden,
  * Exporte nach Ablauf.
* **Konto oder Mitgliedschaft löschen** entfernt die App-Sitzungen und
  Exporte mit.

## 13. Betrieb

* **Konfiguration** über `.env`; ohne Eintrag gelten 15 / 30 / 90 / 60:
  * `MOBILE_API_ACCESS_TTL_MINUTES`
  * `MOBILE_API_REFRESH_IDLE_DAYS`
  * `MOBILE_API_REFRESH_MAX_DAYS`
  * `MOBILE_API_EXPORT_TTL_MINUTES`
* **Keine CORS-Freigabe nötig** (native Apps). Die API nutzt keine Cookies
  und keine Sessions.
* **Migration:** `2024_02_01_000591_create_mobile_api_tables`. Sie legt
  `api_device_session`, `api_idempotency_key` und `api_data_export` an.
* **Tests:** `vendor/bin/phpunit tests/Feature/Api`.
  `OpenApiContractTest` schlägt fehl, wenn Routen und OpenAPI-Datei
  auseinanderlaufen.

## 14. Nicht in v1

* Offline-Modus und Synchronisation.
* Push-Benachrichtigungen.
* In-App-Käufe (Kauf bleibt im externen Webshop).
* Funkgeräte-Simulator, Praxistrainer, Video-Quiz und Prüfungstermine.
* Registrierung in der App.
* Konto löschen in der App (siehe Hinweis Apple oben).
