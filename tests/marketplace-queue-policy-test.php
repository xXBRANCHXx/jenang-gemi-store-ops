<?php
declare(strict_types=1);

require dirname(__DIR__) . '/marketplace-queue-policy.php';

function marketplace_queue_expect(bool $expected, bool $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

$awaitingCollection = ['platform' => 'TikTok', 'marketplaceStatus' => 'AWAITING_COLLECTION'];
marketplace_queue_expect(false, jg_store_ops_marketplace_order_visible($awaitingCollection, 'tiktok', false), 'An unbacked legacy collection row must stay hidden.');
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible($awaitingCollection + ['labelBacked' => true], 'tiktok', false), 'A stored-label collection row must remain visible in Store Ops.');
marketplace_queue_expect(false, jg_store_ops_marketplace_order_visible(['platform' => 'Shopee', 'marketplaceStatus' => 'READY_TO_SHIP'], 'shopee', true), 'Hard Set must reject cached live rows without stored labels.');
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible(['platform' => 'Shopee', 'marketplaceStatus' => 'READY_TO_SHIP', 'instant' => true, 'manualArrangementRequired' => true, 'instantArrangementState' => 'required'], 'shopee', true), 'An unarranged Instant order must remain visible for its manual action.');
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible(['platform' => 'Shopee', 'marketplaceStatus' => 'READY_TO_SHIP', 'instant' => true, 'manualArrangementRequired' => false, 'instantArrangementState' => 'display_only'], 'shopee', true), 'A display-only Instant order must remain visible without a stored label.');
marketplace_queue_expect(false, jg_store_ops_marketplace_action_enabled(['source_platform' => 'shopee', 'instant' => true, 'action' => 'arrange_instant_shipment'], true), 'Store Ops must reject Instant processing actions.');
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible(['platform' => 'Shopee', 'marketplaceStatus' => 'PROCESSED', 'instant' => true, 'instantArrangementState' => 'label_pending'], 'shopee', true), 'An arranged Instant order must remain visible while Store Ops processing is incomplete.');
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible(['platform' => 'Shopee', 'marketplaceStatus' => 'IN_CANCEL', 'cancellationRequested' => true], 'shopee', true), 'A cancellation request must remain visible without a stored label.');
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible(['platform' => 'Shopee', 'marketplaceStatus' => 'PROCESSED', 'labelBacked' => true], 'shopee', true), 'Marketplace PROCESSED means arranged and must remain Listed until Store Ops processes it.');
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible(['platform' => 'Shopee', 'marketplaceStatus' => 'Shipped', 'labelBacked' => true], 'shopee', true), 'Marketplace shipping progress must not erase an order Store Ops has not processed.');
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible(['platform' => 'TikTok', 'shipping_status' => 'SHIPPED', 'labelBacked' => true], 'tiktok', true), 'A label-backed TikTok order must remain Listed until Store Ops records fulfillment.');
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible(['platform' => 'Shopee', 'marketplaceStatus' => 'TO_CONFIRM_RECEIVE', 'labelBacked' => true], 'shopee', true), 'Buyer receipt state must not substitute for Store Ops processing state.');
marketplace_queue_expect(true, jg_store_ops_marketplace_requires_label_backed(true, true, ['automatic_shipment_enabled' => true], true, true), 'An automatic source must be stored-label-only.');
marketplace_queue_expect(false, jg_store_ops_marketplace_requires_label_backed(true, true, ['automatic_shipment_enabled' => false], true, true, true), 'A paused frozen source must expose its unarranged live rows read-only.');
marketplace_queue_expect(true, jg_store_ops_marketplace_requires_label_backed(true, true, ['automatic_shipment_enabled' => false], true, true), 'A frozen automatic source must ignore a contradictory fresh remote manual flag.');
marketplace_queue_expect(false, jg_store_ops_marketplace_requires_label_backed(true, true, ['automatic_shipment_enabled' => false], true, false), 'An explicitly manual source must keep its live queue after global activation.');
marketplace_queue_expect(true, jg_store_ops_marketplace_requires_label_backed(true, true, ['automatic_shipment_enabled' => false], false, false), 'Stale pre-cutover metadata must fail closed after activation.');
marketplace_queue_expect(true, jg_store_ops_marketplace_requires_label_backed(true, true, ['hard_set' => ['enabled' => true]], true, null), 'A legacy active projection without frozen sources must fail closed.');
marketplace_queue_expect(true, jg_store_ops_marketplace_requires_label_backed(false, false, ['automatic_shipment_enabled' => false]), 'Unknown local state must fail closed.');
marketplace_queue_expect(true, jg_store_ops_marketplace_feed_enabled(true, false), 'Big Set OFF must keep the read-only pre-arrangement feed visible.');
marketplace_queue_expect(false, jg_store_ops_marketplace_feed_enabled(false, false), 'Unknown Big Set state must hide every marketplace feed.');
marketplace_queue_expect(true, jg_store_ops_marketplace_feed_enabled(true, true), 'Marketplace feeds must remain visible after Big Set activation.');
$offShopeeReady = ['platform' => 'Shopee', 'status' => 'IMPORTED', 'marketplaceStatus' => 'READY_TO_SHIP'];
$offShopeeProcessed = ['platform' => 'Shopee', 'status' => 'IMPORTED', 'marketplaceStatus' => 'PROCESSED'];
$offTikTokPending = ['platform' => 'TikTok', 'status' => 'IMPORTED', 'marketplaceStatus' => 'SHIPMENT_PENDING'];
$offTikTokAwaitingShipment = ['platform' => 'TikTok', 'status' => 'IMPORTED', 'marketplaceStatus' => 'AWAITING_SHIPMENT'];
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible($offShopeeReady, 'shopee', false, true), 'Big Set OFF must show Shopee READY_TO_SHIP.');
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible(['platform' => 'Shopee', 'marketplaceStatus' => 'IN_CANCEL'], 'shopee', false, true), 'Big Set OFF must show Shopee cancellation requests.');
marketplace_queue_expect(false, jg_store_ops_marketplace_order_visible($offShopeeProcessed, 'shopee', false, true), 'Big Set OFF must hide Shopee PROCESSED.');
marketplace_queue_expect(false, jg_store_ops_marketplace_order_visible($offShopeeReady + ['labelBacked' => true], 'shopee', false, true), 'Big Set OFF must hide stored-label rows even when their marketplace status is stale.');
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible($offTikTokPending, 'tiktok', false, true), 'Big Set OFF must show TikTok SHIPMENT_PENDING.');
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible($offTikTokAwaitingShipment, 'tiktok', false, true), 'Big Set OFF must show TikTok AWAITING_SHIPMENT.');
marketplace_queue_expect(false, jg_store_ops_marketplace_order_visible($awaitingCollection, 'tiktok', false, true), 'Big Set OFF must hide TikTok AWAITING_COLLECTION.');
$ordersApi = file_get_contents(dirname(__DIR__) . '/api/orders/index.php');
$ordersV2Api = file_get_contents(dirname(__DIR__) . '/api/orders-v2/index.php');
marketplace_queue_expect(
    true,
    is_string($ordersApi)
        && is_string($ordersV2Api)
        && str_contains($ordersApi, '$localAutomationPaused || !$localHardSetEnabled')
        && str_contains($ordersV2Api, '$localAutomationPaused || !$localHardSetEnabled'),
    'Hard Set PAUSED must use the same unarranged-only marketplace queue as Big Set OFF.'
);
marketplace_queue_expect(false, jg_store_ops_marketplace_action_enabled(['source_platform' => 'shopee'], false), 'Big Set OFF must reject Shopee actions.');
marketplace_queue_expect(false, jg_store_ops_marketplace_action_enabled(['source_platform' => 'tiktok'], false), 'Big Set OFF must reject TikTok actions.');
marketplace_queue_expect(true, jg_store_ops_marketplace_action_enabled(['source_platform' => 'partner'], false), 'Big Set OFF must not block unrelated partner work.');
marketplace_queue_expect(false, jg_store_ops_marketplace_action_enabled(['source_platform' => 'shopee', 'cancellation_requested' => true], true), 'Cancellation-requested marketplace orders must block Store Ops processing.');
marketplace_queue_expect(false, jg_store_ops_marketplace_action_enabled(['source_platform' => 'shopee'], true), 'An unarranged regular marketplace row must remain read-only.');
marketplace_queue_expect(true, jg_store_ops_marketplace_action_enabled(['source_platform' => 'shopee', 'label_backed' => true], true, true), 'Pausing automatic arrangement must not block work on an already arranged label-backed order.');
marketplace_queue_expect(false, jg_store_ops_marketplace_action_enabled(['source_platform' => 'shopee', 'instant' => true, 'action' => 'arrange_instant_shipment'], true, true), 'Instant processing must remain blocked while automatic arrangement is paused.');
marketplace_queue_expect(true, jg_store_ops_marketplace_action_enabled(['source_platform' => 'shopee', 'action' => 'release_order'], false, true), 'A profile must be able to release its local claim even when marketplace automation is unavailable.');
marketplace_queue_expect(true, jg_store_ops_marketplace_action_enabled(['source_platform' => 'partner'], false, true), 'Pausing Big Set automation must not block unrelated Partner orders.');

echo "marketplace-queue-policy-test: ok\n";
