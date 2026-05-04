# Abandoned Cart for WooCommerce

Recover unpaid WooCommerce orders after WooCommerce auto-cancels them.

This plugin listens for WooCommerce's unpaid-order cancellation flow, captures the abandoned order, sends a recovery
email, restores the customer's cart from a saved snapshot, and attributes the replacement order back to the original
abandoned order.

---

## How It Works

```text
┌───────────────────────────────┐
│         Pending order         │
└───────────────┬───────────────┘
                ▼
┌───────────────────────────────┐
│      Hold stock minutes       │
└───────────────┬───────────────┘
                ▼
┌───────────────────────────────┐
│ WooCommerce cancels the order │
└───────────────┬───────────────┘
                ▼
┌───────────────────────────────┐
│     Recovery email delay      │
└───────────────┬───────────────┘
                ▼
┌───────────────────────────────┐
│      Recovery email sent      │
└───────────────┬───────────────┘
                ▼
┌───────────────────────────────┐
│    Customer restores cart     │
└───────────────┬───────────────┘
                ▼
┌───────────────────────────────┐
│        Recovered order        │
└───────────────────────────────┘
```

---

## Timing

The two timing settings run **sequentially**:

```text
Order placed  ──▶  Hold stock minutes  ──▶  Order auto-cancelled  ──▶  Recovery email delay  ──▶  Email sent
```

**Example**

| Setting                 | Value                                         |
|-------------------------|-----------------------------------------------|
| Hold stock minutes      | `60`                                          |
| Recovery email delay    | `30`                                          |
| **Total time to email** | **~90 min** after the unpaid order was placed |

---

## Settings

### WooCommerce Hold Stock Minutes

> **Location:** `WooCommerce > Settings > Products > Inventory`

This controls how long WooCommerce keeps an unpaid order pending before auto-cancelling it. The plugin only starts after
WooCommerce performs that auto-cancellation.

### Plugin Recovery Email Delay

> **Location:** `WooCommerce > Settings > Abandoned Cart`

This controls how long the plugin waits after the order is cancelled before sending the recovery email.

---

## Important Notes

- The plugin does not email customers before WooCommerce cancels the unpaid order.
- The abandoned cart email requires a valid billing email and a captured cart snapshot.
- Recovery links are one-time use and expire after 7 days.
- Configure the email content in `WooCommerce > Settings > Emails > Abandoned cart reminder`.
