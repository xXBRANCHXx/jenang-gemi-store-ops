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
