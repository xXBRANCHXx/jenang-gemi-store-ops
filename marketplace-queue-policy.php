<?php
declare(strict_types=1);

function jg_store_ops_marketplace_label_backed(array $order): bool
{
    return !empty($order['labelBacked']) || !empty($order['label_backed']);
}

function jg_store_ops_marketplace_awaiting_collection(array $order, string $sourcePlatform): bool
{
    $platform = strtolower(trim((string) ($order['platform'] ?? $order['source_platform'] ?? $sourcePlatform)));
    $source = strtolower(trim($sourcePlatform));
    if ($source !== 'tiktok' && !str_contains($platform, 'tiktok')) {
        return false;
    }
    foreach (['status', 'marketplaceStatus', 'marketplace_status', 'orderStatus', 'order_status'] as $key) {
        $status = strtoupper(trim((string) ($order[$key] ?? '')));
        if ($status === 'AWAITING_COLLECTION') {
            return true;
        }
    }
    return false;
}

function jg_store_ops_marketplace_status(array $order): string
{
    // API-normalized rows use status=IMPORTED for Store Ops workflow state.
    // Always prefer the marketplace's own status field.
    foreach (['marketplaceStatus', 'marketplace_status', 'orderStatus', 'order_status', 'status'] as $key) {
        $value = $order[$key] ?? null;
        if (!is_scalar($value)) {
            continue;
        }
        $status = strtoupper(trim((string) $value));
        if ($status !== '') {
            return $status;
        }
    }
    return '';
}

function jg_store_ops_marketplace_pre_activation_visible(array $order, string $sourcePlatform): bool
{
    if (jg_store_ops_marketplace_label_backed($order)) {
        return false;
    }

    $platform = strtolower(trim((string) ($order['platform'] ?? $order['source_platform'] ?? $sourcePlatform)));
    $source = strtolower(trim($sourcePlatform));
    $status = jg_store_ops_marketplace_status($order);
    if ($source === 'shopee' || str_contains($platform, 'shopee')) {
        return $status === 'READY_TO_SHIP';
    }
    if ($source === 'tiktok' || str_contains($platform, 'tiktok')) {
        return in_array($status, ['AWAITING_SHIPMENT', 'SHIPMENT_PENDING'], true);
    }
    return false;
}

function jg_store_ops_marketplace_order_visible(
    array $order,
    string $sourcePlatform,
    bool $requireLabelBacked,
    bool $preActivationOnly = false
): bool
{
    if ($preActivationOnly) {
        return jg_store_ops_marketplace_pre_activation_visible($order, $sourcePlatform);
    }
    $labelBacked = jg_store_ops_marketplace_label_backed($order);
    if ($requireLabelBacked && !$labelBacked) {
        return false;
    }
    return $labelBacked || !jg_store_ops_marketplace_awaiting_collection($order, $sourcePlatform);
}

function jg_store_ops_marketplace_feed_enabled(bool $localHardSetKnown, bool $localHardSetEnabled): bool
{
    // OFF still has a read-only pre-arrangement queue. Unknown state fails closed.
    return $localHardSetKnown;
}

function jg_store_ops_marketplace_action_enabled(array $key, bool $localHardSetEnabled): bool
{
    $platform = strtolower(trim((string) ($key['source_platform'] ?? '')));
    return !in_array($platform, ['shopee', 'tiktok'], true) || $localHardSetEnabled;
}

function jg_store_ops_marketplace_requires_label_backed(
    bool $localHardSetKnown,
    bool $localHardSetEnabled,
    array $remoteMeta,
    bool $remoteMetaFresh = true,
    ?bool $localSourceAutomatic = null
): bool
{
    if (!$localHardSetKnown) {
        return true;
    }
    // The exact automatic source set is frozen when Store Ops acknowledges the
    // irreversible cutover. It remains authoritative even if a later API
    // response is missing or incorrectly says the source is manual.
    if ($localHardSetEnabled && $localSourceAutomatic === true) {
        return true;
    }
    // An active legacy projection without a frozen per-source decision cannot
    // safely expose live marketplace rows.
    if ($localHardSetEnabled && $localSourceAutomatic === null) {
        return true;
    }
    // A cached response can predate the irreversible cutover. Once the local
    // projection is ON, never trust stale metadata that says a source is still
    // manual; hiding unbacked rows during an outage is safer than exposing an
    // order whose shipment has not been arranged.
    if ($localHardSetEnabled && !$remoteMetaFresh) {
        return true;
    }
    if (array_key_exists('automatic_shipment_enabled', $remoteMeta)) {
        return !empty($remoteMeta['automatic_shipment_enabled']);
    }
    return $localHardSetEnabled || !empty($remoteMeta['hard_set']['enabled']);
}
