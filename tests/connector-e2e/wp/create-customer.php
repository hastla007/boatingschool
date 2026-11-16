<?php

/*
| wp eval-file: Kundenkonto anlegen wie die Registrierung unter „Mein Konto“
| (wc_create_new_customer löst woocommerce_created_customer aus; das Plugin
| liest Firma/Land aus dem Formular und verschickt die Bestätigungsmail).
|
| Argumente: <email> <passwort>. Ausgabe: Kunden-ID.
*/

[$email, $password] = [$args[0] ?? '', $args[1] ?? ''];
if (! is_email($email) || $password === '') {
    WP_CLI::error('Aufruf: wp eval-file create-customer.php <email> <passwort>');
}

// Felder des Registrierungsformulars (BS_Accounts::registration_fields).
$_POST['billing_company'] = 'Connector-E2E Bootsschule';
$_POST['billing_country'] = 'DE';

$customer_id = wc_create_new_customer($email, '', $password, ['first_name' => 'E2E', 'last_name' => 'Schuladmin']);
if (is_wp_error($customer_id)) {
    WP_CLI::error($customer_id->get_error_message());
}

WP_CLI::line((string) $customer_id);
