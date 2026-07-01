<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth-runtime.php';
require_once dirname(__DIR__, 2) . '/order-resolver.php';
require_once dirname(__DIR__, 2) . '/invoice-pdf.php';

jg_admin_require_auth_json();

function jg_store_ops_invoice_pdf_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    jg_store_ops_invoice_pdf_fail('Method not allowed.', 405);
}

$orderId = trim((string) ($_GET['order_id'] ?? $_GET['order'] ?? ''));
if ($orderId === '') {
    jg_store_ops_invoice_pdf_fail('Order ID is required.');
}

try {
    $order = jg_store_ops_resolve_order_by_id($orderId);
    if (!is_array($order)) {
        jg_store_ops_invoice_pdf_fail('Order was not found.', 404);
    }

    $pdf = jg_store_ops_invoice_pdf_document($order);
    $filename = 'invoice-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($order['order_id'] ?? $orderId)) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . addcslashes($filename, "\\\"") . '"');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
} catch (Throwable $error) {
    error_log('Store Ops invoice PDF failed: ' . $error->getMessage());
    jg_store_ops_invoice_pdf_fail('Invoice PDF generation failed.', 500);
}
