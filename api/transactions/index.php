<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/transactions-bootstrap.php';

jg_admin_require_auth_json();
header('Content-Type: application/json; charset=utf-8');

function jg_store_ops_transactions_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_store_ops_transactions_request_json(): array
{
    $raw = file_get_contents('php://input');
    $payload = json_decode(is_string($raw) ? $raw : '', true);
    return is_array($payload) ? $payload : [];
}

function jg_store_ops_transactions_store_preview(array $invoice): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        jg_admin_start_session();
    }

    if (!isset($_SESSION['jg_invoice_previews']) || !is_array($_SESSION['jg_invoice_previews'])) {
        $_SESSION['jg_invoice_previews'] = [];
    }

    $token = 'preview-' . substr(hash('sha256', (string) $invoice['invoice_number'] . microtime(true) . random_int(1000, 9999)), 0, 24);
    $_SESSION['jg_invoice_previews'][$token] = [
        'created_at' => time(),
        'invoice' => $invoice,
    ];

    foreach ($_SESSION['jg_invoice_previews'] as $previewToken => $preview) {
        if (!is_array($preview) || (int) ($preview['created_at'] ?? 0) < time() - 1800) {
            unset($_SESSION['jg_invoice_previews'][$previewToken]);
        }
    }

    return $token;
}

function jg_store_ops_transactions_get_preview(string $token): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        jg_admin_start_session();
    }

    $preview = $_SESSION['jg_invoice_previews'][$token]['invoice'] ?? null;
    if (!is_array($preview)) {
        jg_store_ops_transactions_fail('Invoice preview expired. Upload the PDF again.', 410);
    }

    return $preview;
}

function jg_store_ops_transactions_response(PDO $pdo): void
{
    echo json_encode([
        'metrics' => jg_store_ops_transactions_metrics($pdo),
        'inventory' => jg_store_ops_transactions_fetch_inventory($pdo),
        'transactions' => jg_store_ops_transactions_fetch_recent($pdo),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

try {
    $pdo = jg_store_ops_sku_db();
    jg_store_ops_transactions_ensure_schema($pdo);
} catch (Throwable $throwable) {
    jg_store_ops_transactions_fail('Unable to connect to the SKU database.', 500);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    try {
        jg_store_ops_transactions_response($pdo);
    } catch (Throwable $throwable) {
        jg_store_ops_transactions_fail('Unable to load transactions.', 500);
    }
    exit;
}

if ($method !== 'POST') {
    jg_store_ops_transactions_fail('Method not allowed.', 405);
}

$action = trim((string) ($_POST['action'] ?? ''));
$isMultipart = stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false;
if (!$isMultipart) {
    $payload = jg_store_ops_transactions_request_json();
    $action = trim((string) ($payload['action'] ?? $action));
} else {
    $payload = $_POST;
}

try {
    if ($action === 'preview_invoice') {
        $upload = $_FILES['invoice_pdf'] ?? null;
        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            jg_store_ops_transactions_fail('Upload a readable invoice PDF.');
        }

        $tmpName = (string) ($upload['tmp_name'] ?? '');
        $sourceName = (string) ($upload['name'] ?? 'invoice.pdf');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            jg_store_ops_transactions_fail('Invoice upload was not accepted.');
        }

        if ((int) ($upload['size'] ?? 0) > 10 * 1024 * 1024) {
            jg_store_ops_transactions_fail('Invoice PDF is too large. Maximum size is 10 MB.');
        }

        if (strtolower(pathinfo($sourceName, PATHINFO_EXTENSION)) !== 'pdf') {
            jg_store_ops_transactions_fail('Only PDF invoices are accepted.');
        }

        $text = jg_store_ops_transactions_extract_invoice_text($tmpName);
        $invoice = jg_store_ops_transactions_parse_invoice_text($text, $sourceName);
        $invoice = jg_store_ops_transactions_match_invoice_items($pdo, $invoice);
        $duplicateCount = jg_store_ops_transactions_duplicate_count($pdo, (string) $invoice['invoice_number']);
        $token = jg_store_ops_transactions_store_preview($invoice);

        echo json_encode([
            'preview_token' => $token,
            'duplicate_count' => $duplicateCount,
            'duplicate_warning' => $duplicateCount > 0 ? 'This invoice number already exists in Transaction_Table.' : '',
            'invoice' => $invoice,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'import_invoice') {
        $token = trim((string) ($payload['preview_token'] ?? ''));
        if ($token === '') {
            jg_store_ops_transactions_fail('Invoice preview token is required.');
        }

        $invoice = jg_store_ops_transactions_get_preview($token);
        $allowDuplicate = !empty($payload['allow_duplicate']);
        $result = jg_store_ops_transactions_import_invoice($pdo, $invoice, $allowDuplicate, 'admin');
        unset($_SESSION['jg_invoice_previews'][$token]);

        echo json_encode([
            'result' => $result,
            'metrics' => jg_store_ops_transactions_metrics($pdo),
            'inventory' => jg_store_ops_transactions_fetch_inventory($pdo),
            'transactions' => jg_store_ops_transactions_fetch_recent($pdo),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    jg_store_ops_transactions_fail('Unknown action.', 400);
} catch (RuntimeException $exception) {
    jg_store_ops_transactions_fail($exception->getMessage(), 422);
} catch (Throwable $throwable) {
    jg_store_ops_transactions_fail('Transaction operation failed.', 500);
}
