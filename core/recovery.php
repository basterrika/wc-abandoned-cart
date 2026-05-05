<?php

use Automattic\WooCommerce\Utilities\OrderUtil;

defined('ABSPATH') || exit;

/**
 * Snapshot the live cart at checkout so it can be replayed verbatim on recovery.
 *
 * Captures the cart contents (with any third-party cart_item_data intact),
 * applied coupon codes, and the chosen shipping method as order meta. Runs
 * before the cart is emptied for both classic and Store API checkouts.
 */
add_action('woocommerce_checkout_order_processed', 'wc_ac_capture_cart_snapshot');
add_action('woocommerce_store_api_checkout_order_processed', 'wc_ac_capture_cart_snapshot');
function wc_ac_capture_cart_snapshot($arg): void {
    if (!wc_ac_is_enabled() || !function_exists('WC')) {
        return;
    }

    $woocommerce = WC();
    $cart = $woocommerce->cart;

    if (!$cart) {
        return;
    }

    $order = $arg instanceof WC_Order ? $arg : wc_get_order((int)$arg);

    if (!$order instanceof WC_Order || !is_email((string)$order->get_billing_email())) {
        return;
    }

    $cart_contents = $cart->get_cart_for_session();

    if (empty($cart_contents)) {
        return;
    }

    $session = $woocommerce->session;

    $snapshot = [
        'cart' => $cart_contents,
        'coupons' => $cart->get_applied_coupons(),
        'chosen_shipping_methods' => $session ? (array)$session->get('chosen_shipping_methods', []) : [],
    ];

    $order->update_meta_data(WC_AC_META_CART_SNAPSHOT, $snapshot);
    $order->save();
}

add_action('template_redirect', 'wc_ac_maybe_handle_recovery', 1);
function wc_ac_maybe_handle_recovery(): void {
    if (empty($_GET['wc_ac_recover'])) {
        return;
    }

    wc_ac_handle_recovery_request();
}

/**
 * Handle the `?wc_ac_recover=<token>` URL on the front-end.
 *
 * Looks up the cancelled order by token hash, restores its line items into
 * the live cart, marks the order as reopened, and redirects to checkout.
 */
function wc_ac_handle_recovery_request(): void {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }

    $token = sanitize_text_field(wp_unslash($_GET['wc_ac_recover']));

    if ($token === '' || !function_exists('WC')) {
        return;
    }

    $woocommerce = WC();

    if (function_exists('wc_load_cart') && did_action('woocommerce_init') && (!$woocommerce->cart || !$woocommerce->session)) {
        wc_load_cart();
    }

    $cart = $woocommerce->cart;
    $session = $woocommerce->session;

    if (!$cart || !$session) {
        return;
    }

    if (!wc_ac_is_enabled()) {
        wc_add_notice(__('This recovery link is no longer active.', 'wc-abandoned-cart'), 'error');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    $order = wc_ac_find_order_by_recovery_token($token);

    if (!$order instanceof WC_Order || (int)$order->get_meta(WC_AC_META_RECOVERED_ORDER) > 0) {
        wc_add_notice(__('This recovery link is invalid or has already been used.', 'wc-abandoned-cart'), 'error');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    if (wc_ac_recovery_token_is_expired($order)) {
        $order->delete_meta_data(WC_AC_META_TOKEN_HASH);
        $order->save();

        wc_add_notice(__('This recovery link has expired.', 'wc-abandoned-cart'), 'error');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    $restored = wc_ac_restore_cart_from_order($order);

    if ($restored['restored'] === 0) {
        $order->delete_meta_data(WC_AC_META_TOKEN_HASH);
        $order->save();

        wc_add_notice(__('We could not restore this cart because its items are no longer available.', 'wc-abandoned-cart'), 'error');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    $order->update_meta_data(WC_AC_META_REOPENED_AT, wc_ac_now());
    $order->delete_meta_data(WC_AC_META_TOKEN_HASH);
    $order->save();

    $ttl_minutes = (int)apply_filters('wc_ac_recovery_attribution_ttl_minutes', WC_AC_RECOVERY_ATTRIBUTION_TTL_MINUTES);
    $now = time();

    $session->set('wc_ac_recovery_attribution', [
        'order_id' => $order->get_id(),
        'recovered_at' => $now,
        'expires_at' => $now + max(1, $ttl_minutes) * MINUTE_IN_SECONDS,
        'fingerprint' => $restored['fingerprint'],
    ]);

    wc_add_notice(__('Your cart has been restored. You can finish checkout below.', 'wc-abandoned-cart'));

    if ($restored['failed'] > 0) {
        wc_add_notice(
            sprintf(
                _n(
                    '%d item could not be restored because it is no longer purchasable.',
                    '%d items could not be restored because they are no longer purchasable.',
                    $restored['failed'],
                    'wc-abandoned-cart'
                ),
                $restored['failed']
            ),
            'error'
        );
    }

    wp_safe_redirect(wc_get_checkout_url());
    exit;
}

/**
 * Hook handler for both classic checkout and Store API checkout.
 *
 * Classic passes an order ID; Store API passes the WC_Order object — handle both.
 */
add_action('woocommerce_checkout_order_processed', 'wc_ac_maybe_complete_recovery');
add_action('woocommerce_store_api_checkout_order_processed', 'wc_ac_maybe_complete_recovery');
function wc_ac_maybe_complete_recovery($arg): void {
    if (!function_exists('WC')) {
        return;
    }

    $session = WC()->session;

    if (!$session) {
        return;
    }

    $new_order_id = $arg instanceof WC_Order ? $arg->get_id() : (int)$arg;

    if ($new_order_id <= 0) {
        return;
    }

    $attribution = $session->get('wc_ac_recovery_attribution');

    if (!is_array($attribution) || empty($attribution['order_id'])) {
        return;
    }

    // Always clear once we've decided to consider this attribution — whether we
    // end up linking the order or skipping due to TTL / fingerprint mismatch.
    $session->set('wc_ac_recovery_attribution', null);

    if (time() > (int)($attribution['expires_at'] ?? 0)) {
        return;
    }

    $new_order = wc_get_order($new_order_id);

    if (!$new_order instanceof WC_Order) {
        return;
    }

    if (!wc_ac_order_matches_fingerprint($new_order, (array)($attribution['fingerprint'] ?? []))) {
        return;
    }

    wc_ac_complete_recovery($new_order_id, (int)$attribution['order_id']);
}

/**
 * True when the new order shares at least one product_id+variation_id with
 * the snapshot of items restored when the recovery link was clicked.
 */
function wc_ac_order_matches_fingerprint(WC_Order $order, array $fingerprint): bool {
    if (empty($fingerprint)) {
        return false;
    }

    $needles = array_flip($fingerprint);

    foreach ($order->get_items() as $item) {
        if (!$item instanceof WC_Order_Item_Product) {
            continue;
        }

        $key = $item->get_product_id() . ':' . $item->get_variation_id();

        if (isset($needles[$key])) {
            return true;
        }
    }

    return false;
}

/**
 * Mark an old abandoned order as recovered when its replacement order is placed.
 */
function wc_ac_complete_recovery(int $new_order_id, int $original_order_id): void {
    $original = wc_get_order($original_order_id);

    if ($original instanceof WC_Order && (int)$original->get_meta(WC_AC_META_RECOVERED_ORDER) === 0) {
        $original->update_meta_data(WC_AC_META_RECOVERED_ORDER, (string)$new_order_id);
        $original->update_meta_data(WC_AC_META_RECOVERED_AT, wc_ac_now());
        $original->save();
    }

    $new_order = wc_get_order($new_order_id);

    if ($new_order instanceof WC_Order) {
        $new_order->update_meta_data(WC_AC_META_RECOVERED_FROM, (string)$original_order_id);

        $original_link = sprintf(
            '<a href="%s">#%s</a>',
            esc_url(OrderUtil::get_order_admin_edit_url($original_order_id)),
            esc_html($original instanceof WC_Order ? $original->get_order_number() : (string)$original_order_id)
        );

        $new_order->add_order_note(sprintf(
            /* translators: %s: link to the original abandoned order */
            __('Order recovered thanks to the abandoned cart recovery system from %s.', 'wc-abandoned-cart'),
            $original_link
        ));
        $new_order->save();
    }
}

/**
 * Look up a cancelled order by the SHA-256 hash of its recovery token.
 */
function wc_ac_find_order_by_recovery_token(string $token): ?WC_Order {
    $orders = wc_get_orders([
        'limit' => 1,
        'status' => ['cancelled'],
        'return' => 'ids',
        'meta_query' => [
            [
                'key' => WC_AC_META_TOKEN_HASH,
                'value' => hash('sha256', $token),
            ],
        ],
    ]);

    if (empty($orders)) {
        return null;
    }

    $order = wc_get_order($orders[0]);

    return $order instanceof WC_Order ? $order : null;
}

function wc_ac_recovery_token_is_expired(WC_Order $order): bool {
    // Anchor TTL to EMAIL_SENT_AT, falling back to ABANDONED_AT in the rare
    // case where the post-send save of EMAIL_SENT_AT failed: the link is
    // already in the customer's inbox and shouldn't die over a missed write.
    $anchor = (string)$order->get_meta(WC_AC_META_EMAIL_SENT_AT);

    if ($anchor === '') {
        $anchor = (string)$order->get_meta(WC_AC_META_ABANDONED_AT);
    }

    if ($anchor === '') {
        return true;
    }

    $anchor_timestamp = strtotime($anchor . ' UTC');

    if ($anchor_timestamp === false) {
        return true;
    }

    return ($anchor_timestamp + WC_AC_RECOVERY_TOKEN_TTL_DAYS * DAY_IN_SECONDS) < time();
}

/**
 * Replay the cart snapshot captured at checkout. Restores items with full
 * cart_item_data fidelity, copies billing/shipping addresses onto the customer,
 * re-applies coupons, restores the chosen shipping method, and warns when that
 * method is no longer available.
 *
 * The visitor's existing cart is only emptied once at least one snapshot item
 * passes validation — if nothing is restorable, the live cart is left untouched.
 * Items that survive the plan pass but still fail add_to_cart() (deeper stock,
 * quantity, or third-party validation) trigger a rollback to the previous cart.
 *
 * The returned `fingerprint` is the set of `product_id:variation_id` strings
 * for items that were successfully restored. It's used at checkout time to
 * confirm the placed order still relates to the recovered cart before
 * attributing it.
 *
 * @return array{restored: int, failed: int, fingerprint: array<int, string>}
 * @throws Exception
 */
function wc_ac_restore_cart_from_order(WC_Order $order): array {
    $snapshot = $order->get_meta(WC_AC_META_CART_SNAPSHOT);

    if (!is_array($snapshot) || empty($snapshot['cart'])) {
        return ['restored' => 0, 'failed' => 0, 'fingerprint' => []];
    }

    $planned = [];
    $failed = 0;

    foreach ($snapshot['cart'] as $cart_item) {
        if (!is_array($cart_item) || empty($cart_item['product_id'])) {
            continue;
        }

        $resolve_id = (int)($cart_item['variation_id'] ?? 0) ?: (int)$cart_item['product_id'];
        $product = wc_get_product($resolve_id);

        if (!$product instanceof WC_Product || !$product->is_purchasable() || !$product->is_in_stock()) {
            ++$failed;

            continue;
        }

        // Strip standard cart shape keys; whatever's left is plugin-attached cart_item_data.
        $cart_item_data = $cart_item;
        unset(
            $cart_item_data['key'],
            $cart_item_data['product_id'],
            $cart_item_data['variation_id'],
            $cart_item_data['variation'],
            $cart_item_data['quantity'],
            $cart_item_data['data'],
            $cart_item_data['data_hash'],
            $cart_item_data['line_total'],
            $cart_item_data['line_tax'],
            $cart_item_data['line_subtotal'],
            $cart_item_data['line_subtotal_tax'],
            $cart_item_data['line_tax_data']
        );

        $planned[] = [
            'product_id' => (int)$cart_item['product_id'],
            'quantity' => (int)($cart_item['quantity'] ?? 1),
            'variation_id' => (int)($cart_item['variation_id'] ?? 0),
            'variation' => (array)($cart_item['variation'] ?? []),
            'cart_item_data' => $cart_item_data,
        ];
    }

    if (empty($planned)) {
        return ['restored' => 0, 'failed' => $failed, 'fingerprint' => []];
    }

    $woocommerce = WC();
    $cart = $woocommerce->cart;
    $session = $woocommerce->session;

    if (!$cart || !$session) {
        return ['restored' => 0, 'failed' => $failed, 'fingerprint' => []];
    }

    // Capture live state for rollback: add_to_cart() can still reject every planned
    // item via deeper stock checks, quantity rules, or third-party validation hooks.
    $previous_contents = $cart->get_cart_contents();
    $previous_coupons = $cart->get_applied_coupons();
    $previous_shipping = (array)$session->get('chosen_shipping_methods', []);

    $cart->empty_cart();

    $restored = 0;
    $fingerprint = [];

    foreach ($planned as $item) {
        $key = $cart->add_to_cart(
            $item['product_id'],
            $item['quantity'],
            $item['variation_id'],
            $item['variation'],
            $item['cart_item_data']
        );

        if ($key === false) {
            ++$failed;

            continue;
        }

        $fingerprint[] = $item['product_id'] . ':' . $item['variation_id'];
        ++$restored;
    }

    if ($restored === 0) {
        $cart->set_cart_contents($previous_contents);
        $cart->set_applied_coupons($previous_coupons);
        $session->set('chosen_shipping_methods', $previous_shipping);
        $cart->calculate_totals();

        return ['restored' => 0, 'failed' => $failed, 'fingerprint' => []];
    }

    wc_ac_restore_customer_addresses($order);

    $desired_shipping = (array)($snapshot['chosen_shipping_methods'] ?? []);

    if (!empty($desired_shipping)) {
        $session->set('chosen_shipping_methods', $desired_shipping);
    }

    foreach ((array)($snapshot['coupons'] ?? []) as $code) {
        if (!$cart->has_discount($code)) {
            $cart->apply_coupon($code);
        }
    }

    $cart->calculate_totals();

    if (!empty($desired_shipping)) {
        $actual_shipping = (array)$session->get('chosen_shipping_methods', []);

        foreach ($desired_shipping as $package_index => $method) {
            if (($actual_shipping[$package_index] ?? null) !== $method) {
                wc_add_notice(
                    __('Your previously selected shipping option is no longer available — please review shipping at checkout.', 'wc-abandoned-cart'),
                    'notice'
                );

                break;
            }
        }
    }

    return [
        'restored' => $restored,
        'failed' => $failed,
        'fingerprint' => array_values(array_unique($fingerprint)),
    ];
}

/**
 * Copy the order's billing and shipping addresses onto the live customer so
 * checkout fields are pre-filled.
 */
function wc_ac_restore_customer_addresses(WC_Order $order): void {
    $customer = WC()->customer;

    if (!$customer) {
        return;
    }

    foreach ($order->get_address('billing') as $key => $value) {
        $setter = "set_billing_$key";

        if (method_exists($customer, $setter)) {
            $customer->$setter($value);
        }
    }

    foreach ($order->get_address('shipping') as $key => $value) {
        $setter = "set_shipping_$key";

        if (method_exists($customer, $setter)) {
            $customer->$setter($value);
        }
    }

    $customer->save();
}
