<?php
declare(strict_types=1);

require_once __DIR__ . '/store-ops-fulfillment-runtime.php';

function jg_store_ops_order_records_date(string $value): string
{
    $value = trim($value);
    if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        throw new InvalidArgumentException('Order Records dates must use YYYY-MM-DD.');
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Jakarta'));
    if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Order Records received an invalid date.');
    }
    return $value;
}

/**
 * @return array{date_from:string,date_to:string,start_utc:string,end_utc:string}
 */
function jg_store_ops_order_records_bounds(array $filters, ?DateTimeImmutable $now = null): array
{
    $jakarta = new DateTimeZone('Asia/Jakarta');
    $utc = new DateTimeZone('UTC');
    $today = ($now ?? new DateTimeImmutable('now', $jakarta))->setTimezone($jakarta)->setTime(0, 0);
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    $dateFrom = $dateFrom !== '' ? jg_store_ops_order_records_date($dateFrom) : $today->modify('-29 days')->format('Y-m-d');
    $dateTo = $dateTo !== '' ? jg_store_ops_order_records_date($dateTo) : $today->format('Y-m-d');

    $from = new DateTimeImmutable($dateFrom . ' 00:00:00', $jakarta);
    $to = new DateTimeImmutable($dateTo . ' 00:00:00', $jakarta);
    if ($from > $to) {
        throw new InvalidArgumentException('Date From cannot be after Date To.');
    }
    if ((int) $from->diff($to)->format('%a') > 366) {
        throw new InvalidArgumentException('Order Records supports a maximum range of 367 days.');
    }

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'start_utc' => $from->setTimezone($utc)->format('Y-m-d H:i:s'),
        'end_utc' => $to->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

function jg_store_ops_order_records_duration_label(?int $seconds): string
{
    if ($seconds === null || $seconds < 0) return '-';
    if ($seconds < 60) return $seconds . 's';
    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) return $minutes . 'm' . ($seconds % 60 > 0 ? ' ' . ($seconds % 60) . 's' : '');
    $hours = intdiv($minutes, 60);
    return $hours . 'h' . ($minutes % 60 > 0 ? ' ' . ($minutes % 60) . 'm' : '');
}

function jg_store_ops_order_records_elapsed_seconds(mixed $startedAt, mixed $fulfilledAt): ?int
{
    $startedAt = trim((string) $startedAt);
    $fulfilledAt = trim((string) $fulfilledAt);
    if ($startedAt === '' || $fulfilledAt === '') return null;
    try {
        $utc = new DateTimeZone('UTC');
        $started = new DateTimeImmutable($startedAt, $utc);
        $fulfilled = new DateTimeImmutable($fulfilledAt, $utc);
        $seconds = $fulfilled->getTimestamp() - $started->getTimestamp();
        return $seconds >= 0 ? $seconds : null;
    } catch (Throwable) {
        return null;
    }
}

function jg_store_ops_order_records_source_label(string $platform, string $account = ''): string
{
    $platform = jg_store_ops_fulfillment_normalize_key_part($platform, 32);
    $account = jg_store_ops_fulfillment_normalize_key_part($account, 96);
    if ($platform === 'whatsapp') return 'Whatsapp';
    if ($platform === 'zero_website') return 'ZERO Website';
    if ($platform === 'jenang_gemi_website') return 'Jenang Gemi Website';
    if ($platform === 'partner') {
        $name = preg_replace('/^partner-/', '', $account) ?: 'Partner';
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }

    $known = [
        'jenang-gemi-shopee' => 'JG Shopee',
        'zero-shopee' => 'ZERO Shopee',
        'zfit-shopee' => 'ZFIT Shopee',
        'jenang-gemi-tiktok' => 'JG TikTok',
        'zero-tiktok' => 'ZERO TikTok',
        'zfit-tiktok' => 'ZFIT TikTok',
    ];
    if (isset($known[$account])) return $known[$account];
    if ($account !== '' && $account !== 'default') return ucwords(str_replace(['-', '_'], ' ', $account));
    return match ($platform) {
        'shopee' => 'Shopee',
        'tiktok' => 'TikTok',
        default => $platform !== '' ? ucwords(str_replace(['-', '_'], ' ', $platform)) : 'Order',
    };
}

function jg_store_ops_order_records_customer_name(mixed $value): string
{
    if (!is_scalar($value)) return '';
    return mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) $value)), 0, 160);
}

function jg_store_ops_order_records_customer_name_from_payload(array $payload): string
{
    $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
    $buyer = is_array($payload['buyer'] ?? null) ? $payload['buyer'] : [];
    $candidates = [
        $payload['username'] ?? null,
        $payload['buyer_username'] ?? null,
        $payload['buyerUserName'] ?? null,
        $customer['username'] ?? null,
        $customer['user_name'] ?? null,
        $buyer['username'] ?? null,
        $payload['customerName'] ?? null,
        $payload['customer_name'] ?? null,
        $payload['buyerName'] ?? null,
        $payload['buyer_name'] ?? null,
        $customer['name'] ?? null,
        $customer['full_name'] ?? null,
        $customer['fullName'] ?? null,
        $buyer['name'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $name = jg_store_ops_order_records_customer_name($candidate);
        if ($name !== '') return $name;
    }
    return '';
}

function jg_store_ops_order_records_customer_key(string $platform, string $orderId): string
{
    return strtolower(trim($platform)) . "\0" . strtoupper(trim($orderId));
}

/**
 * Recover customer names for historical direct orders saved before fulfillment
 * began snapshotting the customer identifier.
 *
 * @param array<int,array<string,mixed>> $records
 * @return array<string,string>
 */
function jg_store_ops_order_records_historical_customer_names(PDO $pdo, array $records): array
{
    $orderIds = [];
    $whatsappIds = [];
    foreach ($records as $record) {
        if (trim((string) ($record['customer_name'] ?? '')) !== '') continue;
        $platform = strtolower(trim((string) ($record['source_platform'] ?? '')));
        $orderId = trim((string) ($record['order_id'] ?? ''));
        if ($orderId === '') continue;
        if (in_array($platform, ['whatsapp', 'zero_website', 'jenang_gemi_website'], true)) {
            $orderIds[strtoupper($orderId)] = $orderId;
        }
        if ($platform === 'whatsapp') $whatsappIds[strtoupper($orderId)] = $orderId;
    }
    if ($orderIds === []) return [];

    $names = [];
    $placeholders = [];
    $params = [];
    foreach (array_values($orderIds) as $index => $orderId) {
        $placeholder = ':customer_order_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $orderId;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT source_platform, order_id, payload_json
             FROM store_ops_website_orders
             WHERE order_id IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) continue;
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $name = is_array($payload) ? jg_store_ops_order_records_customer_name_from_payload($payload) : '';
            if ($name === '') continue;
            $names[jg_store_ops_order_records_customer_key(
                (string) ($row['source_platform'] ?? ''),
                (string) ($row['order_id'] ?? '')
            )] = $name;
        }
    } catch (Throwable) {
        // Older installations may not have the website-order history table.
    }

    if ($whatsappIds !== []) {
        $walkinPlaceholders = [];
        $walkinParams = [];
        foreach (array_values($whatsappIds) as $index => $orderId) {
            $placeholder = ':whatsapp_invoice_' . $index;
            $walkinPlaceholders[] = $placeholder;
            $walkinParams[$placeholder] = $orderId;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT invoice_number, customer_name
                 FROM store_ops_walkin_invoices
                 WHERE invoice_type = "whatsapp"
                   AND invoice_number IN (' . implode(', ', $walkinPlaceholders) . ')'
            );
            $stmt->execute($walkinParams);
            foreach ($stmt->fetchAll() as $row) {
                if (!is_array($row)) continue;
                $key = jg_store_ops_order_records_customer_key('whatsapp', (string) ($row['invoice_number'] ?? ''));
                if (isset($names[$key])) continue;
                $name = jg_store_ops_order_records_customer_name($row['customer_name'] ?? '');
                if ($name !== '') $names[$key] = $name;
            }
        } catch (Throwable) {
            // Direct WhatsApp invoices are optional on installations using only Executive orders.
        }
    }
    return $names;
}

function jg_store_ops_order_records_processed_join_sql(): string
{
    return 'INNER JOIN store_ops_order_events_v2 done
            ON done.id = (
                SELECT MAX(done_match.id)
                FROM store_ops_order_events_v2 done_match
                WHERE done_match.source_platform = f.source_platform
                  AND done_match.source_account = f.source_account
                  AND done_match.order_id = f.order_id
                  AND done_match.event_type = "fulfill"
            )';
}

/**
 * Resolve one completed stock ledger entry without changing inventory.
 *
 * @return array{source_platform:string,source_account:string,order_id:string}
 */
function jg_store_ops_order_records_history_repair_key(PDO $pdo, string $orderId): array
{
    $orderId = substr(trim($orderId), 0, 160);
    if ($orderId === '') {
        throw new InvalidArgumentException('Order ID is required for history repair.');
    }

    jg_store_ops_order_stock_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT source_platform, source_account, order_id
         FROM store_ops_inventory_order_deductions
         WHERE order_id = :order_id AND status = "deducted"
         ORDER BY deducted_at DESC
         LIMIT 2'
    );
    $stmt->execute([':order_id' => $orderId]);
    $rows = array_values(array_filter($stmt->fetchAll(), 'is_array'));
    if ($rows === []) {
        throw new OutOfBoundsException('No completed stock deduction exists for this order. Nothing was repaired.');
    }
    if (count($rows) > 1) {
        throw new InvalidArgumentException('This Order ID exists in more than one source. Repair it with an exact source key.');
    }

    return [
        'source_platform' => jg_store_ops_fulfillment_normalize_key_part((string) ($rows[0]['source_platform'] ?? ''), 32),
        'source_account' => jg_store_ops_fulfillment_normalize_key_part((string) ($rows[0]['source_account'] ?? ''), 96),
        'order_id' => trim((string) ($rows[0]['order_id'] ?? '')),
    ];
}

/**
 * Restore a missing completed-history event from the immutable stock ledger.
 * This function never calls a marketplace, changes stock, or reopens Listed.
 *
 * @param array{source_platform:string,source_account:string,order_id:string} $key
 * @param array<int,array<string,mixed>> $items
 * @return array{created:bool,order_id:string,source_platform:string,source_account:string,fulfilled_at:string,processed_by:string,stock_changed:false}
 */
function jg_store_ops_order_records_repair_history(
    PDO $pdo,
    array $key,
    string $repairEmployeeId,
    string $repairEmployeeName,
    array $items = [],
    string $customerName = ''
): array {
    $key = [
        'source_platform' => jg_store_ops_fulfillment_normalize_key_part((string) ($key['source_platform'] ?? ''), 32),
        'source_account' => jg_store_ops_fulfillment_normalize_key_part((string) ($key['source_account'] ?? ''), 96),
        'order_id' => substr(trim((string) ($key['order_id'] ?? '')), 0, 160),
    ];
    if ($key['source_account'] === '') $key['source_account'] = 'default';
    jg_store_ops_fulfillment_validate_key($key);

    $stockState = jg_store_ops_order_stock_state($pdo, $key);
    if (empty($stockState['deducted'])) {
        throw new DomainException('History repair requires a completed stock-deduction ledger entry. Inventory was not changed.');
    }

    $snapshot = jg_store_ops_fulfillment_items_snapshot($items);
    if ($snapshot === []) {
        $deductionItems = [];
        foreach ((array) ($stockState['deductions'] ?? []) as $deduction) {
            if (!is_array($deduction)) continue;
            $deductionItems[] = [
                'sku' => (string) ($deduction['selling_sku'] ?? $deduction['sku'] ?? $deduction['stock_sku'] ?? ''),
                'product_name' => (string) ($deduction['product_name'] ?? $deduction['selling_sku'] ?? $deduction['sku'] ?? ''),
                'quantity' => $deduction['selling_quantity'] ?? $deduction['quantity'] ?? 0,
            ];
        }
        $snapshot = jg_store_ops_fulfillment_items_snapshot($deductionItems);
    }
    $customerName = jg_store_ops_order_records_customer_name($customerName);
    $repairEmployeeId = substr(trim($repairEmployeeId), 0, 64);
    $repairEmployeeName = substr(trim($repairEmployeeName), 0, 120);
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    $pdo->beginTransaction();
    try {
        $selectSql = 'SELECT * FROM store_ops_order_fulfillment_v2
                      WHERE source_platform = :source_platform
                        AND source_account = :source_account
                        AND order_id = :order_id
                      LIMIT 1';
        if ($driver !== 'sqlite') $selectSql .= ' FOR UPDATE';
        $select = $pdo->prepare($selectSql);
        $select->execute([
            ':source_platform' => $key['source_platform'],
            ':source_account' => $key['source_account'],
            ':order_id' => $key['order_id'],
        ]);
        $row = $select->fetch();

        if (!is_array($row)) {
            $insertSql = $driver === 'sqlite'
                ? 'INSERT OR IGNORE INTO store_ops_order_fulfillment_v2
                    (source_platform, source_account, order_id, status, created_at, updated_at)
                   VALUES (:source_platform, :source_account, :order_id, "UNCLAIMED", :created_at, :updated_at)'
                : 'INSERT INTO store_ops_order_fulfillment_v2
                    (source_platform, source_account, order_id, status, created_at, updated_at)
                   VALUES (:source_platform, :source_account, :order_id, "UNCLAIMED", :created_at, :updated_at)
                   ON DUPLICATE KEY UPDATE updated_at = updated_at';
            $insert = $pdo->prepare($insertSql);
            $insert->execute([
                ':source_platform' => $key['source_platform'],
                ':source_account' => $key['source_account'],
                ':order_id' => $key['order_id'],
                ':created_at' => jg_store_ops_fulfillment_now(),
                ':updated_at' => jg_store_ops_fulfillment_now(),
            ]);
            $select->execute([
                ':source_platform' => $key['source_platform'],
                ':source_account' => $key['source_account'],
                ':order_id' => $key['order_id'],
            ]);
            $row = $select->fetch();
        }
        if (!is_array($row)) {
            throw new RuntimeException('Unable to create the local history row.');
        }
        if (strtoupper(trim((string) ($row['status'] ?? ''))) === 'CANCELLED') {
            throw new DomainException('A cancelled order cannot be restored as completed history.');
        }

        $eventParams = [
            ':source_platform' => $key['source_platform'],
            ':source_account' => $key['source_account'],
            ':order_id' => $key['order_id'],
        ];
        $existing = $pdo->prepare(
            'SELECT employee_id, employee_name, created_at
             FROM store_ops_order_events_v2
             WHERE source_platform = :source_platform
               AND source_account = :source_account
               AND order_id = :order_id
               AND event_type = "fulfill"
             ORDER BY id DESC LIMIT 1'
        );
        $existing->execute($eventParams);
        $existingEvent = $existing->fetch();
        if (is_array($existingEvent)) {
            $pdo->commit();
            return [
                'created' => false,
                'order_id' => $key['order_id'],
                'source_platform' => $key['source_platform'],
                'source_account' => $key['source_account'],
                'fulfilled_at' => (string) ($existingEvent['created_at'] ?? $row['fulfilled_at'] ?? ''),
                'processed_by' => (string) ($existingEvent['employee_name'] ?? $existingEvent['employee_id'] ?? ''),
                'stock_changed' => false,
            ];
        }

        $original = $pdo->prepare(
            'SELECT event_type, employee_id, employee_name, created_at
             FROM store_ops_order_events_v2
             WHERE source_platform = :source_platform
               AND source_account = :source_account
               AND order_id = :order_id
               AND event_type IN ("remove_from_listed", "label_print", "scan_complete", "claim", "reclaim")
             ORDER BY CASE WHEN event_type = "remove_from_listed" THEN 0 ELSE 1 END, id DESC
             LIMIT 1'
        );
        $original->execute($eventParams);
        $originalEvent = $original->fetch();
        $originalEvent = is_array($originalEvent) ? $originalEvent : [];

        $fulfilledAt = trim((string) ($row['fulfilled_at'] ?? ''))
            ?: trim((string) ($originalEvent['created_at'] ?? ''))
            ?: trim((string) ($stockState['deducted_at'] ?? ''))
            ?: jg_store_ops_fulfillment_now();
        $processedBy = trim((string) ($originalEvent['employee_id'] ?? ''))
            ?: trim((string) ($row['claimed_by'] ?? ''))
            ?: $repairEmployeeId;
        $processedByName = trim((string) ($originalEvent['employee_name'] ?? ''));
        if ($processedByName === '' && $processedBy !== '') {
            $employee = $pdo->prepare('SELECT display_name FROM store_ops_employees_v2 WHERE id = :id LIMIT 1');
            $employee->execute([':id' => $processedBy]);
            $processedByName = trim((string) ($employee->fetchColumn() ?: ''));
        }
        if ($processedByName === '') $processedByName = $repairEmployeeName !== '' ? $repairEmployeeName : $processedBy;

        $itemsJson = $snapshot !== []
            ? json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : null;
        $update = $pdo->prepare(
            'UPDATE store_ops_order_fulfillment_v2
             SET status = "FULFILLED",
                 fulfilled_at = COALESCE(fulfilled_at, :fulfilled_at),
                 last_activity_at = COALESCE(last_activity_at, :last_activity_at),
                 items_json = CASE WHEN :items_present = 1 AND (items_json IS NULL OR items_json = "") THEN :items_json ELSE items_json END,
                 customer_name = CASE WHEN :customer_present = 1 AND customer_name = "" THEN :customer_name ELSE customer_name END,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            ':fulfilled_at' => $fulfilledAt,
            ':last_activity_at' => $fulfilledAt,
            ':items_present' => is_string($itemsJson) ? 1 : 0,
            ':items_json' => $itemsJson,
            ':customer_present' => $customerName !== '' ? 1 : 0,
            ':customer_name' => $customerName,
            ':updated_at' => jg_store_ops_fulfillment_now(),
            ':id' => (int) $row['id'],
        ]);

        $payload = json_encode([
            'history_only_repair' => true,
            'stock_changed' => false,
            'stock_deducted_at' => $stockState['deducted_at'] ?? null,
            'repaired_by' => ['id' => $repairEmployeeId, 'name' => $repairEmployeeName],
            'original_event_type' => (string) ($originalEvent['event_type'] ?? ''),
            'items' => $snapshot,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $event = $pdo->prepare(
            'INSERT INTO store_ops_order_events_v2 (
                source_platform, source_account, order_id, event_type, employee_id, employee_name,
                sku, quantity, progress_scanned, progress_required, message, payload_json, created_at
             ) VALUES (
                :source_platform, :source_account, :order_id, "fulfill", :employee_id, :employee_name,
                "", 0, 0, 0, :message, :payload_json, :created_at
             )'
        );
        $event->execute($eventParams + [
            ':employee_id' => $processedBy !== '' ? $processedBy : null,
            ':employee_name' => $processedByName,
            ':message' => 'History restored from verified stock deduction; inventory and source status were unchanged.',
            ':payload_json' => $payload,
            ':created_at' => $fulfilledAt,
        ]);
        $pdo->commit();

        return [
            'created' => true,
            'order_id' => $key['order_id'],
            'source_platform' => $key['source_platform'],
            'source_account' => $key['source_account'],
            'fulfilled_at' => $fulfilledAt,
            'processed_by' => $processedByName,
            'stock_changed' => false,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function jg_store_ops_order_records_duration_start_sql(): string
{
    return 'COALESCE(
        f.claimed_at,
        (
            SELECT MAX(start_event.created_at)
            FROM store_ops_order_events_v2 start_event
            WHERE start_event.source_platform = f.source_platform
              AND start_event.source_account = f.source_account
              AND start_event.order_id = f.order_id
              AND start_event.event_type IN ("claim", "reclaim")
              AND start_event.created_at <= f.fulfilled_at
        ),
        (
            SELECT MIN(work_event.created_at)
            FROM store_ops_order_events_v2 work_event
            WHERE work_event.source_platform = f.source_platform
              AND work_event.source_account = f.source_account
              AND work_event.order_id = f.order_id
              AND work_event.event_type IN ("scan", "scan_complete", "label_print")
              AND work_event.created_at <= f.fulfilled_at
        )
    )';
}

/** @return array{where:list<string>,params:array<string,string>} */
function jg_store_ops_order_records_query_parts(array $filters): array
{
    $bounds = jg_store_ops_order_records_bounds($filters);
    $where = [
        'f.status = "FULFILLED"',
        'f.fulfilled_at IS NOT NULL',
        'f.fulfilled_at >= :start_at',
        'f.fulfilled_at < :end_at',
    ];
    $params = [':start_at' => $bounds['start_utc'], ':end_at' => $bounds['end_utc']];
    $query = substr(trim((string) ($filters['q'] ?? '')), 0, 96);
    if ($query !== '') {
        $where[] = 'f.order_id LIKE :query';
        $params[':query'] = '%' . $query . '%';
    }
    $source = substr(trim((string) ($filters['source'] ?? '')), 0, 96);
    if ($source !== '') {
        $where[] = '(f.source_platform LIKE :source_platform OR f.source_account LIKE :source_account)';
        $params[':source_platform'] = '%' . $source . '%';
        $params[':source_account'] = '%' . $source . '%';
    }
    $operator = substr(trim((string) ($filters['operator'] ?? '')), 0, 64);
    if ($operator !== '') {
        $where[] = 'COALESCE(NULLIF(done.employee_id, ""), f.claimed_by, "") = :operator';
        $params[':operator'] = $operator;
    }
    return ['where' => $where, 'params' => $params];
}

/**
 * @return array<int, array<string, mixed>>
 */
function jg_store_ops_order_records(PDO $pdo, array $filters): array
{
    $parts = jg_store_ops_order_records_query_parts($filters);
    $where = $parts['where'];
    $params = $parts['params'];

    $durationStartSql = jg_store_ops_order_records_duration_start_sql();
    $stmt = $pdo->prepare(
        'SELECT
            f.source_platform,
            f.source_account,
            f.order_id,
            f.claimed_at,
            f.scan_completed_at,
            f.label_printed_at,
            f.fulfilled_at,
            f.scan_required,
            f.scan_completed,
            f.customer_name,
            ' . $durationStartSql . ' AS processing_started_at,
            COALESCE(NULLIF(done.employee_id, ""), f.claimed_by, "") AS processed_by,
            COALESCE(NULLIF(done.employee_name, ""), employee.display_name, f.claimed_by, "") AS processed_by_name,
            (
                SELECT COUNT(*)
                FROM store_ops_order_events_v2 error_event
                WHERE error_event.source_platform = f.source_platform
                  AND error_event.source_account = f.source_account
                  AND error_event.order_id = f.order_id
                  AND error_event.event_type IN ("scan_error", "error")
            ) AS scan_error_count
         FROM store_ops_order_fulfillment_v2 f
         ' . jg_store_ops_order_records_processed_join_sql() . '
         LEFT JOIN store_ops_employees_v2 employee
           ON employee.id = COALESCE(NULLIF(done.employee_id, ""), f.claimed_by)
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY f.fulfilled_at DESC, f.id DESC
         LIMIT 500'
    );
    $stmt->execute($params);

    $records = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!is_array($row)) continue;
        $duration = jg_store_ops_order_records_elapsed_seconds($row['processing_started_at'] ?? null, $row['fulfilled_at'] ?? null);
        $records[] = [
            'source_platform' => (string) ($row['source_platform'] ?? ''),
            'source_account' => (string) ($row['source_account'] ?? ''),
            'source_label' => jg_store_ops_order_records_source_label((string) ($row['source_platform'] ?? ''), (string) ($row['source_account'] ?? '')),
            'order_id' => (string) ($row['order_id'] ?? ''),
            'processed_by' => (string) ($row['processed_by'] ?? ''),
            'processed_by_name' => (string) ($row['processed_by_name'] ?? ''),
            'customer_name' => jg_store_ops_order_records_customer_name($row['customer_name'] ?? ''),
            'claimed_at' => $row['claimed_at'] ?? null,
            'processing_started_at' => $row['processing_started_at'] ?? null,
            'scan_completed_at' => $row['scan_completed_at'] ?? null,
            'label_printed_at' => $row['label_printed_at'] ?? null,
            'fulfilled_at' => $row['fulfilled_at'] ?? null,
            'scan_required' => max(0, (int) ($row['scan_required'] ?? 0)),
            'scan_completed' => max(0, (int) ($row['scan_completed'] ?? 0)),
            'scan_error_count' => max(0, (int) ($row['scan_error_count'] ?? 0)),
            'duration_seconds' => $duration,
            'duration_label' => jg_store_ops_order_records_duration_label($duration),
        ];
    }
    $historicalCustomerNames = jg_store_ops_order_records_historical_customer_names($pdo, $records);
    foreach ($records as &$record) {
        if ($record['customer_name'] !== '') continue;
        $key = jg_store_ops_order_records_customer_key($record['source_platform'], $record['order_id']);
        $record['customer_name'] = $historicalCustomerNames[$key] ?? '';
    }
    unset($record);
    return $records;
}

/** @return array{processed:int,processed_today:int,operators:int,timed_orders:int,average_seconds:int,average_label:string} */
function jg_store_ops_order_records_summary_from_db(PDO $pdo, array $filters, ?DateTimeImmutable $now = null): array
{
    $parts = jg_store_ops_order_records_query_parts($filters);
    $jakarta = new DateTimeZone('Asia/Jakarta');
    $today = ($now ?? new DateTimeImmutable('now', $jakarta))->setTimezone($jakarta)->format('Y-m-d');
    $todayBounds = jg_store_ops_order_records_bounds(['date_from' => $today, 'date_to' => $today], $now);
    $params = $parts['params'] + [':today_start' => $todayBounds['start_utc'], ':today_end' => $todayBounds['end_utc']];
    $durationStartSql = jg_store_ops_order_records_duration_start_sql();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS processed,
                COALESCE(SUM(CASE WHEN fulfilled_at >= :today_start AND fulfilled_at < :today_end THEN 1 ELSE 0 END), 0) AS processed_today,
                COUNT(DISTINCT NULLIF(processed_by, "")) AS operators,
                COUNT(CASE WHEN processing_started_at IS NOT NULL AND processing_started_at <= fulfilled_at THEN 1 END) AS timed_orders,
                COALESCE(ROUND(AVG(CASE WHEN processing_started_at IS NOT NULL AND processing_started_at <= fulfilled_at THEN TIMESTAMPDIFF(SECOND, processing_started_at, fulfilled_at) END)), 0) AS average_seconds
         FROM (
             SELECT f.fulfilled_at,
                    COALESCE(NULLIF(done.employee_id, ""), f.claimed_by, "") AS processed_by,
                    ' . $durationStartSql . ' AS processing_started_at
             FROM store_ops_order_fulfillment_v2 f
             ' . jg_store_ops_order_records_processed_join_sql() . '
             WHERE ' . implode(' AND ', $parts['where']) . '
         ) summary_rows'
    );
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    $timed = max(0, (int) ($row['timed_orders'] ?? 0));
    $average = max(0, (int) ($row['average_seconds'] ?? 0));
    return [
        'processed' => max(0, (int) ($row['processed'] ?? 0)),
        'processed_today' => max(0, (int) ($row['processed_today'] ?? 0)),
        'operators' => max(0, (int) ($row['operators'] ?? 0)),
        'timed_orders' => $timed,
        'average_seconds' => $average,
        'average_label' => $timed > 0 ? jg_store_ops_order_records_duration_label($average) : '-',
    ];
}

/**
 * @param array<int, array<string, mixed>> $records
 * @return array{processed:int,processed_today:int,operators:int,timed_orders:int,average_seconds:int,average_label:string}
 */
function jg_store_ops_order_records_summary(array $records, ?DateTimeImmutable $now = null): array
{
    $jakarta = new DateTimeZone('Asia/Jakarta');
    $today = ($now ?? new DateTimeImmutable('now', $jakarta))->setTimezone($jakarta)->format('Y-m-d');
    $operators = [];
    $durationTotal = 0;
    $durationCount = 0;
    $processedToday = 0;
    foreach ($records as $record) {
        $operator = trim((string) ($record['processed_by'] ?? ''));
        if ($operator !== '') $operators[$operator] = true;
        $duration = $record['duration_seconds'] ?? null;
        if (is_int($duration) || is_float($duration) || (is_string($duration) && is_numeric($duration))) {
            $durationTotal += max(0, (int) $duration);
            $durationCount++;
        }
        $fulfilledAt = trim((string) ($record['fulfilled_at'] ?? ''));
        if ($fulfilledAt !== '') {
            try {
                $fulfilled = new DateTimeImmutable($fulfilledAt, new DateTimeZone('UTC'));
                if ($fulfilled->setTimezone($jakarta)->format('Y-m-d') === $today) $processedToday++;
            } catch (Throwable) {
                // An invalid legacy timestamp is left out of today's count.
            }
        }
    }
    $average = $durationCount > 0 ? (int) round($durationTotal / $durationCount) : 0;
    return [
        'processed' => count($records),
        'processed_today' => $processedToday,
        'operators' => count($operators),
        'timed_orders' => $durationCount,
        'average_seconds' => $average,
        'average_label' => $durationCount > 0 ? jg_store_ops_order_records_duration_label($average) : '-',
    ];
}

/**
 * @return array<int, array{id:string,display_name:string}>
 */
function jg_store_ops_order_records_operators(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT done.employee_id AS id,
                COALESCE(NULLIF(MAX(done.employee_name), ""), MAX(employee.display_name), done.employee_id) AS display_name
         FROM store_ops_order_events_v2 done
         LEFT JOIN store_ops_employees_v2 employee ON employee.id = done.employee_id
         WHERE done.event_type = "fulfill"
           AND done.employee_id IS NOT NULL
           AND done.employee_id <> ""
         GROUP BY done.employee_id
         ORDER BY display_name ASC'
    );
    return array_values(array_map(static fn (array $row): array => [
        'id' => (string) ($row['id'] ?? ''),
        'display_name' => (string) ($row['display_name'] ?? $row['id'] ?? ''),
    ], array_filter($stmt->fetchAll(), 'is_array')));
}

/**
 * @return array{events:array<int,array<string,mixed>>,items:array<int,array{sku:string,product_name:string,quantity:float}>,items_source:string}
 */
function jg_store_ops_order_records_detail(PDO $pdo, string $platform, string $account, string $orderId): array
{
    $platform = jg_store_ops_fulfillment_normalize_key_part($platform, 32);
    $account = jg_store_ops_fulfillment_normalize_key_part($account, 96);
    $orderId = trim($orderId);
    if ($platform === '' || $orderId === '') {
        throw new InvalidArgumentException('Order source and ID are required.');
    }

    $processed = $pdo->prepare(
        'SELECT f.items_json
         FROM store_ops_order_fulfillment_v2 f
         WHERE f.source_platform = :platform
           AND f.source_account = :account
           AND f.order_id = :order_id
           AND f.status = "FULFILLED"
           AND EXISTS (
               SELECT 1 FROM store_ops_order_events_v2 done
               WHERE done.source_platform = f.source_platform
                 AND done.source_account = f.source_account
                 AND done.order_id = f.order_id
                 AND done.event_type = "fulfill"
           )'
    );
    $params = [':platform' => $platform, ':account' => $account, ':order_id' => $orderId];
    $processed->execute($params);
    $itemsJson = $processed->fetchColumn();
    if ($itemsJson === false) {
        throw new OutOfBoundsException('Processed order record was not found.');
    }

    $stmt = $pdo->prepare(
        'SELECT event_type, employee_id, employee_name, sku, quantity,
                progress_scanned, progress_required, message, payload_json, created_at
         FROM store_ops_order_events_v2
         WHERE source_platform = :platform
           AND source_account = :account
           AND order_id = :order_id
         ORDER BY created_at ASC, id ASC
         LIMIT 500'
    );
    $stmt->execute($params);
    $events = [];
    $storedItems = json_decode(is_string($itemsJson) ? $itemsJson : '', true);
    $items = is_array($storedItems) ? jg_store_ops_fulfillment_items_snapshot($storedItems) : [];
    $itemsSource = $items !== [] ? 'snapshot' : '';
    $scannedItems = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!is_array($row)) continue;
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        $event = [
            'event_type' => (string) ($row['event_type'] ?? ''),
            'employee_id' => (string) ($row['employee_id'] ?? ''),
            'employee_name' => (string) ($row['employee_name'] ?? ''),
            'sku' => (string) ($row['sku'] ?? ''),
            'quantity' => (float) ($row['quantity'] ?? 0),
            'progress_scanned' => max(0, (int) ($row['progress_scanned'] ?? 0)),
            'progress_required' => max(0, (int) ($row['progress_required'] ?? 0)),
            'message' => (string) ($row['message'] ?? ''),
            'created_at' => $row['created_at'] ?? null,
            'payload' => is_array($payload) ? $payload : null,
        ];
        $events[] = $event;
        if ($event['event_type'] === 'scan' && $event['sku'] !== '') {
            if (!isset($scannedItems[$event['sku']])) {
                $scannedItems[$event['sku']] = [
                    'sku' => $event['sku'],
                    'product_name' => preg_replace('/\s+accepted$/i', '', $event['message']) ?: $event['sku'],
                    'quantity' => 0.0,
                ];
            }
            $scannedItems[$event['sku']]['quantity'] += max(1.0, (float) $event['quantity']);
        }
    }
    if ($items === [] && $scannedItems !== []) {
        $items = array_values($scannedItems);
        $itemsSource = 'scan_events';
    }
    return [
        'events' => $events,
        'items' => $items,
        'items_source' => $itemsSource,
    ];
}
