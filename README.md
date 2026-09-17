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
- **Modul-gefilterter Smarttrainer**: `LearningController@show` akzeptiert
  einen optionalen `?module=`-Query-Parameter, der `SmarttrainerService` auf
  die Fragen eines einzelnen Kursmoduls einschränkt. Wird von den
  Kapitel-Kacheln auf der Kursdetailseite genutzt.
- **Videokurs** (`video_module`, `video_lesson`, `video_progress`,
  `app/Http/Controllers/VideoCourseController.php`): wie Fragen/Module ist
  der Videocontent zentraler, mandantenunabhängiger Content, an
  `course_definition` gehängt (`CourseDefinition::videoModules()`). Nur der
  Sehfortschritt (`video_progress`) ist mandantenbezogen und per RLS
  abgesichert (Policy `video_progress_isolation`). Der Player
  (`resources/views/video/show.blade.php`) zeigt eine nach Kapiteln
  gruppierte Lektionsliste, markiert abgeschlossene Lektionen und springt
  beim Abschließen automatisch zur nächsten Lektion. **Hinweis**: Die per
  `VideoCourseSeeder` angelegten Lektionen verweisen aktuell auf ein
  öffentliches Platzhaltervideo (`https://www.w3schools.com/html/mov_bbb.mp4`)
  und müssen vor einem produktiven Einsatz durch lizenzierte Kursvideos
  ersetzt werden.
- **Schritt-Galerie unter dem Video** (`video_lesson_step`): einzelne
  Lektionen (z. B. Knotenkunde) können eine Schritt-für-Schritt-Galerie
  (`VideoLesson::steps()`) unter dem Player anzeigen. Ohne echtes
  Bildmaterial werden aktuell nur benannte Platzhalter-Schritte angezeigt.
- **Navigationsaufgaben-Trainer** (`navigation_task`, `navigation_question`,
  `app/Http/Controllers/NavigationTaskController.php`): Übungsaufgaben mit
  sofort einsehbarer Musterlösung, bewusst getrennt von der strengen
  Prüfungssimulation (dort gilt "keine Sofortauflösung"). Wie der Videokurs
  zentraler, globaler Content ohne eigenen Fortschritt. **Hinweis**:
  Szenarien und Musterlösungen sind Demo-Platzhalter und müssen vor
  Produktivbetrieb durch fachlich geprüftes, amtliches Material der
  Bootsschule ersetzt werden.
- **Feste Prüfungsbögen** (`exam_paper`, `exam_paper_question`,
  `ExamController@papersOverview`/`startPaper`): ergänzt das bestehende,
  zufällig zusammenstellende `exam_rule_set`/`exam_blueprint` um 15 feste,
  wiederholbare Fragensets ("Bogen 1" .. "Bogen 15", je 30 Fragen: 7
  allgemeine Basisfragen + 23 kursspezifische Fragen). Ein Bogen-Versuch
  ist ein ganz normaler `exam_session`-Datensatz (Zeitlimit, keine
  Sofortauflösung, unveränderliches Ergebnis) mit einem zusätzlichen
  `paper_id`-Verweis statt zufällig gewürfelter Fragen; erneutes Starten
  desselben Bogens setzt einen bereits laufenden Versuch fort statt ihn zu
  duplizieren. Die Prüfungssimulation-Startseite zeigt pro Bogen einen
  Fortschrittsring mit dem Ergebnis des letzten abgeschlossenen Versuchs.
  **Hinweis**: Die Zuordnung Frage→Bogen ist aktuell eine deterministische
  Demo-Verteilung aus dem echten, bereits importierten Fragenpool (kein
  erfundener Fragentext) und muss vor Produktivbetrieb durch die
  tatsächliche amtliche Bogen-Zusammenstellung ersetzt werden.

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
