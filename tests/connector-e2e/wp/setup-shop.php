<?php

/*
| wp eval-file: WooCommerce-Grundeinstellungen für den Connector-E2E-Test
| (Deutschland, EUR, kein „Coming soon“, Zahlart Überweisung). Gibt die
| Adresse von „Mein Konto“ aus.
*/

update_option('woocommerce_default_country', 'DE:BE');
update_option('woocommerce_currency', 'EUR');
update_option('woocommerce_calc_taxes', 'no');
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_enable_myaccount_registration', 'yes');
update_option('woocommerce_onboarding_profile', ['completed' => true, 'skipped' => true]);

$bacs = (array) get_option('woocommerce_bacs_settings', []);
$bacs['enabled'] = 'yes';
update_option('woocommerce_bacs_settings', $bacs);

// Seiten (Mein Konto, Kasse ...) anlegen, falls die Installation das nicht getan hat.
if (wc_get_page_id('myaccount') <= 0 || ! get_post(wc_get_page_id('myaccount'))) {
    WC_Install::create_pages();
}
flush_rewrite_rules();

WP_CLI::line(wc_get_page_permalink('myaccount'));
