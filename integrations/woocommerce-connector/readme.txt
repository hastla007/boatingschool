=== Bootsschul-Plattform Connector ===
Requires at least: 6.5
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.2.0
License: Proprietary

Verkauft Codes der Bootsschul-Plattform über WooCommerce. Nach der Zahlung werden die Codes im Codebestand der verknüpften Bootsschule hinterlegt.

== Beschreibung ==

* Kaufbar: Kurscodes, Verlängerungscodes, Produktcodes (z. B. Fahrstunden) und Kurscodes mit Zusatzprodukt.
* Zustellung ausschließlich in den Codebestand der Bootsschule auf der Plattform. Der Shop sieht und versendet keine Codes.
* Einkaufen kann nur, wer mit einer aktiven Bootsschule verknüpft ist: per Verknüpfungscode aus der Plattform („Mein Konto → Bootsschule verknüpfen“) oder nach Freigabe einer Neuregistrierung durch den Plattform-Betreiber.
* Neue Kundenkonten bestätigen zuerst ihre E-Mail-Adresse (siehe unten). Erst danach meldet das Plugin sie bei der Plattform an, und erst danach ist eine Verknüpfung per Code möglich.
* Genau ein Code-Paket je Bestellposition. Wiederholte Zustellungen nach Zeitüberschreitung erzeugen keine zusätzlichen Codes.
* Rückerstattung oder Storno sperrt unbenutzte Codes. Bereits eingelöste Codes bleiben gültig.
* Endgültig fehlgeschlagene Zustellungen erzeugen eine Bestellnotiz, eine Alarm-Mail und den Knopf „Erneut zustellen“. Ist das Tageskontingent der Schule für Codes überschritten (`CODE_QUOTA_EXCEEDED`), wird nicht automatisch wiederholt: Nach der Freigabe durch den Superadmin „Erneut zustellen“ klicken.
* Höchstmenge je Position kommt aus dem Katalog der Plattform (`max_quantity`, sonst 500).
* Kurs- und Verlängerungscodes lassen sich nur für Kurse bestellen, die die verknüpfte Schule derzeit erhalten kann.
* Rückerstattungen nur als Betrag (ohne Positionsmenge) sperren keine Codes; das Plugin meldet sie per Bestellnotiz und Alarm-Mail.

== Zahlarten ==

Codes werden nur nach bestätigter Zahlung zugestellt.

* Zahlarten ohne bestätigten Zahlungseingang werden an der Kasse ausgeblendet, sobald Bootsschul-Codes im Warenkorb liegen. Standard: `cod` (Nachnahme). Einstellbar unter „Gesperrte Zahlarten“ (Gateway-IDs, kommagetrennt; leer = keine sperren) oder per Filter `bs_connector_blocked_gateways`.
* Bestellungen mit einer dieser Zahlarten (z. B. im Backend angelegt) werden beim Wechsel auf „In Bearbeitung“ nicht zugestellt, sondern erhalten einmalig eine Bestellnotiz. Zugestellt wird, sobald die Zahlung verbucht ist: wenn das Gateway den Zahlungseingang meldet (`woocommerce_payment_complete`) oder die Bestellung nach Zahlungseingang auf „Abgeschlossen“ gesetzt wird. Das Zahlungsdatum allein genügt nicht, weil WooCommerce es schon beim Wechsel auf „In Bearbeitung“ setzt.
* Alle anderen Zahlarten liefern wie bisher, sobald die Bestellung bezahlt ist („In Bearbeitung“ oder „Abgeschlossen“).

== E-Mail-Bestätigung ==

* Nach der Registrierung (`woocommerce_created_customer`) erhält die Kundin eine Mail mit einem signierten Link (HMAC aus den WordPress-Schlüsseln, 3 Tage gültig, nur einmal verwendbar). Ein neu angeforderter Link macht den alten ungültig.
* Erst wenn der Link geöffnet wurde, wird das Konto bei der Plattform angemeldet (`POST /accounts`).
* „Mein Konto → Bootsschule verknüpfen“ zeigt den Stand. Solange die Adresse unbestätigt ist, gibt es dort statt des Verknüpfungsformulars den Knopf „Bestätigungsmail erneut senden“ (höchstens einmal pro Minute).
* Ändert die Kundin ihre Adresse, ist sie wieder unbestätigt.
* Konten, die die Plattform über den Provisionierungs-Endpunkt anlegt, gelten als bestätigt.

== USt-IdNr.-Prüfung (VIES) ==

Gibt eine Kundin eine USt-IdNr. an (Registrierungsfeld „USt-IdNr.“ oder das Meta-Feld der Rechnungsadresse, Einstellung „Meta-Feld der USt-ID“, Standard `billing_vat_id`), prüft das Plugin sie beim EU-Dienst VIES (`POST https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number`).

* **Format:** Zuerst wird das Format je EU-Land geprüft (mit oder ohne Länderkennung, Leer- und Trennzeichen egal; Griechenland = `EL`, Nordirland = `XI`). Falsches Format: Fehlermeldung bei Registrierung, Kasse und „Mein Konto → Adressen“, es wird nichts gespeichert.
* **Ungültig laut VIES** (`valid: false`, `INVALID_INPUT`, `VAT_BLOCKED`): ebenfalls Fehlermeldung.
* **VIES nicht erreichbar** (`MS_UNAVAILABLE`, `SERVICE_UNAVAILABLE`, `TIMEOUT`, `*_MAX_CONCURRENT_REQ*`, `IP_BLOCKED`, Netzfehler, HTTP 5xx): Die Nummer wird angenommen und als „nicht geprüft“ markiert. Action Scheduler prüft sie erneut nach 15 Minuten, 1, 4, 12 und 24 Stunden (Hook `bs_connector_vat_recheck`). Danach Alarm-Mail; im Benutzerprofil lässt sie sich mit „Jetzt erneut prüfen“ von Hand prüfen. Wird eine angenommene Nummer später als ungültig erkannt, geht eine Alarm-Mail an die Alarm-Adresse.
* **Kein EU-Land** (z. B. CH): keine Prüfung, Ergebnis „nicht geprüft (kein EU-Land)“.
* **Ergebnis am Kunden:** Nutzer-Metadaten `_bs_vat_status` (valid, invalid, unchecked, not_applicable), `_bs_vat_number`, `_bs_vat_checked_at`, `_bs_vat_request_id` (Abfragenummer von VIES), `_bs_vat_error`, `_bs_vat_attempts`. Anzeige im Benutzerprofil im Backend („USt-IdNr.-Prüfung (VIES)“).
* **Bestellungen:** Jede neue Bestellung (klassischer und Block-Checkout) erhält das Ergebnis als Bestellnotiz. Lag bei der Bestellung noch kein Ergebnis vor, folgt die Notiz nach der erneuten Prüfung.
* **Abfragenummer:** VIES liefert sie nur, wenn die eigene USt-IdNr. mitgeschickt wird: Einstellung „Eigene USt-IdNr. (für VIES)“ oder `define( 'BS_CONNECTOR_VIES_REQUESTER', 'DE123456789' );`.
* An die Plattform geht die USt-IdNr. ohne Leer- und Trennzeichen mit Länderkennung.
* Abschalten: Einstellung „USt-IdNr. per VIES prüfen“ oder Filter `bs_connector_vat_check` (false). Weitere Filter: `bs_connector_vies_url` (z. B. Testumgebung), `bs_connector_vies_timeout` (Sekunden, Standard 10).

Der Block-Checkout kennt kein eigenes USt-IdNr.-Feld. Dort prüft das Plugin eine Nummer, die ein anderes Plugin als Bestell-Meta (`billing_vat_id` bzw. `_billing_vat_id`) ablegt; sonst gilt das gespeicherte Ergebnis des Kundenkontos.

== Betrieb ==

* **Protokoll:** WooCommerce → Status → Protokolle, Quelle `bs-connector`. Protokolliert werden API-Fehler, Zustellungen, Wiederholungen, Sperren, Bestätigungsmails und Verbindungstests. Codewerte, Verknüpfungscodes, Bestätigungslinks und Geheimnisse stehen nie im Protokoll.
* **Verbindung testen:** Knopf auf dem Einstellungs-Tab „Bootsschule“. Ruft `GET /catalog` mit den gespeicherten Einstellungen auf und zeigt das Ergebnis als Hinweis an (Recht `manage_woocommerce`).
* **Fehlgeschlagene Zustellungen:** WooCommerce → Bootsschul-Zustellungen listet alle Positionen mit Status „fehlgeschlagen“. Mit der Sammelaktion „Erneut zustellen“ werden sie neu angestoßen (Recht `edit_shop_orders`). Die Plattform liefert je Position höchstens einmal.
* **Abgleich per WP-CLI:** `wp bs-connector reconcile [--days=30]` vergleicht zugestellte, fehlgeschlagene und stornierte Positionen der letzten Tage mit `GET /orders/{id}/lines/{line}` und gibt Abweichungen als Tabelle aus (fehlende Pakete, andere Paket-ID oder Menge, offene Codes trotz Storno). Der Befehl ändert nichts.

== Übersetzung ==

Text-Domain `bs-connector`, Ordner `languages/`. Die Vorlage `languages/bs-connector.pot` erzeugt `php bin/make-pot.php` (im Plugin-Ordner) aus allen `__()`-/`esc_html__()`-Aufrufen. Ein Test der Plattform prüft, dass sie aktuell ist.

== Installation ==

1. Ordner `woocommerce-connector` nach `wp-content/plugins/` kopieren und aktivieren (WooCommerce muss aktiv sein).
2. Geheimnisse in `wp-config.php` eintragen:

    define( 'BS_CONNECTOR_APP_URL', 'https://app.example.de' );
    define( 'BS_CONNECTOR_KEY_ID', 'shop' );            // = COMMERCE_SHOP_KEY_ID der Plattform
    define( 'BS_CONNECTOR_SECRET', '…' );               // = COMMERCE_SHOP_SECRET
    define( 'BS_CONNECTOR_INBOUND_KEY_ID', 'app' );     // = COMMERCE_OUTBOUND_KEY_ID
    define( 'BS_CONNECTOR_INBOUND_SECRET', '…' );       // = COMMERCE_OUTBOUND_SECRET

   Ist eine Geheimnis-Konstante gesetzt, blendet WooCommerce → Einstellungen → Bootsschule das Feld aus. Nur aus Kompatibilität lassen sich die Geheimnisse noch dort in der Datenbank speichern (siehe „Geheimnisse“).
3. Auf der Plattform `COMMERCE_SHOP_URL` auf die Shop-Adresse setzen.
4. Produkte anlegen und unter „Allgemein“ die Art (Kurscode, Verlängerungscode, Produktcode) und den Kurs bzw. das Produkt auswählen. Die Auswahl kommt aus dem Katalog der Plattform.

Ausführliche Beschreibung: docs/commerce/README.md im Plattform-Repository.

== Geheimnisse ==

Die beiden Geheimnisse (`BS_CONNECTOR_SECRET`, `BS_CONNECTOR_INBOUND_SECRET`) gehören in `wp-config.php`, nicht in die Datenbank (Datenbank-Sicherungen, Exporte und Plugins mit Lesezugriff auf `wp_options` würden sie sonst mitnehmen).

* Konstante gesetzt: Sie hat immer Vorrang, das Eingabefeld ist ausgeblendet. Eine ältere Kopie in der Datenbank wird beim nächsten Speichern der Einstellungen gelöscht.
* Liegen Geheimnisse in der Datenbank (`bs_connector_settings`), zeigt das Backend allen mit `manage_woocommerce` einen dauerhaften Hinweis mit der Empfehlung, sie nach `wp-config.php` zu verschieben.
* Einstellung „Geheimnisse aus der Datenbank erlauben“ (Standard: an, kompatibel zu bestehenden Installationen) bzw. Filter `bs_connector_allow_db_secrets`: Ist sie aus bzw. liefert der Filter `false`, werden Geheimnisse aus der Datenbank ignoriert – ohne Konstanten ist die Anbindung dann aus (Aufrufe an die Plattform unterbleiben, der Provisionierungs-Endpunkt antwortet 401).

    add_filter( 'bs_connector_allow_db_secrets', '__return_false' );

== Datenschutz ==

An die Plattform gehen nur Kontaktdaten (E-Mail, Name, Firma, Land, USt-IdNr.) und Bestellpositionen (Bestell-/Positionsnummer, Art, Menge). Zahlungsdaten bleiben im Shop.

Das Plugin meldet sich bei den Datenschutz-Werkzeugen von WordPress an (Werkzeuge → Personenbezogene Daten exportieren/löschen). Exportiert bzw. gelöscht werden die Nutzer-Metadaten `bs_app_user_id`, `bs_app_tenant_id`, die Merker `_bs_registered`, `_bs_provisioned`, `_bs_email_confirmed`, `_bs_email_verify_sent` und `_bs_email_verify_key` sowie das Ergebnis der USt-IdNr.-Prüfung (`_bs_vat_*`).

Für die USt-IdNr.-Prüfung geht die Nummer (Länderkennung und Nummer, ggf. die eigene USt-IdNr. des Shops) an den VIES-Dienst der EU-Kommission. Bestellungen und ihr Zustellstatus bleiben für die Buchhaltung erhalten. Das Konto auf der Plattform löscht der Plattform-Betreiber.
