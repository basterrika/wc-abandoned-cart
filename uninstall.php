<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('wc_abandoned_cart_send_recovery_email', null, 'wc-abandoned-cart');
}

delete_option('wc_ac_enabled');
delete_option('wc_ac_recovery_email_delay_minutes');
