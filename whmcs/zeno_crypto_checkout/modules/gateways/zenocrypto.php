<?php

/**
 * WHMCS Zeno Crypto Gateway Module
 * @author     Anurag Rathore <anuragr1983@yahoo.com>
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Define module related meta data.
 */
function zenocrypto_MetaData()
{
    return array(
        'DisplayName' => 'Zeno Crypto Gateway',
        'APIVersion' => '1.1', // Use API Version 1.1
    );
}

/**
 * Ensure a secret key exists for webhook verification.
 */
function zenocrypto_ensureSecretKey()
{
    $existing = Capsule::table('tblpaymentgateways')
        ->where('gateway', 'zenocrypto')
        ->where('setting', 'secret_key')
        ->value('value');

    if (!$existing) {
        $hex = bin2hex(random_bytes(16));
        $uuid = substr($hex, 0, 8) . '-' .
            substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' .
            substr($hex, 20);

        Capsule::table('tblpaymentgateways')->insert([
            'gateway' => 'zenocrypto',
            'setting' => 'secret_key',
            'value'   => encrypt($uuid),
        ]);
    }
}

/**
 * Define gateway configuration options.
 */
function zenocrypto_config()
{
    try {
        zenocrypto_ensureSecretKey();
    } catch (\Exception $e) {
        // Silently fail during gateway discovery
    }

    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Zeno Crypto Gateway',
        ),
        'api_key' => array(
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '40',
            'Default' => '',
            'Description' => '<a href="https://dashboard.zenobank.io/" target="_blank">Get your API key here</a>',
        ),
    );
}

/**
 * Ensure the checkouts cache table exists.
 */
function zenocrypto_ensureCheckoutsTable()
{
    if (!Capsule::schema()->hasTable('mod_zenocrypto_checkouts')) {
        Capsule::schema()->create('mod_zenocrypto_checkouts', function ($table) {
            $table->unsignedInteger('invoice_id')->primary();
            $table->string('checkout_url', 512);
            $table->decimal('amount', 16, 8);
            $table->string('currency', 10);
            $table->timestamp('created_at')->useCurrent();
        });
    }
}

/**
 * Payment link.
 */
function zenocrypto_link($params)
{
    // Gateway Configuration Parameters
    $apiKey = $params['api_key'];
    if (empty($apiKey)) {
        return '<div class="alert alert-danger">Gateway misconfigured: missing API key.</div>';
    }

    $secretKey = $params['secret_key'];
    if (empty($secretKey)) {
        zenocrypto_ensureSecretKey();
        $secretKey = Capsule::table('tblpaymentgateways')
            ->where('gateway', 'zenocrypto')
            ->where('setting', 'secret_key')
            ->value('value');
    }
    if (empty($secretKey)) {
        return '<div class="alert alert-danger">Gateway misconfigured: missing secret key.</div>';
    }
    $secretKey = decrypt($secretKey);

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    $currencyCode = $params['currency'];

    // System Parameters
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    // Check for a cached checkout URL for this invoice
    try {
        zenocrypto_ensureCheckoutsTable();
        $cached = Capsule::table('mod_zenocrypto_checkouts')
            ->where('invoice_id', $invoiceId)
            ->first();

        // Reuse cached checkout if amount and currency haven't changed
        if ($cached
            && bccomp((string) $cached->amount, (string) $amount, 8) === 0
            && $cached->currency === $currencyCode
        ) {
            $url = htmlspecialchars($cached->checkout_url, ENT_QUOTES, 'UTF-8');
            $htmlOutput = '<form method="get" action="' . $url . '">';
            $htmlOutput .= '<input type="submit" value="' . htmlspecialchars($langPayNow, ENT_QUOTES, 'UTF-8') . '" />';
            $htmlOutput .= '</form>';
            return $htmlOutput;
        }
    } catch (\Exception $e) {
        // If cache lookup fails, continue to create a new checkout
    }

    $token = hash_hmac(
        'sha256',
        $invoiceId,
        $secretKey
    );

    // Create a new checkout
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.zenobank.io/api/v1/checkouts",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode([
            'orderId' => $invoiceId,
            'priceAmount' => $amount,
            'priceCurrency' => $currencyCode,
            'webhookUrl' => rtrim($systemUrl, '/') . '/modules/gateways/callback/' . $moduleName . '.php',
            'verificationToken' => $token,
            'successRedirectUrl' => $returnUrl
        ]),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-API-Key: $apiKey",
            // Client metadata headers
            "X-Client-Type: plugin",
            "X-Client-Name: zeno-whmcs",
            "X-Client-Version: 1.0.0",          // e.g. 1.0.0
            "X-Client-Platform: whmcs",
            "X-Client-Platform-Version: " . $whmcsVersion   // e.g. 8.8.0
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($err) {
        return '<div class="alert alert-danger">cURL Error: ' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        logTransaction('zenocrypto', $response, 'API error HTTP ' . $httpCode);
        return '<div class="alert alert-danger">Payment gateway returned an error (HTTP ' . $httpCode . '). Please try again.</div>';
    }

    $result = json_decode($response);
    if (!is_object($result) || empty($result->checkoutUrl)) {
        return '<div class="alert alert-danger">Could not create checkout. Please try again.</div>';
    }

    // Cache the checkout URL for this invoice
    try {
        Capsule::table('mod_zenocrypto_checkouts')->updateOrInsert(
            ['invoice_id' => $invoiceId],
            [
                'checkout_url' => $result->checkoutUrl,
                'amount' => $amount,
                'currency' => $currencyCode,
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );
    } catch (\Exception $e) {
        // Cache write failure is non-fatal
    }

    $url = htmlspecialchars($result->checkoutUrl, ENT_QUOTES, 'UTF-8');
    $htmlOutput = '<form method="get" action="' . $url . '">';
    $htmlOutput .= '<input type="submit" value="' . htmlspecialchars($langPayNow, ENT_QUOTES, 'UTF-8') . '" />';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}
