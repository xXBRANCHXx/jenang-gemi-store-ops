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

## Notes

- The SKU database currently uses local JSON storage in `data/sku-db.json`.
- This is intended as a temporary source of truth until the production database is connected.
- Partner and executive systems should communicate with this repo through APIs and webhooks rather than shared files.
