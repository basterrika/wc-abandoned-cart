<?php

defined('ABSPATH') || exit;

function wc_ac_is_enabled(): bool {
    static $enabled = null;

    if ($enabled === null) {
        $enabled = get_option(WC_AC_OPTION_ENABLED, 'yes') === 'yes';
    }

    return $enabled;
}

function wc_ac_get_recovery_email_delay_minutes(): int {
    static $minutes = null;

    if ($minutes === null) {
        $minutes = absint(get_option(WC_AC_OPTION_EMAIL_DELAY, 60));
    }

    return max(1, min($minutes, 10080));
}

function wc_ac_now(): string {
    return current_time('mysql', true);
}
