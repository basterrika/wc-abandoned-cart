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

    $step_config = wc_ac_get_email_step_config(1);

    if ($step_config === null) {
        return;
    }

    as_schedule_single_action(
        time() + ((int)call_user_func($step_config['delay_minutes']) * MINUTE_IN_SECONDS),
        WC_AC_SEND_EMAIL_HOOK,
        ['order_id' => $order->get_id(), 'step' => 1],
        WC_AC_ACTION_GROUP
    );
}

/**
 * Per-step configuration for the recovery email sequence.
 *
 * Each step maps to its email instance, the meta keys that record its own send
 * state, the delay before it fires, the order note logged on success, and the
 * step that follows it. Keeping the two reminders as explicit entries lets them
 * share one send/retry implementation without building a generic sequencing
 * engine we have no third step for.
 *
 * @return array{email_id: string, sent_at_meta: string, attempts_meta: string, delay_minutes: callable, note: string, failure_note: string, next_step: int|null}|null
 */
function wc_ac_get_email_step_config(int $step): ?array {
    switch ($step) {
        case 1:
            return [
                'email_id' => 'wc_ac_abandoned_cart',
                'sent_at_meta' => WC_AC_META_EMAIL_SENT_AT,
                'attempts_meta' => WC_AC_META_SEND_ATTEMPTS,
                'delay_minutes' => 'wc_ac_get_recovery_email_delay_minutes',
                'note' => __('Abandoned cart recovery email sent.', 'wc-abandoned-cart'),
                /* translators: %d: send attempt number */
                'failure_note' => __('Abandoned cart recovery email failed to send (attempt %d).', 'wc-abandoned-cart'),
                'next_step' => 2,
            ];
        case 2:
            return [
                'email_id' => 'wc_ac_abandoned_cart_2',
                'sent_at_meta' => WC_AC_META_EMAIL_2_SENT_AT,
                'attempts_meta' => WC_AC_META_EMAIL_2_SEND_ATTEMPTS,
                'delay_minutes' => 'wc_ac_get_recovery_email_2_delay_minutes',
                'note' => __('Second abandoned cart recovery email sent.', 'wc-abandoned-cart'),
                /* translators: %d: send attempt number */
                'failure_note' => __('Second abandoned cart recovery email failed to send (attempt %d).', 'wc-abandoned-cart'),
                'next_step' => null,
            ];
    }

    return null;
}

/**
 * Send one reminder in the recovery sequence for an abandoned order.
 *
 * Runs once per scheduled step. Generates a fresh recovery token at send time,
 * stores its hash on the order (rotating out any previous link), and triggers
 * the step's WC_Email instance. On a successful send it records the step's
 * sent-at timestamp and queues the next step, if any.
 */
add_action(WC_AC_SEND_EMAIL_HOOK, 'wc_ac_send_recovery_email', 10, 2);
function wc_ac_send_recovery_email($order_id, $step = 1): void {
    $order_id = (int)$order_id;

    if ($order_id <= 0 || !wc_ac_is_enabled()) {
        return;
    }

    $config = wc_ac_get_email_step_config((int)$step);

    if ($config === null) {
        return;
    }

    $order = wc_get_order($order_id);

    if (!$order instanceof WC_Order || $order->get_status() !== 'cancelled') {
        return;
    }

    if ($order->get_meta(WC_AC_META_ABANDONED_AT) === '') {
        return;
    }

    if ($order->get_meta($config['sent_at_meta']) !== '' || (int)$order->get_meta(WC_AC_META_RECOVERED_ORDER) > 0) {
        return;
    }

    $email_instance = wc_ac_get_email_instance($config['email_id']);

    if (!$email_instance || !$email_instance->is_enabled()) {
        return;
    }

    $attempts = (int)$order->get_meta($config['attempts_meta']) + 1;

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

    // Remember the live link before rotating it in: a later reminder runs after
    // an earlier one was already delivered, so if this send fails we must put the
    // previous token back rather than leave the customer's working link dead.
    $previous_token_hash = (string)$order->get_meta(WC_AC_META_TOKEN_HASH);

    $order->update_meta_data(WC_AC_META_TOKEN_HASH, hash('sha256', $token));
    $order->save();

    $failure_reason = '';

    try {
        $sent = $email_instance->trigger($order, $token);
    }
    catch (Throwable $e) {
        $sent = false;
        $failure_reason = (string)$e->getMessage();

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error(
                sprintf('Uncaught exception sending recovery email for order #%d: %s', $order_id, $e->getMessage()),
                ['source' => 'wc-abandoned-cart']
            );
        }
    }

    if (!$sent) {
        // Restore the previously delivered link instead of leaving this failed
        // send's token in place. Skipped on a first issuance (no prior hash), so
        // the first reminder's failure path is unchanged.
        if ($previous_token_hash !== '') {
            $order->update_meta_data(WC_AC_META_TOKEN_HASH, $previous_token_hash);
        }

        $order->update_meta_data($config['attempts_meta'], (string)$attempts);

        $failure_note = sprintf($config['failure_note'], $attempts);
        $reason = trim(wp_strip_all_tags($failure_reason));

        if ($reason !== '') {
            $failure_note .= ' ' . sprintf(
                /* translators: %s: error reported while sending the email */
                __('Reason: %s', 'wc-abandoned-cart'),
                $reason
            );
        }
        else {
            $failure_note .= ' ' . __('No failure reason was reported by the mailer.', 'wc-abandoned-cart');
        }

        $order->add_order_note($failure_note);
        $order->save();

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error(
                sprintf('Failed to send abandoned cart recovery email for order #%d (step %d, attempt %d).', $order_id, (int)$step, $attempts),
                ['source' => 'wc-abandoned-cart']
            );
        }

        $retry_delays_minutes = [5, 15];
        $delay_min = $retry_delays_minutes[$attempts - 1] ?? null;

        if ($delay_min !== null && function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + ($delay_min * MINUTE_IN_SECONDS),
                WC_AC_SEND_EMAIL_HOOK,
                ['order_id' => $order_id, 'step' => (int)$step],
                WC_AC_ACTION_GROUP
            );
        }

        return;
    }

    $order->update_meta_data($config['sent_at_meta'], wc_ac_now());
    $order->add_order_note($config['note']);
    $order->save();

    wc_ac_maybe_schedule_next_step($order_id, $config['next_step']);
}

/**
 * Queue the next reminder in the sequence after a successful send.
 *
 * The follow-up is scheduled only when its email is enabled, so the common case
 * of a disabled second reminder leaves nothing in the Action Scheduler queue.
 * Its delay is measured from this send, keeping the sequence strictly
 * sequential: hold stock -> cancel -> delay 1 -> email 1 -> delay 2 -> email 2.
 */
function wc_ac_maybe_schedule_next_step(int $order_id, ?int $next_step): void {
    if ($next_step === null || !function_exists('as_schedule_single_action')) {
        return;
    }

    $config = wc_ac_get_email_step_config($next_step);

    if ($config === null) {
        return;
    }

    $email_instance = wc_ac_get_email_instance($config['email_id']);

    if (!$email_instance || !$email_instance->is_enabled()) {
        return;
    }

    as_schedule_single_action(
        time() + ((int)call_user_func($config['delay_minutes']) * MINUTE_IN_SECONDS),
        WC_AC_SEND_EMAIL_HOOK,
        ['order_id' => $order_id, 'step' => $next_step],
        WC_AC_ACTION_GROUP
    );
}

function wc_ac_get_email_instance(string $email_id): ?WC_AC_Email_Abandoned_Cart {
    if (!function_exists('WC')) {
        return null;
    }

    $mailer = WC()->mailer();

    if (!$mailer) {
        return null;
    }

    $emails = $mailer->get_emails();
    $email = $emails[$email_id] ?? null;

    return $email instanceof WC_AC_Email_Abandoned_Cart ? $email : null;
}
