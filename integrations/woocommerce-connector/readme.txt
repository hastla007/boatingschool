=== Bootsschul-Plattform Connector ===
Requires at least: 6.5
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.1.0
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

== Betrieb ==

* **Protokoll:** WooCommerce → Status → Protokolle, Quelle `bs-connector`. Protokolliert werden API-Fehler, Zustellungen, Wiederholungen, Sperren, Bestätigungsmails und Verbindungstests. Codewerte, Verknüpfungscodes, Bestätigungslinks und Geheimnisse stehen nie im Protokoll.
* **Verbindung testen:** Knopf auf dem Einstellungs-Tab „Bootsschule“. Ruft `GET /catalog` mit den gespeicherten Einstellungen auf und zeigt das Ergebnis als Hinweis an (Recht `manage_woocommerce`).
* **Fehlgeschlagene Zustellungen:** WooCommerce → Bootsschul-Zustellungen listet alle Positionen mit Status „fehlgeschlagen“. Mit der Sammelaktion „Erneut zustellen“ werden sie neu angestoßen (Recht `edit_shop_orders`). Die Plattform liefert je Position höchstens einmal.
* **Abgleich per WP-CLI:** `wp bs-connector reconcile [--days=30]` vergleicht zugestellte, fehlgeschlagene und stornierte Positionen der letzten Tage mit `GET /orders/{id}/lines/{line}` und gibt Abweichungen als Tabelle aus (fehlende Pakete, andere Paket-ID oder Menge, offene Codes trotz Storno). Der Befehl ändert nichts.

== Übersetzung ==

Text-Domain `bs-connector`, Ordner `languages/`. Die Vorlage `languages/bs-connector.pot` erzeugt `php bin/make-pot.php` (im Plugin-Ordner) aus allen `__()`-/`esc_html__()`-Aufrufen. Ein Test der Plattform prüft, dass sie aktuell ist.

== Installation ==

1. Ordner `woocommerce-connector` nach `wp-content/plugins/` kopieren und aktivieren (WooCommerce muss aktiv sein).
2. Geheimnisse bevorzugt in `wp-config.php` eintragen:

    define( 'BS_CONNECTOR_APP_URL', 'https://app.example.de' );
    define( 'BS_CONNECTOR_KEY_ID', 'shop' );            // = COMMERCE_SHOP_KEY_ID der Plattform
    define( 'BS_CONNECTOR_SECRET', '…' );               // = COMMERCE_SHOP_SECRET
    define( 'BS_CONNECTOR_INBOUND_KEY_ID', 'app' );     // = COMMERCE_OUTBOUND_KEY_ID
    define( 'BS_CONNECTOR_INBOUND_SECRET', '…' );       // = COMMERCE_OUTBOUND_SECRET

   Alternativ unter WooCommerce → Einstellungen → Bootsschul-Plattform.
3. Auf der Plattform `COMMERCE_SHOP_URL` auf die Shop-Adresse setzen.
4. Produkte anlegen und unter „Allgemein“ die Art (Kurscode, Verlängerungscode, Produktcode) und den Kurs bzw. das Produkt auswählen. Die Auswahl kommt aus dem Katalog der Plattform.

Ausführliche Beschreibung: docs/commerce/README.md im Plattform-Repository.

== Datenschutz ==

An die Plattform gehen nur Kontaktdaten (E-Mail, Name, Firma, Land, USt-IdNr.) und Bestellpositionen (Bestell-/Positionsnummer, Art, Menge). Zahlungsdaten bleiben im Shop.

Das Plugin meldet sich bei den Datenschutz-Werkzeugen von WordPress an (Werkzeuge → Personenbezogene Daten exportieren/löschen). Exportiert bzw. gelöscht werden die Nutzer-Metadaten `bs_app_user_id`, `bs_app_tenant_id` und die Merker `_bs_registered`, `_bs_provisioned`, `_bs_email_confirmed`, `_bs_email_verify_sent` und `_bs_email_verify_key`. Bestellungen und ihr Zustellstatus bleiben für die Buchhaltung erhalten. Das Konto auf der Plattform löscht der Plattform-Betreiber.
