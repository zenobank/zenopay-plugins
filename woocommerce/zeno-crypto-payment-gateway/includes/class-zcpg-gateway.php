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
            'advanced_title' => array(
                'title'       => __('Advanced', 'zeno-crypto-payment-gateway'),
                'type'        => 'title',
                'description' => __('Advanced status and logo settings.', 'zeno-crypto-payment-gateway'),
            ),
            'test_mode'    => array(
                'title'       => __('Test mode', 'zeno-crypto-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Enable test mode', 'zeno-crypto-payment-gateway'),
                'description' => __('When test mode is enabled, the checkout will only be visible to admins.', 'zeno-crypto-payment-gateway'),
                'default'     => 'no',
            ),
            'success_order_status' => array(
                'title'       => __('Order status after successful payment', 'zeno-crypto-payment-gateway'),
                'type'        => 'select',
                'description' => __('Choose the WooCommerce order status to apply when the payment has been completed successfully. Default is Processing.', 'zeno-crypto-payment-gateway'),
                'default'     => 'processing',
                'options'     => $this->get_available_order_statuses(),
            ),
            'expired_order_status' => array(
                'title'       => __('Order status when checkout expires', 'zeno-crypto-payment-gateway'),
                'type'        => 'select',
                'description' => __('Choose the order status to apply when the checkout expires. If not configured, WooCommerce default order status will be used (no change).', 'zeno-crypto-payment-gateway'),
                'default'     => '',
                'options'     => array_merge(
                    array(
                        '' => __('Use WooCommerce default (no change)', 'zeno-crypto-payment-gateway'),
                    ),
                    $this->get_available_order_statuses()
                ),
            ),
            'initiated_order_status' => array(
                'title'       => __('Order status when payment is initiated', 'zeno-crypto-payment-gateway'),
                'type'        => 'select',
                'description' => __('Choose the order status to apply when the customer initiates the payment (clicks Pay with Zeno). If not configured, Pending will be used.', 'zeno-crypto-payment-gateway'),
                'default'     => 'default',
                'options'     => array_merge(
                    array(
                        'default' => __('Use plugin default (Pending)', 'zeno-crypto-payment-gateway'),
                    ),
                    $this->get_available_order_statuses()
                ),
            ),
            'logo_mode'    => array(
                'title'       => __('Checkout logo', 'zeno-crypto-payment-gateway'),
                'type'        => 'select',
                'description' => __('Choose which logo to show in the checkout.', 'zeno-crypto-payment-gateway'),
                'default'     => 'default',
                'options'     => array(
                    'default'  => __('Default', 'zeno-crypto-payment-gateway'),
                    'default2' => __('Crypto', 'zeno-crypto-payment-gateway'),
                    'default3' => __('Stablecoins', 'zeno-crypto-payment-gateway'),
                    'default4' => __('Binance Pay', 'zeno-crypto-payment-gateway'),
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
        echo '</table>'; // Closes the last table opened by generate_settings_html.

        // Logo visual picker data.
        $logo_options = array(
            'default'  => array(
                'label' => __('Default', 'zeno-crypto-payment-gateway'),
                'url'   => ZCPG_PLUGIN_URL . 'assets/checkout-logo.png',
            ),
            'default2' => array(
                'label' => __('Crypto', 'zeno-crypto-payment-gateway'),
                'url'   => ZCPG_PLUGIN_URL . 'assets/checkout-logo-2.png',
            ),
            'default3' => array(
                'label' => __('Stablecoins', 'zeno-crypto-payment-gateway'),
                'url'   => ZCPG_PLUGIN_URL . 'assets/checkout-logo-3.png',
            ),
            'default4' => array(
                'label' => __('Binance Pay', 'zeno-crypto-payment-gateway'),
                'url'   => ZCPG_PLUGIN_URL . 'assets/checkout-logo-4.png',
            ),
            'none'     => array(
                'label' => __('No logo', 'zeno-crypto-payment-gateway'),
                'url'   => '',
            ),
            'custom'   => array(
                'label' => __('Custom', 'zeno-crypto-payment-gateway'),
                'url'   => '',
            ),
        );
        ?>
        <style>
            /* Logo picker */
            .zcpg-logo-picker { display:flex; flex-wrap:wrap; gap:12px; margin-top:4px; }
            .zcpg-logo-card {
                border:2px solid #ddd; border-radius:8px; padding:10px; cursor:pointer;
                text-align:center; min-width:100px; max-width:160px; background:#fff;
                transition: border-color .2s, box-shadow .2s;
            }
            .zcpg-logo-card:hover { border-color:#999; }
            .zcpg-logo-card.selected { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; }
            .zcpg-logo-card img { max-height:40px; max-width:130px; display:block; margin:0 auto 6px; }
            .zcpg-logo-card .zcpg-card-label { font-size:12px; color:#333; }
            .zcpg-logo-card .zcpg-no-logo-icon { font-size:22px; color:#999; margin-bottom:6px; display:block; }
            .zcpg-logo-card .zcpg-custom-icon { font-size:22px; color:#2271b1; margin-bottom:6px; display:block; }
            #zcpg-custom-preview { margin-top:8px; }
            #zcpg-custom-preview img { max-height:40px; }
            /* Advanced collapsible */
            .zcpg-advanced-toggle {
                display:inline-flex; align-items:center; gap:6px; cursor:pointer;
                margin:20px 0 0; padding:10px 0; font-size:14px; font-weight:600;
                color:#2271b1; user-select:none; border:none; background:none;
            }
            .zcpg-advanced-toggle:hover { color:#135e96; }
            .zcpg-advanced-toggle .dashicons {
                font-size:16px; width:16px; height:16px;
                transition: transform .2s;
            }
            .zcpg-advanced-toggle.open .dashicons { transform:rotate(90deg); }
            .zcpg-advanced-content { display:none; }
            .zcpg-advanced-content.open { display:block; }
        </style>
        <script type="text/javascript">
        (function($) {
            /* ── Advanced section: collapsible ── */
            // WooCommerce type=title generates: <h3 id="...">Title</h3><p>desc</p><table>...rows...</table>
            var $advH3 = $('#woocommerce_zcpg_gateway_advanced_title');
            if ($advH3.length) {
                // Collect only the <p> and <table> that follow the <h3>, stop before <style>/<script>.
                var $advContent = $advH3.nextUntil('style, script');

                // Hide original heading.
                $advH3.hide();

                // Build toggle button and wrapper.
                var $toggle = $(
                    '<button type="button" class="zcpg-advanced-toggle">' +
                        '<span class="dashicons dashicons-arrow-right-alt2"></span>' +
                        '<?php echo esc_js(__('Advanced settings', 'zeno-crypto-payment-gateway')); ?>' +
                    '</button>'
                );
                var $wrapper = $('<div class="zcpg-advanced-content"></div>');

                // Insert toggle + wrapper right after the hidden <h3>.
                $advH3.after($toggle);
                $toggle.after($wrapper);

                // Move all advanced content into the wrapper.
                $advContent.appendTo($wrapper);

                $toggle.on('click', function() {
                    $(this).toggleClass('open');
                    $wrapper.toggleClass('open');
                });
            }

            /* ── Logo visual picker ── */
            var $select    = $('#woocommerce_zcpg_gateway_logo_mode');
            var $selectRow = $select.closest('tr');
            var $customRow = $('#woocommerce_zcpg_gateway_custom_logo_url').closest('tr');
            var $customInput = $('#woocommerce_zcpg_gateway_custom_logo_url');

            $select.hide();

            var options = <?php echo wp_json_encode($logo_options); ?>;
            var pickerHtml = '<div class="zcpg-logo-picker">';
            $.each(options, function(key, opt) {
                var inner = '';
                if (key === 'none') {
                    inner = '<span class="zcpg-no-logo-icon">&#8709;</span>';
                } else if (key === 'custom') {
                    inner = '<span class="zcpg-custom-icon">&#9998;</span>';
                } else {
                    inner = '<img src="' + opt.url + '" alt="' + opt.label + '" />';
                }
                pickerHtml += '<div class="zcpg-logo-card" data-value="' + key + '">'
                    + inner
                    + '<span class="zcpg-card-label">' + opt.label + '</span>'
                    + '</div>';
            });
            pickerHtml += '</div>';

            pickerHtml += '<div id="zcpg-custom-preview" style="display:none;margin-top:8px;">'
                + '<img src="" alt="<?php echo esc_attr(__('Custom logo preview', 'zeno-crypto-payment-gateway')); ?>" style="max-height:40px;display:none;" />'
                + '<span style="color:#999;display:none;"><?php echo esc_js(__('Enter a URL to see the preview', 'zeno-crypto-payment-gateway')); ?></span>'
                + '</div>';

            $select.after(pickerHtml);

            var $cards = $selectRow.find('.zcpg-logo-card');
            var $previewWrap = $('#zcpg-custom-preview');
            var $previewImg  = $previewWrap.find('img');
            var $previewHint = $previewWrap.find('span');

            function syncUI() {
                var val = $select.val();
                $cards.removeClass('selected');
                $cards.filter('[data-value="' + val + '"]').addClass('selected');

                if (val === 'custom') {
                    $customRow.show();
                    $previewWrap.show();
                    var url = $.trim($customInput.val());
                    if (url) {
                        $previewImg.attr('src', url).show();
                        $previewHint.hide();
                    } else {
                        $previewImg.hide();
                        $previewHint.show();
                    }
                } else {
                    $customRow.hide();
                    $previewWrap.hide();
                }
            }

            $cards.on('click', function() {
                $select.val($(this).data('value')).trigger('change');
            });

            $select.on('change', syncUI);
            $customInput.on('input change', syncUI);

            syncUI();
        })(jQuery);
        </script>
        <?php
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
     * Get the configured order status to apply when checkout expires.
     *
     * Returns empty string when no change should be applied.
     *
     * @return string
     */
    public function get_expired_order_status(): string
    {
        $status = (string) $this->get_option('expired_order_status', '');

        if ('' === $status) {
            // Use WooCommerce default behaviour (no explicit status change).
            return '';
        }

        $available = $this->get_available_order_statuses();
        if (! isset($available[$status])) {
            return '';
        }

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
        $alternative_4  = ZCPG_PLUGIN_URL . 'assets/checkout-logo-4.png';

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
            case 'default4':
                return $alternative_4;
            case 'default':
            default:
                return $default_logo;
        }
    }

    /**
     * Get the configured status to use when payment is initiated.
     *
     * If set to "default" or invalid, falls back to "pending" to preserve
     * previous behaviour.
     *
     * @return string
     */
    public function get_initiated_order_status(): string
    {
        $setting = (string) $this->get_option('initiated_order_status', 'default');

        if ('default' === $setting || '' === $setting) {
            return 'pending';
        }

        $available = $this->get_available_order_statuses();
        if (! isset($available[$setting])) {
            return 'pending';
        }

        if (0 === strpos($setting, 'wc-')) {
            $setting = substr($setting, 3);
        }

        return $setting;
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

        $initiated_status = $this->get_initiated_order_status();

        $order->update_status(
            $initiated_status,
            esc_html__('Waiting for payment in Crypto Gateway', 'zeno-crypto-payment-gateway')
        );

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
