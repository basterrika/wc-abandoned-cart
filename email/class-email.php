<?php

defined('ABSPATH') || exit;

class WC_AC_Email_Abandoned_Cart extends WC_Email {
    protected string $recovery_url = '';

    public function __construct() {
        $this->id = 'wc_ac_abandoned_cart';
        $this->title = __('Abandoned cart reminder', 'wc-abandoned-cart');
        $this->description = __('Send a reminder when an unpaid order is auto-cancelled by WooCommerce.', 'wc-abandoned-cart');
        $this->customer_email = true;
        $this->manual = false;
        $this->placeholders = [
            '{customer_email}' => '',
            '{recovery_url}' => '',
        ];

        parent::__construct();

        add_filter('woocommerce_prepare_email_for_preview', [$this, 'prepare_for_preview']);
    }

    public function get_default_subject(): string {
        return __('Complete your checkout at {site_title}', 'wc-abandoned-cart');
    }

    public function get_default_heading(): string {
        return __('Your cart is waiting for you', 'wc-abandoned-cart');
    }

    public function init_form_fields(): void {
        $placeholder_text = sprintf(
            __('Available placeholders: %s', 'wc-abandoned-cart'),
            '<code>{site_title}</code>, <code>{customer_email}</code>, <code>{recovery_url}</code>'
        );

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'wc-abandoned-cart'),
                'type' => 'checkbox',
                'label' => __('Enable this email notification', 'wc-abandoned-cart'),
                'default' => 'yes',
            ],
            'subject' => [
                'title' => __('Subject', 'wc-abandoned-cart'),
                'type' => 'text',
                'desc_tip' => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_subject(),
                'default' => '',
            ],
            'heading' => [
                'title' => __('Email heading', 'wc-abandoned-cart'),
                'type' => 'text',
                'desc_tip' => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_heading(),
                'default' => '',
            ],
            'email_body' => [
                'title' => __('Email body', 'wc-abandoned-cart'),
                'type' => 'textarea',
                'description' => $placeholder_text,
                'css' => 'width:400px; height: 140px;',
                'default' => $this->get_default_email_body(),
                'desc_tip' => true,
            ],
            'cta_label' => [
                'title' => __('CTA button label', 'wc-abandoned-cart'),
                'type' => 'text',
                'description' => __('Text shown on the recovery button.', 'wc-abandoned-cart'),
                'placeholder' => $this->get_default_cta_label(),
                'default' => $this->get_default_cta_label(),
                'desc_tip' => true,
            ],
            'email_type' => [
                'title' => __('Email type', 'wc-abandoned-cart'),
                'type' => 'select',
                'description' => __('Choose which format of email to send.', 'wc-abandoned-cart'),
                'default' => 'html',
                'class' => 'email_type wc-enhanced-select',
                'options' => $this->get_email_type_options(),
                'desc_tip' => true,
            ],
        ];
    }

    public function trigger($order, $token): bool {
        $token = (string)$token;

        if (!$this->is_enabled() || !$order instanceof WC_Order || $token === '') {
            return false;
        }

        $this->setup_locale();

        try {
            $recipient = sanitize_email((string)$order->get_billing_email());

            if (!is_email($recipient)) {
                return false;
            }

            $this->object = $order;
            $this->recipient = $recipient;
            $this->recovery_url = esc_url_raw(add_query_arg('wc_ac_recover', $token, wc_get_checkout_url()));
            $this->placeholders['{customer_email}'] = $recipient;
            $this->placeholders['{recovery_url}'] = $this->recovery_url;

            return $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );
        }
        finally {
            $this->object = null;
            $this->recipient = '';
            $this->recovery_url = '';
            $this->placeholders['{customer_email}'] = '';
            $this->placeholders['{recovery_url}'] = '';
            $this->restore_locale();
        }
    }

    public function get_content_html(): string {
        $cta_background_color = $this->get_cta_background_color();
        $cta_text_color = $this->get_cta_text_color($cta_background_color);
        $recovery_url = $this->get_recovery_url();

        ob_start();

        do_action('woocommerce_email_header', $this->get_heading(), $this);

        echo wp_kses_post(wpautop($this->get_email_body()));
        echo wp_kses_post($this->get_cart_items_html());

        if ($recovery_url !== '') {
            echo '<p style="text-align:center;margin:28px 0;"><a href="' . esc_url($recovery_url) . '" style="display:inline-block;background-color:' . esc_attr($cta_background_color) . ';color:' . esc_attr($cta_text_color) . ';padding:14px 36px;text-decoration:none;border-radius:4px;font-weight:bold;font-size:16px;line-height:1.4;">' . esc_html($this->get_cta_label()) . '</a></p>';
        }

        do_action('woocommerce_email_footer', $this);

        return (string)ob_get_clean();
    }

    public function get_content_plain(): string {
        $recovery_url = $this->get_recovery_url();
        $content = $this->get_heading() . "\n\n";
        $content .= wp_strip_all_tags($this->get_email_body()) . "\n\n";
        $content .= $this->get_cart_items_plain();

        if ($recovery_url !== '') {
            $content .= $this->get_cta_label() . ': ' . $recovery_url . "\n";
        }

        return $content;
    }

    public function get_default_email_body(): string {
        return __('You left items in your cart at {site_title}. Use the button below to return to checkout and finish your order.', 'wc-abandoned-cart');
    }

    public function get_default_cta_label(): string {
        return __('Return to checkout', 'wc-abandoned-cart');
    }

    public function get_email_body(): string {
        return $this->format_string($this->get_option('email_body', $this->get_default_email_body()));
    }

    public function get_cta_label(): string {
        return $this->format_string($this->get_option('cta_label', $this->get_default_cta_label()));
    }

    public function get_recovery_url(): string {
        if ($this->recovery_url !== '') {
            return $this->recovery_url;
        }

        if ($this->is_email_preview()) {
            $this->recovery_url = $this->get_preview_recovery_url();

            return $this->recovery_url;
        }

        return '';
    }

    public function prepare_for_preview($email) {
        if (!$email instanceof self) {
            return $email;
        }

        $email->placeholders['{customer_email}'] = 'preview@example.com';
        $email->placeholders['{recovery_url}'] = $email->get_preview_recovery_url();

        return $email;
    }

    protected function get_render_items(): array {
        if ($this->is_email_preview()) {
            return $this->get_preview_items();
        }

        if ($this->object instanceof WC_Order) {
            return $this->build_items_from_order($this->object);
        }

        return [];
    }

    /**
     * @return array<int, array{name: string, quantity: int, image_url: string, meta_html: string, subtotal_html: string}>
     */
    protected function build_items_from_order(WC_Order $order): array {
        $items = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product = $item->get_product();

            $items[] = [
                'name' => wp_strip_all_tags($item->get_name()),
                'quantity' => max(1, (int)$item->get_quantity()),
                'image_url' => $this->get_product_image_url($product),
                'meta_html' => wc_display_item_meta($item, ['echo' => false]),
                'subtotal_html' => $order->get_formatted_line_subtotal($item),
            ];
        }

        return $items;
    }

    protected function get_product_image_url($product): string {
        if (!$product instanceof WC_Product) {
            return '';
        }

        $image_id = $product->get_image_id();

        if (!$image_id && $product instanceof WC_Product_Variation) {
            $parent = wc_get_product($product->get_parent_id());

            if ($parent instanceof WC_Product) {
                $image_id = $parent->get_image_id();
            }
        }

        if (!$image_id) {
            return '';
        }

        $url = wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail');

        return $url ? esc_url_raw($url) : '';
    }

    protected function get_cart_items_html(): string {
        $items = $this->get_render_items();

        if (empty($items)) {
            return '';
        }

        ob_start();

        ?>

        <h2 style="margin:32px 0 12px;font-size:20px;line-height:1.3;"><?php esc_html_e('Your cart', 'wc-abandoned-cart'); ?></h2>
        <table cellspacing="0" cellpadding="12" style="width:100%;border-collapse:collapse;margin:0 0 24px;">
            <tbody>
                <?php

                foreach ($items as $item) {
                    ?>

                    <tr>
                        <td style="vertical-align:middle;width:72px;">
                            <?php

                            if ($item['image_url'] !== '') {
                                ?>

                                <img
                                    src="<?php echo esc_url($item['image_url']); ?>"
                                    alt="<?php echo esc_attr($item['name']); ?>"
                                    width="64"
                                    style="display:block;width:64px;max-width:64px;height:auto;border:0;border-radius:4px;"
                                />

                                <?php
                            }

                            ?>
                        </td>
                        <td style="vertical-align:middle;">
                            <strong><?php echo esc_html($item['name']); ?></strong>
                            <div style="margin-top:4px;color:#666666;"><?php echo esc_html(sprintf(__('Qty: %d', 'wc-abandoned-cart'), $item['quantity'])); ?></div>

                            <?php

                            if ($item['meta_html'] !== '') {
                                ?>

                                <div style="margin-top:4px;color:#666666;"><?php echo wp_kses_post($item['meta_html']); ?></div>

                                <?php
                            }

                            ?>
                        </td>
                        <td style="vertical-align:middle;text-align:right;white-space:nowrap;">
                            <?php echo wp_kses_post($item['subtotal_html']); ?>
                        </td>
                    </tr>

                    <?php
                }

                ?>
            </tbody>
        </table>

        <?php

        return (string)ob_get_clean();
    }

    protected function get_cart_items_plain(): string {
        $items = $this->get_render_items();

        if (empty($items)) {
            return '';
        }

        $content = __('Your cart:', 'wc-abandoned-cart') . "\n";

        foreach ($items as $item) {
            $line = sprintf('- %s x %d', $item['name'], $item['quantity']);

            $meta_plain = trim(wp_strip_all_tags($item['meta_html']));

            if ($meta_plain !== '') {
                $line .= ' (' . $meta_plain . ')';
            }

            $subtotal_plain = trim(wp_strip_all_tags($item['subtotal_html']));

            if ($subtotal_plain !== '') {
                $line .= ' - ' . $subtotal_plain;
            }

            $content .= $line . "\n";
        }

        return $content . "\n";
    }

    protected function is_email_preview(): bool {
        return (bool)apply_filters('woocommerce_is_email_preview', false);
    }

    protected function get_preview_recovery_url(): string {
        return esc_url_raw(add_query_arg('wc_ac_recover', 'preview-token', wc_get_checkout_url()));
    }

    protected function get_cta_background_color(): string {
        $base_color = get_option('woocommerce_email_base_color', '#720eec');

        if ($this->is_email_preview()) {
            $preview_base_color = get_transient('woocommerce_email_base_color');

            if ($preview_base_color !== false && $preview_base_color !== '') {
                $base_color = $preview_base_color;
            }
        }

        $base_color = sanitize_hex_color((string)$base_color);

        return $base_color ?: '#720eec';
    }

    protected function get_cta_text_color(string $background_color): string {
        return wc_light_or_dark($background_color, '#202020', '#ffffff');
    }

    /**
     * @return array<int, array{name: string, quantity: int, image_url: string, meta_html: string, subtotal_html: string}>
     */
    protected function get_preview_items(): array {
        $placeholder_image = esc_url_raw(wc_placeholder_img_src('thumbnail'));

        return [
            [
                'name' => __('Demo Hoodie', 'wc-abandoned-cart'),
                'quantity' => 1,
                'image_url' => $placeholder_image,
                'meta_html' => '',
                'subtotal_html' => wc_price(49.00),
            ],
            [
                'name' => __('Demo Sneakers', 'wc-abandoned-cart'),
                'quantity' => 2,
                'image_url' => $placeholder_image,
                'meta_html' => '<ul class="wc-item-meta"><li><strong>Size:</strong> M</li><li><strong>Color:</strong> Ocean blue</li></ul>',
                'subtotal_html' => wc_price(158.00),
            ],
        ];
    }
}
