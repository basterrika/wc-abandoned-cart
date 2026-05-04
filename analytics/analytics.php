<?php

defined('ABSPATH') || exit;

add_action('admin_menu', 'wc_ac_register_analytics_page', 80);
add_action('admin_enqueue_scripts', 'wc_ac_enqueue_analytics_assets');

/**
 * Determine whether the current screen is the Cart Recovery analytics page.
 */
function wc_ac_is_analytics_page(): bool {
    static $is_analytics_page = null;

    if ($is_analytics_page !== null) {
        return $is_analytics_page;
    }

    $is_analytics_page = isset($_GET['page']) && wc_clean(wp_unslash($_GET['page'])) === 'wc-ac-cart-recovery';

    return $is_analytics_page;
}

/**
 * Return the parent slug WooCommerce uses for the Analytics menu.
 */
function wc_ac_get_analytics_parent_slug(): string {
    return 'wc-admin&path=/analytics/overview';
}

/**
 * Register the Cart Recovery screen under WooCommerce > Analytics.
 */
function wc_ac_register_analytics_page(): void {
    if (!current_user_can('view_woocommerce_reports')) {
        return;
    }

    $hook_suffix = add_submenu_page(
        wc_ac_get_analytics_parent_slug(),
        __('Cart Recovery', 'wc-abandoned-cart'),
        __('Cart Recovery', 'wc-abandoned-cart'),
        'view_woocommerce_reports',
        'wc-ac-cart-recovery',
        static function() {
            require_once WC_AC_PATH . 'analytics/queries.php';
            require_once WC_AC_PATH . 'analytics/page.php';
            wc_ac_render_analytics_page();
        }
    );

    if (!$hook_suffix || !function_exists('wc_admin_connect_page')) {
        return;
    }

    wc_admin_connect_page([
        'id' => 'wc-ac-cart-recovery',
        'parent' => 'woocommerce-analytics',
        'screen_id' => $hook_suffix,
        'title' => __('Cart Recovery', 'wc-abandoned-cart'),
        'path' => 'admin.php?page=wc-ac-cart-recovery',
        'capability' => 'view_woocommerce_reports',
    ]);
}

/**
 * Enqueue page-specific analytics assets.
 */
function wc_ac_enqueue_analytics_assets(): void {
    if (!wc_ac_is_analytics_page()) {
        return;
    }

    wp_enqueue_style(
        'wc-ac-analytics',
        plugins_url('analytics/style.css', WC_AC_FILE),
        ['wc-admin-app'],
        WC_AC_VERSION
    );

    wp_enqueue_script(
        'wc-ac-date-range',
        plugins_url('analytics/date-range.js', WC_AC_FILE),
        [],
        WC_AC_VERSION,
        true
    );
}
