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
