<?php
/**
 * Plugin Name: Abandoned Cart for WooCommerce
 * Description: Recover abandoned carts by re-engaging customers whose unpaid orders were auto-cancelled.
 * Version: 1.0.0
 * Author: Mikel
 * Author URI: https://basterrika.com
 * Update URI: https://github.com/basterrika/wc-abandoned-cart
 * Text Domain: wc-abandoned-cart
 * Domain Path: /translations
 * Requires PHP: 8.4
 * Requires at least: 6.5
 * Tested up to: 6.8
 * Requires Plugins: woocommerce
 * WC requires at least: 10.6
 * WC tested up to: 10.7
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined('ABSPATH') || exit;

const WC_AC_VERSION = '1.0.0';
const WC_AC_FILE = __FILE__;

define('WC_AC_PATH', plugin_dir_path(__FILE__));

add_action('before_woocommerce_init', static function() {
    if (!class_exists(FeaturesUtil::class)) {
        return;
    }

    FeaturesUtil::declare_compatibility('custom_order_tables', WC_AC_FILE);
    FeaturesUtil::declare_compatibility('cart_checkout_blocks', WC_AC_FILE);
});

require_once WC_AC_PATH . 'core/bootstrap.php';
