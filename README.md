# Jenang Gemi Store Ops

Website order ingestion starts disabled. The Executive Dashboard Hard Set activation webhook enables `zero_website` and `jenang_gemi_website` together. Configure `JG_STORE_OPS_WEBSITE_TOKEN` / `store_ops_website_token` to the same high-entropy value on both applications, or let both deployments derive it from their existing shared marketplace setup credential. Set `JG_EXECUTIVE_DASHBOARD_URL` / `executive_dashboard_url` when the dashboard is not at `https://admin.jenanggemi.com`.
Big Set readiness also requires an explicit `JG_MARKETPLACE_SOURCES` / `marketplace_sources` queue list. It may contain manual-only accounts, but it must include every API Ingest `MARKETPLACE_AUTO_SHIP_SOURCES` entry. An unqualified shop such as ZFIT Shopee should stay out of the list until it is authorized and tested; it is not required unless it is explicitly placed in the API Ingest automatic scope. Store Ops freezes the dashboard-provided automatic subset with the permanent cutover timestamp; those exact sources require a validated stored label even if later remote metadata or environment edits disagree. Store Ops uses `JG_SHOPEE_INGEST_SETUP_TOKEN` for Shopee and `JG_TIKTOK_INGEST_SETUP_TOKEN` for TikTok (falling back to the Shopee token only when they are intentionally shared); each credential is verified through the read-only `/fulfillment/access` contract before Store Ops accepts activation. Order-status callbacks send those credentials in authorization headers rather than query strings. An idempotent retry for an already-stored cutover remains accepted even if a later readiness check is degraded, while the first activation still fails closed.
While Big Set is OFF, Store Ops keeps a read-only pre-arrangement queue: Shopee `READY_TO_SHIP` and TikTok `AWAITING_SHIPMENT` / `SHIPMENT_PENDING` only. Shopee `PROCESSED`, TikTok `AWAITING_COLLECTION`, and every stored-label row remain hidden; marketplace print, claim, scan, and completion actions remain blocked. If the local Big Set state is unavailable, the marketplace feed fails closed. After irreversible activation, the frozen automatic sources switch to validated stored-label rows. Unrelated partner and in-store workflows remain available throughout.
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
- `/api/orders-v2/` (active fulfillment dashboard endpoint)
- `/api/orders/` (compatible legacy endpoint)
- `/api/order-lookup/`
- `/api/walk-ins/`
- `/api/invoice-records/`
- `/api/profile-settings/`

## Notes

- Store ops now treats `/sku-db/` as a read-only mirror of the live shared SKU MySQL database.
- New SKU creation and approvals have moved to the executive dashboard.
- `/sku-db/new/` now redirects back to `/sku-db/`.
- `/invoice-printer/` resolves any supported Order ID across walk-in, WhatsApp, website, partner, Shopee, and TikTok sources, then prints through the same invoice layout used by Invoice Records. Invoice printing is read-only and does not update metrics, stock, profiles, order status, or print history.
- The dashboard Reprint popup accepts an exact Order ID or performs live customer-profile search by username, name, phone, email, or marketplace user ID (never address). Search keeps all substring matches while ranking customer identifiers that start with the typed query first. Profile results show dated shipping-label orders across Shopee, TikTok, website, and partner sources, retain the correct platform/account/package routing, and mark currently retrievable labels as Available. Selecting a shipped order whose temporary marketplace label no longer exists shows that explanation without leaving the popup.
- The fulfillment dashboard reads Shopee and TikTok rows through `/api/orders-v2/`, which proxies API Ingest without exposing marketplace tokens. Big Set is enforced per source: automatic sources accept only shipment-arranged, hash-validated stored-label rows; additional sources keep the existing live manual queue. Legacy or unknown cutover responses fail closed, and stale browser marketplace rows are never actionable unless they are label-backed.
- Before TikTok shipment arrangement, the card countdown is labeled `Arrange by` and uses the shipping SLA. Once arrangement and label storage succeed, it becomes `Collection due` and uses the deadline for reaching `IN_TRANSIT`.
- `/api/orders-v2/` also merges partner orders from the Partner Portal feed when `store_ops_orders_token` / `JG_STORE_OPS_ORDERS_TOKEN` is configured. Override the feed URL with `partner_orders_feed_url` / `JG_PARTNER_ORDERS_FEED_URL` if needed. Partner display names are resolved from `partner_registry_url` / `JG_PARTNER_REGISTRY_URL`, which defaults to the executive dashboard public partner registry. Direct partner database access remains an optional fallback through `partner_db_*` / `JG_PARTNER_DB_*`.
- Partner shipping labels are proxied from the Partner Portal feed or `partner_portal_base_url` / `JG_PARTNER_PORTAL_BASE_URL` when uploaded by the partner portal.
- Product scanning uses a USB-COM 1D laser scanner on `/dashboard/scan/`. Store Settings uses the browser's native Web Serial picker to select, recheck, and test the station scanner. The scan page reads through browser Web Serial when available and falls back to the local serial device path `/dev/serial/by-id/usb-SCANNER_cs_SCANNER_YUNEW-if00` or `/dev/ttyACM0` when the web server has permission.
- Platform color coding is stored against the authenticated Store Ops employee profile, so a profile uses the same queue colors across stations and browsers.
- If the local serial fallback reports a permission error, add the web-server user to the Linux `dialout` group or set a udev rule for the IWARE scanner.
- To install the IWARE udev rule on the POS, run `sudo scripts/install-iware-scanner-permissions.sh`, reconnect the scanner, then use Store Settings > Scanner > Recheck / Test Scan.
