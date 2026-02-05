<?php
if (!defined('ABSPATH')) exit;

class ZCPG_Webhook
{

    public function register()
    {
        add_action('rest_api_init', function () {
            register_rest_route('zcpg/v1', '/webhook', [
                'methods'  => 'POST',
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle(WP_REST_Request $request)
    {
        $body      = $request->get_json_params() ?: [];
        $order_id  = intval($body['data']['orderId'] ?? $request->get_param('order_id') ?? 0);
        $checkout_id = sanitize_text_field($body['data']['checkoutId'] ?? $request->get_param('checkout_id') ?? '');
        $received_token = sanitize_text_field($body['data']['verificationToken'] ?? $request->get_param('verification_token') ?? '');
        $status    = sanitize_text_field($body['data']['status'] ?? $request->get_param('status') ?? '');


        $gateways = WC()->payment_gateways()->payment_gateways();
        $gw = $gateways['zcpg_gateway'] ?? null;

        if (! $gw instanceof ZCPG_Gateway) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Gateway not available'], 500);
        }

        $expected_token = $gw->generate_verification_token($order_id);

        // Validate token: if missing or invalid, exit early without doing anything
        if (! $order_id || ! $received_token || ! hash_equals($expected_token, $received_token)) {
            // Optional: you may log invalid attempts here
            // return new WP_REST_Response(['ok' => true], 200);
            return new WP_REST_Response(['ok' => false], 403);
        }

        // Token is valid: process the order status update
        if ($order_id && ($order = wc_get_order($order_id))) {
            if ($status === 'COMPLETED') {
                $success_status = $gw->get_success_order_status();

                if ('completed' === $success_status) {
                    $order->payment_complete($order_id);
                    $order->set_status('completed');
                    $order->save();
                } else {
                    $order->update_status(
                        $success_status,
                        __('Payment confirmed via webhook.', 'zeno-crypto-payment-gateway')
                    );
                }

                $order->add_order_note(__('Payment confirmed via webhook.', 'zeno-crypto-payment-gateway'));
            } else {
                $order->update_status('failed', __('Payment failed via webhook.', 'zeno-crypto-payment-gateway'));
            }
        }

        return [
            'ok' => true,
            'order_id' => $order_id,
            'status' => $status,
            'received_token' => $received_token,
            'order' => $order,
        ];
    }
}
