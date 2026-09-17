# White-Label-Lernplattform für Bootsschulen

Mandantenfähige Lernplattform für SBF See/Binnen, SBF Binnen Segeln, SRC und
UBI: zentraler, versionierter Fragenbestand, weißes Branding pro Bootsschule,
Smarttrainer, Prüfungssimulation. Umgesetzt als modularer Laravel-Monolith
auf PostgreSQL, gemäß dem mitgelieferten technischen Konzept
(`Technisches Umsetzungskonzept`, `Technikpaket` mit `schema.sql`,
`api_spec.md`, `content_import_mapping.md`, `implementation_plan.md`).

## Architektur

- **Laravel 11 / Blade / Tailwind**, PostgreSQL 16.
- **Multi-Tenancy**: jede Bootsschule ist ein `tenant`, aufgelöst per
  Subdomain (`{slug}.CENTRAL_DOMAIN`) oder Custom Domain
  (`app/Http/Middleware/ResolveTenant.php`). Mandantengebundene Tabellen
  (`tenant_branding`, `tenant_user`, `entitlement`, `attempt`, `progress`,
  `favorite`, `exam_session`, `audit_log`) sind zusätzlich per **Postgres
  Row-Level-Security** abgesichert – ein UI-Filter allein zählt nicht als
  Schutz. Globaler Content (Fragen, Module, Kursdefinitionen) ist
  tenant-unabhängig und wird nur referenziert, nie dupliziert.
- **Zwei DB-Rollen**: der Webprozess verbindet sich als
  least-privilege-Rolle `boatingschool_app` (RLS greift für sie ohne
  Ausnahme). Migrations, Seeder und der Content-Import laufen bewusst über
  die Owner-Rolle `boatingschool` (`--database=pgsql_admin`) und umgehen RLS
  absichtlich, weil sie administrative Vorgänge sind.
- **Content-Modell**: `content_question` ist die stabile fachliche Identität,
  jede Textänderung erzeugt eine neue `content_question_revision`. Attempts
  speichern sowohl die stabile Frage als auch die tatsächlich gezeigte
  Revision; Content-Änderungen überschreiben nie historische Attempts oder
  abgeschlossene Prüfungen.
- **Learning Engine** (`app/Services/MasteryCalculator.php`,
  `LearningService.php`, `SmarttrainerService.php`): serverseitige
  Bewertung, unveränderliche Attempts, deterministische und erklärbare
  Priorisierung (neu → falsch → fällige Wiederholung → schwaches Thema →
  gefestigt).
- **Prüfungssimulation** (`app/Http/Controllers/ExamController.php`):
  Regelwerk-Snapshot, keine Sofortauflösung während der Prüfung, serverseitig
  berechnetes Ergebnis nach Abschluss.

## Setup (lokal)

Voraussetzungen: PHP 8.4+, Composer, Node 20+, PostgreSQL 16+.

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
php artisan storage:link
```

`storage:link` wird für den Logo-Upload im Branding benötigt (Bootsschul-Admin
→ Branding-Einstellungen); Dateien landen unter `storage/app/public` und
werden über `public/storage` ausgeliefert.

Zwei DB-Rollen anlegen (einmalig, per Infra/Admin – die Owner-Rolle braucht
aus Sicherheitsgründen absichtlich kein `CREATE ROLE`-Recht für die App
selbst):

```sql
CREATE ROLE boatingschool LOGIN PASSWORD 'boatingschool';
CREATE ROLE boatingschool_app LOGIN PASSWORD 'boatingschool_app';
CREATE DATABASE boatingschool OWNER boatingschool;
CREATE DATABASE boatingschool_test OWNER boatingschool;
GRANT CONNECT ON DATABASE boatingschool TO boatingschool_app;
GRANT CONNECT ON DATABASE boatingschool_test TO boatingschool_app;
```

Migrieren, Fragenbestand importieren und Demodaten seeden (ein Kommando,
läuft komplett über die Owner-Rolle):

```bash
php artisan migrate --database=pgsql_admin --force
php artisan db:seed --database=pgsql_admin --force
```

Der Seed-Lauf importiert automatisch die mitgelieferte zentrale
Fragenbasis (`database/data/zentrale_fragenbasis.xlsx`, 838 Zeilen, davon
821 importierbar – 17 Zeilen sind freie Kartenaufgaben ohne A–D-Antworten
und werden bewusst als Fehler ausgewiesen, nicht stillschweigend
übersprungen), legt die 6 Module und 11 Kursdefinitionen an und erzeugt
zwei Demo-Bootsschulen.

```bash
php artisan serve
```

Da produktiv jede Bootsschule über ihre eigene Subdomain läuft, aber lokal
keine Wildcard-DNS existiert, gibt es einen Dev-Override:
`http://localhost:8000/login?as_tenant=mueller` (nur aktiv in
`APP_ENV=local|testing`). Auf der zentralen Domain ohne gewählten Tenant
erscheint ein einfacher Bootsschul-Picker.

### Demo-Zugänge

| Bootsschule | Rolle | E-Mail | Passwort |
|---|---|---|---|
| Bootsschule Müller (`mueller`) | Admin | admin@bootsschule-mueller.de | password |
| Bootsschule Müller (`mueller`) | Lernender | max@bootsschule-mueller.de | password |
| Hanse Bootsschule Kiel (`hanse-kiel`) | Admin | admin@hanse-bootsschule-kiel.de | password |
| Hanse Bootsschule Kiel (`hanse-kiel`) | Lernender | lena@hanse-bootsschule-kiel.de | password |

## Tests

```bash
php artisan test
```

Die Test-Suite läuft über die reale `boatingschool_app`-Rolle (RLS aktiv),
Fixtures werden über die Owner-Rolle angelegt. Vor dem ersten Lauf muss die
Testdatenbank ebenfalls migriert und geseedet sein
(`DB_DATABASE=boatingschool_test php artisan migrate|db:seed --database=pgsql_admin --force`).

Abgedeckt sind u. a.: Tenant-Isolation auf DB-Ebene (403/404 bei
ID-Manipulation über Mandantengrenzen), serverseitige Bewertung und
Unveränderlichkeit von Attempts, Idempotenz und Revisionslogik des
Content-Imports, sowie die Prüfungsengine (kein Sofort-Feedback,
serverseitige Auswertung).

## Umfang dieser Version

**Umgesetzt** (entspricht Sprint 0–6 des Implementierungsplans): zentraler
Content mit Revisionshistorie, Excel-Import, Tenant/Branding/Rollen mit
RLS, Kurse/Entitlements, vollständige Learning Loop, Smarttrainer,
Lernenden-Dashboard, Bootsschul-Admin (Teilnehmer, manuelle Freischaltung,
Branding), Prüfungssimulation.

**Bewusst ausgeklammert**, konsistent mit der im Technikpaket festgelegten
Reihenfolge "nach V1": Zugangscodes, WooCommerce-/Commerce-Adapter,
automatisierte Domain-Provisionierung, Self-Service-Billing, ein
vollständiges Content-Review-UI (Staging/Diff/Publish-Oberfläche – der
Import validiert und veröffentlicht aktuell in einem Schritt) sowie
fortgeschrittenes Spaced Repetition.

**Wichtiger Hinweis zur Prüfungssimulation**: Die Prüfungsregelwerke
(Fragenmix, Zeitlimit, Bestehensgrenze) sind für alle Kurse als
`draft`/unverifiziert markiert, mit Ausnahme von SBF See, das eine
**Demo-Verifizierung** trägt, um die technische Prüfungssimulation
end-to-end zeigen zu können. Das ersetzt keine fachliche Freigabe – vor
echtem Produktivbetrieb müssen die Regelwerke je Katalog fachlich
verifiziert werden (siehe Umsetzungskonzept, Abschnitt "Noch festzulegende
Betriebsparameter").
