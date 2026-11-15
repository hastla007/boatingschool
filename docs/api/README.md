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
  (Konten entstehen über die Schule oder einen Code), bietet die Löschung
  aber trotzdem an: `POST /me/deletion-request` (siehe Abschnitt 12). Es
  gilt dasselbe wie „Konto löschen“ im Profil im Web
  (`memberships[].legal.account_url`; Datenrechte unter
  `legal.data_rights_url`).
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
* **Medien-URLs sind kurzlebig:** Bilder zu Fragen (`prompt_media[].url`)
  mit geschützten Rechten (z. B. amtliche Fragenbilder) sind signierte
  Adressen, an Person und Schule gebunden und 120 Minuten gültig. Sie
  funktionieren ohne `Authorization`-Header (Bild-Loader). Der Zugang
  wird beim Abruf erneut geprüft; ohne Kurszugang kommt 404.
  * Die App darf diese URLs nicht dauerhaft speichern (keine Datenbank,
    kein Offline-Paket mit URL). Das geladene Bild darf sie im Speicher-
    bzw. Bild-Cache halten (`Cache-Control: private, max-age=3600`).
  * Nach 403 auf eine Bild-URL (abgelaufen) die Frage bzw. Prüfung neu
    laden; die Antwort enthält dann frische URLs. Das gilt auch für eine
    per Idempotency-Key wiederholte Antwort.
  * Öffentliche Medien behalten ihre bisherige, dauerhafte URL.
  * Kartenausschnitte der Navigationsaufgaben (`chart.url` in
    `GET /navigation-tasks/{id}` und im Navigationsteil der Prüfung) gelten
    genauso: signiert, an Person und Schule gebunden, Zugang beim Abruf
    erneut geprüft (404 ohne Kurszugang), 90 Minuten gültig.
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
| Login | 10/Min. je IP, 5 Fehlversuche je E-Mail+IP und Minute, 20 je Konto und Netz (IPv4 /24, IPv6 /48) in 15 Min.; nach 20 Fehlversuchen je Konto insgesamt nur noch 3 je Netz. Ein richtiges Passwort aus einem Netz ohne eigene Fehlversuche scheitert nicht an fremden Fehlversuchen (gemeinsam mit dem Web). |
| 2FA | 10/Min., dazu 5 Versuche je Konto in 5 Min. (gemeinsam mit dem Web) |
| Refresh | 10/Min. je Refresh-Token, 300/Min. je IP |
| Passwort vergessen/zurücksetzen | je 6/Min. |
| Bestätigungsmail | 6/Min. |
| App-Konfiguration, Health | je 60/Min. |
| App-Einstellungen speichern | 60/Min. |
| Schulbezogene Endpunkte | 240/Min. je Konto |
| Codes einlösen | 10/Min. |
| Lerngruppen | Vorschau 30/Min.; Einladen 20 Aufrufe/Std. je Verwalter, je Gruppe und Tag max(20, 2 × Plätze) Einladungen |
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
    einmal benutzt: innerhalb von 60 Sekunden nach der Erneuerung (Antwort
    im Funkloch verloren, zwei gleichzeitige Erneuerungen) gibt es ein neues
    Paar; später beendet es die ganze Gerätesitzung (Diebstahl-Schutz).
    Das dabei verdrängte Paar ist verbraucht: wird dessen Refresh-Token
    später noch vorgelegt, endet die Gerätesitzung ebenfalls. Mehr als fünf
    Wiederholungen in der Toleranz beenden sie auch.
    Ältere Tokens ergeben nur 401. Immer das zuletzt erhaltene Paar speichern
    und Erneuerungen nacheinander statt parallel senden.
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
* **Webansicht der Schule** (eingebettete WebView, z. B. für Seiten, die es
  in der App nicht gibt): `POST /auth/web-session {redirect}` mit
  `X-Tenant-Slug` liefert eine Einmal-URL.
  * Der Code in der URL gilt 60 Sekunden, nur einmal, nur bei dieser Schule
    und nur, solange diese Gerätesitzung besteht. Gespeichert wird nur sein
    Hash.
  * Die WebView lädt die URL mit dem Header `X-Boatingschool-Web-Verifier:
    <verifier>`. Ohne passenden Verifier verfällt der Code ohne Anmeldung.
    Danach leitet die Website auf dieselbe Adresse ohne Code weiter.
  * Ziele nur im Lernbereich (`/dashboard`, `/courses…`, `/tipps-tricks`,
    `/angebote`, `/willkommensbox`), relativ zur Schuladresse. Kein Profil,
    keine Verwaltung, keine fremden Hosts (422 `VALIDATION_FAILED`).
  * Konten mit Team-Rolle bei irgendeiner Schule und Plattform-Konten:
    403 `WEB_SESSION_NOT_ALLOWED`. Ist im Browser schon jemand anderes
    angemeldet, bleibt es dabei (keine Anmeldung).

### Health und App-Einstellungen

* `GET /health` (ohne Token) liefert `status: ok`, `api_version` und
  `server_time`. Wie `GET /app/config` auch im Wartungsmodus und für zu alte
  App-Versionen erreichbar (Load-Balancer).
* `GET /me/settings` / `PUT /me/settings {settings, expected_version?}`
  synchronisieren App-Einstellungen über Geräte hinweg. Sie gelten für das
  Konto (ohne `X-Tenant-Slug`, für alle Schulen gleich).
  * `PUT` ersetzt das ganze Objekt; `{}` setzt zurück. Schlüssel
    `^[a-z0-9_.-]{1,64}$`, höchstens vier Ebenen, höchstens 8192 Bytes.
  * Jede Antwort enthält die `version` (0 = nie gespeichert) und denselben
    Wert als `ETag`. Mit `If-Match: "<version>"` oder `expected_version`
    wird nur gespeichert, wenn niemand dazwischen geändert hat; sonst 409
    `VERSION_CONFLICT` mit dem aktuellen Stand in `details.current`.
  * Die Einstellungen sind Teil des Datenexports (`app_settings`) und werden
    mit dem Konto gelöscht. Keine Gesundheits- oder Zahlungsdaten ablegen.

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

* `access.status`: `active`, `limited` (nur Probezugang), `pending`,
  `expired`, `suspended`, `revoked` oder `not_granted`; dazu `trial`,
  `purchased`, `limits` (Probezugang je Funktion), `expires_soon`,
  `expires_at` und `renew_url`.
* `capabilities` (z. B. `smarttrainer`, `exam`, `video`, `navigation`)
  sagt, welche Funktionen der Kurs hat; `features.<capability>` mit
  `available`/`full`/`limit` sagt, was die Person davon nutzen darf
  (gesperrte Kacheln mit Schloss und `webshop_url`). Was dort `available`
  ist, öffnen auch die Endpunkte der Funktion (siehe Zugriffsregeln).
  `webshop_url` fehlt nur bei vollem Kurszugang -- bei Probe- oder
  Teilzugang bleibt der Kauf-Link.
* `relation` (`hidden`, `recommended`, `recommended_for`) bildet die
  Katalogregeln der WP-App ab: ein gekaufter Kombikurs blendet seine
  Teilkurse aus, Aufbaukurse werden empfohlen.
* `last_activity_at` (letzte Antwort im Kurs) sortiert „Weiterlernen“ über
  alle Kurse. `GET /courses/{id}/overview` nennt in `continue_learning` das
  Modul und das Thema der letzten Frage („Du warst bei …“).

Kursinhalte ohne gültigen Zugang ergeben 403 `ENTITLEMENT_REQUIRED` oder
`ENTITLEMENT_EXPIRED`. Die App zeigt dann `webshop_url` bzw.
`meta.renewal_webshop_url` und die Code-Eingabe. `GET /courses/{id}`
liefert das Kursdetail auch für buchbare Kurse ohne Zugang (mit gesperrten
`features`).

**Zugriffsregeln (WP-Parität, `App\Api\CourseAccess`):**

* **Kurszugang:** aktives Entitlement ohne Einschränkung
  (`entitlement.capabilities = null`).
* **Funktionszugang:** ein Entitlement kann auf einzelne Funktionen
  beschränkt sein (`capabilities`, z. B. `["exam"]` aus dem WP-Import oder
  einer Lerngruppe). Die Endpunkte verlangen genau ihre Funktion:
  Übersicht, Module, Fortschritt, `trainer/next`, `trainer/reveal`,
  `attempts` und Fehlerliste `smarttrainer`; Favoriten und Sammlungen
  `favorites`; Prüfungen (Liste, Start, Verlauf, laufende Prüfung)
  `exam`; Videos `video`; Navigationsaufgaben `navigation`; Praxistrainer
  `praxis`; Funktraining `funk` (nur im vollen Umfang, kein Probezugang).
  Übrige Endpunkte (z. B. Prüfungsanmeldung, Kunden-FAQ) verlangen vollen
  Kurszugang. Das Web öffnet Kurse nur bei vollem Kurszugang; ein Kurs mit
  bloßem Teilzugang bleibt dort buchbar („Teilzugang in der App“).
* **Schulregeln** (`course_access_rule`, je Schule, RLS): `free` öffnet eine
  Funktion ohne Kauf, `free_limited` die ersten N Einträge (beim
  Fragenkatalog: die ersten N gültigen Fragen je Modul; Module zeigen dann
  `lock_reason: trial`), `drip` sperrt ein Modul bis N Tage nach
  Zugangsbeginn (`locked`, `lock_reason: drip`, `unlock_at`). Regeln wirken
  nur für Kurse, die die Schule anbietet, und nur innerhalb ihres
  `valid_from`/`valid_until`.
* **Durchsetzung:** Probezugang und Drip gelten nicht nur für die Anzeige.
  `trainer/next` spielt nur freigegebene Fragen aus (auch mit
  `question_ids`, `collection_id` und `mode=favorites`), `module_id` eines
  gesperrten Moduls, `attempts`, `trainer/reveal` und Favoriten auf eine
  nicht freigegebene Frage ergeben 403 `ENTITLEMENT_REQUIRED`; die
  Fehlerliste zeigt nur freigegebene Fragen. Bei Probezugang zu Prüfung,
  Videos, Navigation und Praxistrainer liefern die Listen die ersten N
  Einträge (Prüfungsbögen, Lektionen, Aufgaben, Karten in Kursreihenfolge);
  ein Direktzugriff auf einen späteren Eintrag ergibt 403
  `ENTITLEMENT_REQUIRED`.
* **Schalter `MOBILE_API_DEFAULT_ACCESS`:** Standard `entitlement`. Die
  WP-App kennt `logged_in` („eingeloggt genügt“); gesetzt bekommt JEDES
  Mitglied der Schule Vollzugriff auf alle angebotenen Kurse. Nur nach
  ausdrücklicher Geschäftsentscheidung setzen; der Import ändert den Wert
  nicht.

**Kursdateien (Downloads, Abschnittsdateien, Karten):**

* `GET /courses/{id}` listet `downloads`, `section_files` (je
  `section_key`) und `charts`. Jeder Eintrag (`FileRef`) hat `locked` und,
  wenn frei, `url` = `GET /files/{fileId}`. Die App ruft die Datei mit Token
  und `X-Tenant-Slug` ab (`requires_authorization: true`); es gibt keine
  öffentlichen oder ratbaren Adressen.
* Regeln (`App\Api\CourseFiles`, gleich für Liste und Download):
  Kategorie `download` verlangt die Funktion `downloads` (voller Kurszugang,
  Zugang mit Capability `downloads` oder Schulregel `downloads`;
  `free_limited` = die ersten N Downloads in Kursreihenfolge). Andere
  Dateien verlangen ihre `required_capability`, ohne Angabe vollen
  Kurszugang. Gesperrt: 403 `ENTITLEMENT_REQUIRED`; unbekannte,
  unveröffentlichte Dateien und Kurse, die die Schule nicht anbietet: 404.
* `capabilities` enthält `downloads`, sobald der Kurs veröffentlichte
  Download-Dateien hat; `features.downloads` zeigt dann den Zugang.
* Kursdateien sind plattformweit (Tabelle `course_file` ohne Schulbezug,
  App-Rolle nur lesend) und liegen auf der privaten Disk unter
  `course-files/`; die Datenbank lässt nur Pfade dort ohne `..` zu.
  PDFs, Bilder, Audio/Video und Text kommen `inline`, andere Typen nur als
  Anhang (`application/octet-stream`). Range-Anfragen (206) werden
  unterstützt.
* **Fragenbilder** tragen zusätzlich `file_id` und `download_url` (ebenfalls
  `GET /files/{fileId}`, z. B. zum Offline-Speichern). Abrufbar, wenn die
  Frage im freigegebenen Fragenkatalog der Person liegt (inkl. Probezugang
  und Drip) oder sie Prüfungszugang im Kurs hat; sonst 404. `url` bleibt
  unverändert.

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
* **Antwort-IDs sind Kennungen je Person** (`answers[].id`,
  `selected_answer_ids`, `correct_answer_ids`, auch in Prüfung und
  Prüfungsverlauf): UUID-förmig, für die angemeldete Person stabil, aber
  nicht die interne ID der Antwort. Die App schickt genau die Kennung
  zurück, die sie bekommen hat. Kennungen nicht zwischen Konten teilen oder
  vergleichen -- für andere Personen sind sie ungültig (422 „Unbekannte
  Antwort.“).
* Fragen einer laufenden Prüfung sind gesperrt, auch bei einer anderen
  Schule (409 `EXAM_QUESTION_LOCKED`).

`POST /courses/{id}/attempts` (mit Idempotency-Key) gibt eine Antwort ab:

* Einfachauswahl: `selected_answer_ids` mit genau einer ID.
* Mehrfachauswahl: alle gewählten IDs.
* Offene Frage: Musterantwort aus `question.model_answer` aufdecken, dann
  `known: true/false` senden. WP-kompatibel geht auch
  `POST /courses/{id}/trainer/reveal {answer_token}` mit dem `answer_token`
  aus `trainer/next` (die ausgelieferte Revision). Kurszugang und
  Kurszugehörigkeit werden dabei neu geprüft; nur offene Fragen (sonst 422),
  Fragen einer laufenden Prüfung → 409 `EXAM_QUESTION_LOCKED`.
* `GET /courses/{id}/attempts/{attemptId}` liefert das Ergebnis erneut;
  steckt die Frage gerade in einer laufenden Prüfung: 409
  `EXAM_QUESTION_LOCKED` (keine Lösung vor der Abgabe).

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
* **Fehlerliste:** `GET /courses/{id}/mistakes` listet die zuletzt falsch
  beantworteten, noch gültigen Fragen des Kurses (gleiche Regel wie
  `mode=wrong`: mindestens ein Versuch, aktuelle Serie 0), neueste zuerst,
  mit Favoriten- und Sammlungsstatus, ohne Lösung. Üben:
  `trainer/next?mode=wrong`.

## 8. Prüfungssimulation

```
GET  /courses/{id}/exams                → Bögen/Optionen, Bestehensregel, laufend?, letztes Ergebnis
GET  /courses/{id}/exam-sessions        → bisherige Versuche (Verlauf)
POST /courses/{id}/exams {paper_id}     → 201 neu / 200 laufende fortsetzen
GET  /exams/{id}                        → Fragen ohne Lösung, gegebene Antworten, phase, Zeit
PUT  /exams/{id}/answers/{questionId}   → festschreiben
POST /exams/{id}/questions/{qid}/reveal → Musterantwort (offene Fragen)
PUT  /exams/{id}/answers/{qid}/flag     → Frage markieren {flagged: true/false}
POST /exams/{id}/answers/{qid}/reveal   → WP-kompatibler Alias für …/questions/{qid}/reveal
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
* **Markieren:** `PUT …/answers/{qid}/flag` speichert eine Markierung zur
  Durchsicht am Server (bleibt beim Fortsetzen auf einem anderen Gerät
  erhalten), auch für schon beantwortete Fragen, und wirkt sich nicht auf
  die Auswertung aus. `GET /exams/{id}` liefert je Frage `flagged` und
  `flagged_count`. Bei `…/flag` und dem Reveal-Alias darf `{qid}` auch die
  Position der Frage sein (1-basiert).
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
  * `code` (stabiler Kapitel-Code) und `group` (Gruppenüberschrift) sind
    optional und meist `null`.
  * `meta`: `lessons`, `completed` und `next_lesson_id` (nächste offene
    Lektion im Kursfluss, ohne eigenständige Kapitel).
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
  * `POST /navigation-tasks/{id}/parts/{partId}` ist der Weg im Format der
    bisherigen App: Antwort und/oder dreistufige Selbstbewertung
    (`correct`, `partly`, `wrong`; nur `correct` zählt als richtig). Eine neue
    Antwort setzt eine frühere Selbstbewertung zurück. Der PUT-Weg speichert
    `correct`/`incorrect` als `correct`/`wrong` mit. Die Aufgabe zeigt die
    Bewertung je Teilfrage (`parts[].self_assessment`).
* **Code einlösen:** `POST /coupons/redeem {code}`.
  * Groß-/Kleinschreibung und Leerzeichen am Rand sind egal; beim
    16-stelligen Standardformat auch Leerzeichen statt oder fehlende
    Bindestriche.
  * Danach `GET /courses` neu laden.
  * `type`: `course`, `course_renewal`, `product` oder `group_license`
    (Lerngruppe, siehe unten).
* **Lerngruppen (Gruppenlizenzen):** eine Gruppe gehört genau einer Schule
  und hat eine feste Zahl Plätze für einen oder mehrere Kurse (ganzer Kurs
  oder einzelne Funktionen, z. B. nur Prüfungssimulation).
  * **Anlegen** durch den Betreiber (`app:learning-group-create`, siehe
    Betrieb) oder durch Inhaber/Admins der Schule im Web-Admin-Bereich
    (Tab „Lerngruppen“, gleiche Regeln über `LearningGroupService::create`,
    nur Kurse, die die Schule anbietet). Dort auch: Name, Plätze (nicht
    unter die belegten), Ende und Verwalter ändern (neuer Verwalter muss
    aktiver Teilnehmer sein; ein neues Ende gilt auch für die Gruppen-
    zugänge der aktiven Mitglieder), Mitglieder einsehen und entfernen,
    Gruppe beenden -- jeweils mit Protokolleintrag (`learning_group.*`).
    Ausbilder und Lernende haben keinen Zugriff. Die App verwaltet
    bestehende Gruppen, legt aber keine an.
  * **Verwalten** darf nur der eingetragene Verwalter (aktiver Teilnehmer
    der Schule): `GET /me/groups`, `GET /me/groups/{id}`,
    `POST /me/groups/{id}/invites {emails[]}`,
    `DELETE /me/groups/{id}/members/{memberId}`,
    `POST /me/groups/{id}/join-code`. Alle mit `X-Tenant-Slug`; Gruppen
    anderer Schulen und anderer Verwalter ergeben 404.
  * **Plätze:** aktive Mitglieder und offene Einladungen (30 Tage gültig).
    Einladen und Beitreten sperren die Gruppe kurz, parallele Anfragen
    können die Platzzahl nicht überziehen.
  * **Einladung:** jede gültige Adresse bekommt eine Mail mit persönlichem
    Code, gleich ob es ein Konto gibt -- die Antwort (`invited`, `skipped`)
    verrät das nicht. Der Name erscheint erst nach dem Beitritt.
  * **Beitreten:** `GET /groups/join/{token}` zeigt die Vorschau,
    `POST /coupons/redeem` mit dem Beitrittscode (`g…`), der persönlichen
    Einladung (`i…`, nur mit der eingeladenen Adresse) oder dem Link
    `…/gruppe/<code>/` tritt bei. Nur Teilnehmer der Schule der Gruppe; je
    Kurs entsteht ein Kurszugang mit `source_type = group` (bei einzelnen
    Funktionen mit `capabilities`), gültig bis zum Ende der Gruppe.
  * **Entfernen** gibt den Platz frei und beendet genau die Gruppen-
    zugänge der Person (`status: expired`, Enddatum jetzt -- kein
    `revoked`, das bleibt der bewussten Sperre durch die Schule
    vorbehalten); eigene Zugänge (Kauf, Code, Schule) bleiben, ein eigener
    Kurscode bzw. das Freischalten durch die Schule ist danach wie gewohnt
    möglich. Über den Beitrittscode kommt sie nicht wieder hinein, nur mit
    einer neuen persönlichen Einladung.
* **Datenexport:**
  * `POST /profile/export` erzeugt eine JSON-Datei mit allen Daten bei
    dieser Schule, inkl. der App-Geräte (`status: ready`, bei einem Fehler
    `failed`). Ohne Idempotency-Key: jeder Aufruf erzeugt einen frischen
    Export.
  * `GET /profile/export/{id}` liefert `download_url`: signiert, 10 Minuten
    gültig, ohne Token nutzbar (z. B. Teilen-Menü).
  * Nach 60 Minuten ist der Export abgelaufen; die Datei löscht das
    stündliche Aufräumen.

### Tipps und Kunden-FAQs

* `GET /tips` liefert die aktiven Tipps und Tricks nach Kategorie (dieselben
  wie im Web, plattformweit). Schulkontext nötig, aber kein Kurszugang.
  Je Tipp: bereinigtes `html` (relative Links absolut, Bilder nur über
  http(s)), reiner `text` und die externen `links`.
* `GET /courses/{id}/faqs` liefert die veröffentlichten Kunden-FAQs eines
  Kurses nach Kategorie mit Unterkategorien (flache Liste mit `depth` und
  `parent_id`). Kurszugang nötig. Kombikurse (z. B. `SRC-UBI`,
  `SBF-SEE-BIN`) zeigen zusätzlich die FAQs der enthaltenen Kurse; jede FAQ
  erscheint nur einmal.
* Die FAQs sind plattformweiter Inhalt (wie die Tipps, ohne Schulbezug) und
  kommen per Import aus der alten WordPress-App:

  ```sh
  php artisan mobile-api:import-faqs storage/app/import/mobile-faqs.json
  ```

  Die Datei enthält `categories` (`source_key`, `course_code`, `name`,
  `slug`, `parent_source_key?`, `sort_order?`) und `faqs` (`source_key`,
  `question`, `answer` als HTML, `status?` = `draft`/`published`/`archived`,
  `sort_order?`, `category_source_keys`). Der Import prüft erst die ganze
  Datei, aktualisiert dann über die `source_key`s in einer Transaktion und
  löscht nichts, was in der Datei fehlt. Er läuft über die Owner-Verbindung;
  die App-Rolle darf die FAQ-Tabellen nur lesen.

## 10a. Praxistrainer, Funk-Training, Prüfungsanmeldung

Alle Endpunkte prüfen Schule (`X-Tenant-Slug`) und Kurszugang wie die
übrigen Kursendpunkte (403 `ENTITLEMENT_REQUIRED`/`ENTITLEMENT_EXPIRED`).
Der Lernstand ist derselbe wie im Web.

* **Praxistrainer:** `GET /courses/{id}/praxis` liefert die Karten nach
  Kategorie mit Frage, Antwort und Erklärung (Aufdecken in der App).
  * `PUT /courses/{id}/praxis/{cardId} {status}` mit `open`, `practice`
    oder `known`. `known` ist „Gemeistert“ im Web; „Wieder öffnen“ im Web
    setzt auf `open`.
  * `title` ist `null`, wenn die Unterkategorie die Lösung verraten würde.
    Bilder haben `role`: `question` (zur Frage) oder `solution` (erst nach
    dem Aufdecken zeigen).
* **Funk-Training (SRC/UBI):** unter `/courses/{id}/radio`; Kurse ohne
  Funkschein liefern 404.
  * Überblick, Buchstabiertafel (`/spelling`), Texte (`/texts/{textId}`),
    Vokabeln (`/vocabulary`), Praxisaufgaben (`/practice`) und
    Musterlösungen (`/samples`).
  * Texte: `translation` (Seefunktexte, Lösung der gewählten Richtung erst
    nach der ersten Auswertung), `dictation` (UBI-Aufnahme) und `spelling`
    (UBI-Abgabe, nicht auswertbar). Die Auswertung ist eine grobe
    Wort-Übereinstimmung wie im Web; leere Felder werden nicht bewertet.
  * Vokabeln: `POST …/vocabulary/{cardId}/answers {known, streak?}` mit
    `Idempotency-Key`. Nach 3× „gewusst“ in Folge gilt die Karte als
    gelernt. Mit `streak` (Stand der App) wird ein doppelt gesendeter
    Klick erkannt (`repeated: true`).
  * Die Gerätesimulation gibt es nur im Browser (`device_simulator.web_url`,
    nur wenn sie für die Person freigeschaltet ist).
* **Prüfungsanmeldung:** `GET /courses/{id}/exam-registration`, nur lesend.
  * `mode: school` (Standard): die Schule meldet an, es gilt ihr Termintool;
    `guides` ist leer.
  * `mode: self`: die Anleitung der Web-Seite „Prüfung buchen“ (Gebühren,
    Fristen, Unterlagen, Varianten, Prüfungsverbände) und `web_url`. Wer
    schon eine Praxis-Anmeldung abgeschickt hat, behält das Termintool
    (`uses_appointment_tool: true`).
  * Angemeldet wird nie in der App (`registration_in_app: false`).

## 11. Fehlercodes

| HTTP | `code` | Bedeutung / App-Reaktion |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Token fehlt/abgelaufen → einmal `refresh`, sonst Login |
| 403 | `EMAIL_NOT_VERIFIED` | Bestätigungsmail anbieten |
| 403 | `TENANT_MEMBERSHIP_REQUIRED` / `_SUSPENDED`, `TENANT_UNAVAILABLE` | Schulwahl / Support |
| 403 | `ENTITLEMENT_REQUIRED` / `ENTITLEMENT_EXPIRED` | Webshop-Link, Code-Eingabe |
| 403 | `FORBIDDEN` | keine Berechtigung |
| 403 | `LINK_INVALID` | Download-Link manipuliert oder abgelaufen → Status neu laden |
| 403 | `WEB_SESSION_DISABLED` / `WEB_SESSION_NOT_ALLOWED` | Webansicht ohne Anmeldung öffnen bzw. auf den Browser verweisen |
| 403 | `ACCOUNT_DELETION_NOT_ALLOWED` | Plattform-Konto: an den Support verweisen |
| 404 | `RESOURCE_NOT_FOUND` | auch für fremde Daten (keine Aussage über Existenz) |
| 405 | `METHOD_NOT_ALLOWED` | Programmierfehler in der App |
| 409 | `CONFLICT` | allgemeiner Konflikt bzw. dieselbe Anfrage läuft noch (`Retry-After`) |
| 409 | `IDEMPOTENCY_KEY_REUSED` | neuen Schlüssel erzeugen |
| 409 | `COLLECTION_NOT_DELETABLE` | Standard-Sammlung |
| 409 | `VERSION_CONFLICT` | App-Einstellungen anderswo geändert → `details.current` übernehmen oder zusammenführen |
| 409 | `EXAM_NOT_AVAILABLE` | Regelwerk nicht freigegeben |
| 409 | `EXAM_ANSWER_LOCKED` | Antwort bereits festgeschrieben |
| 409 | `EXAM_QUESTION_LOCKED` | Frage gerade in einer laufenden Prüfung (Lernmodus) |
| 409 | `EXAM_QUESTIONS_OPEN` | erst den Fragebogen beenden, dann Navigation |
| 409 | `EXAM_ALREADY_SUBMITTED` / `EXAM_NOT_SUBMITTED` | Ergebnis bzw. Prüfung laden |
| 409 | `EXAM_NO_NAVIGATION_PART`, `EXAM_NAVIGATION_ALREADY_SUBMITTED`, `EXAM_NAVIGATION_NOT_SUBMITTED` | Phase neu laden / nachfragen |
| 409 | `NAVIGATION_TASK_IN_EXAM` | erst Prüfung beenden (`exam_id` im Fehler) |
| 409 | `GROUP_NOT_ACTIVE` | Lerngruppe abgelaufen oder gesperrt |
| 410 | `EXAM_EXPIRED` | Prüfung bzw. Ergebnis neu laden |
| 410 | `EXPORT_EXPIRED` | neuen Export anfordern |
| 410 | `GONE` | allgemein: nicht mehr verfügbar |
| 422 | `VALIDATION_FAILED` | Eingaben prüfen, Details in `errors` |
| 422 | `TWO_FACTOR_INVALID` | 2FA-Code falsch |
| 422 | `PASSWORD_RESET_INVALID_OR_EXPIRED` | neuen Link anfordern |
| 422 | `COUPON_INVALID` | „Code ungültig, abgelaufen oder bereits eingelöst“ |
| 422 | `COUPON_NOT_APPLICABLE` | Meldung aus `detail` anzeigen |
| 422 | `DELETION_CONFIRMATION_INVALID` | Löschlink ungültig, abgelaufen oder benutzt → Löschung neu beantragen |
| 422 | `GROUP_SCOPE_UNSUPPORTED` | Gruppenumfang nicht abbildbar → Support |
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
  * Exporte und ihre Dateien nach Ablauf,
  * offene Löschanträge nach Ablauf (7 Tage),
  * Einmal-Codes der Webansicht nach einem Tag,
  * abgelaufene Lerngruppen-Einladungen (Adresse und Code werden entfernt).
* **Konto löschen in der App** (`POST /me/deletion-request` mit
  `X-Tenant-Slug`) wirkt wie „Konto löschen“ im Web-Profil, nur bestätigt
  die Person per Link aus einer Mail statt mit ihrem Passwort:
  * Die Mail enthält einen Link auf `MOBILE_API_DELETION_CONFIRMATION_PATH`
    der zentralen Domain mit `request_id` und `confirm_key` (7 Tage gültig,
    nur der Hash wird gespeichert). Die App öffnet ihn als Universal-/App-Link
    und ruft `POST /me/deletion-request/confirm` auf (ohne Token).
  * Ohne installierte App öffnet der Link im Browser eine
    **Bestätigungsseite** (nur zentrale Domain, Gast-Layout mit dem Branding
    der Schule): Schule, was gelöscht wird, ob das ganze Konto entfällt,
    Gültigkeit des Links. Das Aufrufen löscht nichts (Link-Vorschauen der
    Mailprogramme); erst „Endgültig löschen“ (POST an denselben Pfad, CSRF)
    löscht mit derselben Logik wie die App (`AppAccountDeletion`). Ungültige,
    abgelaufene oder benutzte Links bekommen eine einheitliche Meldung ohne
    Hinweis, ob es den Antrag gab. Der Link ist der Nachweis: gelöscht wird
    das Konto aus dem Antrag, auch wenn im Browser jemand anderes angemeldet
    ist; die eigene Web-Sitzung endet, wenn das Konto ganz entfällt.
  * Prüfversuche sind je Link (10) und je IP (20) pro Stunde begrenzt; App
    und Webseite teilen sich die Kontingente.
  * Die Bestätigung löscht sofort Mitgliedschaft und alle Daten bei dieser
    Schule und pseudonymisiert das Protokoll (`AccountErasureService::
    leaveTenant`). Ohne weitere Mitgliedschaft wird das Konto gelöscht, alle
    App- und Web-Sitzungen enden (`account_deleted: true`).
  * Ein Eintrag `profile.delete` (Kanal `app` bzw. `web`, ohne
    Personenbezug) bleibt als Nachweis im Protokoll der Schule.
* **Konto löschen** entfernt App-Sitzungen, App-Einstellungen, Exporte samt
  Dateien und gespeicherte Antworten sofort. **Eine Schule verlassen** entfernt die
  Exporte, offenen Löschanträge und Webansicht-Codes dieser Schule samt
  Dateien; die App-Sitzungen, App-Einstellungen und gespeicherten Antworten
  (ohne Schulbezug, nach 24 Stunden ohnehin gelöscht) gelten kontoweit und
  bleiben, solange eine weitere Mitgliedschaft besteht.
* **Lerngruppen:** `learning_group` und `learning_group_member` haben
  `tenant_id` und RLS je Schule; Mitglieder und Verwalter müssen per
  Fremdschlüssel Mitglieder derselben Schule sein. Eine Schule verlassen
  entfernt die eigenen Gruppenplätze und Einladungen an die eigene Adresse
  (bei dieser Schule); selbst verwaltete Gruppen bleiben ohne Verwalter
  bestehen, damit die übrigen Mitglieder ihren Zugang behalten -- einen
  neuen Verwalter tragen Inhaber/Admins im Admin-Bereich ein. Die
  Kontolöschung entfernt auch offene Gruppeneinladungen anderer Schulen.
  Der Datenexport enthält eigene Plätze/Einladungen und für verwaltete
  Gruppen nur Kennzahlen; der Export beim Vertragsende der Schule enthält
  alle Gruppen samt Mitgliedern.
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
  * `MOBILE_API_MIN_VERSION_IOS` / `_ANDROID` und `MOBILE_API_STORE_URL_IOS`
    / `_ANDROID` (leer = keine Mindestversion)
  * `MOBILE_API_WEB_SESSION_ENABLED` (Standard `true`): Einmal-Anmeldung
    der Webansicht
  * `MOBILE_API_DELETION_CONFIRMATION_PATH` (Standard `/app/konto-loeschen`):
    Pfad des Bestätigungslinks (Web-Bestätigungsseite ohne App); muss als
    Universal Link (iOS) bzw. App Link (Android) auf die App zeigen
  * `MOBILE_API_IOS_APP_IDS` (`<Team-ID>.<Bundle-ID>`, kommagetrennt) sowie
    `MOBILE_API_ANDROID_PACKAGE` und `MOBILE_API_ANDROID_SHA256_FINGERPRINTS`
    (kommagetrennt): Die zentrale Domain liefert damit
    `/.well-known/apple-app-site-association` (nur dieser Pfad) bzw.
    `/.well-known/assetlinks.json` aus. Ohne Werte gibt es beide Dateien
    nicht (404), der Link öffnet dann immer die Webseite. Die App braucht
    zusätzlich die Associated Domain `applinks:<zentrale Domain>` bzw. einen
    Intent-Filter mit `autoVerify` für den Pfad. Wer die Dateien statisch
    ausliefert (Webserver/CDN), lässt die Variablen leer.
* **Wartungsmodus** der Plattform (Superadmin-Einstellungen) gilt auch für
  die API (503 `MAINTENANCE`); `GET /app/config` und `GET /health` bleiben
  erreichbar.
* **Keine CORS-Freigabe nötig** (native Apps). Die API nutzt keine Cookies
  und keine Sessions (Ausnahme: das Einlösen des Webansicht-Codes meldet die
  Website-Sitzung an).
* **Migrationen:** `2024_02_01_000591_create_mobile_api_tables` legt
  `api_device_session`, `api_idempotency_key` und `api_data_export` an,
  `…_000592_create_mobile_customer_faq_tables` die FAQ-Tabellen (nur lesbar
  für die App-Rolle), `…_000593_create_api_user_setting_table` die
  App-Einstellungen, `…_000600` (Löschanträge) und `…_000601`
  (Webansicht-Codes) mit `tenant_id` und Row-Level-Security.
* **Zugänge und Zugriffsregeln aus WordPress übernehmen** (Owner-
  Verbindung, idempotent über Quellschlüssel, legt keine Schulen oder Konten
  an):
  ```sh
  php artisan app:import-mobile-entitlements /pfad/mobile-entitlements.json
  php artisan app:import-mobile-access-rules /pfad/mobile-access.json
  ```
  Entitlements: `{"tenant_slug": "...", "entitlements": [{"email", "course_code",
  "feature_code" ("all" oder WP-Funktion), "source_key", "status": "active",
  "valid_from"?, "valid_until"?}]}`. Das App-Konto mit derselben E-Mail muss
  bestehen; fehlende Mitgliedschaften werden als Lernende angelegt, gesperrte
  oder mehrdeutige Konten brechen ab. Der Export enthält E-Mail-Adressen.
  Regeln: `{"default_access"?, "rules": [{"tenant_slug", "course_code",
  "feature_code", "access_type" (free/free_limited/drip), "limit_value"?,
  "module_code"? (nur drip), "valid_from"?, "valid_until"?, "source_key"?}]}`.
  `module_code` ist der native Modulcode. Video-Drip-Regeln brauchen die
  WP-Kapitelkennung an den Videokapiteln und brechen sonst ab.
* **Kursdateien aus WordPress übernehmen** (Owner-Verbindung, kopiert in
  die private Disk, idempotent über `course_code` + `source_key`):
  ```sh
  php artisan app:import-mobile-course-files /pfad/manifest.json /pfad/wp-medien
  ```
  Manifest: `{"files": [{"course_code", "source_key", "file" (relativ zum
  Quellverzeichnis), "category" (download/section/chart), "title",
  "description"?, "section_key"? (nur section/chart), "required_capability"?,
  "mime_type"?, "sha256"?, "sort_order"?, "published"? (Standard true)}]}`.
  Dateien außerhalb des Quellverzeichnisses oder mit falscher Prüfsumme
  brechen den ganzen Lauf ab (bereits kopierte Dateien werden entfernt).
* **Lerngruppen anlegen und beenden** (Owner-Verbindung):
  ```sh
  php artisan app:learning-group-create <schul-slug> <verwalter@mail> "Segelverein" \
      --seats=10 --course=SRC --course=FKN:exam_simulation --valid-until=2027-12-31
  php artisan app:learning-group-revoke <gruppen-id>
  ```
  Der Verwalter muss aktiver Teilnehmer der Schule sein. `--course` ist ein
  Kurscode, optional mit WP-Funktion (`question_bank`, `exam_simulation`,
  `video_course`, `navigation_trainer`, `praxis_trainer`, `funk_training`).
  Beenden beendet alle Gruppenzugänge (`expired`, Enddatum jetzt); offene
  Einladungen verfallen. `--valid-until` gilt bis Tagesende deutscher Zeit.
  Dasselbe geht für Inhaber/Admins im Admin-Bereich (Tab „Lerngruppen“).
  Migrationen: `2024_02_01_000597_create_learning_group_tables`,
  `2024_02_01_000760_end_group_entitlements_as_expired` (stellt bisher
  widerrufene Gruppenzugänge auf `expired` um),
  `2024_02_01_000800_grant_insert_on_learning_group_to_app_role` (die
  App-Rolle darf Gruppen einfügen -- nur in der eigenen Schule, RLS mit
  `WITH CHECK`; Löschen bleibt der Owner-Verbindung vorbehalten).
* **Tests:** `vendor/bin/phpunit tests/Feature/Api`.
  `OpenApiContractTest` schlägt fehl, wenn Routen und OpenAPI-Datei
  auseinanderlaufen.

## 14. Nicht in v1

* Offline-Modus und Synchronisation.
* Push-Benachrichtigungen.
* In-App-Käufe (Kauf bleibt im externen Webshop).
* Funkgeräte-Simulator (nur Link in den Browser), Video-Quiz und
  Prüfungstermine (eine Video-Lektion lässt sich deshalb ohne Quiz
  abschließen).
* Untertitel und Vorschaubilder für Videos; Seekarten fehlen bisher in den
  Navigationsaufgaben (`chart: null`), dafür braucht es die Papierkarte.
* Registrierung in der App.
