# Captain-Connectoren für BoatingSchool (offene Aufgaben)

Stand: 16.11.2026.

## Entscheidung Nr. 17

Entscheidung des Inhabers vom 16.11.2026: Die Connectoren dieses Projekts zu den Captain-Plugins sind **in PRO enthalten**. Jeder Connector ist ein eigenes WordPress-Plugin, das separat installiert wird. Er läuft aber nur mit der Pro-Lizenz des Captain-Produkts, an das er andockt (fail-closed). Ein eigenes Lizenzprodukt je Connector gibt es nicht.

Antworten des Inhabers zu den offenen Fragen:

- **Ort des Codes:** Der Code bleibt im Projekt-Repository, also hier.
- **Wer den Connector bekommt:** Das Recht gilt nur für die Lizenzen der eigenen Projekte (BoatingSchool, LearnBoating) und steht nicht in den öffentlichen Tarifen.
- **Updates:** Sie kommen über den Lizenzserver als weiteres Paket der Pro-Lizenz. Ausgeliefert werden sie über den Updater des Pro-Add-ons.

Gesamtbild und Aufgaben in den anderen Repositories:

- hastla007/captain-pro `TODO.md` Abschnitt 13: Lizenzauskunft für Begleit-Plugins (K-01), Updates für Begleit-Plugins (K-04).
- hastla007/wp-license `docs/TODO.md` Abschnitt 14: Rechte im Katalog, mehrere Pakete je Produkt.
- hastla007/wp-formmaster `docs/ENTSCHEIDUNGEN.md` Nr. 17 und TODO P8-40 bis P8-50, P8-87.
- hastla007/wp-workflowmaster `TODO.md`: Kundenconnector 1, WF-CUST-LIC-01/02.

Nicht betroffen ist `integrations/woocommerce-connector`. Das ist die eigene Shop-Anbindung dieses Projekts an WooCommerce (`docs/commerce/README.md`), kein Captain-Connector.

## Aufgaben in diesem Repository

| # | Connector | Punkt | Hängt ab von |
|---|---|---|---|
| BS-1 | Form-Captain-Lead-Connector | Eigenes WordPress-Plugin (wp-formmaster P8-46). Es prüft sein Recht über Form Captain Pro, ohne eigenen Lizenzschlüssel. Ohne Lizenz oder ohne Recht pausiert der Auftrag, der Entry bleibt erhalten (P8-87). | captain-pro K-01 |
| BS-2 | Form-Captain-Lead-Connector | Laravel-Seite: Lead-Vertrag, Endpunkt `POST /api/integrations/v1/form-captain/leads`, Lead-Inbox im Superadmin, eigener HMAC-Schlüssel (wp-formmaster P8-41 bis P8-45, P8-49, P8-50). Die Lizenz betrifft nur das WordPress-Plugin, nicht den Laravel-Endpunkt. | – |
| BS-3 | Workflow-Connector (Kundenconnector 1) | Outbox-Dispatcher, versionierte Ereignisse, eigener Workflow-Schlüssel und Tenant-Scope auf der Laravel-Seite (wp-workflowmaster WF-CUST1-02 bis -07, -09). Der WordPress-Teil (WF-CUST1-08) läuft nur mit Workflow Captain Pro (WF-CUST-LIC-01). | captain-pro K-01 |
| BS-4 | beide WordPress-Plugins | `Requires Plugins` mit dem jeweiligen Pro-Add-on, Anmeldung als Begleit-Plugin für Updates (captain-pro K-04), Release-ZIPs aus diesem Repository beim Pro-Produkt am Lizenzserver einstellen (wp-license PC-3). | captain-pro K-04, wp-license PC-3 |
| BS-5 | Lizenzen | Auf der Pro-Lizenz von BoatingSchool die Connector-Rechte eintragen, zum Beispiel `form.…` und `workflow.…`; die Namen folgen wp-license PC-1. | Inhaber |
