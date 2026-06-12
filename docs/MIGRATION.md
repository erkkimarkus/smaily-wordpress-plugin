# MIGRATION.md — Upgrading from legacy Smaily plugin to Smaily Connect 2.0

This guide is for sites currently running the legacy
`sendsmaily/smaily-wordpress-plugin` (version 1.x) and upgrading to **Smaily
Connect 2.0**. If you're installing the plugin fresh on a clean WordPress site,
see `INSTALL.md` instead.

**Read this document fully before starting the upgrade.** The upgrade is designed
to be safe and reversible, but a few specifics about how the new version coexists
with the legacy plugin are important to understand in advance.

---

## TL;DR — what to expect

- **It's an in-place upgrade.** Same plugin slug, same folder, same file. The
  new version replaces the old one.
- **Your existing data and credentials persist.** Smaily account, subscriber
  sync settings, WooCommerce integration — all continue working.
- **Contact sync continues uninterrupted** during the upgrade. There's no
  window where syncing stops.
- **A new setup wizard runs the first time you open the plugin in WP-admin**
  after upgrading. The legacy admin pages are hidden, but the underlying data
  is untouched.
- **After the wizard finishes**, the new code takes over subscriber syncing and
  the legacy hooks are deactivated. You get access to new features (Backfill,
  recommendation engine integration).
- **Rollback is possible** by reinstalling the legacy version — your data is
  not destroyed.

The upgrade has been tested at the mechanism level (database, hooks, credential
handling). The first real `1.x → 2.0` install in production is your site, which
is why the verification steps below matter.

---

## Before you start

### Required reading

This document, in full. Take 10 minutes.

### Required actions

1. **Take a full backup** of your WordPress site (files + database). Any
   reputable backup plugin works (UpdraftPlus, BackWPup, your hosting provider's
   built-in backup). **Do not skip this step.**

2. **Note your current Smaily account settings**, in case you ever need to
   reconfigure manually:
   - Smaily subdomain (e.g. `mystore` from `mystore.sendsmaily.net`)
   - API username
   - Which subscribers list(s) are receiving WooCommerce contacts

3. **Verify version compatibility:**
   - WordPress: **6.6 or later** (tested up to 7.0)
   - WooCommerce: **10.0 or later** (tested up to 10.7)
   - PHP: **8.0 or later** (8.3 recommended)

   You can check these in `Tools → Site Health → Info` in WP-admin.

4. **Plan a low-traffic window** for the upgrade itself (~10 minutes of admin
   work, plus 24 hours of light observation). Choose a time when checkout
   activity is low.

---

## The upgrade procedure

There are three ways to upgrade. The recommended path is the simplest and most
reliable for most sites.

### Recommended: Upload via WP-admin

1. Go to `Plugins` in WP-admin
2. Click `Add New Plugin` → `Upload Plugin`
3. Choose the new `smaily-connect-2.0.0.zip` file
4. When asked "Replace current with uploaded?", click **Replace**
5. **Wait** for the upload and replacement to complete (typically 10-30 seconds)
6. The plugin remains active. You should see the standard "Plugin updated
   successfully" message.

### Alternative: Deactivate first, then upload

If your hosting environment doesn't allow Replace, or you want to be extra
cautious:

1. `Plugins → Installed Plugins → Smaily for WooCommerce → Deactivate`
2. `Plugins → Add New → Upload Plugin` → upload the new ZIP
3. After upload completes, `Activate` the plugin

This path may briefly interrupt subscriber syncing (a few seconds while the
plugin is deactivated). Negligible in practice.

### CLI: WP-CLI

For sites managed via SSH or CI:

```bash
wp plugin install /path/to/smaily-connect-2.0.0.zip --force
wp plugin deactivate smaily-connect
wp plugin activate smaily-connect
```

**Important:** the `deactivate` + `activate` step is **necessary** even after
`--force`, because `--force` on an already-active plugin doesn't trigger the
activation hook on its own. The plugin's built-in upgrade detector (see below)
will eventually catch this, but explicit deactivate-activate runs the
activation hook immediately and is cleaner.

---

## What happens during the upgrade

The new version contains both the legacy code (`Smaily_Connect\*` namespace)
and the new code (`Smaily\Connect\*` namespace) in the same plugin file. They
run side by side until you finish the new setup wizard. Here's what changes
immediately on activation:

| What | Change | Impact on you |
|------|--------|---------------|
| Smaily credentials | Read by both legacy and new code; not converted | None — your subdomain, username, and password keep working |
| Subscriber sync | Continues via legacy code (new code is gated until wizard finishes) | None — contacts continue flowing to Smaily |
| Admin menu | Legacy "Smaily" top-level menu is hidden; new "Smaily Connect" menu appears | The plugin's admin area looks new |
| Database tables | New tables (`smly_plus_*`, `smly_rec_*`) are created automatically | Background change; you don't need to do anything |
| Scheduled jobs | Legacy WP-Cron jobs are removed; new Action Scheduler jobs are scheduled in their place | More reliable background work; you can monitor in `Tools → Scheduled Actions` |

**Existing Smaily templates that reference fields like `{{ first_name }}`
continue to work** — the new code uses the same field-naming convention as the
legacy plugin and the official Smaily WooCommerce plugin.

---

## The first hour after upgrading

Open WP-admin within an hour of upgrading and walk through these checks. They
take about 10 minutes.

### Step 1: Confirm the new admin menu appears

1. Reload any WP-admin page
2. Look for **Smaily Connect** in the left sidebar (top-level menu, with the
   Smaily logo)
3. The legacy **Smaily** menu should be hidden

If the legacy menu is still visible after a hard reload, see Troubleshooting
below.

### Step 2: The wizard should launch

1. Click `Smaily Connect` in the sidebar
2. The setup wizard should open on Step 1 (Connection)
3. Your existing Smaily credentials (subdomain, username) should be **pre-filled**

If the wizard doesn't launch automatically, you can open it directly at
`/wp-admin/admin.php?page=smaily-connect`.

### Step 3: Verify subscriber sync is still working (without touching anything)

1. **Do not complete the wizard yet.** Sync is currently running via the legacy
   code path.
2. Open a private/incognito window and make a small WooCommerce purchase (a
   test product, low price, your own email)
3. Wait 1-2 minutes
4. Check your Smaily account — the test contact should appear, exactly as it
   did before the upgrade

If the contact arrives, **legacy sync is healthy**. You can proceed to the
wizard at your convenience.

### Step 4: Check the database

In WP-admin, go to `Tools → Site Health → Info → Database`. Verify the new
tables exist:

- `wp_smly_plus_event_queue` (or your prefix instead of `wp_`)
- `wp_smly_plus_backfill_job`
- `wp_smly_plus_automation_mapping`
- `wp_smly_rec_event_queue`
- `wp_smly_rec_visitor`

If any are missing, the activation hook may not have run. See "Activation hook
didn't fire" in Troubleshooting.

### Step 5: Check Action Scheduler

`Tools → Scheduled Actions`. Filter by `smly_plus`. You should see recurring
jobs scheduled (contact sync, abandoned cart, queue flush, retry). Status
should be `Pending` or `Complete`, not `Failed`.

---

## Completing the wizard

When you're ready (any time within the first few days after upgrading), complete
the setup wizard. This is when sync officially hands off from legacy to new code.

### What changes when you finish the wizard

The new code's hook handler activates and the legacy hooks are deregistered.
After this point:

- **Only the new code** handles new WooCommerce events (customer registration,
  account updates, checkout)
- **Existing legacy data** (credentials, queued events, automation mappings) is
  preserved unchanged
- **Smaily-side data** (your subscriber lists, templates, automations) is
  completely unaffected — the change is only in how your WordPress site **sends**
  data to Smaily, not what's stored there

### The wizard steps

1. **Connection** — your credentials are pre-filled; just verify and continue
2. **Subscriber sync** — choose which WordPress events trigger contact sync
   (registration, checkout, account updates)
3. **WooCommerce integration** — abandoned cart, welcome series, first-order
   triggers
4. **Recommendation engine** (optional) — connect to the rec engine if you have
   a setup token from Smaily
5. **Finish** — review and confirm

**The wizard saves your choices step by step**, so you can close it and return
later without losing progress.

### After Finish

You'll be redirected to the new Settings page. Subscriber sync is now running
through the new code. You can verify by repeating the test purchase from Step 3
above — the contact should still arrive in Smaily, but now through the new code
path.

---

## Important warnings

### The legacy credential guard

The legacy plugin registers a WordPress filter
(`pre_update_option_smaily_connect_api_credentials`) that **protects** the
credential record from outside modification. This is a security feature, not a
bug.

**Practical implication:** if you ever need to change your Smaily credentials
through WP-CLI (`wp option update smaily_connect_api_credentials ...`) or
direct database access, the legacy filter may reject the change. Use the
wizard or the new Settings page instead — those go through the proper code
path that the legacy guard recognizes.

### Don't skip the wizard indefinitely

While the legacy code keeps subscriber syncing alive without the wizard, the
new code's features (Backfill, recommendation engine integration, new
abandoned-cart workflow) are **locked** until the wizard finishes. There's no
penalty for using legacy sync for a few days while you familiarize yourself
with the new UI — but plan to complete the wizard within a week or two so you
can take advantage of the new features.

### Don't reinstall the legacy plugin separately

If for any reason you reinstall the legacy `sendsmaily/smaily-wordpress-plugin`
ZIP **on top of Smaily Connect 2.0**, the resulting state is undefined. The
new plugin will be overwritten by the legacy one. Always use one or the
other — not both side by side.

### Your sale-price data is read fresh, not migrated

If your store uses sale prices, you might wonder whether your existing
discount data carries over to the recommendation engine. It doesn't need to —
and that's by design.

The plugin reads sale prices **fresh from WooCommerce** every time it syncs a
product (the regular price and the sale price are both pulled from the product
at sync time). It does not convert or copy any stored discount values from the
legacy plugin.

This matters because the recommendation engine's sale model uses a
"compare-at" convention: it stores the **higher** pre-sale reference price and
compares it to the **current** price to determine whether a product is on
sale. The legacy plugin stored the **lower** on-sale price under a different
name. A literal copy of the old value into the new field would invert the
meaning — genuinely discounted products would read as "not on sale." Because
the plugin re-reads live prices from WooCommerce rather than copying old data,
this inversion never happens. Your sale prices simply work, with no migration
step on your part.

---

## Rollback — if you need to go back to legacy 1.x

The upgrade is reversible. Your data is not destroyed.

### Steps

1. `Plugins → Installed Plugins → Smaily Connect → Deactivate`
2. `Plugins → Add New → Upload Plugin` → upload the original legacy plugin ZIP
3. When asked "Replace current with uploaded?", click **Replace**
4. `Activate`

The legacy plugin will resume operation. Your `smaily_connect_*` settings are
untouched, so it picks up exactly where it left off.

### What stays behind after rollback

The new database tables (`smly_plus_*`, `smly_rec_*`) remain. They don't
interfere with the legacy plugin (which doesn't know about them), but they
take up a small amount of space.

If you want to remove them:

```sql
DROP TABLE wp_smly_plus_event_queue;
DROP TABLE wp_smly_plus_backfill_job;
DROP TABLE wp_smly_plus_automation_mapping;
DROP TABLE wp_smly_rec_event_queue;
DROP TABLE wp_smly_rec_visitor;
DELETE FROM wp_options WHERE option_name LIKE 'smly_plus_%';
DELETE FROM wp_options WHERE option_name LIKE 'smly_rec_%';
```

(Replace `wp_` with your table prefix.) Do this only after rollback is
complete and verified.

### When rollback is appropriate

- A specific feature isn't working and you need to keep your site running
  while we investigate
- You need to revert to a known-good state before a high-traffic event

Rollback is **not** a permanent solution. If you hit an issue with 2.0, please
report it (see Support below) so it can be fixed; rollback is a temporary
safety net, not the recommended end state.

---

## Troubleshooting

### Wizard didn't launch automatically

Go directly to `/wp-admin/admin.php?page=smaily-connect`. If the page exists,
the plugin is working; the auto-launch is just a redirect convenience.

### Legacy "Smaily" menu still appears

Hard-reload WP-admin (`Ctrl+Shift+R` or `Cmd+Shift+R`). If still visible,
disable browser extensions that may cache admin pages, or open in a private
window.

If the legacy menu persists across hard reloads and private windows, the
plugin's admin-menu hide hook may have failed to register. Check `Settings →
Plugins` to confirm Smaily Connect is active, and look at the debug log
(`wp-content/debug.log` if enabled) for PHP errors.

### Activation hook didn't fire (new tables missing)

This can happen with `wp plugin install --force` without a subsequent
deactivate-activate. The plugin includes an upgrade-detector that runs on the
next WP-admin page load — so the simplest fix is to **open any WP-admin page**
and the tables will be created automatically.

If they're still missing after that, manually trigger activation:

```bash
wp plugin deactivate smaily-connect
wp plugin activate smaily-connect
```

### Contact sync stopped working after the upgrade

Within the first hour, before completing the wizard:

1. Check that the legacy code is still active. Open the WP-admin Plugins page
   and verify `Smaily Connect` shows version 2.0.x and is **Active**.
2. Look at `Tools → Scheduled Actions` for any failed actions related to
   `smaily_connect_*` or `smly_plus_contact_sync`.
3. Check `wp-content/debug.log` (if `WP_DEBUG_LOG` is enabled) for PHP errors.

After completing the wizard:

1. Verify the wizard reached the Finish step successfully (you were redirected
   to the new Settings page)
2. Check `Tools → Scheduled Actions` for failed `smly_plus_*` actions
3. The new Settings page has a "Test connection" button on the Connection tab
   — run it. If it fails, your credentials may need to be re-entered.

### Wizard Finish errors

Check `wp-content/debug.log`. The most common cause is the activation hook not
having run (see above) — the wizard's Finish step expects new database tables
to exist.

If the log shows a "table doesn't exist" error, run the activation manually
(deactivate + activate), then return to the wizard.

### "Class not found" PHP errors

This usually indicates leftover files from a previous plugin version. The fix:

1. Deactivate Smaily Connect
2. SSH or FTP into the site
3. Navigate to `wp-content/plugins/smaily-connect/`
4. Confirm only the new version's files are present (the `Version:` header
   in `smaily-connect.php` should show `2.1.0-beta.1` or later)
5. If there are unexpected `.php` files from older versions, delete the entire
   `smaily-connect` directory and re-upload the new ZIP cleanly

---

## After the first 24 hours

If everything is working, you don't need to do anything special. Subscriber
sync is healthy, the new Settings page is your interface, and the legacy code
is sitting dormant.

A few good practices for the first week:

- **Check the Smaily account daily** to confirm new contacts continue arriving
  at the expected rate
- **Monitor `Tools → Scheduled Actions`** for any failures (`Failed` status
  with a `smly_plus_*` hook name)
- **Try the new features** — Backfill (syncing historical customers to
  Smaily), abandoned cart workflow, the new mobile-friendly Settings UI

If you see anything unexpected, see Support below.

---

## Support

For issues encountered during or after the migration:

- **Smaily support:** https://smaily.com/help/ (your first stop for any issue
  related to Smaily-side data, templates, or automations)
- **Plugin issues:** report on GitHub at the plugin repo (link in your install
  package), or via the maintainer contact provided to you
- **Critical urgency** (site is down, customers can't checkout): immediately
  roll back to the legacy version (see Rollback above) and contact support
  with details

When reporting an issue, please include:

- WordPress version, WooCommerce version, PHP version (from `Tools → Site
  Health → Info`)
- The plugin version (from `Plugins → Installed Plugins`)
- The build hash from your browser console: open WP-admin, open the browser
  console (F12), type `window.smailyConnectBoot?.buildHash` and copy what it
  prints
- A description of what you were doing when the issue appeared
- Any error messages from `wp-content/debug.log` if available

---

## Acknowledgments

This migration plan was designed and verified with the mechanism-level
testing described in the audit report (available in the project's
`docs/DECISIONS.md` for technical readers). The first real `1.x → 2.0`
production install is your site — your observations during the first week
are the final verification step, and your feedback shapes how this guide
evolves.

Thank you for being the pilot for Smaily Connect 2.0.
