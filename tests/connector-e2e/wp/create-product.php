<?php

/*
| wp eval-file: Produkt „Kurscode“ anlegen. Der Kurs kommt aus dem Katalog
| der App (signiertes GET /catalog über das Plugin); bevorzugt <kurscode>,
| sonst der erste Kurs. Gespeichert wird die Zuordnung wie im Backend
| (BS_Products::save_fields): Art „course“, Kurscode, Produkt virtuell.
|
| Argumente: [kurscode]. Ausgabe: "<produkt-id> <kurscode>".
*/

$courses = array_values(array_filter(array_map(
    static fn ($course) => (string) ($course['code'] ?? ''),
    BS_Products::catalog()['courses']
)));
if ($courses === []) {
    WP_CLI::error('Katalog der App ist leer oder nicht erreichbar (GET /catalog).');
}
$code = in_array($args[0] ?? '', $courses, true) ? $args[0] : $courses[0];

$product = new WC_Product_Simple;
$product->set_name('Kurscode '.$code.' (E2E)');
$product->set_status('publish');
$product->set_regular_price('49.00');
$product->set_virtual(true);
$product->update_meta_data(BS_Products::META_KIND, 'course');
$product->update_meta_data(BS_Products::META_COURSE, $code);
$product->save();

WP_CLI::line($product->get_id().' '.$code);
