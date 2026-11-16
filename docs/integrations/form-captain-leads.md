# Lead-Eingang für Form Captain (Vertrag `form-captain-lead/1`)

**Status:** Version 1, übernommen am 16.11.2026 aus dem Entwurf `hastla007/wp-formmaster`,
`docs/spec/projekt-connectoren-vertraege.md`, Teil A (P8-42). Mit dieser Datei ist der Vertrag für die Plattform
verbindlich (BS-07). Abweichungen vom Entwurf stehen in Abschnitt 10 und gehen an `wp-formmaster` zurück.
Der Pilot braucht weiterhin die Freigabe des Inhabers (P8-40).

**Code:** `routes/integrations.php`, `app/Http/Middleware/Integrations/VerifyFormCaptainSignature.php`,
`app/Http/Controllers/Integrations/FormCaptainLeadController.php`, `app/Integrations/FormCaptain/`,
`app/Models/FormCaptainLead.php`, Inbox `app/Http/Controllers/Superadmin/LeadController.php`,
WordPress-Connector `integrations/boatingschool-form-connector/` (eigene README).
**Tests:** `tests/Feature/Integrations/`, `tests/Unit/FormConnectorContractTest.php`.

## 1. Abgrenzung

- Ein **Lead** ist ein unqualifizierter Interessent aus einem Formular der Website (Demo, Beratung, Infos zum
  Schulzugang). Er gehört zu keinem Tenant. Der Eingang legt **nie** einen Benutzer, eine Schule, ein Shopkonto,
  App-Konten oder Codes an; die Inbox bietet auch keinen Knopf dafür. Wird aus einem Lead eine Schule, legt der
  Superadmin sie wie bisher unter „Bootsschulen“ an.
- Der Endpunkt ist **getrennt** von der Commerce-API `/api/commerce/v1` (P8-41, am Code bestätigt bei `d152f21`):
  eigene Route, eigene Middleware, eigener HMAC-Schlüssel (`FORM_CAPTAIN_LEAD_*`, nie `COMMERCE_SHOP_SECRET`), eigene
  Drosselung, keine Commerce-Rechte, nicht die `api`-Gruppe der Mobile-API.

## 2. Endpunkte

Basis: `https://<zentrale Domain>/api/integrations/v1/form-captain`

| Methode und Pfad | Zweck |
|---|---|
| `POST /leads` | Lead anlegen (Body Abschnitt 4, `Idempotency-Key` Pflicht) |
| `POST /ping` | Verbindungstest, Body `{}`; Antwort `200 {"ok":true,"schema":"form-captain-lead/1","key_id":"…"}`, legt nichts an |
| `DELETE /leads/{lead_id}` | Löschung aus Form Captain, ohne Body; `204` gelöscht, `404 not_found` nicht (mehr) vorhanden |

Nur JSON (`Content-Type: application/json`), Body höchstens 16 KB, keine Query-Parameter. Jede Antwort trägt
`Cache-Control: no-store, private`.

## 3. Authentisierung (HMAC)

| Header | Inhalt |
|---|---|
| `X-FC-Key-Id` | Kennung des Schlüssels, z. B. `fc-lead-2026-11` |
| `X-FC-Timestamp` | Unix-Sekunden UTC |
| `X-FC-Nonce` | 32 Hex-Zeichen (klein), zufällig je Anfrage |
| `X-FC-Signature` | `v1=` + hex( HMAC-SHA256( secret, `"v1\n{timestamp}\n{nonce}\n{METHODE}\n{pfad}\n" + hex(SHA-256(body))` ) ) |

- `{pfad}` ist der Pfad der Anfrage mit führendem `/`, ohne Host und Query (`/api/integrations/v1/form-captain/leads`).
  Signiert werden alle drei Endpunkte, auch `DELETE` (Body leer).
- `|jetzt − timestamp| ≤ 300 s`, sonst `401 signature_expired`. Vergleich in konstanter Zeit.
- Nonce je Key-Id 10 Minuten gespeichert (Cache); Wiederholung → `409 replay_detected`.
- Unbekannte Key-Id → `401 key_unknown`; widerrufene Key-Id → `403 key_revoked`.

**Schlüssel und Rotation** (`config/form_captain.php`):

```
FORM_CAPTAIN_LEAD_KEY_ID=fc-lead-2026-11
FORM_CAPTAIN_LEAD_SECRET=<mind. 32 zufällige Zeichen>
FORM_CAPTAIN_LEAD_KEY_ID_NEXT=fc-lead-2026-12      # nur während einer Rotation
FORM_CAPTAIN_LEAD_SECRET_NEXT=<neues Geheimnis>
FORM_CAPTAIN_LEAD_REVOKED_KEY_IDS=fc-lead-2026-10  # kommagetrennt, liefert 403 key_revoked
FORM_CAPTAIN_LEAD_SITE_URLS=https://www.boatingschool.example   # optional: erlaubte Websites
```

Ablauf: neues Paar als `…_NEXT` eintragen und deployen, im Connector eintragen, alten Schlüssel nach höchstens 7 Tagen
entfernen bzw. widerrufen. Ohne `FORM_CAPTAIN_LEAD_SECRET` ist der Endpunkt abgeschaltet (alle Aufrufe `401`). Die
Inbox zeigt je Schlüssel „zuletzt benutzt“ (nie das Geheimnis).

## 4. Body (`form-captain-lead/1`)

JSON-Schema (Draft 2020-12) unverändert aus dem Entwurf:

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "form-captain-lead/1",
  "type": "object",
  "additionalProperties": false,
  "required": ["schema", "source", "contact", "consent", "submitted_at"],
  "properties": {
    "schema":       { "const": "form-captain-lead/1" },
    "source": {
      "type": "object", "additionalProperties": false,
      "required": ["site_url", "form_id", "entry_id"],
      "properties": {
        "site_url":   { "type": "string", "format": "uri", "maxLength": 200 },
        "page_url":   { "type": "string", "format": "uri", "maxLength": 500 },
        "form_id":    { "type": "integer", "minimum": 1 },
        "entry_id":   { "type": "integer", "minimum": 1 },
        "plugin_version": { "type": "string", "maxLength": 20 }
      }
    },
    "purpose":      { "enum": ["demo", "consultation", "school_access_info"] },
    "contact": {
      "type": "object", "additionalProperties": false,
      "required": ["email"],
      "properties": {
        "name":         { "type": "string", "maxLength": 120 },
        "email":        { "type": "string", "format": "email", "maxLength": 190 },
        "phone":        { "type": "string", "maxLength": 40 },
        "organisation": { "type": "string", "maxLength": 160 },
        "role":         { "type": "string", "maxLength": 80 }
      }
    },
    "school_size":  { "enum": ["1-2", "3-10", "11-50", "50+"] },
    "message":      { "type": "string", "maxLength": 4000 },
    "consent": {
      "type": "object", "additionalProperties": false,
      "required": ["given_at", "text_revision"],
      "properties": {
        "given_at":      { "type": "string", "format": "date-time" },
        "text_revision": { "type": "integer", "minimum": 1 },
        "text_hash":     { "type": "string", "pattern": "^[a-f0-9]{64}$" }
      }
    },
    "submitted_at": { "type": "string", "format": "date-time" }
  }
}
```

Geprüft von `App\Integrations\FormCaptain\LeadSchema` (ohne Bibliothek): unbekannte Eigenschaften werden abgelehnt,
nicht verworfen; `format: uri` = absolute `http`/`https`-URL; `format: date-time` = RFC 3339 mit `Z` oder Offset,
kein Überlauf (`2026-02-30` ist ungültig). Gespeichert werden genau diese Felder, sonst nichts.

**Nie übertragen:** sensible oder versteckte Felder, IP-Adressen, User-Agent, interne Notizen, Werte anderer
Formularfelder, der Einwilligungstext selbst (nur Revision und optional Hash).

## 5. Idempotenz

- `Idempotency-Key` = Schlüssel des Form-Captain-Jobs (`form-captain:{site_id}:{entry_id}:{action_key}:{instance_id}`),
  höchstens 191 Zeichen aus `A–Z a–z 0–9 : . _ -`.
- Die Plattform speichert Schlüssel, SHA-256 des Bodys und Ergebnis in `form_captain_lead_request` mindestens 30 Tage
  (`app:prune-form-captain-leads`), **auch nach dem Löschen des Leads** und **über eine Schlüsselrotation hinweg**.
- Gleicher Schlüssel + gleicher Body-Hash → dieselbe Antwort mit derselben `lead_id`, Status `200` (statt `201`) bzw.
  `202`, Header `Idempotent-Replay: true`. Ist der Lead inzwischen gelöscht, steht `"status": "deleted"` darin; er wird
  nicht neu angelegt.
- Gleicher Schlüssel + anderer Body-Hash → `409 idempotency_conflict`.
- Gleichzeitige Zustellung desselben Jobs: eindeutiger Index auf dem Schlüssel; die zweite Anfrage bekommt die Antwort
  der ersten.

## 6. Antworten und Fehlercodes

Format: `{"code": "…", "message": "…"}`, bei Erfolg zusätzlich `lead_id` und `status`, bei `422` zusätzlich
`errors: [{"pointer": "/contact/email", "code": "format"}]` (Codes: `required`, `type`, `additional_property`,
`max_length`, `format`, `enum`, `const`, `minimum`, `pattern`). Fehlerantworten enthalten nie Werte aus dem Body.

| HTTP | `code` | Bedeutung | Ergebnis im Connector |
|---|---|---|---|
| 201 | `created` | Lead angelegt, `{ "lead_id": "L-<ULID>", "status": "new" }` | `success`, `remote_id` = `lead_id` |
| 200 | `created` | idempotente Wiederholung | `success` (`already_created`), gleiche `remote_id` |
| 202 | `accepted_merged` | Tagesgrenze der Adresse erreicht, kein neuer Lead, bestehende `lead_id` | `success` |
| 400 | `bad_request` | kein JSON-Objekt, zu groß, kein JSON-Content-Type, Query-Parameter, `Idempotency-Key` fehlt/ungültig | `fail` |
| 401 | `signature_invalid` / `signature_expired` / `key_unknown` | Authentisierung | `fail` (`signature_expired` beim ersten Versuch `retry`) |
| 403 | `key_revoked` | Schlüssel widerrufen | `fail` |
| 403 | `site_not_allowed` | `source.site_url` nicht in `FORM_CAPTAIN_LEAD_SITE_URLS` | `fail` |
| 404 | `not_found` | nur `DELETE`: Lead nicht (mehr) vorhanden | Löschung erledigt |
| 409 | `replay_detected` | Nonce wiederverwendet | `retry` mit neuer Nonce |
| 409 | `idempotency_conflict` | gleicher Schlüssel, anderer Inhalt | `fail` |
| 422 | `schema_invalid` | Body verletzt das Schema | `fail` |
| 429 | `rate_limited` | Grenze überschritten, Header `Retry-After` | `retry` |
| 5xx / Timeout | – | Server- oder Netzfehler | `retry` (Backoff des Kerns, höchstens 8 Versuche) |

Reihenfolge der Prüfung: Größe, Query, Content-Type → Key-Id (widerrufen, unbekannt) → Format der Header →
Zeitfenster → Signatur → Nonce → Drosselung → `Idempotency-Key` → JSON → Schema → erlaubte Website → Idempotenz →
Tagesgrenze → Anlage.

## 7. Drosselung

- Je Key-Id 60 Anfragen pro Minute und 1 000 pro Tag (alle drei Endpunkte), eigener Topf, erst nach gültiger Signatur
  gezählt (nicht signierte Anfragen verbrauchen kein Kontingent).
- Je Absenderadresse (HMAC-SHA-256 der kleingeschriebenen Adresse mit `APP_KEY`) 5 neue Leads in 24 Stunden. Darüber:
  kein neuer Lead, der jüngste Lead der Adresse zählt `merged_count` hoch, Antwort `202 accepted_merged` mit dessen
  `lead_id`. Inhalte der zusammengeführten Einsendung werden nicht gespeichert.

## 8. Datenmodell, Inbox, Aufbewahrung (BS-06, BS-09)

- `form_captain_lead`: `public_id` (`L-<ULID>`), Status `new` (Neu) · `in_progress` (In Bearbeitung) · `done`
  (Erledigt) · `rejected` (Abgelehnt), Schemafelder, `email_hash`, `merged_count`, interne `notes`,
  `rejection_reason`, `handled_by_user_id`, `status_changed_at`, `retain_until`.
- Beide Tabellen haben Row Level Security ohne Policy und kein GRANT an die App-Rolle: nur die Owner-Verbindung
  (Superadmin, signierter Endpunkt hinter `admin-db`) liest und schreibt. Ein Test prüft, dass die App-Rolle nichts
  sieht.
- **Inbox** unter `/superadmin/leads` (Menü „Leads“): Liste mit Filter (offen, je Status, alle) und Suche (E-Mail, Name,
  Organisation, Lead-ID), Detail mit Einwilligungsnachweis und Herkunft, Status und Notiz ändern, ablehnen (Grund),
  endgültig löschen. Nur Superadmins (`auth`, `superadmin`, `verified`); jede Änderung landet im Protokoll
  (`AuditSuperadminChanges`, nur Feldnamen). Eine Löschung in der Plattform wirkt nicht auf den Form-Captain-Entry.
- **Aufbewahrung:** `retain_until` = Eingang bzw. letzte Statusänderung + `FORM_CAPTAIN_LEAD_RETENTION_DAYS`
  (Platzhalter 180). `app:prune-form-captain-leads` (täglich 04:35) löscht abgelaufene Leads **nur**, wenn
  `FORM_CAPTAIN_LEAD_RETENTION_ENABLED=true` gesetzt ist – die Frist ist bis zur Rechtsprüfung offen (offener Punkt 3
  des Entwurfs). Idempotenz-Einträge löscht der Befehl immer nach 30 Tagen. `--dry-run` zählt nur.

## 9. Datenschutz

- Zweck: Beantwortung der Anfrage (Demo, Beratung, Schulzugang). Grundlage: Einwilligung im Formular; der Nachweis
  (Text, Zeitpunkt) liegt im Form-Captain-Entry, die Plattform speichert Zeitpunkt, Revision und optional Hash.
- Löschung in Form Captain (`form_captain_entry_deleted_v1`) und Widerruf der Einwilligung
  (`form_captain_consent_withdrawn_v1`) → der Connector ruft `DELETE /leads/{lead_id}` (abschaltbar im Connector).
- Export: der Superadmin sieht alle gespeicherten Felder in der Detailansicht (Auskunft); ein Datei-Export ist nicht
  gebaut.

## 10. Abweichungen vom Entwurf (an `wp-formmaster` zurückgemeldet)

1. **Schlüsselverwaltung:** Schlüssel stehen in der Server-Konfiguration (`.env`), nicht im Superadmin angelegt
   (Entwurf A.2 „im Superadmin angelegt“). Zwei aktive Schlüssel, Widerruf über `FORM_CAPTAIN_LEAD_REVOKED_KEY_IDS`;
   „zuletzt benutzt“ zeigt die Inbox (aus dem Cache, nicht dauerhaft).
2. **Zusätzlicher Code `403 site_not_allowed`:** optionale Liste erlaubter Websites (`FORM_CAPTAIN_LEAD_SITE_URLS`).
3. **Zusätzlicher Code `404 not_found`** für `DELETE` (Entwurf: „204 oder 404“ ohne Code).
4. **`400 bad_request` auch** für fehlenden/ungültigen `Idempotency-Key`, falschen Content-Type und Query-Parameter
   (Entwurf nennt nur „kein JSON, zu groß“).
5. **Replay nach Löschung:** eine idempotente Wiederholung nach dem Löschen des Leads liefert die alte `lead_id` mit
   `"status": "deleted"` (Entwurf lässt den Fall offen).
6. **Antwort `202 accepted_merged`** enthält `lead_id` und `status` wie `201`; der Connector meldet `success`.
7. **Pfad in der Signatur** ist ausdrücklich der volle Pfad der Anfrage mit führendem `/` (Entwurf: `{path}`).

## 11. Offen

- Werte für `purpose` und `school_size` mit dem tatsächlichen B2B-Angebot abgleichen (P8-40); eine Änderung ist eine
  neue Schema-Version.
- Aufbewahrungsfrist (Rechtsprüfung), danach `FORM_CAPTAIN_LEAD_RETENTION_ENABLED` setzen.
- Staging-Abnahme WordPress → Plattform (BS-13): echtes WordPress mit Form Captain und Connector gegen Staging, Testleads
  löschen, Datenschutzabnahme dokumentieren.
