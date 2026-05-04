<?php

defined('ABSPATH') || exit;

const WC_AC_SETTINGS_TAB = 'abandoned_cart';
const WC_AC_ACTION_GROUP = 'wc-abandoned-cart';
const WC_AC_SEND_EMAIL_HOOK = 'wc_abandoned_cart_send_recovery_email';
const WC_AC_OPTION_ENABLED = 'wc_ac_enabled';
const WC_AC_OPTION_EMAIL_DELAY = 'wc_ac_recovery_email_delay_minutes';
const WC_AC_META_ABANDONED_AT = '_wc_ac_abandoned_at';
const WC_AC_META_EMAIL_SENT_AT = '_wc_ac_email_sent_at';
const WC_AC_META_SEND_ATTEMPTS = '_wc_ac_send_attempts';
const WC_AC_META_TOKEN_HASH = '_wc_ac_recovery_token_hash';
const WC_AC_META_REOPENED_AT = '_wc_ac_reopened_at';
const WC_AC_META_RECOVERED_AT = '_wc_ac_recovered_at';
const WC_AC_META_RECOVERED_ORDER = '_wc_ac_recovered_order_id';
const WC_AC_META_RECOVERED_FROM = '_wc_ac_recovered_from_order_id';
const WC_AC_META_CART_SNAPSHOT = '_wc_ac_cart_snapshot';
const WC_AC_RECOVERY_TOKEN_TTL_DAYS = 7;
const WC_AC_RECOVERY_ATTRIBUTION_TTL_MINUTES = 60;

require_once WC_AC_PATH . 'core/deactivate.php';

add_action('plugins_loaded', static function() {
    if (!class_exists('WooCommerce')) {
        return;
    }

    require_once WC_AC_PATH . 'settings/settings.php';
    require_once WC_AC_PATH . 'core/abandonment.php';
    require_once WC_AC_PATH . 'core/recovery.php';

    add_filter('woocommerce_email_classes', 'wc_ac_register_email_class');

    if (is_admin() && !wp_doing_ajax()) {
        require_once WC_AC_PATH . 'settings/settings-admin.php';
        require_once WC_AC_PATH . 'analytics/analytics.php';
    }
}, 20);

function wc_ac_register_email_class($emails) {
    require_once WC_AC_PATH . 'email/class-email.php';

    $emails['wc_ac_abandoned_cart'] = new WC_AC_Email_Abandoned_Cart();

    return $emails;
}
