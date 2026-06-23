# Jenang Gemi Store Ops

Website order ingestion starts disabled. The Executive Dashboard Hard Set activation webhook enables `zero_website` and `jenang_gemi_website` together. Configure `JG_STORE_OPS_WEBSITE_TOKEN` / `store_ops_website_token` to the same high-entropy value on both applications, or let both deployments derive it from their existing shared marketplace setup credential. Set `JG_EXECUTIVE_DASHBOARD_URL` / `executive_dashboard_url` when the dashboard is not at `https://admin.jenanggemi.com`.
Keep deployment credentials outside Git by placing them in the ignored `.env`
or `config.runtime.php`; `/public_html/config.runtime.php` is also loaded.

Store Ops persists website orders idempotently, re-verifies every order against the token-authenticated executive feed and cutover timestamp, resolves item SKUs through the existing SKU DB, and proxies the executive-uploaded PDF through authenticated endpoints.

Operational backend for `store.jenanggemi.com`.

## Scope

- SKU database and master catalog
- Inventory and stock thresholds
- Orders and order-edit workflow
- COGS history and operational pricing inputs
- Webhook and API integrations to external systems

## Current routes

- `/dashboard/`
- `/sku-db/`
- `/sku-db/new/`
- `/inventory/`
- `/orders/`
- `/integrations/`
- `/logout/`
- `/api/orders/`

## Notes

- Store ops now treats `/sku-db/` as a read-only mirror of the live shared SKU MySQL database.
- New SKU creation and approvals have moved to the executive dashboard.
- `/sku-db/new/` now redirects back to `/sku-db/`.
- The fulfillment dashboard reads Shopee and TikTok queue rows through `/api/orders/`, which proxies API Ingest without exposing marketplace tokens. Marketplace rows are accepted only when API Ingest confirms shipment arrangement and successful private label storage (`shipmentArranged` + `labelReady`); status-ready orders and stale cached pre-launch responses are rejected.
- `/api/orders/` also merges partner orders from the Partner Portal feed when `store_ops_orders_token` / `JG_STORE_OPS_ORDERS_TOKEN` is configured. Override the feed URL with `partner_orders_feed_url` / `JG_PARTNER_ORDERS_FEED_URL` if needed. Partner display names are resolved from `partner_registry_url` / `JG_PARTNER_REGISTRY_URL`, which defaults to the executive dashboard public partner registry. Direct partner database access remains an optional fallback through `partner_db_*` / `JG_PARTNER_DB_*`.
- Partner shipping labels are proxied from the Partner Portal feed or `partner_portal_base_url` / `JG_PARTNER_PORTAL_BASE_URL` when uploaded by the partner portal.
- Product scanning now uses a USB-COM 1D laser scanner on `/dashboard/scan/`. Store Settings persists the intended IWARE X-Series 101 configuration and lists the matching V6.2-1D manual setup codes to scan. The scan page reads through browser Web Serial when available and falls back to the local serial device path `/dev/serial/by-id/usb-SCANNER_cs_SCANNER_YUNEW-if00` or `/dev/ttyACM0` when the web server has permission.
- If the local serial fallback reports a permission error, add the web-server user to the Linux `dialout` group or set a udev rule for the IWARE scanner.
- To install the IWARE udev rule on the POS, run `sudo scripts/install-iware-scanner-permissions.sh`, reconnect the scanner, then use Store Settings > Scanner > Recheck / Test Scan.
