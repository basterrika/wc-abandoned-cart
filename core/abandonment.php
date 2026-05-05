<?php

defined('ABSPATH') || exit;

/**
 * Request-scoped tracker of orders being processed by wc_cancel_unpaid_orders().
 *
 * The filter records the auto-cancel intent; the action consumes it. Keeping
 * this in memory ensures only this exact code path can flag an order for
 * recovery — unrelated future cancellations of the same order can never
 * trigger an email by mistake.
 */
final class WC_AC_Unpaid_Cancel_State {
    private static array $tracked = [];

    public static function track(int $order_id): void {
        self::$tracked[$order_id] = true;
    }

    public static function consume(int $order_id): bool {
        if (isset(self::$tracked[$order_id])) {
            unset(self::$tracked[$order_id]);

            return true;
        }

        return false;
    }
}

add_filter('woocommerce_cancel_unpaid_order', 'wc_ac_capture_unpaid_cancel_intent', 100, 2);
function wc_ac_capture_unpaid_cancel_intent($cancel, $order) {
    if (!$cancel || !$order instanceof WC_Order || !wc_ac_is_enabled()) {
        return $cancel;
    }

    if (!is_email((string)$order->get_billing_email())) {
        return $cancel;
    }

    WC_AC_Unpaid_Cancel_State::track($order->get_id());

    return $cancel;
}

add_action('woocommerce_order_status_cancelled', 'wc_ac_handle_order_cancelled', 10, 2);
function wc_ac_handle_order_cancelled($order_id, $order): void {
    if (!WC_AC_Unpaid_Cancel_State::consume((int)$order_id)) {
        return;
    }

    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order_id);
    }

    if (!$order instanceof WC_Order) {
        return;
    }

    if (!is_array($order->get_meta(WC_AC_META_CART_SNAPSHOT))) {
        return;
    }

    $order->update_meta_data(WC_AC_META_ABANDONED_AT, wc_ac_now());
    $order->save();

    if (!function_exists('as_schedule_single_action')) {
        return;
    }

    as_schedule_single_action(
        time() + (wc_ac_get_recovery_email_delay_minutes() * MINUTE_IN_SECONDS),
        WC_AC_SEND_EMAIL_HOOK,
        ['order_id' => $order->get_id()],
        WC_AC_ACTION_GROUP
    );
}

/**
 * Send the recovery email for an abandoned order.
 *
 * Generates a fresh recovery token at send time, stores its hash on the order,
 * and triggers the WC_Email instance.
 */
add_action(WC_AC_SEND_EMAIL_HOOK, 'wc_ac_send_recovery_email');
function wc_ac_send_recovery_email($order_id): void {
    $order_id = (int)$order_id;

    if ($order_id <= 0 || !wc_ac_is_enabled()) {
        return;
    }

    $order = wc_get_order($order_id);

    if (!$order instanceof WC_Order || $order->get_status() !== 'cancelled') {
        return;
    }

    if ($order->get_meta(WC_AC_META_ABANDONED_AT) === '') {
        return;
    }

    if ($order->get_meta(WC_AC_META_EMAIL_SENT_AT) !== '' || (int)$order->get_meta(WC_AC_META_RECOVERED_ORDER) > 0) {
        return;
    }

    $email_instance = wc_ac_get_email_instance();

    if (!$email_instance || !$email_instance->is_enabled()) {
        return;
    }

    $attempts = (int)$order->get_meta(WC_AC_META_SEND_ATTEMPTS) + 1;

    try {
        $token = bin2hex(random_bytes(32));
    }
    catch (Throwable $e) {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error(
                sprintf('Could not generate recovery token for order #%d: %s', $order_id, $e->getMessage()),
                ['source' => 'wc-abandoned-cart']
            );
        }

        return;
    }

    $order->update_meta_data(WC_AC_META_TOKEN_HASH, hash('sha256', $token));
    $order->save();

    try {
        $sent = $email_instance->trigger($order, $token);
    }
    catch (Throwable $e) {
        $sent = false;

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error(
                sprintf('Uncaught exception sending recovery email for order #%d: %s', $order_id, $e->getMessage()),
                ['source' => 'wc-abandoned-cart']
            );
        }
    }

    if (!$sent) {
        $order->update_meta_data(WC_AC_META_SEND_ATTEMPTS, (string)$attempts);
        $order->save();

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error(
                sprintf('Failed to send abandoned cart recovery email for order #%d (attempt %d).', $order_id, $attempts),
                ['source' => 'wc-abandoned-cart']
            );
        }

        $retry_delays_minutes = [5, 15];
        $delay_min = $retry_delays_minutes[$attempts - 1] ?? null;

        if ($delay_min !== null && function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + ($delay_min * MINUTE_IN_SECONDS),
                WC_AC_SEND_EMAIL_HOOK,
                ['order_id' => $order_id],
                WC_AC_ACTION_GROUP
            );
        }

        return;
    }

    $order->update_meta_data(WC_AC_META_EMAIL_SENT_AT, wc_ac_now());
    $order->add_order_note(__('Abandoned cart recovery email sent.', 'wc-abandoned-cart'));
    $order->save();
}

function wc_ac_get_email_instance(): ?WC_AC_Email_Abandoned_Cart {
    if (!function_exists('WC')) {
        return null;
    }

    $mailer = WC()->mailer();

    if (!$mailer) {
        return null;
    }

    $emails = $mailer->get_emails();
    $email = $emails['wc_ac_abandoned_cart'] ?? null;

    return $email instanceof WC_AC_Email_Abandoned_Cart ? $email : null;
}
