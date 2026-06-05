<?php

defined('ABSPATH') || exit;

/**
 * Second abandoned-cart reminder.
 *
 * A follow-up to the first reminder, sent only when the cart is still
 * unrecovered after the configured delay. Inherits all rendering (cart table,
 * CTA, preview, colours) from the first reminder and overrides only its
 * identity and default copy, so merchants configure it independently in
 * WooCommerce > Settings > Emails. Disabled by default — it is opt-in.
 */
class WC_AC_Email_Abandoned_Cart_Second extends WC_AC_Email_Abandoned_Cart {
    protected function define_id(): string {
        return 'wc_ac_abandoned_cart_2';
    }

    protected function define_title(): string {
        return __('Abandoned cart reminder (second)', 'wc-abandoned-cart');
    }

    protected function define_description(): string {
        return __('Send a follow-up reminder when the cart still hasn\'t been recovered after the first reminder. Disabled by default.', 'wc-abandoned-cart');
    }

    public function init_form_fields(): void {
        parent::init_form_fields();

        $this->form_fields['enabled']['default'] = 'no';
    }

    public function get_default_subject(): string {
        return __('Still interested? Your cart at {site_title} is waiting', 'wc-abandoned-cart');
    }

    public function get_default_heading(): string {
        return __('Your cart is still saved', 'wc-abandoned-cart');
    }

    public function get_default_email_body(): string {
        return __('Just a reminder that the items in your cart at {site_title} are still available. Use the button below to pick up right where you left off before they sell out.', 'wc-abandoned-cart');
    }
}
