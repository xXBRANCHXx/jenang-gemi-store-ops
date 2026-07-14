# Jenang Gemi Store Ops

Website order ingestion starts disabled. The Executive Dashboard Hard Set activation webhook enables `zero_website` and `jenang_gemi_website` together. Configure `JG_STORE_OPS_WEBSITE_TOKEN` / `store_ops_website_token` to the same high-entropy value on both applications, or let both deployments derive it from their existing shared marketplace setup credential. Set `JG_EXECUTIVE_DASHBOARD_URL` / `executive_dashboard_url` when the dashboard is not at `https://admin.jenanggemi.com`.
Keep new deployment credentials outside Git by placing them in the ignored
`.env` or `config.runtime.php`; `/public_html/config.runtime.php` is also
loaded. The legacy Hostinger deployment still reads `config.local.php`, so do
not remove it until the live server has been migrated to an ignored runtime
file with the same values. Use `config.local.example.php` only as a template for
new deployments.

Store Ops persists website orders idempotently, re-verifies every order against the token-authenticated executive feed and cutover timestamp, resolves item SKUs through the existing SKU DB, and proxies the executive-uploaded PDF through authenticated endpoints.

Operational backend for `store.jenanggemi.com`.

## Scope

- SKU database and master catalog
- Inventory and stock thresholds
- Fulfillment, walk-in, WhatsApp, and invoice-record workflows
- Universal invoice printing by Order ID
- COGS history and operational pricing inputs
- Webhook and API integrations to external systems

## Current routes

- `/dashboard/`
- `/sku-db/`
- `/sku-db/new/`
- `/inventory/`
- `/walk-ins/`
- `/whatsapp-orders/`
- `/invoice-printer/`
- `/invoice-records/`
- `/orders/` redirects to `/invoice-records/`
- `/integrations/`
- `/logout/`
- `/api/orders/`
- `/api/order-lookup/`
- `/api/walk-ins/`
- `/api/invoice-records/`
- `/api/profile-settings/`

## Notes

- Store ops now treats `/sku-db/` as a read-only mirror of the live shared SKU MySQL database.
- New SKU creation and approvals have moved to the executive dashboard.
- `/sku-db/new/` now redirects back to `/sku-db/`.
- `/invoice-printer/` resolves any supported Order ID across walk-in, WhatsApp, website, partner, Shopee, and TikTok sources, then prints through the same invoice layout used by Invoice Records. Invoice printing is read-only and does not update metrics, stock, profiles, order status, or print history.
- The dashboard Reprint popup accepts an exact Order ID or performs live customer-profile search by username, name, phone, email, or marketplace user ID (never address). Profile results show dated shipping-label orders across Shopee, TikTok, website, and partner sources, retain the correct platform/account/package routing, and mark currently retrievable labels as Available. Selecting a shipped order whose temporary marketplace label no longer exists shows that explanation without leaving the popup.
- The fulfillment dashboard reads Shopee and TikTok queue rows through `/api/orders/`, which proxies API Ingest without exposing marketplace tokens. While Executive Hard/Big Set is off, Store Ops shows the live marketplace ready-to-ship queue. After Hard/Big Set is active, API Ingest returns the stored-label queue backed by shipment arrangement and private label storage.
- `/api/orders/` also merges partner orders from the Partner Portal feed when `store_ops_orders_token` / `JG_STORE_OPS_ORDERS_TOKEN` is configured. Override the feed URL with `partner_orders_feed_url` / `JG_PARTNER_ORDERS_FEED_URL` if needed. Partner display names are resolved from `partner_registry_url` / `JG_PARTNER_REGISTRY_URL`, which defaults to the executive dashboard public partner registry. Direct partner database access remains an optional fallback through `partner_db_*` / `JG_PARTNER_DB_*`.
- Partner shipping labels are proxied from the Partner Portal feed or `partner_portal_base_url` / `JG_PARTNER_PORTAL_BASE_URL` when uploaded by the partner portal.
- Product scanning uses a USB-COM 1D laser scanner on `/dashboard/scan/`. Store Settings uses the browser's native Web Serial picker to select, recheck, and test the station scanner. The scan page reads through browser Web Serial when available and falls back to the local serial device path `/dev/serial/by-id/usb-SCANNER_cs_SCANNER_YUNEW-if00` or `/dev/ttyACM0` when the web server has permission.
- Platform color coding is stored against the authenticated Store Ops employee profile, so a profile uses the same queue colors across stations and browsers.
- If the local serial fallback reports a permission error, add the web-server user to the Linux `dialout` group or set a udev rule for the IWARE scanner.
- To install the IWARE udev rule on the POS, run `sudo scripts/install-iware-scanner-permissions.sh`, reconnect the scanner, then use Store Settings > Scanner > Recheck / Test Scan.
