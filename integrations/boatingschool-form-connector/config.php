<?php

use BoatingschoolFormConnector\Features\LeadDelivery;

/**
 * Einstellungen des Add-on-Kernels für den BoatingSchool Form-Connector (BS-11, Entscheidung 17).
 * Gemeinsam für die Plugin-Datei und uninstall.php. Muster: learnboating-ticket-connector/config.php.
 */
defined('ABSPATH') || exit;

/* Version des Plugins; gleich dem Kopf „Version:“ und BOATINGSCHOOL_FORM_VERSION der Plugin-Datei. */
defined('BOATINGSCHOOL_FORM_CONNECTOR_VERSION') || define('BOATINGSCHOOL_FORM_CONNECTOR_VERSION', '1.1.0');

/* Produktschlüssel im Katalog des Lizenzservers (= Plugin-Ordner und Textdomain, Entscheidung 17). */
defined('BOATINGSCHOOL_FORM_CONNECTOR_PRODUCT_KEY') || define('BOATINGSCHOOL_FORM_CONNECTOR_PRODUCT_KEY', 'boatingschool-form-connector');

/*
 * HTTPS-Adresse des Lizenzservers. PLATZHALTER: Solange der Host example.com ist, meldet der Kernel „nicht
 * eingerichtet“ und sendet keine Anfrage (keine Lizenz = Connector aus). Eine Testseite setzt die Konstante in
 * wp-config.php.
 */
defined('BOATINGSCHOOL_FORM_CONNECTOR_LICENSE_SERVER') || define('BOATINGSCHOOL_FORM_CONNECTOR_LICENSE_SERVER', 'https://license.example.com');

/* Öffentliche Ed25519-Schlüssel des Lizenzservers, base64, kommagetrennt. PLATZHALTER: leer. */
defined('BOATINGSCHOOL_FORM_CONNECTOR_LICENSE_PUBLIC_KEYS') || define('BOATINGSCHOOL_FORM_CONNECTOR_LICENSE_PUBLIC_KEYS', '');

/* Kundenkonto auf dem Lizenzserver (Verlängern-Link). PLATZHALTER: leer. */
defined('BOATINGSCHOOL_FORM_CONNECTOR_ACCOUNT_URL') || define('BOATINGSCHOOL_FORM_CONNECTOR_ACCOUNT_URL', '');

/* Niedrigste Version von Form Captain (Connector-Vertrag v1). */
defined('BOATINGSCHOOL_FORM_CONNECTOR_MIN_CORE_VERSION') || define('BOATINGSCHOOL_FORM_CONNECTOR_MIN_CORE_VERSION', '1.0.0-dev');

return [
    'plugin_file' => __DIR__.'/boatingschool-form-connector.php',
    'product_key' => BOATINGSCHOOL_FORM_CONNECTOR_PRODUCT_KEY,
    'version' => BOATINGSCHOOL_FORM_CONNECTOR_VERSION,
    // Gleich dem Kopf „Update URI“ der Plugin-Datei (tests/Unit/FormConnectorContractTest.php).
    'update_uri' => 'https://license.example.com/boatingschool-form-connector',
    'name' => 'BoatingSchool Form-Connector',
    'server_url' => BOATINGSCHOOL_FORM_CONNECTOR_LICENSE_SERVER,
    'public_keys' => BOATINGSCHOOL_FORM_CONNECTOR_LICENSE_PUBLIC_KEYS,
    'account_url' => BOATINGSCHOOL_FORM_CONNECTOR_ACCOUNT_URL,
    // Eigenes Präfix (Entscheidung 17): nicht Teil von Form Captain Pro.
    'entitlement_prefix' => 'boatingschool_form.',
    'core' => [
        'name' => 'Form Captain',
        'min_version' => BOATINGSCHOOL_FORM_CONNECTOR_MIN_CORE_VERSION,
        'version' => 'FORM_CAPTAIN_VERSION',
        'menu_parent' => 'form-captain',
    ],
    // Nach form_captain_loaded_v1 (plugins_loaded, Priorität 20): Anmeldung sofort, vor dem Einsammeln an init.
    'boot_priority' => 30,
    // Eigene Optionen (Präfix boatingschool_form_) entfernt uninstall.php selbst; der Kernel räumt nur Namen mit
    // seinem Präfix boatingschool_form_connector_ (Lizenzdaten) ab.
    'features' => [
        LeadDelivery::class,
    ],
];
