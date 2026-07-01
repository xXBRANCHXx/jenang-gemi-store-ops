<?php
declare(strict_types=1);

function jg_store_ops_invoice_pdf_ascii(mixed $value): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($converted)) {
            $text = $converted;
        }
    }
    return preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
}

function jg_store_ops_invoice_pdf_escape(string $text): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], jg_store_ops_invoice_pdf_ascii($text));
}

function jg_store_ops_invoice_pdf_money(mixed $value, string $currency = 'IDR'): string
{
    $number = is_numeric($value) ? (float) $value : 0.0;
    $prefix = strtoupper(trim($currency)) === 'IDR' ? 'Rp ' : strtoupper(trim($currency)) . ' ';
    return $prefix . number_format($number, 2, '.', ',');
}

function jg_store_ops_invoice_pdf_date(mixed $value): string
{
    $raw = trim((string) $value);
    $timestamp = $raw !== '' ? strtotime($raw) : false;
    return $timestamp === false ? gmdate('m/d/Y') : gmdate('m/d/Y', $timestamp);
}

function jg_store_ops_invoice_pdf_wrap(string $text, int $width = 86): array
{
    $text = jg_store_ops_invoice_pdf_ascii($text);
    $wrapped = wordwrap($text, $width, "\n", true);
    return array_values(array_filter(explode("\n", $wrapped), static fn (string $line): bool => trim($line) !== ''));
}

function jg_store_ops_invoice_pdf_lines(array $order): array
{
    $source = is_array($order['source'] ?? null) ? $order['source'] : [];
    $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
    $revenue = is_array($order['revenue'] ?? null) ? $order['revenue'] : [];
    $timestamps = is_array($order['timestamps'] ?? null) ? $order['timestamps'] : [];
    $currency = (string) ($revenue['currency'] ?? 'IDR');
    $lines = [
        ['Invoice ' . (string) ($order['order_id'] ?? ''), 18],
        ['PT. Zero Foods Indonesia', 10],
        ['Jl. Jombor Tegal No.124 A, Jombor Lor, Sinduadi, Kec. Mlati, Sleman YO 55284, Indonesia', 8],
        ['', 8],
        ['Source: ' . (string) ($source['label'] ?? 'Order'), 10],
        ['Status: ' . (string) ($order['status'] ?? ''), 10],
        ['Invoice Date: ' . jg_store_ops_invoice_pdf_date($timestamps['created_at'] ?? $timestamps['ordered_at'] ?? ''), 10],
        ['Due Date: ' . jg_store_ops_invoice_pdf_date($timestamps['created_at'] ?? $timestamps['ordered_at'] ?? ''), 10],
        ['', 8],
        ['Customer', 12],
        ['Name: ' . ((string) ($customer['name'] ?? '') !== '' ? (string) $customer['name'] : ((string) ($customer['username'] ?? '') ?: '-')), 10],
        ['Phone: ' . ((string) ($customer['phone'] ?? '') ?: '-'), 10],
        ['Email: ' . ((string) ($customer['email'] ?? '') ?: '-'), 10],
        ['Address: ' . ((string) ($customer['address'] ?? '') ?: '-'), 10],
        ['', 8],
        ['Items', 12],
    ];

    $items = array_values(array_filter((array) ($order['items'] ?? []), 'is_array'));
    if ($items === []) {
        $lines[] = ['No order items available.', 10];
    }
    foreach ($items as $index => $item) {
        $quantity = (float) ($item['quantity'] ?? 0);
        $quantityLabel = rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
        $name = (string) ($item['name'] ?? $item['sku'] ?? 'Order item');
        $sku = (string) ($item['sku'] ?? '');
        $lineTotal = jg_store_ops_invoice_pdf_money($item['line_total'] ?? 0, $currency);
        $itemText = sprintf('%d. %s x %s%s - %s', $index + 1, $quantityLabel, $name, $sku !== '' ? ' [' . $sku . ']' : '', $lineTotal);
        foreach (jg_store_ops_invoice_pdf_wrap($itemText, 86) as $wrappedLine) {
            $lines[] = [$wrappedLine, 9];
        }
    }

    $lines[] = ['', 8];
    $lines[] = ['Subtotal: ' . jg_store_ops_invoice_pdf_money($revenue['subtotal'] ?? $revenue['gross'] ?? 0, $currency), 10];
    $lines[] = ['Discount: ' . jg_store_ops_invoice_pdf_money($revenue['discount_total'] ?? 0, $currency), 10];
    $lines[] = ['Tax: ' . jg_store_ops_invoice_pdf_money($revenue['tax'] ?? 0, $currency), 10];
    if ((float) ($revenue['fees'] ?? 0) > 0) {
        $lines[] = ['Marketplace Fees: ' . jg_store_ops_invoice_pdf_money($revenue['fees'] ?? 0, $currency), 10];
    }
    $lines[] = ['Total: ' . jg_store_ops_invoice_pdf_money($revenue['total'] ?? $revenue['gross'] ?? 0, $currency), 13];
    $lines[] = ['', 8];
    $lines[] = ['Payment Communication: ' . (string) ($order['order_id'] ?? ''), 10];
    $lines[] = ['#BeHealthy #BeWealthy #BeHappy', 10];

    return $lines;
}

function jg_store_ops_invoice_pdf_pages(array $lines): array
{
    $pages = [];
    $current = [];
    $y = 790;
    foreach ($lines as $line) {
        $size = (int) ($line[1] ?? 10);
        $height = $size + 6;
        if ($y - $height < 58 && $current !== []) {
            $pages[] = $current;
            $current = [];
            $y = 790;
        }
        $current[] = [$line[0], $size, $y];
        $y -= $height;
    }
    if ($current !== []) {
        $pages[] = $current;
    }
    return $pages;
}

function jg_store_ops_invoice_pdf_stream(array $pageLines, int $pageNumber, int $pageCount): string
{
    $stream = "BT\n";
    foreach ($pageLines as [$text, $size, $y]) {
        $stream .= sprintf("/F1 %d Tf 1 0 0 1 50 %d Tm (%s) Tj\n", (int) $size, (int) $y, jg_store_ops_invoice_pdf_escape((string) $text));
    }
    $stream .= sprintf("/F1 8 Tf 1 0 0 1 50 34 Tm (%s) Tj\n", jg_store_ops_invoice_pdf_escape('zerofoods.id | zerofoods.id@gmail.com | Page ' . $pageNumber . '/' . $pageCount));
    $stream .= "ET\n";
    return $stream;
}

function jg_store_ops_invoice_pdf_document(array $order): string
{
    $pages = jg_store_ops_invoice_pdf_pages(jg_store_ops_invoice_pdf_lines($order));
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $kids = [];
    $nextId = 4;
    $pageCount = count($pages);
    foreach ($pages as $index => $pageLines) {
        $pageId = $nextId++;
        $contentId = $nextId++;
        $kids[] = $pageId . ' 0 R';
        $stream = jg_store_ops_invoice_pdf_stream($pageLines, $index + 1, $pageCount);
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach (array_keys($objects) as $id) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

    return $pdf;
}
