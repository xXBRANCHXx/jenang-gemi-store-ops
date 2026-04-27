<?php
declare(strict_types=1);

require_once __DIR__ . '/sku-db-bootstrap.php';

function jg_store_ops_transaction_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function jg_store_ops_transactions_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `Transaction_Table` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            import_group_id VARCHAR(40) NOT NULL,
            invoice_number VARCHAR(64) NOT NULL,
            po_number VARCHAR(64) NOT NULL DEFAULT "",
            po_context VARCHAR(64) NOT NULL DEFAULT "",
            source_order VARCHAR(64) NOT NULL DEFAULT "",
            source_reference VARCHAR(64) NOT NULL DEFAULT "",
            sku VARCHAR(12) NOT NULL DEFAULT "",
            item_tag VARCHAR(255) NOT NULL,
            item_description VARCHAR(255) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            cogs DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            source_file VARCHAR(255) NOT NULL DEFAULT "",
            raw_text_hash CHAR(64) NOT NULL,
            is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(80) NOT NULL DEFAULT "admin",
            created_at DATETIME NOT NULL,
            KEY idx_transaction_invoice (invoice_number),
            KEY idx_transaction_po_sku (po_number, sku),
            KEY idx_transaction_sku_created (sku, created_at),
            KEY idx_transaction_group (import_group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function jg_store_ops_transactions_normalize_key(string $value): string
{
    $value = preg_replace('/\[[^\]]+\]/', ' ', $value) ?? $value;
    $value = strtoupper($value);
    return preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
}

function jg_store_ops_transactions_money_to_float(string $value): float
{
    $clean = preg_replace('/[^0-9,.-]+/', '', $value) ?? '';
    if (str_contains($clean, ',') && str_contains($clean, '.')) {
        $clean = str_replace(',', '', $clean);
    } elseif (substr_count($clean, ',') === 1 && !str_contains($clean, '.')) {
        $clean = str_replace(',', '.', $clean);
    } else {
        $clean = str_replace(',', '', $clean);
    }

    return round((float) $clean, 2);
}

function jg_store_ops_transactions_number_to_float(string $value): float
{
    return jg_store_ops_transactions_money_to_float($value);
}

function jg_store_ops_transactions_command_exists(string $command): bool
{
    if (!function_exists('shell_exec')) {
        return false;
    }

    $path = trim((string) @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null'));
    return $path !== '';
}

function jg_store_ops_transactions_extract_text_with_pdftotext(string $path): string
{
    if (!function_exists('proc_open') || !function_exists('proc_close') || !jg_store_ops_transactions_command_exists('pdftotext')) {
        return '';
    }

    $command = 'pdftotext -layout ' . escapeshellarg($path) . ' -';
    $pipes = [];
    $process = @proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        return '';
    }

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== 0 || !is_string($output) || trim($output) === '') {
        return is_string($error) ? '' : '';
    }

    return $output;
}

function jg_store_ops_transactions_decode_pdf_literal(string $value): string
{
    $decoded = '';
    $length = strlen($value);
    for ($i = 0; $i < $length; $i++) {
        $char = $value[$i];
        if ($char !== '\\') {
            $decoded .= $char;
            continue;
        }

        $i++;
        if ($i >= $length) {
            break;
        }

        $escaped = $value[$i];
        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\b", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
        if (isset($map[$escaped])) {
            $decoded .= $map[$escaped];
            continue;
        }

        if (preg_match('/[0-7]/', $escaped) === 1) {
            $octal = $escaped;
            for ($j = 0; $j < 2 && $i + 1 < $length && preg_match('/[0-7]/', $value[$i + 1]) === 1; $j++) {
                $i++;
                $octal .= $value[$i];
            }
            $decoded .= chr(octdec($octal));
            continue;
        }

        $decoded .= $escaped;
    }

    return $decoded;
}

function jg_store_ops_transactions_decode_pdf_hex(string $value): string
{
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $value) ?? '';
    if ($hex === '') {
        return '';
    }

    if (strlen($hex) % 2 === 1) {
        $hex .= '0';
    }

    $binary = (string) @hex2bin($hex);
    return str_replace("\0", '', $binary);
}

function jg_store_ops_transactions_extract_text_from_stream(string $stream): string
{
    $chunks = [];
    if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)\s*Tj/s', $stream, $matches)) {
        foreach ($matches[1] as $text) {
            $chunks[] = jg_store_ops_transactions_decode_pdf_literal((string) $text);
        }
    }

    if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*Tj/s', $stream, $matches)) {
        foreach ($matches[1] as $text) {
            $chunks[] = jg_store_ops_transactions_decode_pdf_hex((string) $text);
        }
    }

    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $stream, $matches)) {
        foreach ($matches[1] as $arrayBody) {
            if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)|<([0-9A-Fa-f\s]+)>/s', (string) $arrayBody, $parts, PREG_SET_ORDER)) {
                $line = '';
                foreach ($parts as $part) {
                    $line .= isset($part[1]) && $part[1] !== ''
                        ? jg_store_ops_transactions_decode_pdf_literal((string) $part[1])
                        : jg_store_ops_transactions_decode_pdf_hex((string) ($part[2] ?? ''));
                }
                $chunks[] = $line;
            }
        }
    }

    return trim(implode("\n", array_filter($chunks)));
}

function jg_store_ops_transactions_extract_text_fallback(string $path): string
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return '';
    }

    $text = [];
    if (preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $dictionary = (string) $match[1];
            $stream = (string) $match[2];
            if (stripos($dictionary, '/FlateDecode') !== false && function_exists('zlib_decode')) {
                $decoded = @zlib_decode($stream);
                if (is_string($decoded)) {
                    $stream = $decoded;
                }
            }

            $streamText = jg_store_ops_transactions_extract_text_from_stream($stream);
            if ($streamText !== '') {
                $text[] = $streamText;
            } elseif (stripos($stream, 'CrossIndustryInvoice') !== false || stripos($stream, '<rsm:') !== false) {
                $text[] = trim($stream);
            }
        }
    }

    return trim(implode("\n", $text));
}

function jg_store_ops_transactions_extract_invoice_text(string $path): string
{
    $text = jg_store_ops_transactions_extract_text_with_pdftotext($path);
    if (trim($text) !== '') {
        return $text;
    }

    $text = jg_store_ops_transactions_extract_text_fallback($path);
    if (trim($text) !== '') {
        return $text;
    }

    throw new RuntimeException('Unable to extract readable text from the PDF.');
}

function jg_store_ops_transactions_xml_value(string $xml, string $pattern): string
{
    if (!preg_match($pattern, $xml, $match)) {
        return '';
    }

    return trim(html_entity_decode(strip_tags((string) ($match[1] ?? '')), ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

function jg_store_ops_transactions_parse_invoice_xml(string $text, string $sourceFile = ''): ?array
{
    if (stripos($text, 'CrossIndustryInvoice') === false && stripos($text, '<rsm:') === false) {
        return null;
    }

    $invoiceNumber = strtoupper(jg_store_ops_transactions_xml_value(
        $text,
        '/<rsm:ExchangedDocument\b[^>]*>.*?<ram:ID\b[^>]*>(.*?)<\/ram:ID>/is'
    ));
    if ($invoiceNumber === '') {
        $invoiceNumber = strtoupper(jg_store_ops_transactions_xml_value($text, '/<ram:PaymentReference\b[^>]*>(.*?)<\/ram:PaymentReference>/is'));
    }

    $poNumber = strtoupper(jg_store_ops_transactions_xml_value(
        $text,
        '/<ram:BuyerOrderReferencedDocument\b[^>]*>.*?<ram:IssuerAssignedID\b[^>]*>(.*?)<\/ram:IssuerAssignedID>/is'
    ));

    $items = [];
    if (preg_match_all('/<ram:IncludedSupplyChainTradeLineItem\b[^>]*>(.*?)<\/ram:IncludedSupplyChainTradeLineItem>/is', $text, $matches)) {
        foreach ($matches[1] as $itemXml) {
            $description = jg_store_ops_transactions_xml_value((string) $itemXml, '/<ram:SpecifiedTradeProduct\b[^>]*>.*?<ram:Name\b[^>]*>(.*?)<\/ram:Name>/is');
            $quantity = jg_store_ops_transactions_number_to_float(jg_store_ops_transactions_xml_value((string) $itemXml, '/<ram:BilledQuantity\b[^>]*>(.*?)<\/ram:BilledQuantity>/is'));
            $unitPrice = jg_store_ops_transactions_money_to_float(jg_store_ops_transactions_xml_value((string) $itemXml, '/<ram:NetPriceProductTradePrice\b[^>]*>.*?<ram:ChargeAmount\b[^>]*>(.*?)<\/ram:ChargeAmount>/is'));
            if ($unitPrice <= 0) {
                $unitPrice = jg_store_ops_transactions_money_to_float(jg_store_ops_transactions_xml_value((string) $itemXml, '/<ram:GrossPriceProductTradePrice\b[^>]*>.*?<ram:ChargeAmount\b[^>]*>(.*?)<\/ram:ChargeAmount>/is'));
            }
            $lineTotal = jg_store_ops_transactions_money_to_float(jg_store_ops_transactions_xml_value((string) $itemXml, '/<ram:LineTotalAmount\b[^>]*>(.*?)<\/ram:LineTotalAmount>/is'));
            $cogs = $quantity > 0 ? round($lineTotal / $quantity, 2) : $unitPrice;

            if ($description === '' || $quantity <= 0) {
                continue;
            }

            $items[] = [
                'item_tag' => $description,
                'item_description' => $description,
                'quantity' => number_format($quantity, 2, '.', ''),
                'unit_price' => number_format($unitPrice, 2, '.', ''),
                'line_total' => number_format($lineTotal, 2, '.', ''),
                'cogs' => number_format($cogs, 2, '.', ''),
                'sku' => '',
                'match_status' => 'unmatched',
            ];
        }
    }

    if ($invoiceNumber === '' || $poNumber === '' || $items === []) {
        return null;
    }

    return [
        'invoice_number' => $invoiceNumber,
        'po_number' => $poNumber,
        'po_context' => '',
        'source_order' => '',
        'source_reference' => $poNumber,
        'source_file' => basename($sourceFile),
        'raw_text_hash' => hash('sha256', $text),
        'items' => $items,
    ];
}

function jg_store_ops_transactions_parse_invoice_text(string $text, string $sourceFile = ''): array
{
    $xmlInvoice = jg_store_ops_transactions_parse_invoice_xml($text, $sourceFile);
    if (is_array($xmlInvoice)) {
        return $xmlInvoice;
    }

    $normalizedText = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = array_values(array_filter(array_map(static fn ($line): string => rtrim((string) $line), explode("\n", $normalizedText)), static fn ($line): bool => trim($line) !== ''));
    $flat = preg_replace('/[ \t]+/', ' ', $normalizedText) ?? $normalizedText;

    $invoiceNumber = '';
    if (preg_match('/\bInvoice\s+(INV[\/A-Z0-9._-]+)/i', $flat, $match)) {
        $invoiceNumber = strtoupper((string) $match[1]);
    } elseif (preg_match('/Payment Communication:\s*(INV[\/A-Z0-9._-]+)/i', $flat, $match)) {
        $invoiceNumber = strtoupper((string) $match[1]);
    }

    $sourceOrder = '';
    $sourceReference = '';
    foreach ($lines as $index => $line) {
        if (stripos($line, 'Invoice Date') !== false && stripos($line, 'Reference') !== false) {
            $next = $lines[$index + 1] ?? '';
            if (preg_match('/\b\d{2}\/\d{2}\/\d{4}\b\s+\b\d{2}\/\d{2}\/\d{4}\b\s+([A-Z0-9._-]+)\s+([A-Z0-9._-]+)/i', $next, $match)) {
                $sourceOrder = strtoupper((string) $match[1]);
                $sourceReference = strtoupper((string) $match[2]);
            }
            break;
        }
    }

    $poContext = '';
    foreach ($lines as $line) {
        if (preg_match('/^\s*(PO\s*[-:]?\s*[A-Z0-9._\/-]+)\s*$/i', trim($line), $match)) {
            $poContext = strtoupper(preg_replace('/\s+/', ' ', (string) $match[1]) ?? (string) $match[1]);
            break;
        }
    }

    $poNumber = $sourceReference !== '' ? $sourceReference : $poContext;
    $items = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (stripos($trimmed, 'Rp ') === false || stripos($trimmed, 'Units') === false) {
            continue;
        }

        if (!preg_match('/^(.+?)\s+([0-9]+(?:[.,][0-9]+)?)\s+Units?\s+([0-9.,]+)\s+(?:.*?)Rp\s*([0-9.,]+)\s*$/i', $trimmed, $match)) {
            continue;
        }

        $description = trim((string) $match[1]);
        $quantity = jg_store_ops_transactions_number_to_float((string) $match[2]);
        $lineTotal = jg_store_ops_transactions_money_to_float((string) $match[4]);
        $unitPrice = jg_store_ops_transactions_money_to_float((string) $match[3]);
        $cogs = $quantity > 0 ? round($lineTotal / $quantity, 2) : 0.00;

        $items[] = [
            'item_tag' => $description,
            'item_description' => $description,
            'quantity' => number_format($quantity, 2, '.', ''),
            'unit_price' => number_format($unitPrice, 2, '.', ''),
            'line_total' => number_format($lineTotal, 2, '.', ''),
            'cogs' => number_format($cogs, 2, '.', ''),
            'sku' => '',
            'match_status' => 'unmatched',
        ];
    }

    if ($invoiceNumber === '') {
        throw new RuntimeException('Invoice number was not found in the PDF.');
    }

    if ($poNumber === '') {
        throw new RuntimeException('PO number was not found in the PDF.');
    }

    if ($items === []) {
        throw new RuntimeException('No invoice product rows were found in the PDF.');
    }

    return [
        'invoice_number' => $invoiceNumber,
        'po_number' => $poNumber,
        'po_context' => $poContext,
        'source_order' => $sourceOrder,
        'source_reference' => $sourceReference,
        'source_file' => basename($sourceFile),
        'raw_text_hash' => hash('sha256', $normalizedText),
        'items' => $items,
    ];
}

function jg_store_ops_transactions_fetch_sku_match_index(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            s.sku,
            s.tag,
            p.name AS product_name,
            f.name AS flavor_name,
            u.name AS unit_name,
            s.volume
        FROM sku_skus s
        INNER JOIN sku_products p ON p.id = s.product_id
        INNER JOIN sku_flavors f ON f.id = s.flavor_id
        INNER JOIN sku_units u ON u.id = s.unit_id'
    );

    $index = [];
    foreach ($stmt->fetchAll() as $row) {
        $sku = (string) ($row['sku'] ?? '');
        if ($sku === '') {
            continue;
        }

        $candidates = [
            (string) ($row['tag'] ?? ''),
            (string) ($row['product_name'] ?? ''),
            trim((string) ($row['product_name'] ?? '') . ' ' . (string) ($row['volume'] ?? '') . ' ' . (string) ($row['unit_name'] ?? '')),
            trim((string) ($row['flavor_name'] ?? '') . ' ' . (string) ($row['volume'] ?? '') . ' ' . (string) ($row['unit_name'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            $key = jg_store_ops_transactions_normalize_key($candidate);
            if ($key !== '' && !isset($index[$key])) {
                $index[$key] = $sku;
            }
        }
    }

    return $index;
}

function jg_store_ops_transactions_match_invoice_items(PDO $pdo, array $invoice): array
{
    $index = jg_store_ops_transactions_fetch_sku_match_index($pdo);
    foreach ($invoice['items'] as $itemIndex => $item) {
        $tag = (string) ($item['item_tag'] ?? '');
        $variants = [
            $tag,
            preg_replace('/\[[^\]]+\]/', '', $tag) ?? $tag,
            preg_replace('/^Sample\s+/i', '', $tag) ?? $tag,
            preg_replace('/^Sachet\s*-\s*/i', '', $tag) ?? $tag,
        ];

        foreach ($variants as $variant) {
            $key = jg_store_ops_transactions_normalize_key((string) $variant);
            if ($key !== '' && isset($index[$key])) {
                $invoice['items'][$itemIndex]['sku'] = $index[$key];
                $invoice['items'][$itemIndex]['match_status'] = 'matched';
                break;
            }
        }
    }

    return $invoice;
}

function jg_store_ops_transactions_duplicate_count(PDO $pdo, string $invoiceNumber): int
{
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT import_group_id) FROM `Transaction_Table` WHERE invoice_number = :invoice_number');
    $stmt->execute([':invoice_number' => $invoiceNumber]);
    return (int) $stmt->fetchColumn();
}

function jg_store_ops_transactions_touch_sku_version(PDO $pdo, string $now): void
{
    $stmt = $pdo->prepare('UPDATE sku_meta SET updated_at = :updated_at WHERE meta_key = "version"');
    $stmt->execute([':updated_at' => $now]);
}

function jg_store_ops_transactions_import_invoice(PDO $pdo, array $invoice, bool $allowDuplicate, string $createdBy = 'admin'): array
{
    jg_store_ops_transactions_ensure_schema($pdo);
    $duplicateCount = jg_store_ops_transactions_duplicate_count($pdo, (string) $invoice['invoice_number']);
    if ($duplicateCount > 0 && !$allowDuplicate) {
        throw new RuntimeException('This invoice number already exists. Enable duplicate test import to import it again.');
    }

    $now = jg_store_ops_transaction_now();
    $importGroupId = 'txn-' . substr(hash('sha256', (string) $invoice['invoice_number'] . microtime(true) . random_int(1000, 9999)), 0, 24);
    $insert = $pdo->prepare(
        'INSERT INTO `Transaction_Table` (
            import_group_id, invoice_number, po_number, po_context, source_order, source_reference,
            sku, item_tag, item_description, quantity, line_total, cogs, source_file,
            raw_text_hash, is_duplicate, created_by, created_at
        ) VALUES (
            :import_group_id, :invoice_number, :po_number, :po_context, :source_order, :source_reference,
            :sku, :item_tag, :item_description, :quantity, :line_total, :cogs, :source_file,
            :raw_text_hash, :is_duplicate, :created_by, :created_at
        )'
    );
    $updateSku = $pdo->prepare('UPDATE sku_skus SET current_stock = current_stock + :quantity, cogs = :cogs, updated_at = :updated_at WHERE sku = :sku');
    $history = $pdo->prepare(
        'INSERT INTO sku_cogs_history (sku, old_price, new_price, takes_place, recorded_at)
         VALUES (:sku, :old_price, :new_price, :takes_place, :recorded_at)'
    );
    $oldCogs = $pdo->prepare('SELECT cogs FROM sku_skus WHERE sku = :sku LIMIT 1');

    $inserted = 0;
    $inventoryUpdated = 0;
    $pdo->beginTransaction();
    try {
        foreach ($invoice['items'] as $item) {
            $sku = trim((string) ($item['sku'] ?? ''));
            $quantity = number_format((float) ($item['quantity'] ?? 0), 2, '.', '');
            $lineTotal = number_format((float) ($item['line_total'] ?? 0), 2, '.', '');
            $cogs = number_format((float) ($item['cogs'] ?? 0), 2, '.', '');

            $insert->execute([
                ':import_group_id' => $importGroupId,
                ':invoice_number' => (string) $invoice['invoice_number'],
                ':po_number' => (string) $invoice['po_number'],
                ':po_context' => (string) ($invoice['po_context'] ?? ''),
                ':source_order' => (string) ($invoice['source_order'] ?? ''),
                ':source_reference' => (string) ($invoice['source_reference'] ?? ''),
                ':sku' => $sku,
                ':item_tag' => (string) ($item['item_tag'] ?? ''),
                ':item_description' => (string) ($item['item_description'] ?? ''),
                ':quantity' => $quantity,
                ':line_total' => $lineTotal,
                ':cogs' => $cogs,
                ':source_file' => (string) ($invoice['source_file'] ?? ''),
                ':raw_text_hash' => (string) ($invoice['raw_text_hash'] ?? ''),
                ':is_duplicate' => $duplicateCount > 0 ? 1 : 0,
                ':created_by' => $createdBy,
                ':created_at' => $now,
            ]);
            $inserted++;

            if ($sku === '') {
                continue;
            }

            $oldCogs->execute([':sku' => $sku]);
            $oldPrice = $oldCogs->fetchColumn();
            if ($oldPrice === false) {
                continue;
            }

            $stockQuantity = (int) round((float) $quantity);
            $updateSku->execute([
                ':quantity' => $stockQuantity,
                ':cogs' => $cogs,
                ':updated_at' => $now,
                ':sku' => $sku,
            ]);
            $history->execute([
                ':sku' => $sku,
                ':old_price' => number_format((float) $oldPrice, 2, '.', ''),
                ':new_price' => $cogs,
                ':takes_place' => sprintf('PO %s | Invoice %s | Qty %s', (string) $invoice['po_number'], (string) $invoice['invoice_number'], $quantity),
                ':recorded_at' => $now,
            ]);
            $inventoryUpdated++;
        }

        if ($inventoryUpdated > 0) {
            jg_store_ops_transactions_touch_sku_version($pdo, $now);
        }

        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $throwable;
    }

    return [
        'import_group_id' => $importGroupId,
        'inserted' => $inserted,
        'inventory_updated' => $inventoryUpdated,
        'is_duplicate' => $duplicateCount > 0,
    ];
}

function jg_store_ops_transactions_fetch_recent(PDO $pdo, int $limit = 200): array
{
    jg_store_ops_transactions_ensure_schema($pdo);
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->query(
        'SELECT id, import_group_id, invoice_number, po_number, po_context, source_order, source_reference,
            sku, item_tag, quantity, line_total, cogs, is_duplicate, created_by, created_at
         FROM `Transaction_Table`
         ORDER BY created_at DESC, id DESC
         LIMIT ' . $limit
    );

    return array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'import_group_id' => (string) ($row['import_group_id'] ?? ''),
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
            'po_number' => (string) ($row['po_number'] ?? ''),
            'po_context' => (string) ($row['po_context'] ?? ''),
            'source_order' => (string) ($row['source_order'] ?? ''),
            'source_reference' => (string) ($row['source_reference'] ?? ''),
            'sku' => (string) ($row['sku'] ?? ''),
            'item_tag' => (string) ($row['item_tag'] ?? ''),
            'quantity' => number_format((float) ($row['quantity'] ?? 0), 2, '.', ''),
            'line_total' => number_format((float) ($row['line_total'] ?? 0), 2, '.', ''),
            'cogs' => number_format((float) ($row['cogs'] ?? 0), 2, '.', ''),
            'is_duplicate' => (bool) ($row['is_duplicate'] ?? false),
            'created_by' => (string) ($row['created_by'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }, $stmt->fetchAll());
}

function jg_store_ops_transactions_fetch_inventory(PDO $pdo): array
{
    jg_store_ops_transactions_ensure_schema($pdo);
    $stmt = $pdo->query(
        'SELECT
            s.sku,
            s.tag,
            b.name AS brand_name,
            p.name AS product_name,
            f.name AS flavor_name,
            u.name AS unit_name,
            s.volume,
            s.current_stock,
            s.stock_trigger,
            s.cogs,
            s.updated_at,
            t.invoice_number AS latest_invoice_number,
            t.po_number AS latest_po_number,
            t.created_at AS latest_transaction_at
        FROM sku_skus s
        INNER JOIN sku_brands b ON b.id = s.brand_id
        INNER JOIN sku_products p ON p.id = s.product_id
        INNER JOIN sku_flavors f ON f.id = s.flavor_id
        INNER JOIN sku_units u ON u.id = s.unit_id
        LEFT JOIN `Transaction_Table` t ON t.id = (
            SELECT tt.id
            FROM `Transaction_Table` tt
            WHERE tt.sku = s.sku
            ORDER BY tt.created_at DESC, tt.id DESC
            LIMIT 1
        )
        ORDER BY b.name, p.name, f.name, s.volume, s.sku'
    );

    return array_map(static function (array $row): array {
        return [
            'sku' => (string) ($row['sku'] ?? ''),
            'tag' => (string) ($row['tag'] ?? ''),
            'brand_name' => (string) ($row['brand_name'] ?? ''),
            'product_name' => (string) ($row['product_name'] ?? ''),
            'flavor_name' => (string) ($row['flavor_name'] ?? ''),
            'unit_name' => (string) ($row['unit_name'] ?? ''),
            'volume' => number_format((float) ($row['volume'] ?? 0), 1, '.', ''),
            'current_stock' => (int) ($row['current_stock'] ?? 0),
            'stock_trigger' => (int) ($row['stock_trigger'] ?? 0),
            'cogs' => number_format((float) ($row['cogs'] ?? 0), 2, '.', ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'latest_invoice_number' => (string) ($row['latest_invoice_number'] ?? ''),
            'latest_po_number' => (string) ($row['latest_po_number'] ?? ''),
            'latest_transaction_at' => (string) ($row['latest_transaction_at'] ?? ''),
        ];
    }, $stmt->fetchAll());
}

function jg_store_ops_transactions_metrics(PDO $pdo): array
{
    jg_store_ops_transactions_ensure_schema($pdo);
    $transactionCount = (int) $pdo->query('SELECT COUNT(*) FROM `Transaction_Table`')->fetchColumn();
    $invoiceCount = (int) $pdo->query('SELECT COUNT(DISTINCT invoice_number) FROM `Transaction_Table`')->fetchColumn();
    $poCount = (int) $pdo->query('SELECT COUNT(DISTINCT po_number) FROM `Transaction_Table` WHERE po_number <> ""')->fetchColumn();
    $lowStockCount = (int) $pdo->query('SELECT COUNT(*) FROM sku_skus WHERE current_stock <= stock_trigger')->fetchColumn();

    return [
        'transaction_count' => $transactionCount,
        'invoice_count' => $invoiceCount,
        'po_count' => $poCount,
        'low_stock_count' => $lowStockCount,
    ];
}
