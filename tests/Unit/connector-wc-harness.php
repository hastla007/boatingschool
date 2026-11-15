<?php

/**
 * Minimaler WordPress-/WooCommerce-Ersatz für Plugin-Tests
 * (ConnectorOrdersAndCheckoutTest): eigener PHP-Prozess ohne Laravel, echte
 * Plugin-Klassen (Checkout, Bestellungen), Beobachtungen als JSON. Kein Test
 * für echtes WooCommerce -- nur für die Plugin-Logik.
 *
 * Aufruf: php connector-wc-harness.php <szenario> '<json-optionen>'
 */
[$scenario, $options] = [$argv[1] ?? '', json_decode($argv[2] ?? '{}', true) ?: []];

define('ABSPATH', __DIR__.'/');
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['bs_transients'] = [];
$GLOBALS['bs_notices'] = [];
$GLOBALS['bs_mails'] = [];
$GLOBALS['bs_actions'] = [];
$GLOBALS['bs_products'] = [];
$GLOBALS['bs_orders'] = [];
$GLOBALS['bs_cart'] = [];
$GLOBALS['bs_logged_in'] = $options['logged_in'] ?? true;

function __($text, $domain = null)
{
    return $text;
}
function get_transient($key)
{
    return $GLOBALS['bs_transients'][$key] ?? false;
}
function set_transient($key, $value, $ttl = 0)
{
    $GLOBALS['bs_transients'][$key] = $value;

    return true;
}
function delete_transient($key)
{
    unset($GLOBALS['bs_transients'][$key]);

    return true;
}
function get_option($name, $default = false)
{
    return $name === 'admin_email' ? 'admin@shop.test' : $default;
}
function apply_filters($hook, $value)
{
    return $value;
}
function is_user_logged_in()
{
    return $GLOBALS['bs_logged_in'];
}
function get_current_user_id()
{
    return 7;
}
function wc_add_notice($message, $type = 'success')
{
    $GLOBALS['bs_notices'][] = $message;
}
function wc_get_product($id)
{
    return $GLOBALS['bs_products'][(int) $id] ?? false;
}
function wc_get_order($id)
{
    return $GLOBALS['bs_orders'][(int) $id] ?? false;
}
function wp_json_encode($data)
{
    return json_encode($data);
}
function wp_mail($to, $subject, $message)
{
    $GLOBALS['bs_mails'][] = $subject;
}
function wp_specialchars_decode($text)
{
    return $text;
}
function get_bloginfo($what)
{
    return 'Shop';
}
function as_enqueue_async_action($hook, $args, $group)
{
    $GLOBALS['bs_actions'][] = [$hook, $args];
}
function as_schedule_single_action($time, $hook, $args, $group)
{
    $GLOBALS['bs_actions'][] = [$hook, $args];
}
function as_has_scheduled_action($hook, $args, $group)
{
    return false;
}
function as_unschedule_all_actions($hook, $args, $group) {}
function WC()
{
    return (object) ['cart' => new class
    {
        public function get_cart(): array
        {
            return $GLOBALS['bs_cart'];
        }
    }];
}

/** Gemeinsame Metadaten wie WC_Data. */
trait BS_Test_Meta
{
    public array $meta = [];

    public function get_meta($key)
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data($key, $value): void
    {
        $this->meta[$key] = $value;
    }

    public function delete_meta_data($key): void
    {
        unset($this->meta[$key]);
    }

    public function save(): void {}
}

class WC_Product
{
    use BS_Test_Meta;

    public function __construct(public int $id, public string $name, array $meta = [])
    {
        $this->meta = $meta;
    }

    public function is_type($type): bool
    {
        return false;
    }

    public function get_parent_id(): int
    {
        return 0;
    }

    public function get_name(): string
    {
        return $this->name;
    }
}

class WC_Order_Item
{
    use BS_Test_Meta;

    public function __construct(public int $id, public int $order_id, public ?WC_Product $product, public int $quantity = 1) {}

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_order_id(): int
    {
        return $this->order_id;
    }

    public function get_product(): ?WC_Product
    {
        return $this->product;
    }

    public function get_name(): string
    {
        return $this->product ? $this->product->get_name() : 'Position';
    }

    public function get_quantity(): int
    {
        return $this->quantity;
    }
}

class WC_Order
{
    use BS_Test_Meta;

    public array $notes = [];

    /** @param WC_Order_Item[] $items */
    public function __construct(public int $id, public string $status, public array $items, public string $payment_method = 'cheque') {}

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_items(): array
    {
        $items = [];
        foreach ($this->items as $item) {
            $items[$item->get_id()] = $item;
        }

        return $items;
    }

    public function get_item($id)
    {
        return $this->get_items()[(int) $id] ?? false;
    }

    public function is_paid(): bool
    {
        return in_array($this->status, ['processing', 'completed'], true);
    }

    public function has_status($status): bool
    {
        return in_array($this->status, (array) $status, true);
    }

    public function get_payment_method(): string
    {
        return $this->payment_method;
    }

    public function get_payment_method_title(): string
    {
        return $this->payment_method;
    }

    public function get_customer_id(): int
    {
        return 7;
    }

    public function get_order_number(): string
    {
        return (string) $this->id;
    }

    public function get_edit_order_url(): string
    {
        return 'https://shop.test/wp-admin/order/'.$this->id;
    }

    public function add_order_note($note): void
    {
        $this->notes[] = $note;
    }
}

final class BS_Api_Client
{
    public static function request(string $method, string $path, ?array $data = null, array $extra_headers = []): array
    {
        return ['status' => null, 'body' => null, 'error' => 'nicht erwartet: '.$method.' '.$path];
    }
}

$includes = dirname(__DIR__, 2).'/integrations/woocommerce-connector/includes/';
foreach (['delivery-policy', 'settings', 'products', 'accounts', 'checkout', 'orders'] as $file) {
    require $includes.'class-bs-'.$file.'.php';
}

// Kontostatus aus dem Zwischenspeicher (keine Anfrage an die App).
if (isset($options['status'])) {
    set_transient(BS_Accounts::STATUS_CACHE.'7', $options['status']);
}

$out = [];
switch ($scenario) {
    case 'add_to_cart':
        // Kurscode für SBF-SEE, Produktcode, normales Produkt.
        $GLOBALS['bs_products'] = [
            1 => new WC_Product(1, 'Kurscode SBF See', ['_bs_kind' => 'course', '_bs_course_code' => 'SBF-SEE']),
            2 => new WC_Product(2, 'Verlängerung SBF See', ['_bs_kind' => 'renewal', '_bs_course_code' => 'SBF-SEE']),
            3 => new WC_Product(3, 'Fahrstunde', ['_bs_kind' => 'product', '_bs_product_code' => 'FAHRSTUNDE-1-EH']),
            4 => new WC_Product(4, 'T-Shirt'),
        ];
        foreach ($GLOBALS['bs_products'] as $id => $product) {
            $GLOBALS['bs_notices'] = [];
            $out[$id] = ['passed' => BS_Checkout::validate_add_to_cart(true, $id), 'notice' => $GLOBALS['bs_notices'][0] ?? null];
        }
        break;

    case 'cancel_after_delivery':
        // Gelieferte Position, Bestellung storniert und später wieder „In Bearbeitung“.
        $product = new WC_Product(3, 'Fahrstunde', ['_bs_kind' => 'product', '_bs_product_code' => 'FAHRSTUNDE-1-EH']);
        $item = new WC_Order_Item(11, 50, $product, 2);
        $item->meta = ['_bs_target' => '{"kind":"product"}', '_bs_delivery_status' => 'delivered', '_bs_batch_id' => 'b-1', '_bs_attempts' => 1];
        $order = new WC_Order(50, 'cancelled', [$item]);
        $GLOBALS['bs_orders'][50] = $order;

        BS_Orders::on_cancel(50);
        $out['voids'] = array_values(array_filter($GLOBALS['bs_actions'], static fn ($a) => $a[0] === BS_Orders::HOOK_VOID));
        $GLOBALS['bs_actions'] = [];

        $order->status = 'processing';
        BS_Orders::schedule_order(50);
        $out['deliveries'] = count(array_filter($GLOBALS['bs_actions'], static fn ($a) => $a[0] === BS_Orders::HOOK_DELIVER));
        $out['notes'] = $order->notes;
        $out['mails'] = $GLOBALS['bs_mails'];
        $out['item_status'] = $item->get_meta('_bs_delivery_status');

        // Noch einmal (z. B. „Abgeschlossen“): keine zweite Meldung.
        $order->status = 'completed';
        BS_Orders::schedule_order(50);
        $out['notes_after_second'] = count($order->notes);
        $out['mails_after_second'] = count($GLOBALS['bs_mails']);
        break;

    default:
        fwrite(STDERR, "Unbekanntes Szenario: {$scenario}\n");
        exit(2);
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
