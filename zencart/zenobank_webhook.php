<?php
/**
 * ZenoBank Crypto Payment Gateway — Webhook Handler
 *
 * This file receives POST notifications from ZenoBank when a payment status changes.
 * Webhook URL: https://yourdomain.com/zenobank_webhook.php
 *
 * @copyright Copyright 2024-2025 ZenoBank
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version 1.0.0
 */

// Bootstrap Zen Cart catalog environment (defines $db, table constants, helper functions)
define('IS_ADMIN_FLAG', false);
require 'includes/application_top.php';

// ── Logging helper ────────────────────────────────────────────────────────────

function zenobank_log(string $message): void
{
    $debug = defined('MODULE_PAYMENT_ZENOBANK_DEBUG') && MODULE_PAYMENT_ZENOBANK_DEBUG === 'True';
    if (!$debug) {
        return;
    }
    $log_file = (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : '') . 'logs/zenobank.log';
    $line = '[' . date('Y-m-d H:i:s') . '] [ZenoBank Webhook] ' . $message . PHP_EOL;
    file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
}

// ── 1. Read raw POST payload ──────────────────────────────────────────────────

$raw_body = file_get_contents('php://input');

zenobank_log("Webhook received | IP=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . " | body=" . $raw_body);

if (empty($raw_body)) {
    zenobank_log("ERROR: Empty request body → 400");
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

$payload = json_decode($raw_body, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    zenobank_log("ERROR: Invalid JSON → 400");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// ── 2. Extract required fields ────────────────────────────────────────────────

$checkout_data    = $payload['data'] ?? [];
$order_id_raw     = $checkout_data['orderId'] ?? '';
$received_token   = (string)($checkout_data['verificationToken'] ?? '');
$status           = strtoupper((string)($checkout_data['status'] ?? ''));

zenobank_log("Parsed fields | orderId={$order_id_raw} | status={$status} | token=" . substr($received_token, 0, 8) . '...');

if ($order_id_raw === '' || $received_token === '' || $status === '') {
    zenobank_log("ERROR: Missing required fields (orderId/verificationToken/status) → 400");
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$order_id = (string)(int)$order_id_raw; // sanitise to integer string

// ── 3. Load internal secret ───────────────────────────────────────────────────

$secret = '';
if (defined('MODULE_PAYMENT_ZENOBANK_SECRET')) {
    $secret = MODULE_PAYMENT_ZENOBANK_SECRET;
} else {
    // Fallback: read directly from DB if constant not loaded
    $secret_row = $db->Execute(
        "SELECT configuration_value FROM " . TABLE_CONFIGURATION
        . " WHERE configuration_key = 'MODULE_PAYMENT_ZENOBANK_SECRET' LIMIT 1"
    );
    if (!$secret_row->EOF) {
        $secret = $secret_row->fields['configuration_value'];
    }
}

if ($secret === '') {
    zenobank_log("ERROR: Secret not configured → 500");
    error_log('ZenoBank webhook: secret not configured');
    http_response_code(500);
    echo json_encode(['error' => 'Internal configuration error']);
    exit;
}

// ── 4. Verify token (HMAC-SHA256) ─────────────────────────────────────────────

$expected_token = hash_hmac('sha256', $order_id, $secret);
if (!hash_equals($expected_token, $received_token)) {
    zenobank_log("ERROR: Token verification FAILED for order_id={$order_id} → 403");
    http_response_code(403);
    echo json_encode(['error' => 'Invalid verification token']);
    exit;
}

zenobank_log("Token verification OK for order_id={$order_id}");

// ── 5. Only react to COMPLETED and EXPIRED ────────────────────────────────────

if (!in_array($status, ['COMPLETED', 'EXPIRED'], true)) {
    zenobank_log("Status '{$status}' is not handled → ignored");
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => 'unhandled_status']);
    exit;
}

// ── 6. Fetch current order from DB ────────────────────────────────────────────

$order_row = $db->Execute(
    "SELECT orders_id, orders_status FROM " . TABLE_ORDERS
    . " WHERE orders_id = '" . (int)$order_id . "' LIMIT 1"
);

if ($order_row->EOF) {
    zenobank_log("ERROR: Order #{$order_id} not found in DB → 404");
    http_response_code(404);
    echo json_encode(['error' => 'Order not found']);
    exit;
}

$current_status = (int)$order_row->fields['orders_status'];
zenobank_log("Order #{$order_id} found | current_status={$current_status}");

// Target status IDs from module configuration
$completed_status_id = defined('MODULE_PAYMENT_ZENOBANK_COMPLETED_STATUS_ID')
    ? (int)MODULE_PAYMENT_ZENOBANK_COMPLETED_STATUS_ID
    : 2; // default: Processing

$expired_status_id = defined('MODULE_PAYMENT_ZENOBANK_EXPIRED_STATUS_ID')
    ? (int)MODULE_PAYMENT_ZENOBANK_EXPIRED_STATUS_ID
    : 0;

// ── 7. Idempotency: never downgrade a completed order ─────────────────────────

if ($current_status === $completed_status_id) {
    zenobank_log("Order #{$order_id} already completed (status={$current_status}) → skipping");
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'note' => 'already_completed']);
    exit;
}

// ── 8. Apply state transition ─────────────────────────────────────────────────

if ($status === 'COMPLETED') {

    zenobank_log("Updating order #{$order_id} status: {$current_status} → {$completed_status_id} (COMPLETED)");

    $db->Execute(
        "UPDATE " . TABLE_ORDERS
        . " SET orders_status = '" . $completed_status_id . "', last_modified = NOW()"
        . " WHERE orders_id = '" . (int)$order_id . "'"
    );

    zen_update_orders_history(
        (int)$order_id,
        'ZenoBank: payment completed',
        'ZenoBank',
        $completed_status_id,
        0   // not notified by email; set to 1 if you want customer email notification
    );

    zenobank_log("Order #{$order_id} marked as COMPLETED ✓");

} elseif ($status === 'EXPIRED') {

    // If expired status is not configured (0), ignore silently
    if ($expired_status_id <= 0) {
        zenobank_log("EXPIRED received but expired_status_id=0 → ignored");
        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'reason' => 'expired_status_not_configured']);
        exit;
    }

    zenobank_log("Updating order #{$order_id} status: {$current_status} → {$expired_status_id} (EXPIRED)");

    $db->Execute(
        "UPDATE " . TABLE_ORDERS
        . " SET orders_status = '" . $expired_status_id . "', last_modified = NOW()"
        . " WHERE orders_id = '" . (int)$order_id . "'"
    );

    zen_update_orders_history(
        (int)$order_id,
        'ZenoBank: payment expired',
        'ZenoBank',
        $expired_status_id,
        0
    );

    zenobank_log("Order #{$order_id} marked as EXPIRED ✓");
}

// ── 9. Success response ───────────────────────────────────────────────────────

zenobank_log("Webhook processed successfully → 200");
http_response_code(200);
echo json_encode(['status' => 'ok']);
exit;
