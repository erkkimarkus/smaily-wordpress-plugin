# Smaily Connect — Install & Setup Guide

This guide walks a store owner through installing **Smaily Connect (BETA)**,
connecting it to Smaily, syncing your WooCommerce data, and confirming everything
works. No code or command line is required.

> This is the operational install + verify guide. A formal pilot-acceptance test
> plan (business pass/fail criteria) is tracked separately in `TESTING.md`.

---

## 1. Install the plugin

### Requirements

| Component | Minimum | Tested up to |
|-----------|---------|--------------|
| WordPress | 6.6 | 7.0 |
| WooCommerce | 6.9 | 10.7 |
| PHP | 8.0 | 8.3 |
| HTTPS | required (Smaily + recommendations APIs are HTTPS-only) | — |
| Smaily account | active, with API access | — |

You will also need, from your Smaily account:

- Your Smaily **subdomain**, **API username**, and **API password**
  (Smaily → Settings → API).
- *(Optional, for product recommendations)* a **setup URL** from
  **Smaily admin → Recommendations** — a one-time link of the form
  `https://<host>/setup/<token>`.

### Steps

1. Download the release ZIP — `smaily-connect-2.0.0-beta.x.zip` — provided to you
   (the BETA is distributed as a ZIP, not through the WordPress.org directory yet).
2. In WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the ZIP and click **Install Now**, then **Activate**.
4. A new **Smaily Connect** item appears in the left admin sidebar. Click it to
   open the **Setup wizard**.

*(Screenshot: Plugins → Upload Plugin screen.)*

> If you already run an older Smaily plugin, this one installs alongside it and
> takes over once you finish the setup wizard — your existing contact sync keeps
> working until then.

---

## 2. Set up (the wizard)

Opening **Smaily Connect** lands you on a 6-step wizard. Until you finish it,
clicking **Settings** sends you back to the wizard — so just work through the
steps in order.

### Step 1 — Connect

- Enter your Smaily **subdomain**, **API username**, and **API password**, then
  click **Test connection**. You should see a green confirmation.
- *(Optional)* Under **Recommendations engine**, paste the **setup URL** from
  *Smaily admin → Recommendations* and click **Connect**. You'll see
  **"Connected to tenant: <your shop>"**. Skip this if you're not using product
  recommendations yet — you can add it later from Settings.

### Step 2 — Subscribers

- Keep **Sync contacts to Smaily** on, and tick the customer fields you want
  synced (name, phone, etc.).
- Optionally show a newsletter opt-in checkbox during registration / checkout.
- Under **Import existing users**, click **Start backfill** to send your current
  customers to Smaily. A progress bar shows how it's going.

### Step 3 — WooCommerce automations

- Turn on **Welcome email**, **First-order email**, and/or **Abandoned-cart
  reminders**, and pick the Smaily workflow each should trigger.

### Step 4 — Recommendations *(only if you connected the engine in Step 1)*

- **Syncing is automatic.** Once connected, your **products, customers, and
  orders** sync to the recommendation engine on their own — there's nothing to
  switch on per data type.
- **Import existing data:** run the **Products**, **Customers**, and **Orders**
  backfills (one **Start** each) so the engine has history to learn from.
- **Browsing telemetry (opt-in):** the **"Track browsing behavior"** toggle is
  **off by default**. It powers "similar products" recommendations from what
  shoppers view. It is **consent-gated** — browse events only fire for visitors
  who have granted marketing consent through a supported consent plugin:
  - **WP Consent API** (WordPress 6.5+), **CookieYes**, **Complianz**, or
    **Cookiebot**.
  - If your store has **no consent banner**, browse tracking collects nothing
    even with the toggle on. Server-side data (orders, customers, products) is
    unaffected — it always syncs.

  > Turning the toggle **on** = *you* allow browse tracking. The shopper's
  > cookie-consent banner is the second gate: both must say yes before any browse
  > event is sent.

### Step 5 — Integrations

- Informational only (Elementor, Contact Form 7, Landing Pages links). Nothing to
  configure here.

### Step 6 — Done

- Review the summary of what you activated and click **Finish**. This unlocks the
  **Settings** tabs for ongoing management.

---

## 3. Verify it's working

After finishing the wizard, open **Smaily Connect → Settings**.

1. **Connection** — the Connection tab shows **✓ Connected** (Smaily, and the
   recommendation tenant if you connected it). Use **Test connection** to
   re-check at any time.
2. **Backfill completed cleanly** — on the Subscribers and Recommendations tabs,
   each backfill panel should read **"N synced of N"** with **0 failed**. (The
   count is engine-confirmed — it reflects records the destination actually
   accepted, not just records read.)
3. **Event Log is healthy** — open **Settings → Event Log**. Recent rows should
   show status **sent**. If you see **failed** rows, see Troubleshooting below.
4. **Live smoke test** — edit any product (e.g. change its price) and **Update**
   it. Within about a minute, **Settings → Event Log** should show a
   `catalog.upsert` row for that product flip to **sent**. That confirms live
   sync end-to-end.

*(Screenshot: Event Log tab showing recent "sent" rows.)*

---

## 4. Troubleshooting — "X didn't sync"

Almost everything is diagnosable from **Settings → Event Log**.

### Find the failure

1. Open **Settings → Event Log**.
2. Set the **Status** filter to **Failed** (or click **View only failed** in the
   red banner if one is shown).
3. Read the **Last error** column. Click **Details** on a row for the full error
   and the event payload. Common patterns:
   - `http_4xx` / `d6_item_error …` — the record was rejected (e.g. a product with
     **no SKU**, which the engine requires). Fix the record, then retry.
   - `http_5xx` / a network/timeout error — a temporary outage; a retry usually
     succeeds.

### Recover

- Click **Retry** on a single failed row, or **Retry all failed** in the banner.
  Retried rows are re-queued and re-sent within a minute — the row flips to
  **pending**, then **sent**.

### Proactive notices

Smaily Connect runs a periodic health check and shows a red admin notice when
something needs attention. You may see:

- **"N sync events failed in the last 24 hours"** — open the Event Log, review the
  failed rows, fix and retry. The notice clears itself once the count drops.
- **"The recommendation engine has been unreachable for over an hour"** — the
  engine is down; sync is **queued** and resumes automatically when it recovers.
  Nothing to do unless it persists.
- **"The Smaily API has been unreachable for over an hour"** — contact sync and
  email automations are paused; check your Smaily credentials (Connection tab →
  Test connection) and that your server can reach Smaily over HTTPS.

Each notice links to the Event Log and can be **dismissed** (it stays hidden for
24 hours, then reappears if the problem is still present).

### Common situations

| Symptom | Likely cause | What to do |
|---------|-------------|-----------|
| Nothing syncs at all | Not connected / wizard not finished | Settings → Connection → Test connection; finish the wizard |
| Products rejected | Missing SKU | Add SKUs to the products; retry from the Event Log |
| Browse "similar products" empty | Browse tracking off, or no shopper consent | Enable **Track browsing behavior** (Step 4) **and** install a consent banner |
| A backfill shows fewer "synced" than total | Some rows failed | Event Log → filter Failed → fix → Retry |

If a problem persists after retrying and the connection tests succeed, capture a
screenshot of the failed row's **Details** (the error + payload) for support.

---

## Where things live (quick reference)

| You want to… | Go to |
|---|---|
| Re-run setup | Smaily Connect → **Setup wizard** |
| Change credentials / reconnect | Settings → **Connection** |
| Re-run a backfill | Settings → **Subscribers** / **Recommendations** |
| See what synced / failed, retry | Settings → **Event Log** |
| Turn browse tracking on/off | Settings → **Recommendations** |
