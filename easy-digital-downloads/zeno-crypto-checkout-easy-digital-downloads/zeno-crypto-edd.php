<?php

/**
 * Plugin Name: Zeno Crypto Checkout for Easy Digital Downloads
 * Description: Accept Crypto Payments in USDT and USDC across Ethereum, BNB Chain, Arbitrum, Base, Polygon, Solana, and Binance Pay
 * Version: 1.0.0
 * Author: Zeno Bank
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: zeno-crypto-checkout-for-easy-digital-downloads
 * Requires Plugins: easy-digital-downloads
 */

defined('ABSPATH') || exit;

define('ZCEDD_VERSION', '1.0.0');
define('ZCEDD_API_ENDPOINT', 'https://api.zenobank.io');
define('ZCEDD_GATEWAY_ID', 'zcpg');
define('ZCEDD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZCEDD_PLUGIN_URL', plugin_dir_url(__FILE__));

add_action(
	'plugins_loaded',
	function () {
		if (! class_exists('Easy_Digital_Downloads')) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e('Zeno Crypto Checkout requires Easy Digital Downloads to be installed and active.', 'zeno-crypto-checkout-for-easy-digital-downloads');
					echo '</p></div>';
				}
			);
			return;
		}

		require_once __DIR__ . '/includes/zc-edd-gateway.php';
		require_once __DIR__ . '/includes/zc-edd-webhook.php';

		zc_edd_register_webhook_route();
	},
	11
);

/**
 * Generate secrets on activation (store in EDD settings option).
 */
register_activation_hook(
	__FILE__,
	function () {
		$option_key = 'edd_settings';
		$settings   = get_option($option_key, array());

		if (empty($settings['zc_edd_secret_live'])) {
			$settings['zc_edd_secret_live'] = wp_generate_password(32, false, false);
		}

		update_option($option_key, $settings);
	}
);

/**
 * Plugin action links (Settings).
 */
add_filter(
	'plugin_action_links_' . plugin_basename(__FILE__),
	function ($links) {
		$settings_url  = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=zcpg');
		$settings_link = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'zeno-crypto-checkout-for-easy-digital-downloads') . '</a>';
		array_unshift($links, $settings_link);
		return $links;
	}
);
