<?php

defined('ABSPATH') || exit;

add_filter('woocommerce_settings_tabs_array', 'wc_ac_register_settings_tab', 50);
function wc_ac_register_settings_tab($tabs) {
    $tabs[WC_AC_SETTINGS_TAB] = __('Abandoned Cart', 'wc-abandoned-cart');

    return $tabs;
}

add_action('woocommerce_settings_tabs_' . WC_AC_SETTINGS_TAB, 'wc_ac_render_settings_tab');
function wc_ac_render_settings_tab(): void {
    WC_Admin_Settings::output_fields(wc_ac_get_settings_fields());
}

add_action('woocommerce_update_options_' . WC_AC_SETTINGS_TAB, 'wc_ac_save_settings_tab');
function wc_ac_save_settings_tab(): void {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }

    if (isset($_POST[WC_AC_OPTION_EMAIL_DELAY])) {
        $_POST[WC_AC_OPTION_EMAIL_DELAY] = max(1, min(absint(wp_unslash($_POST[WC_AC_OPTION_EMAIL_DELAY])), 10080));
    }

    WC_Admin_Settings::save_fields(wc_ac_get_settings_fields());
}

function wc_ac_get_settings_fields(): array {
    return [
        [
            'title' => __('Abandoned cart recovery', 'wc-abandoned-cart'),
            'type' => 'title',
            'desc' => __('Send a recovery email when WooCommerce auto-cancels an unpaid order. Configure the email itself in WooCommerce > Settings > Emails. The auto-cancel timer is set in WooCommerce > Settings > Products > Inventory (Hold stock minutes).', 'wc-abandoned-cart'),
            'id' => 'wc_ac_settings_section',
        ],
        [
            'title' => __('Enable abandoned cart recovery', 'wc-abandoned-cart'),
            'id' => WC_AC_OPTION_ENABLED,
            'type' => 'checkbox',
            'default' => 'yes',
            'autoload' => true,
            'desc' => __('Send a recovery email when an unpaid order is auto-cancelled', 'wc-abandoned-cart'),
        ],
        [
            'title' => __('Recovery email delay (minutes)', 'wc-abandoned-cart'),
            'id' => WC_AC_OPTION_EMAIL_DELAY,
            'type' => 'number',
            'default' => '60',
            'autoload' => true,
            'custom_attributes' => [
                'min' => 1,
                'max' => 10080,
                'step' => 1,
            ],
            'desc' => __('How long to wait after the order is cancelled before the recovery email is sent.', 'wc-abandoned-cart'),
        ],
        [
            'type' => 'sectionend',
            'id' => 'wc_ac_settings_section',
        ],
    ];
}
