# Owner-Verbindung (`pgsql_admin`) und `withoutGlobalScopes()`

Die App spricht die Datenbank normalerweise über die Rolle `boatingschool_app`
an (Verbindung `pgsql`). Für diese Rolle gilt Row Level Security: jede
mandantenbezogene Tabelle zeigt nur Zeilen der Schule, deren ID in
`app.current_tenant_id` steht (gesetzt von `TenantContext`). Zusätzlich filtern
die Modelle mit `BelongsToTenant` bzw. dem Scope von `CourseDefinition`
anwendungsseitig nach der aktuellen Schule.

Die **Owner-Verbindung `pgsql_admin`** gehört der Tabellen-Eigentümerin und
umgeht RLS vollständig. `withoutGlobalScopes()` entfernt die anwendungsseitige
Filterung. Beides ist deshalb nur dort erlaubt, wo eine Abfrage *bewusst*
mehrere Schulen oder gar keine Schule betrifft.

## Wann ist die Owner-Verbindung erlaubt?

| Kategorie | Beispiele | Begründung |
|---|---|---|
| Superadmin | `Superadmin\*`-Controller (Middleware `admin-db`) | Plattformverwaltung über alle Schulen |
| Commerce-API | `App\Commerce\*`, `Commerce\CommerceController` | signierte Shop-Aufrufe; die Schule ergibt sich erst aus Shopkonto/Code |
| Konsolenbefehle | `app:expire-entitlements`, Importe, Backups | Cron/Deploy ohne Mandantenkontext |
| Mandantenauflösung & Login | `ResolveTenant`, `EnsureTenantMembership`, Auth-Controller | es gibt noch keinen Mandanten bzw. das Konto ist plattformweit |
| Mandantenübergreifende Dienste | `AccountErasureService`, `ParticipantInviter`, `InvitationAcceptance`, DSGVO-Export | Konten (`app_user`) und Mitgliedschaften gelten über Schulen hinweg |
| Plattform-Katalog | `CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')` | nur Katalogkurse, nicht die schuleigenen |

**Nicht erlaubt** ist die Owner-Verbindung als Abkürzung, weil eine Abfrage im
Schulbereich „nichts findet“ – dann fehlt fast immer der Mandantenkontext oder
die Abfrage gehört in einen der obigen Fälle. Daten einer Schule werden im
Schul- und Lernbereich immer über die App-Verbindung mit gesetztem Mandanten
gelesen.

## Regeln für den Code

- Immer explizit filtern: wer RLS umgeht, schreibt `where('tenant_id', …)`
  bzw. `where('user_id', …)` selbst dazu.
- Kurze Blöcke mit `App\Support\OwnerConnection::run(fn () => …)` statt
  `config(['database.default' => 'pgsql_admin'])` von Hand: der Helfer stellt
  die vorherige Verbindung auch bei Exceptions und unter langlebigen Workern
  (Octane) wieder her.
- Einzelne Abfragen dürfen `Model::on('pgsql_admin')` bzw.
  `DB::connection('pgsql_admin')` nutzen.
- Im Code-Kommentar kurz sagen, *warum* RLS hier umgangen wird.

## Architekturtest und Allowlist

`tests/Unit/OwnerConnectionUsageTest.php` durchsucht `app/` (ohne Kommentare)
nach `pgsql_admin`, `withoutGlobalScopes()` und `OwnerConnection::` und
schlägt fehl, sobald eine Datei das nutzt, die nicht in der Konstante
`ALLOWLIST` steht. Er schlägt ebenfalls fehl, wenn ein Eintrag nicht mehr
gebraucht wird.

Neue Verwendung freigeben:

1. Prüfen, ob es wirklich einer der Fälle oben ist (Review!).
2. In `ALLOWLIST` unter der passenden Kategorie eintragen:
   `'app/Pfad/Datei.php' => 'Begründung in einer Zeile.',`
3. Fällt die Verwendung später weg, den Eintrag wieder entfernen (der Test
   meldet das).
