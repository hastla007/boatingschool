<?php

/*
| wp eval-file: Bestellung des Kunden mit Zahlart Überweisung anlegen und nach
| Zahlungseingang auf „Abgeschlossen“ setzen -- wie der Shop-Admin im Backend.
| Der Statuswechsel plant über das Plugin die Zustellung ein (Action Scheduler).
|
| Argumente: <kunden-id> <produkt-id> <menge>. Ausgabe: Bestell-ID.
*/

[$customer_id, $product_id, $quantity] = [(int) ($args[0] ?? 0), (int) ($args[1] ?? 0), (int) ($args[2] ?? 0)];
$product = wc_get_product($product_id);
if (! get_userdata($customer_id) || ! $product || $quantity < 1) {
    WP_CLI::error('Aufruf: wp eval-file create-order.php <kunden-id> <produkt-id> <menge>');
}

$order = wc_create_order(['customer_id' => $customer_id]);
$order->add_product($product, $quantity);
$order->set_address([
    'first_name' => 'E2E',
    'last_name' => 'Schuladmin',
    'company' => 'Connector-E2E Bootsschule',
    'country' => 'DE',
    'email' => get_userdata($customer_id)->user_email,
], 'billing');
$gateways = WC()->payment_gateways()->payment_gateways();
$order->set_payment_method($gateways['bacs'] ?? 'bacs');
$order->calculate_totals();
$order->save();
$order->update_status('completed', 'E2E: Zahlungseingang verbucht.');

WP_CLI::line((string) $order->get_id());
