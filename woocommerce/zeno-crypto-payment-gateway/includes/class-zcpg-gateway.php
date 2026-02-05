<?php
if (! defined('ABSPATH')) {
    exit;
}

class ZCPG_Gateway extends WC_Payment_Gateway
{
    private $api_key_live;
    private $secret_live;
    private $debug;
    private $test_mode;
    private $success_order_status;

    public function __construct()
    {
        $this->id                 = 'zcpg_gateway';
        $this->method_title       = esc_html__('Zeno Crypto Gateway', 'zeno-crypto-payment-gateway');
        $this->method_description = esc_html__('Accept Crypto Payments with Ease', 'zeno-crypto-payment-gateway');
        $this->has_fields         = false;
        $this->supports           = array('products');
        $this->icon               = $this->get_checkout_icon_url();
        $this->init_form_fields();
        $this->init_settings();

        $this->enabled     = $this->get_option('enabled', 'no');
        $this->title       = $this->get_option('title', __('Pay with Crypto', 'zeno-crypto-payment-gateway'));
        $this->description = $this->get_option(
            'description',
            esc_html__('Pay securely using USDT, USDC or Binance Pay.', 'zeno-crypto-payment-gateway')
        );

        $this->api_key_live = $this->get_option('api_key_live', '');
        $this->secret_live  = $this->get_option('secret_live', '');
        $this->test_mode    = ('yes' === $this->get_option('test_mode', 'no')); // Live mode by default
        $this->debug        = false; // Oculto y deshabilitado en la UI
        $this->success_order_status = $this->get_success_order_status();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function needs_setup()
    {
        return ! $this->has_valid_api_key();
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled'      => array(
                'title'   => __('Enable/Disable', 'zeno-crypto-payment-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Enable Zeno Gateway', 'zeno-crypto-payment-gateway'),
                'default' => 'no',
            ),
            'api_key_live' => array(
                'title'       => __('API Key', 'zeno-crypto-payment-gateway'),
                'type'        => 'password',
                'placeholder' => __('Enter your API key', 'zeno-crypto-payment-gateway'),
                'description' => wp_kses_post(
                    sprintf(
                        /* translators: %s: URL to the ZenoBank Dashboard. */
                        esc_html__('Get your API key here: %s', 'zeno-crypto-payment-gateway'),
                        sprintf(
                            '<a href="%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>',
                            esc_url('https://dashboard.zenobank.io/')
                        )
                    )
                ),
            ),
            'title'        => array(
                'title'       => __('Title', 'zeno-crypto-payment-gateway'),
                'type'        => 'text',
                'description' => __('Text that the customer sees at the checkout.', 'zeno-crypto-payment-gateway'),
                'default'     => __('USDT, USDC, Binance Pay', 'zeno-crypto-payment-gateway'),
            ),
            'description'  => array(
                'title'   => __('Description', 'zeno-crypto-payment-gateway'),
                'type'    => 'textarea',
                'default' => __('Pay with Crypto', 'zeno-crypto-payment-gateway'),
            ),
            'success_order_status' => array(
                'title'       => __('Order status after successful payment', 'zeno-crypto-payment-gateway'),
                'type'        => 'select',
                'description' => __('Choose the WooCommerce order status to apply when the payment has been completed successfully. Default is Processing.', 'zeno-crypto-payment-gateway'),
                'default'     => 'processing',
                'options'     => $this->get_available_order_statuses(),
            ),
            'test_mode'    => array(
                'title'       => __('Test mode', 'zeno-crypto-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Enable test mode', 'zeno-crypto-payment-gateway'),
                'description' => __('When test mode is enabled, the checkout will only be visible to admins.', 'zeno-crypto-payment-gateway'),
                'default'     => 'no',
            ),
            'logo_mode'    => array(
                'title'       => __('Checkout logo', 'zeno-crypto-payment-gateway'),
                'type'        => 'select',
                'description' => __('Choose which logo to show in the checkout.', 'zeno-crypto-payment-gateway'),
                'default'     => 'default',
                'options'     => array(
                    'default'  => __('Default logo', 'zeno-crypto-payment-gateway'),
                    'default2' => __('Alternative logo 2', 'zeno-crypto-payment-gateway'),
                    'default3' => __('Alternative logo 3', 'zeno-crypto-payment-gateway'),
                    'none'     => __('No logo', 'zeno-crypto-payment-gateway'),
                    'custom'   => __('Custom logo (URL or upload)', 'zeno-crypto-payment-gateway'),
                ),
            ),
            'custom_logo_url' => array(
                'title'       => __('Custom logo URL', 'zeno-crypto-payment-gateway'),
                'type'        => 'text',
                'description' => __('Enter the URL of the image to use as the checkout logo, or upload an image in the Media Library and paste its URL here.', 'zeno-crypto-payment-gateway'),
                'default'     => '',
            ),
        );
    }


    public function admin_options()
    {


        echo '<h2>' . esc_html($this->method_title) . '</h2>';

        if (! empty($this->method_description)) {
            echo '<p>' . esc_html($this->method_description) . '</p>';
        }

        // Support link in settings.
        echo '<p><a href="' . esc_url('https://zenobank.io/support') . '" target="_blank" rel="noopener noreferrer">'
            . esc_html__('Support', 'zeno-crypto-payment-gateway')
            . '</a></p>';

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    public function process_admin_options()
    {
        $saved  = parent::process_admin_options();
        $errors = array();

        $enabled         = ('yes' === $this->get_option('enabled', 'no'));
        $title           = trim((string) $this->get_option('title', ''));
        $current_api_key = trim((string) $this->get_option('api_key_live', ''));
        $test_mode       = (bool) $this->test_mode;

        if (empty($title)) {
            $errors[] = esc_html__('The "Title" field is required.', 'zeno-crypto-payment-gateway');
        }

        if ($enabled) {
            if (empty($current_api_key)) {
                $errors[] = esc_html__('You cannot enable this payment method without an API Key.', 'zeno-crypto-payment-gateway');
            }
        }

        if (! empty($errors)) {
            foreach ($errors as $error) {
                WC_Admin_Settings::add_error($error);
            }

            if ($enabled) {
                $this->update_option('enabled', 'no');
            }

            return false;
        }

        return $saved;
    }

    public function payment_fields()
    {
        $template = locate_template('zeno-crypto-payment-gateway/checkout-payment-fields.php');
        if (! $template) {
            $template = ZCPG_PLUGIN_DIR . 'templates/checkout-payment-fields.php';
        }
        $title       = $this->title;
        $description = $this->description;
        include $template; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
    }

    private function current_endpoint(): string
    {
        return ZCPG_API_ENDPOINT;
    }

    private function current_api_key(): string
    {
        return (string) $this->api_key_live;
    }

    private function current_secret(): string
    {
        return (string) $this->secret_live;
    }

    /**
     * Get the configured order status to apply after successful payment.
     *
     * @return string e.g. 'processing', 'completed'.
     */
    public function get_success_order_status(): string
    {
        $status = (string) $this->get_option('success_order_status', 'processing');

        // Sanitize: allow only known statuses, fallback to 'processing'.
        $available = $this->get_available_order_statuses();
        if (! isset($available[$status])) {
            $status = 'processing';
        }

        // Remove potential "wc-" prefix to match wc_update_order_status expectations.
        if (0 === strpos($status, 'wc-')) {
            $status = substr($status, 3);
        }

        return $status;
    }

    /**
     * Available order statuses for settings dropdown.
     *
     * @return array
     */
    private function get_available_order_statuses(): array
    {
        if (function_exists('wc_get_order_statuses')) {
            $statuses = wc_get_order_statuses();
            $result   = array();
            foreach ($statuses as $key => $label) {
                // Normalize keys to strip "wc-" prefix.
                if (0 === strpos($key, 'wc-')) {
                    $normalized = substr($key, 3);
                } else {
                    $normalized = $key;
                }

                $result[$normalized] = $label;
            }

            return $result;
        }

        // Fallback to common core statuses.
        return array(
            'processing' => _x('Processing', 'Order status', 'zeno-crypto-payment-gateway'),
            'completed'  => _x('Completed', 'Order status', 'zeno-crypto-payment-gateway'),
            'on-hold'    => _x('On hold', 'Order status', 'zeno-crypto-payment-gateway'),
        );
    }

    /**
     * Resolve checkout icon URL based on settings.
     *
     * @return string
     */
    private function get_checkout_icon_url(): string
    {
        $logo_mode      = $this->get_option('logo_mode', 'default');
        $custom_logo    = $this->get_option('custom_logo_url', '');
        $default_logo   = ZCPG_PLUGIN_URL . 'assets/checkout-logo.png';
        $alternative_2  = ZCPG_PLUGIN_URL . 'assets/checkout-logo-2.png';
        $alternative_3  = ZCPG_PLUGIN_URL . 'assets/checkout-logo-3.png';

        switch ($logo_mode) {
            case 'none':
                return '';
            case 'custom':
                $url = esc_url_raw((string) $custom_logo);
                return $url ?: $default_logo;
            case 'default2':
                return $alternative_2;
            case 'default3':
                return $alternative_3;
            case 'default':
            default:
                return $default_logo;
        }
    }

    private function has_valid_api_key(): bool
    {
        $api_key = $this->get_option('api_key_live', '');
        return ! empty($api_key);
    }

    public function generate_verification_token($order_id)
    {
        return hash_hmac('sha256', (string) $order_id, $this->current_secret());
    }

    public function process_payment($order_id)
    {
        $order    = wc_get_order($order_id);
        $amount   = $order->get_total();
        $currency = $order->get_currency();

        $verification_token = $this->generate_verification_token($order_id);

        $success_url = $order->get_checkout_order_received_url();

        $webhook_url = add_query_arg(
            array(
                'order_id' => $order_id,
            ),
            rest_url('zcpg/v1/webhook')
        );

        $payload = array(
            'version'           => ZCPG_VERSION,
            'platform'          => 'woocommerce',
            'priceAmount'       => $amount,
            'priceCurrency'     => $currency,
            'orderId'           => (string) $order_id,
            'successRedirectUrl'        => $success_url,
            'verificationToken' => $verification_token,
            'webhookUrl'        => $webhook_url,
        );

        $args = array(
            'headers' => array(
                'x-api-key'    => $this->current_api_key(),
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode($payload),
            'timeout' => 25,
        );

        $response = wp_remote_post(trailingslashit($this->current_endpoint()) . 'api/v1/checkouts', $args);

        if (is_wp_error($response)) {
            wc_add_notice(esc_html__('Error connecting with the gateway.', 'zeno-crypto-payment-gateway'), 'error');
            return array('result' => 'failure');
        }

        $body        = json_decode(wp_remote_retrieve_body($response), true);
        $payment_url = isset($body['checkoutUrl']) ? (string) $body['checkoutUrl'] : '';

        if (! $payment_url) {
            wc_add_notice(__('The payment URL could not be generated.', 'zeno-crypto-payment-gateway'), 'error');
            return array('result' => 'failure');
        }

        $order->update_status('pending', esc_html__('Waiting for payment in Crypto Gateway', 'zeno-crypto-payment-gateway'));

        update_post_meta($order_id, '_zcpg_payment_url', esc_url_raw($payment_url));

        return array(
            'result'   => 'success',
            'redirect' => esc_url_raw($payment_url),
        );
    }

    public static function handle_return()
    {
        if (! isset($_GET['_wpnonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'zcpg_return_nonce')) {
            wp_die(esc_html__('Security check failed. Please try again.', 'zeno-crypto-payment-gateway'));
        }

        $order_id = isset($_GET['order_id']) ? (int) sanitize_text_field(wp_unslash($_GET['order_id'])) : 0;
        $hash     = isset($_GET['verification_token']) ? sanitize_text_field(wp_unslash($_GET['verification_token'])) : '';

        if (! $order_id || ! ($order = wc_get_order($order_id))) {
            wp_safe_redirect(wc_get_page_permalink('shop'));
            exit;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $gw       = isset($gateways['zcpg_gateway']) ? $gateways['zcpg_gateway'] : null;

        if (! ($gw instanceof self)) {
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        $expected = hash_hmac('sha256', (string) $order_id, $gw->current_secret());

        if (! hash_equals($expected, $hash)) {
            $order->add_order_note(esc_html__('Invalid return: incorrect signature.', 'zeno-crypto-payment-gateway'));
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }

        $success_status = $gw->get_success_order_status();

        if ('completed' === $success_status) {
            // Let WooCommerce run its internal payment complete logic first.
            $order->payment_complete();
            // Then explicitly set status to completed as requested in settings.
            $order->set_status('completed');
            $order->save();
        } else {
            $order->update_status(
                $success_status,
                esc_html__('Payment confirmed in return URL.', 'zeno-crypto-payment-gateway')
            );
        }

        $order->add_order_note(esc_html__('Payment confirmed in return URL.', 'zeno-crypto-payment-gateway'));

        wp_safe_redirect($gw->get_return_url($order));
        exit;
    }
}
