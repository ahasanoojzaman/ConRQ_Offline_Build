<?php
/**
 * Common helper functions.
 */

function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function money(float $amount): string
{
    return number_format($amount, 2);
}

function today(): string
{
    return date('Y-m-d');
}

function flash(string $key, ?string $msg = null)
{
    if ($msg !== null) {
        $_SESSION['flash'][$key] = $msg;
        return null;
    }
    if (!empty($_SESSION['flash'][$key])) {
        $val = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $val;
    }
    return null;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function require_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_verify()) {
        http_response_code(419);
        die('Your session expired or the request was invalid. Please go back and try again.');
    }
}

function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    $text = strtolower($text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    return $text ?: 'tenant';
}

/**
 * Generate the next document number for a tenant (invoice/quotation/proforma/purchase)
 * and atomically increments the counter in tenant_settings.
 */
function next_doc_number(PDO $db, int $tenantId, string $docType): string
{
    $map = [
        'invoice'   => ['prefix_col' => 'invoice_prefix',   'next_col' => 'next_invoice_no'],
        'proforma'  => ['prefix_col' => 'proforma_prefix',  'next_col' => 'next_proforma_no'],
        'quotation' => ['prefix_col' => 'quotation_prefix', 'next_col' => 'next_quotation_no'],
        'purchase'  => ['prefix_col' => 'purchase_prefix',  'next_col' => 'next_purchase_no'],
        'credit_note' => ['prefix_col' => 'invoice_prefix', 'next_col' => 'next_invoice_no'],
        'debit_note'  => ['prefix_col' => 'invoice_prefix', 'next_col' => 'next_invoice_no'],
    ];
    $cols = $map[$docType] ?? $map['invoice'];

    // If we are already inside a transaction (e.g. called from the invoice
    // save flow), reuse it instead of opening a nested one - PDO/MySQL do not
    // support nested transactions and will throw "There is already an active
    // transaction" if we try.
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $stmt = $db->prepare("SELECT {$cols['prefix_col']} AS prefix, {$cols['next_col']} AS next_no
                               FROM tenant_settings WHERE tenant_id = ? FOR UPDATE");
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Tenant settings not found.');
        }
        $number = $row['next_no'];
        $upd = $db->prepare("UPDATE tenant_settings SET {$cols['next_col']} = {$cols['next_col']} + 1 WHERE tenant_id = ?");
        $upd->execute([$tenantId]);
        if ($ownsTransaction) {
            $db->commit();
        }

        $fy = fy_label();
        return $row['prefix'] . $fy . str_pad((string)$number, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $ex) {
        if ($ownsTransaction) {
            $db->rollBack();
        }
        throw $ex;
    }
}

/** Financial year label like 25-26 (India FY: Apr-Mar). */
function fy_label(): string
{
    $y = (int)date('Y');
    $m = (int)date('n');
    if ($m >= 4) {
        $start = $y;
        $end = $y + 1;
    } else {
        $start = $y - 1;
        $end = $y;
    }
    return substr((string)$start, 2, 2) . substr((string)$end, 2, 2) . '/';
}

/**
 * Calculate GST split (CGST/SGST vs IGST) for a line item.
 * $sameState true => intra-state (CGST+SGST split), false => inter-state (IGST).
 */
function calc_gst(float $taxableAmount, float $rate, bool $sameState): array
{
    $tax = round($taxableAmount * $rate / 100, 2);
    if ($sameState) {
        $half = round($tax / 2, 2);
        return ['cgst' => $half, 'sgst' => $tax - $half, 'igst' => 0.0, 'total_tax' => $tax];
    }
    return ['cgst' => 0.0, 'sgst' => 0.0, 'igst' => $tax, 'total_tax' => $tax];
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function old(string $key, $default = '')
{
    return e($_POST[$key] ?? $default);
}

function is_ajax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
