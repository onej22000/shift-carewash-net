<?php
// 月次請求書（admin/invoice*.php）共通処理。
// 月末入居者数は admin/linen_trends.php が保存する facility_resident_counts を読み取り専用で参照する。
// 金額はすべて整数（円）で扱い、浮動小数は使わない。

require_once __DIR__ . '/auth.php';

function inv_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function inv_yen(int $amount): string
{
    return number_format($amount);
}

function inv_is_month(string $value): bool
{
    return (bool) preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $value);
}

function inv_is_date(string $value): bool
{
    if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

// 整数文字列（負数可、9桁まで）を int に。不正なら null。
function inv_parse_int($raw): ?int
{
    if (!is_string($raw)) {
        return null;
    }
    $raw = str_replace([',', ' ', '　'], '', trim($raw));
    if (!preg_match('/\A-?\d{1,9}\z/', $raw)) {
        return null;
    }
    return (int) $raw;
}

function inv_month_first_day(string $month): string
{
    return $month . '-01';
}

function inv_month_last_day(string $month): string
{
    return (new DateTimeImmutable($month . '-01'))->format('Y-m-t');
}

function inv_month_label(string $month): string
{
    return (int) substr($month, 0, 4) . '年' . (int) substr($month, 5, 2) . '月';
}

// 負の数も含めて切り捨て（floor）の整数除算。
function inv_floor_div(int $a, int $b): int
{
    $q = intdiv($a, $b);
    if ($a % $b !== 0 && (($a < 0) !== ($b < 0))) {
        $q--;
    }
    return $q;
}

// 税込単価方式：税込合計から内消費税を逆算（8月分 No.00000009 と同一計算）。
function inv_calc_totals(int $totalIncl): array
{
    $tax = inv_floor_div($totalIncl * 10, 110);
    return ['total_incl' => $totalIncl, 'tax' => $tax, 'total_excl' => $totalIncl - $tax];
}

function inv_format_no(?int $no): string
{
    return $no === null ? '（未採番）' : str_pad((string) $no, 8, '0', STR_PAD_LEFT);
}

function inv_status_label(string $status): string
{
    return ['draft' => '下書き', 'issued' => '確定', 'void' => '取消'][$status] ?? $status;
}

function inv_fetch_issuer(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM inv_issuer WHERE id = 1')->fetch();
    return $row === false ? [] : $row;
}

function inv_fetch_client(PDO $pdo, int $clientId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM inv_clients WHERE id = :id');
    $stmt->execute([':id' => $clientId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function inv_fetch_invoice(PDO $pdo, int $invoiceId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM inv_invoices WHERE id = :id');
    $stmt->execute([':id' => $invoiceId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function inv_fetch_lines(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare('SELECT * FROM inv_invoice_lines WHERE invoice_id = :id ORDER BY sort_order, id');
    $stmt->execute([':id' => $invoiceId]);
    return $stmt->fetchAll();
}

// 請求対象になりうる施設（linen_trends.php と同じ条件）のうち、受託開始日が対象月の月末以前のもの。
function inv_eligible_facilities(PDO $pdo, string $month): array
{
    $stmt = $pdo->prepare(
        "SELECT id, name, onboarding_start_date
         FROM facilities
         WHERE onboarding_start_date IS NOT NULL AND onboarding_start_date <= :month_end
           AND is_active = 1
           AND (facility_type IS NULL OR facility_type != 'クリーニング所')
         ORDER BY onboarding_start_date, id"
    );
    $stmt->execute([':month_end' => inv_month_last_day($month)]);
    return $stmt->fetchAll();
}

// 対象月の初日時点で有効な最新の単価。
function inv_effective_price(PDO $pdo, int $facilityId, string $month): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM inv_unit_prices
         WHERE facility_id = :facility_id AND is_active = 1 AND effective_from <= :first_day
         ORDER BY effective_from DESC LIMIT 1'
    );
    $stmt->execute([':facility_id' => $facilityId, ':first_day' => inv_month_first_day($month)]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

// 対象月の月末入居者数（facility_resident_counts、未入力なら null）。
function inv_resident_count(PDO $pdo, int $facilityId, string $month): ?int
{
    $stmt = $pdo->prepare(
        'SELECT resident_count FROM facility_resident_counts WHERE facility_id = :facility_id AND month_end_date = :month_end'
    );
    $stmt->execute([':facility_id' => $facilityId, ':month_end' => inv_month_last_day($month)]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int) $value;
}

// 請求先・対象月の請求元データ（usage行の素材と、不足しているデータのエラー）。
// 単価が別の請求先に登録されている施設は対象外。単価未登録の施設はエラーとして含める。
function inv_collect_sources(PDO $pdo, int $clientId, string $month): array
{
    $usage = [];
    $errors = [];
    foreach (inv_eligible_facilities($pdo, $month) as $facility) {
        $facilityId = (int) $facility['id'];
        $price = inv_effective_price($pdo, $facilityId, $month);
        if ($price !== null && (int) $price['client_id'] !== $clientId) {
            continue;
        }
        $count = inv_resident_count($pdo, $facilityId, $month);
        if ($count === null) {
            $errors[] = $facility['name'] . '：' . inv_month_label($month) . '末の入居者数が未入力です。';
        }
        if ($price === null) {
            $errors[] = $facility['name'] . '：' . inv_month_label($month) . '1日時点で有効な単価が未登録です。';
        }
        if ($count !== null && $price !== null) {
            $usage[] = [
                'facility_id' => $facilityId,
                'facility_name' => (string) $facility['name'],
                'product_code' => $price['product_code'],
                'description' => $price['item_name'],
                'quantity' => $count,
                'unit' => $price['unit'],
                'unit_price' => (int) $price['unit_price'],
            ];
        }
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM inv_adjustments
         WHERE client_id = :client_id AND apply_month = :month AND applied_invoice_id IS NULL
         ORDER BY id'
    );
    $stmt->execute([':client_id' => $clientId, ':month' => $month]);

    return ['usage' => $usage, 'adjustments' => $stmt->fetchAll(), 'errors' => $errors];
}

// 下書きを確定できるかの検証。表示時・確定時の両方で使う。
function inv_draft_errors(PDO $pdo, array $invoice, array $lines): array
{
    $sources = inv_collect_sources($pdo, (int) $invoice['client_id'], (string) $invoice['billing_month']);
    $errors = $sources['errors'];

    $usageFacilityIds = [];
    $adjustmentIds = [];
    foreach ($lines as $line) {
        if ($line['line_type'] === 'usage' && $line['facility_id'] !== null) {
            $usageFacilityIds[(int) $line['facility_id']] = true;
        }
        if ($line['line_type'] === 'adjustment' && $line['adjustment_id'] !== null) {
            $adjustmentIds[(int) $line['adjustment_id']] = true;
        }
    }
    foreach ($sources['usage'] as $usage) {
        if (!isset($usageFacilityIds[$usage['facility_id']])) {
            $errors[] = $usage['facility_name'] . '：明細行がありません（入居者数・単価の登録後に下書きを作り直してください）。';
        }
    }
    $pendingIds = [];
    foreach ($sources['adjustments'] as $adjustment) {
        $pendingIds[(int) $adjustment['id']] = true;
        if (!isset($adjustmentIds[(int) $adjustment['id']])) {
            $errors[] = '訂正・値引き「' . $adjustment['description'] . '」が下書きに含まれていません（下書きを作り直してください）。';
        }
    }
    foreach (array_keys($adjustmentIds) as $adjustmentId) {
        if (!isset($pendingIds[$adjustmentId])) {
            $errors[] = '下書き内の訂正・値引き行（調整ID ' . $adjustmentId . '）が削除済みか反映月が変更されています（下書きを作り直してください）。';
        }
    }
    if (!$lines) {
        $errors[] = '明細行がありません。';
    }
    return $errors;
}

// 明細の合計を再計算して請求書に保存する。
function inv_recalc_invoice(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM inv_invoice_lines WHERE invoice_id = :id');
    $stmt->execute([':id' => $invoiceId]);
    $totals = inv_calc_totals((int) $stmt->fetchColumn());
    $pdo->prepare(
        'UPDATE inv_invoices SET total_incl = :incl, tax = :tax, total_excl = :excl, updated_at = NOW() WHERE id = :id'
    )->execute([':incl' => $totals['total_incl'], ':tax' => $totals['tax'], ':excl' => $totals['total_excl'], ':id' => $invoiceId]);
    return $totals;
}

function inv_admin_nav(array $admin, string $current): string
{
    $links = [
        'invoice' => ['/admin/invoice.php', '請求書'],
        'adjustments' => ['/admin/invoice_adjustments.php', '訂正・値引き'],
        'settings' => ['/admin/invoice_settings.php', '請求設定'],
    ];
    $html = 'ログイン中: ' . inv_h($admin['name']) . 'さん（管理者）';
    foreach ($links as $key => [$href, $label]) {
        $html .= ' | ' . ($key === $current ? '<strong>' . inv_h($label) . '</strong>' : '<a href="' . inv_h($href) . '">' . inv_h($label) . '</a>');
    }
    return $html . ' | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a>';
}

function inv_common_css(): string
{
    return <<<'CSS'
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        h2 { font-size: 1.1em; margin: 24px 0 8px; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; margin-bottom: 12px; }
        table.list { border-collapse: collapse; width: 100%; margin-bottom: 16px; }
        table.list th, table.list td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; vertical-align: top; }
        table.list th { background: #f5f5f5; white-space: nowrap; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .neg { color: #b3261e; }
        .status-draft { color: #856404; font-weight: bold; }
        .status-issued { color: #1e7e34; font-weight: bold; }
        .status-void { color: #888; text-decoration: line-through; }
        form.inline { display: inline; }
        .form-grid { display: grid; grid-template-columns: max-content 1fr; gap: 8px 12px; align-items: center; max-width: 720px; }
        .form-grid input[type=text], .form-grid select, .form-grid textarea { width: 100%; box-sizing: border-box; }
        button { padding: 6px 14px; cursor: pointer; }
        button.primary { background: #0b5ed7; color: #fff; border: none; border-radius: 4px; }
        button.danger { background: #b3261e; color: #fff; border: none; border-radius: 4px; }
        button:disabled { background: #aaa; cursor: not-allowed; }
        .muted { color: #777; font-size: 0.9em; }
CSS;
}
