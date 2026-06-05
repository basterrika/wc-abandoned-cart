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

An optional **second reminder** can follow the first. Its delay is measured from
when the first email is sent, and it only goes out if the cart has not been
recovered in the meantime:

```text
… ──▶ Recovery email delay ──▶ First email ──▶ Second email delay ──▶ Second email
```

---

## Settings

### WooCommerce Hold Stock Minutes

> **Location:** `WooCommerce > Settings > Products > Inventory`

This controls how long WooCommerce keeps an unpaid order pending before auto-cancelling it. The plugin only starts after
WooCommerce performs that auto-cancellation.

### Plugin Recovery Email Delay

> **Location:** `WooCommerce > Settings > Abandoned Cart`

This controls how long the plugin waits after the order is cancelled before sending the recovery email.

### Second Recovery Email (optional)

> **Location:** `WooCommerce > Settings > Abandoned Cart` (delay) and `WooCommerce > Settings > Emails > Abandoned cart reminder (second)` (content and on/off)

A follow-up reminder, **disabled by default**. Enable it from the Emails screen and
set its delay — measured from when the first email is sent — under Abandoned Cart.
It is sent only if the cart has not been recovered, and sending it issues a fresh
recovery link (which supersedes the first email's link).

---

## Important Notes

- The plugin does not email customers before WooCommerce cancels the unpaid order.
- The abandoned cart email requires a valid billing email and a captured cart snapshot.
- Recovery links are one-time use and expire 7 days after the most recent reminder is sent.
- A second reminder is available but disabled by default; enable it per the Settings section above.
- Configure the email content in `WooCommerce > Settings > Emails > Abandoned cart reminder`.
