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

    public function __construct()
    {
        $this->id                 = 'zcpg_gateway';
        $this->method_title       = esc_html__('Zeno Crypto Gateway', 'zeno-crypto-payment-gateway');
        $this->method_description = esc_html__('Get paid in crypto. Receive fiat', 'zeno-crypto-payment-gateway');
        $this->has_fields         = true;
        $this->supports           = array('products');
        $this->icon               = ZCPG_PLUGIN_URL . 'assets/icon-128x128.png';

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled     = $this->get_option('enabled', 'no');
        $this->title       = $this->get_option('title', __('Pay with Crypto', 'zeno-crypto-payment-gateway'));
        $this->description = $this->get_option(
            'description',
            esc_html__('Pay securely with cryptocurrency. You will be redirected to our secure payment page to complete your transaction.', 'zeno-crypto-payment-gateway')
        );

        $this->api_key_live = $this->get_option('api_key_live', '');
        $this->secret_live  = $this->get_option('secret_live', '');
        $this->test_mode    = false; // Live mode by default
        $this->debug        = false; // Oculto y deshabilitado en la UI

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
                'description' => __('Text that the customer sees in the checkout.', 'zeno-crypto-payment-gateway'),
                'default'     => __('Pay with Crypto', 'zeno-crypto-payment-gateway'),
            ),
            'description'  => array(
                'title'   => __('Description', 'zeno-crypto-payment-gateway'),
                'type'    => 'textarea',
                'default' => __('Pay securely with crypto', 'zeno-crypto-payment-gateway'),
            ),
        );
    }

    /**
     * Página de ajustes del gateway (admin) con video embebido.
     */
    public function admin_options()
    {


        echo '<h2>' . esc_html($this->method_title) . '</h2>';

        if (! empty($this->method_description)) {
            echo '<p>' . esc_html($this->method_description) . '</p>';
        }

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

        $order->payment_complete();
        $order->add_order_note(esc_html__('Payment confirmed in return URL.', 'zeno-crypto-payment-gateway'));
        wp_safe_redirect($gw->get_return_url($order));
        exit;
    }
}
