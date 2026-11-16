=== BoatingSchool Workflow Connector ===
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.2.0
License: Proprietary

Ereignisse der Bootsschul-Plattform als Auslöser in Workflow Captain und begrenzte Befehle zurück an die Plattform.

== Beschreibung ==

Eigenes, nicht verkauftes Projektprodukt (Entscheidung 17): eigener Produktschlüssel `boatingschool-workflow-connector`,
Präfix `boatingschool_workflow`, Recht `boatingschool_workflow.connector` (`wplc-catalog.json`). Eine Pro-Lizenz eines
Captain-Produkts deckt den Connector nicht ab. Ohne gültige Lizenz nimmt er keine neuen Ereignisse an und führt keine
Befehle aus; bereits Erfasstes bleibt.

Anmeldung an Workflow Captain über den öffentlichen Connector-Vertrag v1
(`workflow_captain_register_connectors_v1`, Schlüssel `boatingschool-connector`, Typen `xboatingschoolconnector.*`).
Laufzeit: Workflow Captain Pro mit dem Recht `workflow.custom_connectors` (Entscheidung F-18a). Ohne Pro meldet sich
der Connector nicht an, ohne Recht lehnt Workflow Captain ab; Statusseite und Hinweis: „Workflow Captain Pro fehlt
oder das Recht fehlt“. Die Pro-Lizenz ersetzt nicht die eigene Lizenz des Connectors. Bis zur Freigabe unter „Workflow Captain → Eigene Connectoren“ bleibt der
Connector aus.

Ereignisse (je Version 1): shop_account.registered/linked/deactivated, provisioning.succeeded/failed,
code_batch.delivered/revoked. Nur Referenzen, Status, Mengen und Kurs-/Produktschlüssel – nie Codes, Verknüpfungscodes,
Passwörter, E-Mail-Adressen, Namen oder USt-IDs.

Aktionen: `xboatingschoolconnector.school_admin.notice` (feste Vorlagen an die Schuladmins),
`xboatingschoolconnector.learning_group.invite` (optional, in der App standardmäßig aus).

Nicht Teil dieses Connectors: Zustellung bezahlter Codes, Idempotenz je Bestellposition, Wiederholung und Sperre bei
Rückerstattung. Das bleibt beim WooCommerce-Connector (`integrations/woocommerce-connector`); ein Workflow löst diese
Befehle nie aus.

Ausführlich: `docs/workflow/README.md` im Repository der Plattform.

== Einrichtung ==

In `wp-config.php` (eigene Workflow-Schlüssel, nie die des WooCommerce-Connectors):

    define( 'BSW_CONNECTOR_APP_URL', 'https://lernen.example.de' );
    define( 'BSW_CONNECTOR_KEY_ID', 'workflow' );                 // = WORKFLOW_KEY_ID der App
    define( 'BSW_CONNECTOR_SECRET', '…' );                        // = WORKFLOW_SECRET der App
    define( 'BSW_CONNECTOR_INBOUND_KEY_ID', 'app-workflow' );     // = WORKFLOW_OUTBOUND_KEY_ID der App
    define( 'BSW_CONNECTOR_INBOUND_SECRET', '…' );                // = WORKFLOW_OUTBOUND_SECRET der App

In der App `WORKFLOW_CONNECTOR_URL` auf `https://<shop>/wp-json/boatingschool-workflow/v1/events` setzen.
Danach unter „Werkzeuge → BoatingSchool Workflow“ den Contract-Handshake und das Testereignis ausführen.

Schlüsselwechsel: in der App ein zweites Paar (`WORKFLOW_SECRET_NEXT` bzw. ausgehend ein neues
`WORKFLOW_OUTBOUND_SECRET`), hier vorübergehend `BSW_CONNECTOR_INBOUND_KEY_ID_NEXT`/`_SECRET_NEXT`; danach das alte Paar
entfernen.

Die Lizenzbibliothek liegt nicht im Repository; der Paketbau legt sie unter `lib/license-client.php` ab.

== Changelog ==

= 0.2.0 =
* Laufzeit nach Entscheidung F-18a: braucht Workflow Captain Pro mit dem Recht workflow.custom_connectors. Ohne Pro
  keine Anmeldung und keine Aktionen (workflow_captain_pro_missing), ohne Recht lehnt Workflow Captain ab
  (runtime_not_allowed); Ereignisse der App bekommen 503 mit diesem Code, Aktionen enden dauerhaft. Statusseite
  (Zeile „Workflow Captain Pro (Laufzeit)“), Testereignis und Admin-Hinweis nennen den Grund.

= 0.1.0 =
* Erste Fassung: Ereignis-Eingang mit HMAC, Zeitfenster, Replay- und Doppel-Schutz; Manifest, Payload-Mapping,
  zwei Aktionen, Statusseite mit Handshake, Testereignis und Fehlerdiagnose; eigene Lizenzprüfung (fail-closed).
