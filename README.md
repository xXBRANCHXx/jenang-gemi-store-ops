# Jenang Gemi Store Ops

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
- The fulfillment dashboard reads live Shopee `READY_TO_SHIP` orders through `/api/orders/`, which proxies the ingest service without exposing Shopee tokens to browser JavaScript.
- `/api/orders/` also merges partner orders from the Partner Portal feed when `store_ops_orders_token` / `JG_STORE_OPS_ORDERS_TOKEN` is configured. Override the feed URL with `partner_orders_feed_url` / `JG_PARTNER_ORDERS_FEED_URL` if needed. Partner display names are resolved from `partner_registry_url` / `JG_PARTNER_REGISTRY_URL`, which defaults to the executive dashboard public partner registry. Direct partner database access remains an optional fallback through `partner_db_*` / `JG_PARTNER_DB_*`.
- Partner shipping labels are proxied from the Partner Portal feed or `partner_portal_base_url` / `JG_PARTNER_PORTAL_BASE_URL` when uploaded by the partner portal.
- Product scanning now uses a USB-COM 1D laser scanner on `/dashboard/scan/`. Store Settings persists the intended IWARE X-Series 101 configuration and lists the matching V6.2-1D manual setup codes to scan. The scan page reads through browser Web Serial when available and falls back to the local serial device path `/dev/serial/by-id/usb-SCANNER_cs_SCANNER_YUNEW-if00` or `/dev/ttyACM0` when the web server has permission.
- If the local serial fallback reports a permission error, add the web-server user to the Linux `dialout` group or set a udev rule for the IWARE scanner.
- To install the IWARE udev rule on the POS, run `sudo scripts/install-iware-scanner-permissions.sh`, reconnect the scanner, then use Store Settings > Scanner > Recheck / Test Scan.
