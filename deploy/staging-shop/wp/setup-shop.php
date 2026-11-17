<?php

/*
| wp eval-file: WooCommerce-Grundeinstellungen für den Staging-Shop
| (Deutschland/EUR, kein „Coming soon“, Registrierung unter „Mein Konto“) und
| Zahlarten Überweisung, Nachnahme und Testzahlung (mu-plugins/bs-staging-testpay.php).
*/

update_option('woocommerce_default_country', 'DE:BE');
update_option('woocommerce_currency', 'EUR');
update_option('woocommerce_price_thousand_sep', '.');
update_option('woocommerce_price_decimal_sep', ',');
update_option('woocommerce_currency_pos', 'right_space');
update_option('woocommerce_calc_taxes', 'no');
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_enable_myaccount_registration', 'yes');
update_option('woocommerce_onboarding_profile', ['completed' => true, 'skipped' => true]);
update_option('woocommerce_task_list_hidden', 'yes');
// Firmenfeld an der Kasse: bewusst nicht gesetzt. Ab Connector 1.4.4 zeigt das Plugin es
// selbst als optional an; so prüft der Staging-Lauf den Standard neuer Installationen.

foreach (['bacs', 'cod', 'bs_testpay'] as $gateway) {
    $settings = (array) get_option("woocommerce_{$gateway}_settings", []);
    $settings['enabled'] = 'yes';
    update_option("woocommerce_{$gateway}_settings", $settings);
}

if (wc_get_page_id('myaccount') <= 0 || ! get_post(wc_get_page_id('myaccount'))) {
    WC_Install::create_pages();
}
flush_rewrite_rules();

WP_CLI::line('Mein Konto: '.wc_get_page_permalink('myaccount'));
WP_CLI::line('HPOS: '.(get_option('woocommerce_custom_orders_table_enabled') === 'yes' ? 'aktiv' : 'aus'));
WP_CLI::line('Zahlarten: '.implode(', ', array_keys(WC()->payment_gateways()->payment_gateways())));
