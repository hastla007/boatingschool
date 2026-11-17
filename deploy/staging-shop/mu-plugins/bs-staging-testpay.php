<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Plugin Name: Staging: Testzahlung
 * Description: Nur für den Staging-Shop (deploy/staging-shop): Zahlart „Testzahlung“, die wie ein Gateway mit Zahlungsbestätigung (Stripe/WooPayments im Testmodus) $order->payment_complete() aufruft. Ersatz für ein echtes Testkonto; in Produktion nicht verfügbar.
 */
if (! defined('ABSPATH') || (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production')) {
    return;
}

add_action('plugins_loaded', static function (): void {
    if (! class_exists('WC_Payment_Gateway')) {
        return;
    }

    final class BS_Staging_Testpay_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'bs_testpay';
            $this->method_title = 'Testzahlung (Staging)';
            $this->method_description = 'Bestätigt die Zahlung sofort mit payment_complete() – wie ein Gateway im Testmodus. Nur Staging.';
            $this->has_fields = false;
            $this->supports = ['products', 'refunds'];
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title', 'Testzahlung');
            $this->description = $this->get_option('description', '');
            $this->enabled = $this->get_option('enabled', 'no');
            add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields(): void
        {
            $this->form_fields = [
                'enabled' => ['title' => 'Aktiv', 'type' => 'checkbox', 'label' => 'Testzahlung anbieten', 'default' => 'no'],
                'title' => ['title' => 'Titel', 'type' => 'text', 'default' => 'Testzahlung'],
                'description' => ['title' => 'Beschreibung', 'type' => 'textarea', 'default' => 'Staging: Zahlung wird sofort als eingegangen bestätigt.'],
            ];
        }

        public function process_payment($order_id): array
        {
            $order = wc_get_order($order_id);
            // Wie ein echtes Gateway nach der Bestätigung durch den Zahlungsdienst.
            $order->payment_complete('TEST-'.$order_id.'-'.time());
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }

            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        public function process_refund($order_id, $amount = null, $reason = ''): bool
        {
            return true;
        }
    }

    add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
        $gateways[] = 'BS_Staging_Testpay_Gateway';

        return $gateways;
    });
}, 11);

// Block-Kasse: Zahlart im Client registrieren (ohne Build-Schritt).
add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry): void {
    if (! class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    final class BS_Staging_Testpay_Blocks extends AbstractPaymentMethodType
    {
        protected $name = 'bs_testpay';

        public function initialize(): void
        {
            $this->settings = (array) get_option('woocommerce_bs_testpay_settings', []);
        }

        public function is_active(): bool
        {
            return ($this->settings['enabled'] ?? 'no') === 'yes';
        }

        public function get_payment_method_script_handles(): array
        {
            wp_register_script('bs-staging-testpay', false, ['wc-blocks-registry', 'wc-settings', 'wp-element'], '1', true);
            wp_add_inline_script('bs-staging-testpay', <<<'JS'
                (function () {
                    var data = (window.wc.wcSettings.getSetting('bs_testpay_data', {}) || {});
                    var el = window.wp.element.createElement;
                    var label = data.title || 'Testzahlung';
                    var content = el('div', null, data.description || '');
                    window.wc.wcBlocksRegistry.registerPaymentMethod({
                        name: 'bs_testpay',
                        label: el('span', null, label),
                        ariaLabel: label,
                        content: content,
                        edit: content,
                        canMakePayment: function () { return true; },
                        supports: { features: ['products'] }
                    });
                })();
            JS);

            return ['bs-staging-testpay'];
        }

        public function get_payment_method_data(): array
        {
            return [
                'title' => $this->settings['title'] ?? 'Testzahlung',
                'description' => $this->settings['description'] ?? '',
                'supports' => ['products'],
            ];
        }
    }

    $registry->register(new BS_Staging_Testpay_Blocks);
});
