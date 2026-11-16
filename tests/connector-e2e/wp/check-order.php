<?php

/*
| wp eval-file: Stand der Zustellung einer Bestellung ausgeben und prüfen.
| Erfüllt, wenn jede Position mit Bootsschul-Codes „zugestellt“ ist
| (_bs_delivery_status = delivered, mit Batch der App) und die Bestellnotiz
| „… Bootsschul-Code(s) … im Codebestand der Bootsschule hinterlegt.“ steht.
|
| Argumente: <bestell-id> [--quiet]. Exit 0 = zugestellt, 1 = (noch) nicht.
*/

$order = wc_get_order((int) ($args[0] ?? 0));
if (! $order) {
    WP_CLI::error('Bestellung nicht gefunden: '.($args[0] ?? ''));
}
$quiet = in_array('--quiet', $args, true);
$say = static function (string $line) use ($quiet): void {
    if (! $quiet) {
        WP_CLI::line($line);
    }
};

$say(sprintf('Bestellung %d: Status %s, Zahlart %s', $order->get_id(), $order->get_status(), $order->get_payment_method()));
$items = 0;
$delivered = 0;
foreach ($order->get_items() as $item_id => $item) {
    if ((string) $item->get_meta('_bs_target') === '') {
        continue;
    }
    $items++;
    $status = (string) $item->get_meta('_bs_delivery_status');
    $batch = (string) $item->get_meta('_bs_batch_id');
    $say(sprintf('  Position %d „%s“ x%d: %s, Versuche %s, Batch %s, Fehler %s', $item_id, $item->get_name(), $item->get_quantity(),
        $status ?: '-', (string) $item->get_meta('_bs_attempts') ?: '0', $batch ?: '-', (string) $item->get_meta('_bs_error') ?: '-'));
    if ($status === 'delivered' && $batch !== '') {
        $delivered++;
    }
}

$noted = false;
foreach (array_reverse(wc_get_order_notes(['order_id' => $order->get_id()])) as $note) {
    $say('  Notiz: '.$note->content);
    $noted = $noted || str_contains($note->content, 'im Codebestand der Bootsschule hinterlegt');
}

WP_CLI::halt($items > 0 && $delivered === $items && $noted ? 0 : 1);
