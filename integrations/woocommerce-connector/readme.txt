=== Bootsschul-Plattform Connector ===
Requires at least: 6.4
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: Proprietary

Verkauft Codes der Bootsschul-Plattform über WooCommerce. Nach der Zahlung werden die Codes im Codebestand der verknüpften Bootsschule hinterlegt.

== Beschreibung ==

* Kaufbar: Kurscodes, Verlängerungscodes, Produktcodes (z. B. Fahrstunden) und Kurscodes mit Zusatzprodukt.
* Zustellung ausschließlich in den Codebestand der Bootsschule auf der Plattform. Der Shop sieht und versendet keine Codes.
* Einkaufen kann nur, wer mit einer aktiven Bootsschule verknüpft ist: per Verknüpfungscode aus der Plattform („Mein Konto → Bootsschule verknüpfen“) oder nach Freigabe einer Neuregistrierung durch den Plattform-Betreiber.
* Genau ein Code-Paket je Bestellposition. Wiederholte Zustellungen nach Zeitüberschreitung erzeugen keine zusätzlichen Codes.
* Rückerstattung oder Storno sperrt unbenutzte Codes. Bereits eingelöste Codes bleiben gültig.
* Endgültig fehlgeschlagene Zustellungen erzeugen eine Bestellnotiz, eine Alarm-Mail und den Knopf „Erneut zustellen“.

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
