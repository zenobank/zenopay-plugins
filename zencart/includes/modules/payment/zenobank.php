<?php
/**
 * ZenoBank Crypto Payment Gateway for Zen Cart
 *
 * Allows merchants to accept cryptocurrency payments via https://zenobank.io/
 *
 * @copyright Copyright 2024-2025 ZenoBank
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version 1.0.0
 */

define('ZENOBANK_API_ENDPOINT', 'https://api.zenobank.io');
define('ZENOBANK_PLUGIN_VERSION', '1.0.0');
define('ZENOBANK_CLIENT_NAME', 'zeno-zencart');

class zenobank
{
    /** @var int used to check if module is installed */
    protected $_check;

    /** @var string internal module code */
    public $code;

    /** @var string payment method title shown at checkout */
    public $title;

    /** @var string payment method description shown at checkout */
    public $description;

    /** @var bool whether module is enabled */
    public $enabled;

    /** @var int order status set on checkout */
    public $order_status;

    /** @var int display sort order */
    public $sort_order;

    /** @var string error message storage */
    protected $_error;

    // ── Logging ──────────────────────────────────────────────────────────────

    /**
     * Write a message to logs/zenobank.log when Debug Log is enabled.
     *
     * @param string $message
     */
    protected function log(string $message): void
    {
        if (!defined('MODULE_PAYMENT_ZENOBANK_DEBUG') || MODULE_PAYMENT_ZENOBANK_DEBUG !== 'True') {
            return;
        }
        $log_file = (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : '') . 'logs/zenobank.log';
        $line = '[' . date('Y-m-d H:i:s') . '] [ZenoBank] ' . $message . PHP_EOL;
        file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
    }

    public function __construct()
    {
        global $order;

        $this->code = 'zenobank';

        $this->title = defined('MODULE_PAYMENT_ZENOBANK_TITLE')
            ? MODULE_PAYMENT_ZENOBANK_TITLE
            : 'Crypto Payment';

        $this->description = defined('MODULE_PAYMENT_ZENOBANK_DESCRIPTION')
            ? MODULE_PAYMENT_ZENOBANK_DESCRIPTION
            : 'Pay with cryptocurrency via ZenoBank';

        $this->sort_order = defined('MODULE_PAYMENT_ZENOBANK_SORT_ORDER')
            ? MODULE_PAYMENT_ZENOBANK_SORT_ORDER
            : null;

        $this->enabled = defined('MODULE_PAYMENT_ZENOBANK_STATUS')
            && MODULE_PAYMENT_ZENOBANK_STATUS === 'True';

        if (null === $this->sort_order) {
            return false;
        }

        // Disable if API key is not configured
        if ($this->enabled
            && (!defined('MODULE_PAYMENT_ZENOBANK_API_KEY')
                || trim(MODULE_PAYMENT_ZENOBANK_API_KEY) === '')
        ) {
            $this->enabled = false;
            if (IS_ADMIN_FLAG === true) {
                $this->title .= '<span class="alert"> (not configured — API Key required)</span>';
            }
        }

        if (IS_ADMIN_FLAG === true && $this->enabled) {
            $this->title .= ' [ZenoBank]';
        }

        if (defined('MODULE_PAYMENT_ZENOBANK_ORDER_STATUS_ID')
            && (int)MODULE_PAYMENT_ZENOBANK_ORDER_STATUS_ID > 0
        ) {
            $this->order_status = (int)MODULE_PAYMENT_ZENOBANK_ORDER_STATUS_ID;
        }

        if (is_object($order)) {
            $this->update_status();
        }
    }

    /**
     * Zone-based availability check
     */
    public function update_status()
    {
        global $order, $db;

        if ($this->enabled
            && defined('MODULE_PAYMENT_ZENOBANK_ZONE')
            && (int)MODULE_PAYMENT_ZENOBANK_ZONE > 0
            && isset($order->billing['country']['id'])
        ) {
            $check_flag = false;
            $check = $db->Execute(
                "SELECT zone_id FROM " . TABLE_ZONES_TO_GEO_ZONES
                . " WHERE geo_zone_id = '" . (int)MODULE_PAYMENT_ZENOBANK_ZONE . "'"
                . " AND zone_country_id = '" . (int)$order->billing['country']['id'] . "'"
                . " ORDER BY zone_id"
            );

            while (!$check->EOF) {
                if ($check->fields['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check->fields['zone_id'] == $order->billing['zone_id']) {
                    $check_flag = true;
                    break;
                }
                $check->MoveNext();
            }

            if (!$check_flag) {
                $this->enabled = false;
            }
        }
    }

    public function javascript_validation()
    {
        return false;
    }

    public function selection()
    {
        return [
            'id'     => $this->code,
            'module' => $this->title,
        ];
    }

    public function pre_confirmation_check()
    {
        return false;
    }

    public function confirmation()
    {
        return ['title' => $this->description];
    }

    public function process_button()
    {
        return false;
    }

    /**
     * Called before order is saved to DB.
     * Nothing to do here — we need the order ID which is only available in after_order_create().
     */
    public function before_process()
    {
        return false;
    }

    /**
     * Called right after the order record is created in DB.
     * This is where we call the ZenoBank API since we now have the real order ID.
     *
     * @param int $order_id  The newly created order ID
     */
    public function after_order_create($order_id)
    {
        global $order;

        $this->log("after_order_create() called for order_id={$order_id}");

        $api_key = defined('MODULE_PAYMENT_ZENOBANK_API_KEY')
            ? trim(MODULE_PAYMENT_ZENOBANK_API_KEY)
            : '';

        $secret = defined('MODULE_PAYMENT_ZENOBANK_SECRET')
            ? MODULE_PAYMENT_ZENOBANK_SECRET
            : '';

        if ($api_key === '' || $secret === '') {
            $this->log("ERROR: API Key or Secret is not configured");
            $_SESSION['zenobank_error'] = MODULE_PAYMENT_ZENOBANK_TEXT_ERROR_COMMUNICATION;
            return;
        }

        $order_id_str       = (string)(int)$order_id;
        $verification_token = hash_hmac('sha256', $order_id_str, $secret);

        // Determine site domain for headers
        $site_domain = '';
        if (defined('HTTP_SERVER')) {
            $site_domain = (string)parse_url(HTTP_SERVER, PHP_URL_HOST);
        }
        if ($site_domain === '' && isset($_SERVER['HTTP_HOST'])) {
            $site_domain = $_SERVER['HTTP_HOST'];
        }

        // Build URLs
        $success_url = zen_href_link(FILENAME_CHECKOUT_SUCCESS, '', 'SSL');
        $webhook_url = (defined('HTTPS_SERVER') ? HTTPS_SERVER : HTTP_SERVER)
            . (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/')
            . 'zenobank_webhook.php';

        // Order total and currency
        $price_amount   = number_format((float)$order->info['total'], 2, '.', '');
        $price_currency = strtoupper($order->info['currency']);

        // ZC version string
        $zc_version = defined('PROJECT_VERSION_MAJOR') && defined('PROJECT_VERSION_MINOR')
            ? PROJECT_VERSION_MAJOR . '.' . PROJECT_VERSION_MINOR
            : '2.1.0';

        $payload = json_encode([
            'priceAmount'        => $price_amount,
            'priceCurrency'      => $price_currency,
            'orderId'            => $order_id_str,
            'successRedirectUrl' => $success_url,
            'webhookUrl'         => $webhook_url,
            'verificationToken'  => $verification_token,
        ]);

        $this->log("API request → POST /api/v1/checkouts | amount={$price_amount} {$price_currency} | webhookUrl={$webhook_url}");

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-api-key: ' . $api_key,
            'X-Client-Type: plugin',
            'X-Client-Domain: ' . $site_domain,
            'X-Client-Name: ' . ZENOBANK_CLIENT_NAME,
            'X-Client-Version: ' . ZENOBANK_PLUGIN_VERSION,
            'X-Client-Platform: ZenCart',
            'X-Client-Platform-Version: ' . $zc_version,
        ];

        $ch = curl_init(ZENOBANK_API_ENDPOINT . '/api/v1/checkouts');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response_body = curl_exec($ch);
        $http_code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error    = curl_error($ch);
        curl_close($ch);

        if ($curl_error !== '' || ($http_code !== 200 && $http_code !== 201)) {
            $this->log("ERROR: API response http={$http_code} curl_error={$curl_error} body={$response_body}");
            error_log('ZenoBank: API error http=' . $http_code . ' curl=' . $curl_error . ' body=' . $response_body);
            $_SESSION['zenobank_error'] = MODULE_PAYMENT_ZENOBANK_TEXT_ERROR_COMMUNICATION;
            return;
        }

        $data = json_decode($response_body, true);
        if (empty($data['checkoutUrl'])) {
            $this->log("ERROR: No checkoutUrl in response: {$response_body}");
            error_log('ZenoBank: no checkoutUrl in response: ' . $response_body);
            $_SESSION['zenobank_error'] = MODULE_PAYMENT_ZENOBANK_TEXT_ERROR_COMMUNICATION;
            return;
        }

        $this->log("API response OK http={$http_code} | checkoutUrl={$data['checkoutUrl']}");
        $_SESSION['zenobank_checkout_url'] = $data['checkoutUrl'];
    }

    /**
     * Called after order is fully saved. Redirect to ZenoBank payment page.
     */
    public function after_process()
    {
        global $messageStack;

        if (!empty($_SESSION['zenobank_error'])) {
            $error_msg = $_SESSION['zenobank_error'];
            unset($_SESSION['zenobank_error']);
            $this->log("after_process(): API error occurred, staying on checkout_success");
            $messageStack->add_session('checkout_success', $error_msg, 'error');
            return false;
        }

        if (!empty($_SESSION['zenobank_checkout_url'])) {
            $url = $_SESSION['zenobank_checkout_url'];
            unset($_SESSION['zenobank_checkout_url']);
            $this->log("after_process(): redirecting customer to ZenoBank → {$url}");
            zen_redirect($url);
        }

        return false;
    }

    public function get_error()
    {
        if (isset($this->_error)) {
            return [
                'title' => MODULE_PAYMENT_ZENOBANK_TEXT_ERROR,
                'error' => $this->_error,
            ];
        }
        return false;
    }

    public function check()
    {
        global $db;
        if (!isset($this->_check)) {
            $check_query = $db->Execute(
                "SELECT configuration_value FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = 'MODULE_PAYMENT_ZENOBANK_STATUS'"
            );
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }

    public function install()
    {
        global $db, $messageStack;

        if (defined('MODULE_PAYMENT_ZENOBANK_STATUS')) {
            $messageStack->add_session('ZenoBank module already installed.', 'error');
            zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=zenobank', 'NONSSL'));
            return 'failed';
        }

        // Generate a cryptographically random secret key (64 hex chars)
        $secret = bin2hex(random_bytes(32));

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
            VALUES
            ('Enable ZenoBank Crypto',
             'MODULE_PAYMENT_ZENOBANK_STATUS',
             'True',
             'Accept cryptocurrency payments via ZenoBank?',
             '6', '1', 'zen_cfg_select_option(array(\'True\', \'False\'), ', NOW())"
        );

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
            VALUES
            ('Payment Method Title',
             'MODULE_PAYMENT_ZENOBANK_TITLE',
             'Crypto Payment',
             'Title shown to customers at checkout',
             '6', '2', NOW())"
        );

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
            VALUES
            ('Payment Method Description',
             'MODULE_PAYMENT_ZENOBANK_DESCRIPTION',
             'Pay securely with cryptocurrency (USDC, USDT, and more) via ZenoBank',
             'Description shown on the order confirmation page',
             '6', '3', NOW())"
        );

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
            VALUES
            ('ZenoBank API Key',
             'MODULE_PAYMENT_ZENOBANK_API_KEY',
             '',
             'Your API Key from dashboard.zenobank.io → Integrations',
             '6', '4', NOW())"
        );

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
            VALUES
            ('Sort Order',
             'MODULE_PAYMENT_ZENOBANK_SORT_ORDER',
             '0',
             'Sort order of display. Lowest is displayed first.',
             '6', '5', NOW())"
        );

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added)
            VALUES
            ('Payment Zone',
             'MODULE_PAYMENT_ZENOBANK_ZONE',
             '0',
             'If a zone is selected, only enable this payment method for that zone.',
             '6', '6', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', NOW())"
        );

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
            VALUES
            ('Pending Payment Order Status',
             'MODULE_PAYMENT_ZENOBANK_ORDER_STATUS_ID',
             '1',
             'Order status while waiting for crypto payment',
             '6', '7', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', NOW())"
        );

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
            VALUES
            ('Completed Payment Order Status',
             'MODULE_PAYMENT_ZENOBANK_COMPLETED_STATUS_ID',
             '2',
             'Order status after successful payment via ZenoBank',
             '6', '8', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', NOW())"
        );

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
            VALUES
            ('Expired Payment Order Status',
             'MODULE_PAYMENT_ZENOBANK_EXPIRED_STATUS_ID',
             '0',
             'Order status when payment expires (0 = do not change status)',
             '6', '9', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', NOW())"
        );

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
            VALUES
            ('Debug Log',
             'MODULE_PAYMENT_ZENOBANK_DEBUG',
             'False',
             'Write detailed payment steps to logs/zenobank.log. Disable in production.',
             '6', '10', 'zen_cfg_select_option(array(\'True\', \'False\'), ', NOW())"
        );

        // Secret key — stored internally, NOT shown in admin keys() list, NOT editable
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
            (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
            VALUES
            ('ZenoBank Internal Secret',
             'MODULE_PAYMENT_ZENOBANK_SECRET',
             '" . zen_db_input($secret) . "',
             'Auto-generated internal secret. Do not edit manually.',
             '6', '99', NOW())"
        );
    }

    public function remove()
    {
        global $db;
        $all_keys = array_merge($this->keys(), ['MODULE_PAYMENT_ZENOBANK_SECRET']);
        $db->Execute(
            "DELETE FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key IN ('" . implode("', '", $all_keys) . "')"
        );
    }

    /**
     * These keys appear in the Admin panel as editable fields.
     * MODULE_PAYMENT_ZENOBANK_SECRET is intentionally excluded.
     */
    public function keys()
    {
        return [
            'MODULE_PAYMENT_ZENOBANK_STATUS',
            'MODULE_PAYMENT_ZENOBANK_TITLE',
            'MODULE_PAYMENT_ZENOBANK_DESCRIPTION',
            'MODULE_PAYMENT_ZENOBANK_API_KEY',
            'MODULE_PAYMENT_ZENOBANK_SORT_ORDER',
            'MODULE_PAYMENT_ZENOBANK_ZONE',
            'MODULE_PAYMENT_ZENOBANK_ORDER_STATUS_ID',
            'MODULE_PAYMENT_ZENOBANK_COMPLETED_STATUS_ID',
            'MODULE_PAYMENT_ZENOBANK_EXPIRED_STATUS_ID',
            'MODULE_PAYMENT_ZENOBANK_DEBUG',
        ];
    }
}
