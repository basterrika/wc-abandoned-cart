<?php

defined('ABSPATH') || exit;

/**
 * On deactivation, clear any recovery emails still sitting in the Action Scheduler
 * queue so they don't fire after the plugin's code is gone.
 */
register_deactivation_hook(WC_AC_FILE, static function() {
    if (!function_exists('as_unschedule_all_actions')) {
        return;
    }

    as_unschedule_all_actions(WC_AC_SEND_EMAIL_HOOK, null, WC_AC_ACTION_GROUP);
});
