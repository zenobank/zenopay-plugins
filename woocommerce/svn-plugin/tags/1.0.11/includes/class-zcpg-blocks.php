<?php
if (! defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class ZCPG_Blocks_Gateway extends AbstractPaymentMethodType
{

    protected $name = 'zcpg_gateway';
    protected $settings = [];

    public function initialize()
    {
        $this->settings = get_option('woocommerce_' . $this->name . '_settings', []);
    }

    public function is_active()
    {
        return $this->settings['enabled'] === 'yes';
    }

    public function get_payment_method_data()
    {
        return [
            'title'       => $this->settings['title'] ?? __('USDT, USDC, Binance Pay', 'zeno-crypto-payment-gateway'),
            'description' => $this->settings['description'] ?? '',
            'supports'    => ['products', 'block'],
        ];
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'zcpg-blocks',
            plugins_url('assets/js/blocks.js', __FILE__),
            ['wc-blocks-registry', 'wc-settings', 'wp-element'],
            '1.0.11',
            true
        );
        return ['zcpg-blocks'];
    }
}
