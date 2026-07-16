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
marketplace_queue_expect(true, jg_store_ops_marketplace_order_visible(['platform' => 'Shopee', 'marketplaceStatus' => 'PROCESSED', 'labelBacked' => true], 'shopee', true), 'Hard Set must retain validated stored-label rows.');
marketplace_queue_expect(true, jg_store_ops_marketplace_requires_label_backed(true, true, ['automatic_shipment_enabled' => true], true, true), 'An automatic source must be stored-label-only.');
marketplace_queue_expect(true, jg_store_ops_marketplace_requires_label_backed(true, true, ['automatic_shipment_enabled' => false], true, true), 'A frozen automatic source must ignore a contradictory fresh remote manual flag.');
marketplace_queue_expect(false, jg_store_ops_marketplace_requires_label_backed(true, true, ['automatic_shipment_enabled' => false], true, false), 'An explicitly manual source must keep its live queue after global activation.');
marketplace_queue_expect(true, jg_store_ops_marketplace_requires_label_backed(true, true, ['automatic_shipment_enabled' => false], false, false), 'Stale pre-cutover metadata must fail closed after activation.');
marketplace_queue_expect(true, jg_store_ops_marketplace_requires_label_backed(true, true, ['hard_set' => ['enabled' => true]], true, null), 'A legacy active projection without frozen sources must fail closed.');
marketplace_queue_expect(true, jg_store_ops_marketplace_requires_label_backed(false, false, ['automatic_shipment_enabled' => false]), 'Unknown local state must fail closed.');
marketplace_queue_expect(false, jg_store_ops_marketplace_feed_enabled(true, false), 'Big Set OFF must hide every marketplace feed.');
marketplace_queue_expect(false, jg_store_ops_marketplace_feed_enabled(false, false), 'Unknown Big Set state must hide every marketplace feed.');
marketplace_queue_expect(true, jg_store_ops_marketplace_feed_enabled(true, true), 'Marketplace feeds may load only after Big Set activation.');
marketplace_queue_expect(false, jg_store_ops_marketplace_action_enabled(['source_platform' => 'shopee'], false), 'Big Set OFF must reject Shopee actions.');
marketplace_queue_expect(false, jg_store_ops_marketplace_action_enabled(['source_platform' => 'tiktok'], false), 'Big Set OFF must reject TikTok actions.');
marketplace_queue_expect(true, jg_store_ops_marketplace_action_enabled(['source_platform' => 'partner'], false), 'Big Set OFF must not block unrelated partner work.');

echo "marketplace-queue-policy-test: ok\n";
