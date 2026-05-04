<?php

use Automattic\WooCommerce\Utilities\OrderUtil;

defined('ABSPATH') || exit;

/**
 * Return the supported analytics date ranges.
 *
 * @return array<int, string>
 */
function wc_ac_get_analytics_range_options(): array {
    return [
        7 => __('Last 7 days', 'wc-abandoned-cart'),
        30 => __('Last 30 days', 'wc-abandoned-cart'),
        90 => __('Last 90 days', 'wc-abandoned-cart'),
    ];
}

/**
 * Return the selected analytics range.
 *
 * Returns 0 when a custom date range is selected.
 * Falls back to the default (30 days) if the nonce is invalid.
 */
function wc_ac_get_analytics_range_days(): int {
    if (!isset($_GET['range'])) {
        return 30;
    }

    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

    if (!wp_verify_nonce($nonce, 'wc_ac_date_range')) {
        return 30;
    }

    if (wc_clean(wp_unslash($_GET['range'])) === 'custom') {
        return 0;
    }

    $allowed = array_keys(wc_ac_get_analytics_range_options());
    $days = absint(wp_unslash($_GET['range']));

    return in_array($days, $allowed, true) ? $days : 30;
}

/**
 * Return validated custom date range from the request.
 *
 * @return array{start: string, end: string} Dates in 'Y-m-d' format.
 */
function wc_ac_get_analytics_custom_dates(): array {
    $timezone = wp_timezone();
    $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    $default_start = (new DateTimeImmutable('now', $timezone))->modify('-29 days')->format('Y-m-d');

    if (isset($_GET['start'], $_GET['end'])) {
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, 'wc_ac_date_range')) {
            return ['start' => $default_start, 'end' => $today];
        }
    }

    $start = isset($_GET['start']) ? wc_clean(wp_unslash($_GET['start'])) : $default_start;
    $end = isset($_GET['end']) ? wc_clean(wp_unslash($_GET['end'])) : $today;

    $start_date = DateTimeImmutable::createFromFormat('Y-m-d', $start, $timezone);
    $end_date = DateTimeImmutable::createFromFormat('Y-m-d', $end, $timezone);

    if (!$start_date || $start_date->format('Y-m-d') !== $start) {
        $start = $default_start;
        $start_date = new DateTimeImmutable($start, $timezone);
    }

    if (!$end_date || $end_date->format('Y-m-d') !== $end) {
        $end = $today;
        $end_date = new DateTimeImmutable($end, $timezone);
    }

    if ($start_date > $end_date) {
        [$start, $end] = [$end, $start];
        [$start_date, $end_date] = [$end_date, $start_date];
    }

    $diff = (int)$start_date->diff($end_date)->days;

    if ($diff > 365) {
        $start = $end_date->modify('-365 days')->format('Y-m-d');
    }

    return ['start' => $start, 'end' => $end];
}

/**
 * Build the local and GMT bounds for a reporting period.
 *
 * @return array<string, mixed>
 */
function wc_ac_get_analytics_period($days): array {
    $timezone = wp_timezone();
    $utc = new DateTimeZone('UTC');

    if ((int)$days === 0) {
        $custom = wc_ac_get_analytics_custom_dates();
        $start_local = (new DateTimeImmutable($custom['start'], $timezone))->setTime(0, 0, 0);
        $end_local = (new DateTimeImmutable($custom['end'], $timezone))->setTime(23, 59, 59);
        $computed_days = (int)$start_local->diff($end_local)->days + 1;

        return [
            'days' => $computed_days,
            'start_local' => $start_local,
            'end_local' => $end_local,
            'start_gmt' => $start_local->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end_gmt' => $end_local->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    $days = in_array((int)$days, array_keys(wc_ac_get_analytics_range_options()), true) ? (int)$days : 30;
    $end_local = (new DateTimeImmutable('now', $timezone))->setTime(23, 59, 59);
    $start_local = $end_local->setTime(0, 0, 0)->modify('-' . ($days - 1) . ' days');

    return [
        'days' => $days,
        'start_local' => $start_local,
        'end_local' => $end_local,
        'start_gmt' => $start_local->setTimezone($utc)->format('Y-m-d H:i:s'),
        'end_gmt' => $end_local->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

/**
 * Build the report payload for the analytics screen.
 *
 * @return array<string, mixed>
 */
function wc_ac_get_analytics_report($days): array {
    $is_custom = (int)$days === 0;
    $period = wc_ac_get_analytics_period($days);
    $abandoned_daily = wc_ac_get_analytics_abandoned_daily($period);
    $recovered_agg = wc_ac_get_analytics_recovered_daily($period);
    $cohort_reopened_count = wc_ac_get_analytics_reopened_count_for_abandoned_period($period);
    $cohort_recovered_count = wc_ac_get_analytics_recovered_count_for_abandoned_period($period);
    $chart = wc_ac_build_analytics_chart_data($period, $abandoned_daily, $recovered_agg['daily']);
    $abandoned_count = array_sum($abandoned_daily);
    $current_abandoned = wc_ac_get_current_abandoned_summary();

    return [
        'range_days' => $is_custom ? 'custom' : $period['days'],
        'period' => $period,
        'summary' => [
            'abandoned' => $abandoned_count,
            'reopened' => $cohort_reopened_count,
            'recovered' => $recovered_agg['total_count'],
            'recovered_revenue' => $recovered_agg['total_revenue'],
            'recovery_rate' => $abandoned_count > 0 ? ($cohort_recovered_count / $abandoned_count) * 100 : 0,
            'current_abandoned' => $current_abandoned['count'],
            'recoverable_revenue' => $current_abandoned['revenue'],
        ],
        'chart' => $chart,
        'recent_orders' => wc_ac_get_recent_recovered_orders(
            wc_ac_get_analytics_recent_recovered($period)
        ),
    ];
}

/**
 * Whether HPOS (Custom Order Tables) is the active order storage.
 */
function wc_ac_orders_use_hpos(): bool {
    static $is_hpos = null;

    if ($is_hpos !== null) {
        return $is_hpos;
    }

    $is_hpos = OrderUtil::custom_orders_table_usage_is_enabled();

    return $is_hpos;
}

/**
 * Return the order-meta table name (HPOS or postmeta).
 */
function wc_ac_orders_meta_table(): string {
    global $wpdb;

    return wc_ac_orders_use_hpos() ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
}

/**
 * Return the column on the meta table that references an order ID.
 */
function wc_ac_orders_meta_id_column(): string {
    return wc_ac_orders_use_hpos() ? 'order_id' : 'post_id';
}

/**
 * Check whether WooCommerce Analytics order stats are available.
 */
function wc_ac_has_analytics_order_stats_table(): bool {
    global $wpdb;

    static $has_table = null;

    if ($has_table !== null) {
        return $has_table;
    }

    $table = $wpdb->prefix . 'wc_order_stats';
    $has_table = $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

    return $has_table;
}

/**
 * Return the order statuses excluded from WooCommerce analytics, prefixed with 'wc-'.
 *
 * @return array<int, string>
 */
function wc_ac_get_analytics_excluded_order_statuses(): array {
    static $excluded_statuses = null;

    if ($excluded_statuses !== null) {
        return $excluded_statuses;
    }

    $excluded_statuses = get_option(
        'woocommerce_excluded_report_order_statuses',
        ['pending', 'failed', 'cancelled']
    );

    if (!is_array($excluded_statuses)) {
        $excluded_statuses = ['pending', 'failed', 'cancelled'];
    }

    $excluded_statuses = array_values(
        array_unique(
            array_map(
                static function($status): string {
                    $status = sanitize_key((string)$status);

                    if ($status === '') {
                        return 'wc-trash';
                    }

                    return strpos($status, 'wc-') === 0 ? $status : 'wc-' . $status;
                },
                array_merge(['auto-draft', 'trash'], $excluded_statuses)
            )
        )
    );

    return $excluded_statuses;
}

/**
 * Return a SQL expression that converts a GMT datetime column or value to a local DATE.
 *
 * Uses CONVERT_TZ with the site's named timezone when MySQL timezone tables
 * are available (DST-aware). Falls back to a fixed UTC offset otherwise.
 */
function wc_ac_sql_gmt_to_local_date(string $column): string {
    static $mode = null;
    static $tz_param = null;

    if ($mode === null) {
        $tz_string = wp_timezone_string();

        if ($tz_string !== '' && !preg_match('/^[+-]/', $tz_string) && strpos($tz_string, 'UTC') !== 0) {
            global $wpdb;

            $test = $wpdb->get_var(
                $wpdb->prepare("SELECT CONVERT_TZ('2024-06-15 12:00:00', 'UTC', %s)", $tz_string)
            );

            if ($test !== null) {
                $mode = 'convert_tz';
                $tz_param = esc_sql($tz_string);
            }
        }

        if ($mode === null) {
            $mode = 'offset';
            $tz_param = (int)wp_timezone()->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        }
    }

    if ($mode === 'convert_tz') {
        return "DATE(CONVERT_TZ({$column}, 'UTC', '{$tz_param}'))";
    }

    return "DATE(DATE_ADD({$column}, INTERVAL {$tz_param} SECOND))";
}

/**
 * Return daily abandoned-cart counts in the period.
 *
 * Buckets the GMT timestamp stored in WC_AC_META_EMAIL_SENT_AT into local
 * calendar days so the chart aligns with the merchant's timezone.
 *
 * @param array<string, mixed> $period Analytics period.
 *
 * @return array<string, int> Keyed by 'Y-m-d' local date.
 */
function wc_ac_get_analytics_abandoned_daily(array $period): array {
    global $wpdb;

    $meta_table = wc_ac_orders_meta_table();
    $date_expr = wc_ac_sql_gmt_to_local_date('m.meta_value');

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers built internally.
    $sql = $wpdb->prepare(
        "SELECT {$date_expr} AS day, COUNT(*) AS cnt
        FROM `{$meta_table}` m
        WHERE m.meta_key = %s
            AND m.meta_value >= %s
            AND m.meta_value <= %s
        GROUP BY day",
        WC_AC_META_EMAIL_SENT_AT,
        (string)$period['start_gmt'],
        (string)$period['end_gmt']
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);
    $counts = [];

    if (is_array($rows)) {
        foreach ($rows as $row) {
            $counts[(string)$row['day']] = (int)$row['cnt'];
        }
    }

    return $counts;
}

/**
 * Return daily recovered-order counts and revenue.
 *
 * Joins wc_order_stats (indexed by date_created) with the orders-meta table
 * to find new orders that carry the WC_AC_META_RECOVERED_FROM marker.
 * Filters out the same statuses WooCommerce Analytics excludes.
 *
 * @param array<string, mixed> $period Analytics period.
 *
 * @return array{daily: array<string, array{count: int, revenue: float}>, total_count: int, total_revenue: float}
 */
function wc_ac_get_analytics_recovered_daily(array $period): array {
    $empty = ['daily' => [], 'total_count' => 0, 'total_revenue' => 0.0];

    if (!wc_ac_has_analytics_order_stats_table()) {
        return $empty;
    }

    global $wpdb;

    $order_stats_table = $wpdb->prefix . 'wc_order_stats';
    $meta_table = wc_ac_orders_meta_table();
    $id_col = wc_ac_orders_meta_id_column();
    $excluded_statuses = wc_ac_get_analytics_excluded_order_statuses();
    $status_placeholders = implode(', ', array_fill(0, count($excluded_statuses), '%s'));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers built internally.
    $sql = $wpdb->prepare(
        "SELECT DATE(stats.date_created) AS day,
                COUNT(*) AS cnt,
                SUM(stats.total_sales) AS revenue
        FROM `{$order_stats_table}` stats
        INNER JOIN `{$meta_table}` m
            ON m.{$id_col} = stats.order_id
            AND m.meta_key = %s
        WHERE stats.parent_id = 0
            AND stats.date_created >= %s
            AND stats.date_created <= %s
            AND stats.status NOT IN ({$status_placeholders})
        GROUP BY day",
        ...array_merge(
            [
                WC_AC_META_RECOVERED_FROM,
                $period['start_local']->format('Y-m-d H:i:s'),
                $period['end_local']->format('Y-m-d H:i:s'),
            ],
            $excluded_statuses
        )
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);

    if (!is_array($rows) || empty($rows)) {
        return $empty;
    }

    $daily = [];
    $total_count = 0;
    $total_revenue = 0.0;

    foreach ($rows as $row) {
        $cnt = (int)$row['cnt'];
        $rev = (float)$row['revenue'];
        $daily[(string)$row['day']] = ['count' => $cnt, 'revenue' => $rev];
        $total_count += $cnt;
        $total_revenue += $rev;
    }

    return ['daily' => $daily, 'total_count' => $total_count, 'total_revenue' => $total_revenue];
}

/**
 * Fetch the most recent recovered orders (the new orders) for the table.
 *
 * @param array<string, mixed> $period Analytics period.
 *
 * @return array<int, array<string, mixed>>
 */
function wc_ac_get_analytics_recent_recovered(array $period, int $limit = 10): array {
    if (!wc_ac_has_analytics_order_stats_table()) {
        return [];
    }

    global $wpdb;

    $order_stats_table = $wpdb->prefix . 'wc_order_stats';
    $meta_table = wc_ac_orders_meta_table();
    $id_col = wc_ac_orders_meta_id_column();
    $excluded_statuses = wc_ac_get_analytics_excluded_order_statuses();
    $status_placeholders = implode(', ', array_fill(0, count($excluded_statuses), '%s'));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers built internally.
    $sql = $wpdb->prepare(
        "SELECT stats.order_id,
                stats.date_created_gmt,
                stats.status,
                stats.total_sales
        FROM `{$order_stats_table}` stats
        INNER JOIN `{$meta_table}` m
            ON m.{$id_col} = stats.order_id
            AND m.meta_key = %s
        WHERE stats.parent_id = 0
            AND stats.date_created >= %s
            AND stats.date_created <= %s
            AND stats.status NOT IN ({$status_placeholders})
        ORDER BY stats.date_created DESC
        LIMIT %d",
        ...array_merge(
            [
                WC_AC_META_RECOVERED_FROM,
                $period['start_local']->format('Y-m-d H:i:s'),
                $period['end_local']->format('Y-m-d H:i:s'),
            ],
            $excluded_statuses,
            [$limit]
        )
    );

    $results = $wpdb->get_results($sql, ARRAY_A);

    return is_array($results) ? $results : [];
}

/**
 * Count carts abandoned in the period that the customer reopened from the recovery email.
 *
 * @param array<string, mixed> $period Analytics period.
 */
function wc_ac_get_analytics_reopened_count_for_abandoned_period(array $period): int {
    global $wpdb;

    $meta_table = wc_ac_orders_meta_table();
    $id_col = wc_ac_orders_meta_id_column();

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers built internally.
    $sql = $wpdb->prepare(
        "SELECT COUNT(DISTINCT m_sent.{$id_col})
        FROM `{$meta_table}` m_sent
        INNER JOIN `{$meta_table}` m_reopen
            ON m_reopen.{$id_col} = m_sent.{$id_col}
            AND m_reopen.meta_key = %s
        WHERE m_sent.meta_key = %s
            AND m_sent.meta_value >= %s
            AND m_sent.meta_value <= %s",
        WC_AC_META_REOPENED_AT,
        WC_AC_META_EMAIL_SENT_AT,
        (string)$period['start_gmt'],
        (string)$period['end_gmt']
    );

    return (int)$wpdb->get_var($sql);
}

/**
 * Count carts abandoned in the period that became valid recovered orders.
 *
 * Joins through the recovered-order meta into wc_order_stats so we only
 * count cohorts whose new order is still in a counted status.
 *
 * @param array<string, mixed> $period Analytics period.
 */
function wc_ac_get_analytics_recovered_count_for_abandoned_period(array $period): int {
    if (!wc_ac_has_analytics_order_stats_table()) {
        return 0;
    }

    global $wpdb;

    $order_stats_table = $wpdb->prefix . 'wc_order_stats';
    $meta_table = wc_ac_orders_meta_table();
    $id_col = wc_ac_orders_meta_id_column();
    $excluded_statuses = wc_ac_get_analytics_excluded_order_statuses();
    $status_placeholders = implode(', ', array_fill(0, count($excluded_statuses), '%s'));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers built internally.
    $sql = $wpdb->prepare(
        "SELECT COUNT(DISTINCT m_sent.{$id_col})
        FROM `{$meta_table}` m_sent
        INNER JOIN `{$meta_table}` m_rec
            ON m_rec.{$id_col} = m_sent.{$id_col}
            AND m_rec.meta_key = %s
        INNER JOIN `{$order_stats_table}` stats
            ON stats.order_id = CAST(m_rec.meta_value AS UNSIGNED)
        WHERE m_sent.meta_key = %s
            AND m_sent.meta_value >= %s
            AND m_sent.meta_value <= %s
            AND stats.parent_id = 0
            AND stats.status NOT IN ({$status_placeholders})",
        ...array_merge(
            [
                WC_AC_META_RECOVERED_ORDER,
                WC_AC_META_EMAIL_SENT_AT,
                (string)$period['start_gmt'],
                (string)$period['end_gmt'],
            ],
            $excluded_statuses
        )
    );

    return (int)$wpdb->get_var($sql);
}

/**
 * Count current abandoned carts and sum their recoverable revenue in one query.
 *
 * "Current abandoned" = recovery email sent within the link TTL, token hash
 * still on the order, customer hasn't reopened the cart, no recovered order
 * exists, and the original order is still cancelled. Revenue comes from
 * wc_order_stats.total_sales (denormalized order total).
 *
 * The cutoff arithmetic mirrors wc_ac_recovery_token_is_expired() so a cart
 * counted here is guaranteed to accept the recovery link if clicked now.
 *
 * @return array{count: int, revenue: float}
 */
function wc_ac_get_current_abandoned_summary(): array {
    global $wpdb;
    static $summary = null;

    if ($summary !== null) {
        return $summary;
    }

    if (!wc_ac_has_analytics_order_stats_table()) {
        $summary = ['count' => 0, 'revenue' => 0.0];

        return $summary;
    }

    $order_stats_table = $wpdb->prefix . 'wc_order_stats';
    $meta_table = wc_ac_orders_meta_table();
    $id_col = wc_ac_orders_meta_id_column();
    $cutoff_gmt = gmdate('Y-m-d H:i:s', time() - WC_AC_RECOVERY_TOKEN_TTL_DAYS * DAY_IN_SECONDS);

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers built internally.
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT m_sent.{$id_col}) AS abandoned_count,
                    COALESCE(SUM(stats.total_sales), 0) AS recoverable_revenue
            FROM `{$meta_table}` m_sent
            INNER JOIN `{$meta_table}` m_token
                ON m_token.{$id_col} = m_sent.{$id_col}
                AND m_token.meta_key = %s
            INNER JOIN `{$order_stats_table}` stats
                ON stats.order_id = m_sent.{$id_col}
            LEFT JOIN `{$meta_table}` m_reopen
                ON m_reopen.{$id_col} = m_sent.{$id_col}
                AND m_reopen.meta_key = %s
            LEFT JOIN `{$meta_table}` m_rec
                ON m_rec.{$id_col} = m_sent.{$id_col}
                AND m_rec.meta_key = %s
            WHERE m_sent.meta_key = %s
                AND m_sent.meta_value >= %s
                AND m_reopen.{$id_col} IS NULL
                AND m_rec.{$id_col} IS NULL
                AND stats.parent_id = 0
                AND stats.status = %s",
            WC_AC_META_TOKEN_HASH,
            WC_AC_META_REOPENED_AT,
            WC_AC_META_RECOVERED_ORDER,
            WC_AC_META_EMAIL_SENT_AT,
            $cutoff_gmt,
            'wc-cancelled'
        ),
        ARRAY_A
    );

    if (!is_array($row)) {
        $summary = ['count' => 0, 'revenue' => 0.0];

        return $summary;
    }

    $summary = [
        'count' => (int)$row['abandoned_count'],
        'revenue' => (float)$row['recoverable_revenue'],
    ];

    return $summary;
}

/**
 * Build daily chart series from pre-aggregated SQL data.
 *
 * @param array<string, mixed> $period Analytics period.
 * @param array<string, int> $abandoned_daily Keyed by 'Y-m-d'.
 * @param array<string, array{count: int, revenue: float}> $recovered_daily Keyed by 'Y-m-d'.
 *
 * @return array<string, array<int, mixed>>
 */
function wc_ac_build_analytics_chart_data(array $period, array $abandoned_daily, array $recovered_daily): array {
    $timezone = wp_timezone();
    $labels = [];
    $abandoned = [];
    $recovered = [];
    $revenue = [];
    $cursor = $period['start_local'];

    while ($cursor <= $period['end_local']) {
        $key = $cursor->format('Y-m-d');
        $labels[] = wp_date('M j', $cursor->getTimestamp(), $timezone);
        $abandoned[] = $abandoned_daily[$key] ?? 0;
        $recovered[] = isset($recovered_daily[$key]) ? $recovered_daily[$key]['count'] : 0;
        $revenue[] = isset($recovered_daily[$key]) ? (float)$recovered_daily[$key]['revenue'] : 0.0;
        $cursor = $cursor->modify('+1 day');
    }

    return [
        'labels' => $labels,
        'abandoned' => $abandoned,
        'recovered' => $recovered,
        'revenue' => $revenue,
    ];
}

/**
 * Prepare recent recovered orders for display.
 *
 * Batch-loads the WC_Order objects so we only run one extra query for
 * order numbers and billing emails (no N+1).
 *
 * @param array<int, array<string, mixed>> $recovered_orders Recent recovered orders (limited).
 *
 * @return array<int, array<string, mixed>>
 */
function wc_ac_get_recent_recovered_orders(array $recovered_orders): array {
    if (empty($recovered_orders)) {
        return [];
    }

    $order_ids = array_map(static function($order): int {
        return absint($order['order_id']);
    }, $recovered_orders);

    $order_objects = [];

    foreach (wc_get_orders(['include' => $order_ids, 'limit' => count($order_ids)]) as $obj) {
        $order_objects[$obj->get_id()] = $obj;
    }

    $orders = [];
    $datetime_format = get_option('date_format') . ' ' . get_option('time_format');

    foreach ($recovered_orders as $order) {
        $order_id = absint($order['order_id']);
        $order_object = $order_objects[$order_id] ?? null;

        $orders[] = [
            'order_id' => $order_id,
            'order_number' => $order_object ? $order_object->get_order_number() : $order_id,
            'email' => $order_object ? sanitize_email((string)$order_object->get_billing_email()) : '',
            'status' => wc_ac_get_analytics_order_status_label((string)$order['status']),
            'date_created' => get_date_from_gmt((string)$order['date_created_gmt'], $datetime_format),
            'total_sales' => (float)$order['total_sales'],
            'edit_url' => wc_ac_get_analytics_order_edit_url($order_id),
        ];
    }

    return $orders;
}

/**
 * Return the admin edit URL for an order.
 */
function wc_ac_get_analytics_order_edit_url($order_id): string {
    $order_id = absint($order_id);

    if ($order_id <= 0) {
        return '';
    }

    return OrderUtil::get_order_admin_edit_url($order_id);
}

/**
 * Return a human-readable order status label.
 */
function wc_ac_get_analytics_order_status_label($status): string {
    $status = str_replace('wc-', '', (string)$status);

    return wc_get_order_status_name($status);
}
