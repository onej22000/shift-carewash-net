<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/invoice_common.php';

$admin = require_login('admin');
$pdo = getPdo();

function invoice_redirect(?int $invoiceId = null): void
{
    header('Location: /admin/invoice.php' . ($invoiceId !== null ? '?id=' . $invoiceId : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $invoiceId = (int) ($_POST['id'] ?? 0);

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
        invoice_redirect($invoiceId > 0 ? $invoiceId : null);
    }

    if ($action === 'generate') {
        $month = (string) ($_POST['month'] ?? '');
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $issueDate = (string) ($_POST['issue_date'] ?? '');
        $overwrite = (string) ($_POST['overwrite'] ?? '') === '1';
        if (!inv_is_month($month) || !inv_is_date($issueDate) || inv_fetch_client($pdo, $clientId) === null) {
            set_flash('error', '対象月・請求先・売上日を正しく指定してください。');
            invoice_redirect();
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT id, status FROM inv_invoices
                 WHERE billing_month = :month AND client_id = :client_id AND status IN ('draft', 'issued') FOR UPDATE"
            );
            $stmt->execute([':month' => $month, ':client_id' => $clientId]);
            $existing = $stmt->fetchAll();
            foreach ($existing as $row) {
                if ($row['status'] === 'issued') {
                    $pdo->rollBack();
                    set_flash('error', inv_month_label($month) . '分は確定済みの請求書があるため作成できません。作り直す場合は確定済みの請求書を取消してください。');
                    invoice_redirect((int) $row['id']);
                }
            }
            if ($existing && !$overwrite) {
                $pdo->rollBack();
                set_flash('error', inv_month_label($month) . '分の下書きが既にあります。作り直す場合は確認ダイアログでOKを選んでください。');
                invoice_redirect((int) $existing[0]['id']);
            }
            $deleteLines = $pdo->prepare('DELETE FROM inv_invoice_lines WHERE invoice_id = :id');
            $deleteInvoice = $pdo->prepare("DELETE FROM inv_invoices WHERE id = :id AND status = 'draft'");
            foreach ($existing as $row) {
                $deleteLines->execute([':id' => $row['id']]);
                $deleteInvoice->execute([':id' => $row['id']]);
            }

            $sources = inv_collect_sources($pdo, $clientId, $month);
            $pdo->prepare(
                "INSERT INTO inv_invoices (billing_month, client_id, issue_date, status, created_at, updated_at)
                 VALUES (:month, :client_id, :issue_date, 'draft', NOW(), NOW())"
            )->execute([':month' => $month, ':client_id' => $clientId, ':issue_date' => $issueDate]);
            $newId = (int) $pdo->lastInsertId();

            $insertLine = $pdo->prepare(
                'INSERT INTO inv_invoice_lines
                    (invoice_id, sort_order, line_type, product_code, description, quantity, unit, unit_price, amount, tax_rate, facility_id, adjustment_id)
                 VALUES (:invoice_id, :sort_order, :line_type, :product_code, :description, :quantity, :unit, :unit_price, :amount, 10.0, :facility_id, :adjustment_id)'
            );
            $sort = 0;
            foreach ($sources['usage'] as $usage) {
                $sort += 10;
                $insertLine->execute([
                    ':invoice_id' => $newId, ':sort_order' => $sort, ':line_type' => 'usage',
                    ':product_code' => $usage['product_code'], ':description' => $usage['description'],
                    ':quantity' => $usage['quantity'], ':unit' => $usage['unit'], ':unit_price' => $usage['unit_price'],
                    ':amount' => $usage['quantity'] * $usage['unit_price'],
                    ':facility_id' => $usage['facility_id'], ':adjustment_id' => null,
                ]);
            }
            foreach ($sources['adjustments'] as $adjustment) {
                $sort += 10;
                $insertLine->execute([
                    ':invoice_id' => $newId, ':sort_order' => $sort, ':line_type' => 'adjustment',
                    ':product_code' => '004', ':description' => $adjustment['description'],
                    ':quantity' => (int) $adjustment['quantity'], ':unit' => '人', ':unit_price' => (int) $adjustment['unit_price'],
                    ':amount' => (int) $adjustment['amount'],
                    ':facility_id' => $adjustment['facility_id'], ':adjustment_id' => (int) $adjustment['id'],
                ]);
            }
            inv_recalc_invoice($pdo, $newId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', '下書きの作成に失敗しました。');
            invoice_redirect();
        }
        set_flash('success', inv_month_label($month) . '分の下書きを作成しました。' . ($sources['errors'] ? '不足データがあるため確定できません。' : ''));
        invoice_redirect($newId);
    }

    $invoice = $invoiceId > 0 ? inv_fetch_invoice($pdo, $invoiceId) : null;
    if ($invoice === null) {
        set_flash('error', '請求書が見つかりません。');
        invoice_redirect();
    }

    if ($action === 'save') {
        if ($invoice['status'] !== 'draft') {
            set_flash('error', '確定済み・取消済みの請求書は編集できません。');
            invoice_redirect($invoiceId);
        }
        $errors = [];
        $issueDate = (string) ($_POST['issue_date'] ?? '');
        if (!inv_is_date($issueDate)) {
            $errors[] = '売上日を正しく入力してください。';
        }
        $note = trim((string) ($_POST['note'] ?? ''));

        $existingLines = [];
        foreach (inv_fetch_lines($pdo, $invoiceId) as $line) {
            $existingLines[(int) $line['id']] = $line;
        }
        $deleteIds = array_map('intval', (array) ($_POST['delete'] ?? []));
        $postedLines = (array) ($_POST['lines'] ?? []);
        $updates = [];
        $validateLine = function (array $input, string $label) use (&$errors): ?array {
            $description = trim((string) ($input['description'] ?? ''));
            $productCode = trim((string) ($input['product_code'] ?? ''));
            $unit = trim((string) ($input['unit'] ?? ''));
            $quantity = inv_parse_int($input['quantity'] ?? null);
            $unitPrice = inv_parse_int($input['unit_price'] ?? null);
            $lineErrors = [];
            if ($description === '' || mb_strlen($description) > 200) {
                $lineErrors[] = '商品名は1〜200文字';
            }
            if (mb_strlen($productCode) > 10 || mb_strlen($unit) > 10) {
                $lineErrors[] = '商品コード・単位は10文字以内';
            }
            if ($quantity === null || $unitPrice === null) {
                $lineErrors[] = '数量・単価は整数';
            }
            if ($lineErrors) {
                $errors[] = $label . '：' . implode('、', $lineErrors);
                return null;
            }
            return [
                'description' => $description, 'product_code' => $productCode, 'unit' => $unit,
                'quantity' => $quantity, 'unit_price' => $unitPrice, 'amount' => $quantity * $unitPrice,
            ];
        };
        $rowNo = 0;
        foreach ($existingLines as $lineId => $line) {
            $rowNo++;
            if (in_array($lineId, $deleteIds, true) && $line['line_type'] === 'manual') {
                continue;
            }
            if (!isset($postedLines[$lineId]) || !is_array($postedLines[$lineId])) {
                $errors[] = '明細の送信内容が不足しています。画面を再読み込みしてください。';
                break;
            }
            $validated = $validateLine($postedLines[$lineId], $rowNo . '行目');
            if ($validated !== null) {
                $updates[$lineId] = $validated;
            }
        }
        $newLine = null;
        $newInput = (array) ($_POST['new_line'] ?? []);
        if (trim((string) ($newInput['description'] ?? '')) !== '' || trim((string) ($newInput['quantity'] ?? '')) !== '' || trim((string) ($newInput['unit_price'] ?? '')) !== '') {
            $newLine = $validateLine($newInput, '追加行');
        }

        if ($errors) {
            set_flash('error', '保存していません。' . implode(' ／ ', $errors));
            invoice_redirect($invoiceId);
        }

        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                'UPDATE inv_invoice_lines
                 SET description = :description, product_code = :product_code, unit = :unit,
                     quantity = :quantity, unit_price = :unit_price, amount = :amount
                 WHERE id = :id AND invoice_id = :invoice_id'
            );
            foreach ($updates as $lineId => $values) {
                $update->execute([
                    ':description' => $values['description'], ':product_code' => $values['product_code'], ':unit' => $values['unit'],
                    ':quantity' => $values['quantity'], ':unit_price' => $values['unit_price'], ':amount' => $values['amount'],
                    ':id' => $lineId, ':invoice_id' => $invoiceId,
                ]);
            }
            $delete = $pdo->prepare("DELETE FROM inv_invoice_lines WHERE id = :id AND invoice_id = :invoice_id AND line_type = 'manual'");
            foreach ($deleteIds as $lineId) {
                $delete->execute([':id' => $lineId, ':invoice_id' => $invoiceId]);
            }
            if ($newLine !== null) {
                $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM inv_invoice_lines WHERE invoice_id = :id');
                $stmt->execute([':id' => $invoiceId]);
                $pdo->prepare(
                    "INSERT INTO inv_invoice_lines
                        (invoice_id, sort_order, line_type, product_code, description, quantity, unit, unit_price, amount, tax_rate)
                     VALUES (:invoice_id, :sort_order, 'manual', :product_code, :description, :quantity, :unit, :unit_price, :amount, 10.0)"
                )->execute([
                    ':invoice_id' => $invoiceId, ':sort_order' => (int) $stmt->fetchColumn() + 10,
                    ':product_code' => $newLine['product_code'], ':description' => $newLine['description'],
                    ':quantity' => $newLine['quantity'], ':unit' => $newLine['unit'],
                    ':unit_price' => $newLine['unit_price'], ':amount' => $newLine['amount'],
                ]);
            }
            $pdo->prepare('UPDATE inv_invoices SET issue_date = :issue_date, note = :note WHERE id = :id')
                ->execute([':issue_date' => $issueDate, ':note' => $note === '' ? null : $note, ':id' => $invoiceId]);
            inv_recalc_invoice($pdo, $invoiceId);
            $pdo->commit();
            set_flash('success', '下書きを保存しました（合計を再計算しました）。');
        } catch (Throwable $e) {
            $pdo->rollBack();
            set_flash('error', '下書きの保存に失敗しました。');
        }
        invoice_redirect($invoiceId);
    }

    if ($action === 'delete_draft') {
        if ($invoice['status'] !== 'draft') {
            set_flash('error', '削除できるのは下書きのみです。');
            invoice_redirect($invoiceId);
        }
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM inv_invoice_lines WHERE invoice_id = :id')->execute([':id' => $invoiceId]);
        $pdo->prepare("DELETE FROM inv_invoices WHERE id = :id AND status = 'draft'")->execute([':id' => $invoiceId]);
        $pdo->commit();
        set_flash('success', '下書きを削除しました。');
        invoice_redirect();
    }

    if ($action === 'issue') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM inv_invoices WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $invoiceId]);
            $invoice = $stmt->fetch();
            if ($invoice === false || $invoice['status'] !== 'draft') {
                throw new RuntimeException('確定できるのは下書きのみです。');
            }
            $lines = inv_fetch_lines($pdo, $invoiceId);
            $errors = inv_draft_errors($pdo, $invoice, $lines);
            if ($errors) {
                throw new RuntimeException('確定できません。' . implode(' ／ ', $errors));
            }
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM inv_invoices WHERE billing_month = :month AND client_id = :client_id AND status = 'issued'"
            );
            $stmt->execute([':month' => $invoice['billing_month'], ':client_id' => $invoice['client_id']]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('同じ月・請求先の確定済み請求書が既にあります。');
            }
            $issuer = $pdo->query('SELECT * FROM inv_issuer WHERE id = 1 FOR UPDATE')->fetch();
            $client = inv_fetch_client($pdo, (int) $invoice['client_id']);
            if ($issuer === false || $client === null) {
                throw new RuntimeException('当方情報・請求先が登録されていません。');
            }
            $invoiceNo = (int) $issuer['next_invoice_no'];
            $totals = inv_recalc_invoice($pdo, $invoiceId);
            $issuerSnapshot = $issuer;
            unset($issuerSnapshot['next_invoice_no'], $issuerSnapshot['updated_at']);
            $clientSnapshot = $client;
            unset($clientSnapshot['updated_at']);

            $pdo->prepare(
                "UPDATE inv_invoices
                 SET invoice_no = :invoice_no, status = 'issued', issuer_snapshot = :issuer, client_snapshot = :client,
                     issued_at = NOW(), updated_at = NOW()
                 WHERE id = :id AND status = 'draft'"
            )->execute([
                ':invoice_no' => $invoiceNo,
                ':issuer' => json_encode($issuerSnapshot, JSON_UNESCAPED_UNICODE),
                ':client' => json_encode($clientSnapshot, JSON_UNESCAPED_UNICODE),
                ':id' => $invoiceId,
            ]);
            $pdo->prepare('UPDATE inv_issuer SET next_invoice_no = :next WHERE id = 1')->execute([':next' => $invoiceNo + 1]);

            $applyAdjustment = $pdo->prepare(
                'UPDATE inv_adjustments SET applied_invoice_id = :invoice_id WHERE id = :id AND applied_invoice_id IS NULL'
            );
            foreach ($lines as $line) {
                if ($line['line_type'] === 'adjustment' && $line['adjustment_id'] !== null) {
                    $applyAdjustment->execute([':invoice_id' => $invoiceId, ':id' => (int) $line['adjustment_id']]);
                    if ($applyAdjustment->rowCount() !== 1) {
                        throw new RuntimeException('訂正・値引きの反映に失敗しました（他の請求書に反映済みの可能性があります）。');
                    }
                }
            }
            $pdo->commit();
            set_flash('success', '請求書を確定しました（No. ' . inv_format_no($invoiceNo) . '、合計 ' . inv_yen($totals['total_incl']) . '円）。');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', $e instanceof RuntimeException ? $e->getMessage() : '確定処理に失敗しました。');
        }
        invoice_redirect($invoiceId);
    }

    if ($action === 'void') {
        $reason = trim((string) ($_POST['void_reason'] ?? ''));
        if ($invoice['status'] !== 'issued') {
            set_flash('error', '取消できるのは確定済みの請求書のみです。');
        } elseif ($reason === '') {
            set_flash('error', '取消理由を入力してください。');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE inv_invoices SET status = 'void', voided_at = NOW(), void_reason = :reason, updated_at = NOW()
                 WHERE id = :id AND status = 'issued'"
            )->execute([':reason' => $reason, ':id' => $invoiceId]);
            // 取消した請求書に反映していた訂正・値引きは未反映に戻し、再作成する請求書に載せられるようにする。
            $pdo->prepare('UPDATE inv_adjustments SET applied_invoice_id = NULL WHERE applied_invoice_id = :id')
                ->execute([':id' => $invoiceId]);
            $pdo->commit();
            set_flash('success', '請求書 No. ' . inv_format_no((int) $invoice['invoice_no']) . ' を取消しました（番号は欠番として残ります）。');
        }
        invoice_redirect($invoiceId);
    }

    set_flash('error', '不明な操作です。');
    invoice_redirect($invoiceId);
}

$flash = pop_flash();
$csrfToken = csrf_token();
$clients = $pdo->query('SELECT * FROM inv_clients ORDER BY is_active DESC, client_code, id')->fetchAll();
$clientNames = [];
foreach ($clients as $client) {
    $clientNames[(int) $client['id']] = $client['name'];
}

$viewId = (int) ($_GET['id'] ?? 0);
$invoice = $viewId > 0 ? inv_fetch_invoice($pdo, $viewId) : null;
$lines = $invoice !== null ? inv_fetch_lines($pdo, $viewId) : [];
$draftErrors = ($invoice !== null && $invoice['status'] === 'draft') ? inv_draft_errors($pdo, $invoice, $lines) : [];

$invoices = $pdo->query('SELECT * FROM inv_invoices ORDER BY billing_month DESC, id DESC')->fetchAll();
$draftKeys = [];
foreach ($invoices as $row) {
    if ($row['status'] === 'draft') {
        $draftKeys[$row['billing_month'] . '|' . $row['client_id']] = true;
    }
}
$defaultMonth = (new DateTimeImmutable('first day of last month'))->format('Y-m');
$lineTypeLabels = ['usage' => '利用', 'adjustment' => '訂正・値引き', 'manual' => '手動'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>請求書 | 管理者</title>
    <style>
<?= inv_common_css() ?>
        table.lines input[type=text] { width: 100%; box-sizing: border-box; }
        table.lines input.n { width: 7em; text-align: right; }
        table.lines input.s { width: 4.5em; }
        .totals { border-collapse: collapse; margin: 8px 0 16px auto; }
        .totals th, .totals td { border: 1px solid #ccc; padding: 6px 12px; }
        .totals td { text-align: right; min-width: 8em; }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-start; margin: 16px 0; }
    </style>
</head>
<body>
<header>
    <h1>請求書</h1>
    <nav><?= inv_admin_nav($admin, 'invoice') ?></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= inv_h($flash['type']) ?>"><?= inv_h($flash['message']) ?></p>
<?php endif; ?>

<?php if ($invoice !== null): ?>
    <?php $isDraft = $invoice['status'] === 'draft'; ?>
    <p><a href="/admin/invoice.php">← 請求書一覧</a></p>
    <h2>
        <?= inv_h(inv_month_label($invoice['billing_month'])) ?>分　<?= inv_h($clientNames[(int) $invoice['client_id']] ?? '（不明な請求先）') ?>
        　<span class="status-<?= inv_h($invoice['status']) ?>"><?= inv_h(inv_status_label($invoice['status'])) ?></span>
        　No. <?= inv_h(inv_format_no($invoice['invoice_no'] !== null ? (int) $invoice['invoice_no'] : null)) ?>
    </h2>
    <p><a href="/admin/invoice_print.php?id=<?= (int) $invoice['id'] ?>" target="_blank" rel="noopener">印刷表示<?= $isDraft ? '（下書きプレビュー）' : '' ?></a></p>

    <?php if ($invoice['status'] === 'void'): ?>
        <p class="notice">取消日時：<?= inv_h($invoice['voided_at']) ?>　理由：<?= inv_h($invoice['void_reason']) ?></p>
    <?php endif; ?>

    <?php if ($draftErrors): ?>
        <div class="message error">
            <strong>確定できません：</strong>
            <ul>
                <?php foreach ($draftErrors as $error): ?>
                    <li><?= inv_h($error) ?></li>
                <?php endforeach; ?>
            </ul>
            入居者数は <a href="/admin/linen_trends.php">推移予測（月末入居者数の入力）</a>、単価は <a href="/admin/invoice_settings.php?tab=prices">請求設定（施設別単価）</a> で登録してから、下書きを作り直してください。
        </div>
    <?php endif; ?>

    <?php if ($isDraft): ?>
    <form method="post" action="/admin/invoice.php">
        <input type="hidden" name="csrf_token" value="<?= inv_h($csrfToken) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
    <?php endif; ?>
        <p>
            売上日：
            <?php if ($isDraft): ?>
                <input type="date" name="issue_date" value="<?= inv_h($invoice['issue_date']) ?>" required>
            <?php else: ?>
                <?= inv_h($invoice['issue_date']) ?>
            <?php endif; ?>
        </p>
        <table class="list lines">
            <thead>
                <tr>
                    <th>種別</th><th>商品コード</th><th>商品名</th><th class="num">数量</th><th>単位</th><th class="num">単価</th><th class="num">金額</th><th>備考</th>
                    <?php if ($isDraft): ?><th>削除</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <?php $lineId = (int) $line['id']; ?>
                    <tr>
                        <td><?= inv_h($lineTypeLabels[$line['line_type']] ?? $line['line_type']) ?></td>
                        <?php if ($isDraft): ?>
                            <td><input type="text" class="s" name="lines[<?= $lineId ?>][product_code]" value="<?= inv_h($line['product_code']) ?>" maxlength="10"></td>
                            <td><input type="text" name="lines[<?= $lineId ?>][description]" value="<?= inv_h($line['description']) ?>" maxlength="200" required></td>
                            <td class="num"><input type="text" class="n" inputmode="numeric" name="lines[<?= $lineId ?>][quantity]" value="<?= (int) $line['quantity'] ?>" required></td>
                            <td><input type="text" class="s" name="lines[<?= $lineId ?>][unit]" value="<?= inv_h($line['unit']) ?>" maxlength="10"></td>
                            <td class="num"><input type="text" class="n" inputmode="numeric" name="lines[<?= $lineId ?>][unit_price]" value="<?= (int) $line['unit_price'] ?>" required></td>
                        <?php else: ?>
                            <td><?= inv_h($line['product_code']) ?></td>
                            <td><?= inv_h($line['description']) ?></td>
                            <td class="num"><?= inv_yen((int) $line['quantity']) ?></td>
                            <td><?= inv_h($line['unit']) ?></td>
                            <td class="num"><?= inv_yen((int) $line['unit_price']) ?></td>
                        <?php endif; ?>
                        <td class="num<?= (int) $line['amount'] < 0 ? ' neg' : '' ?>"><?= inv_yen((int) $line['amount']) ?></td>
                        <td>課<?= inv_h($line['tax_rate']) ?>%</td>
                        <?php if ($isDraft): ?>
                            <td><?php if ($line['line_type'] === 'manual'): ?><label><input type="checkbox" name="delete[]" value="<?= $lineId ?>"> 削除</label><?php endif; ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($isDraft): ?>
                    <tr>
                        <td>手動行を追加</td>
                        <td><input type="text" class="s" name="new_line[product_code]" value="004" maxlength="10"></td>
                        <td><input type="text" name="new_line[description]" value="" maxlength="200" placeholder="商品名（入力した場合のみ追加）"></td>
                        <td class="num"><input type="text" class="n" inputmode="numeric" name="new_line[quantity]" value=""></td>
                        <td><input type="text" class="s" name="new_line[unit]" value="人" maxlength="10"></td>
                        <td class="num"><input type="text" class="n" inputmode="numeric" name="new_line[unit_price]" value=""></td>
                        <td></td><td>課10.0%</td><td></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <table class="totals">
            <tr><th>【課税10.0% 税込額】</th><td><?= inv_yen((int) $invoice['total_incl']) ?></td></tr>
            <tr><th>【内消費税額】</th><td><?= inv_yen((int) $invoice['tax']) ?></td></tr>
            <tr><th>税抜額</th><td><?= inv_yen((int) $invoice['total_excl']) ?></td></tr>
            <tr><th>合計</th><td><strong><?= inv_yen((int) $invoice['total_incl']) ?></strong></td></tr>
        </table>
        <p>
            備考（請求書の下部に印字）：<br>
            <?php if ($isDraft): ?>
                <textarea name="note" rows="2" cols="60"><?= inv_h($invoice['note']) ?></textarea>
            <?php else: ?>
                <?= nl2br(inv_h($invoice['note'] ?? '')) ?>
            <?php endif; ?>
        </p>
    <?php if ($isDraft): ?>
        <button type="submit" class="primary">保存して再計算</button>
    </form>

    <div class="actions">
        <form method="post" action="/admin/invoice.php" onsubmit="return confirm('この内容で確定します。確定後は編集できません（修正は取消→再作成、または翌月の訂正で行います）。よろしいですか？');">
            <input type="hidden" name="csrf_token" value="<?= inv_h($csrfToken) ?>">
            <input type="hidden" name="action" value="issue">
            <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
            <button type="submit" class="primary"<?= $draftErrors ? ' disabled' : '' ?>>確定（請求書No.を採番）</button>
        </form>
        <form method="post" action="/admin/invoice.php" onsubmit="return confirm('この下書きを削除します。よろしいですか？');">
            <input type="hidden" name="csrf_token" value="<?= inv_h($csrfToken) ?>">
            <input type="hidden" name="action" value="delete_draft">
            <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
            <button type="submit" class="danger">下書きを削除</button>
        </form>
    </div>
    <p class="muted">未保存の編集内容は確定時に反映されません。編集した場合は先に「保存して再計算」を押してください。</p>
    <?php elseif ($invoice['status'] === 'issued'): ?>
        <h2>取消</h2>
        <form method="post" action="/admin/invoice.php" onsubmit="return confirm('この請求書を取消します。番号は欠番として残ります。よろしいですか？');">
            <input type="hidden" name="csrf_token" value="<?= inv_h($csrfToken) ?>">
            <input type="hidden" name="action" value="void">
            <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
            <p>取消理由（必須）：<br><textarea name="void_reason" rows="2" cols="60" required></textarea></p>
            <button type="submit" class="danger">取消する</button>
        </form>
        <p class="muted">確定日時：<?= inv_h($invoice['issued_at']) ?>。確定済みの請求書は編集できません。修正は「取消 → 下書きを作り直して再確定」か、翌月以降の「訂正・値引き」で行ってください。</p>
    <?php endif; ?>

<?php else: ?>
    <h2>下書きを作成</h2>
    <?php if (!$clients): ?>
        <p class="notice">請求先が登録されていません。<a href="/admin/invoice_settings.php?tab=clients">請求設定</a>で登録してください。</p>
    <?php else: ?>
        <form method="post" action="/admin/invoice.php" id="generate-form">
            <input type="hidden" name="csrf_token" value="<?= inv_h($csrfToken) ?>">
            <input type="hidden" name="action" value="generate">
            <input type="hidden" name="overwrite" value="0">
            <div class="form-grid">
                <label for="g-month">対象月</label>
                <input type="month" id="g-month" name="month" value="<?= inv_h($defaultMonth) ?>" required>
                <label for="g-client">請求先</label>
                <select id="g-client" name="client_id">
                    <?php foreach ($clients as $client): ?>
                        <option value="<?= (int) $client['id'] ?>"><?= inv_h('(' . $client['client_code'] . ') ' . $client['name'] . ($client['is_active'] ? '' : '（無効）')) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="g-date">売上日</label>
                <input type="date" id="g-date" name="issue_date" value="<?= inv_h(date('Y-m-d')) ?>" required>
            </div>
            <p><button type="submit" class="primary">下書き作成</button></p>
            <p class="muted">月末入居者数（推移予測で入力）× 施設別単価 で明細を作り、反映月が対象月の未反映の訂正・値引きを追加します。</p>
        </form>
        <script>
            (function () {
                var drafts = <?= json_encode((object) $draftKeys) ?>;
                var form = document.getElementById('generate-form');
                form.addEventListener('submit', function (e) {
                    var key = form.month.value + '|' + form.client_id.value;
                    if (drafts[key]) {
                        if (!confirm('この月・請求先の下書きが既にあります。編集内容を破棄して作り直しますか？')) {
                            e.preventDefault();
                            return;
                        }
                        form.overwrite.value = '1';
                    }
                });
            })();
        </script>
    <?php endif; ?>

    <h2>請求書一覧</h2>
    <?php if (!$invoices): ?>
        <p class="notice">請求書はまだありません。</p>
    <?php else: ?>
        <table class="list">
            <thead>
                <tr><th>請求書No.</th><th>対象月</th><th>請求先</th><th>状態</th><th>売上日</th><th class="num">合計（税込）</th><th class="num">内消費税</th><th>確定日時</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $row): ?>
                    <tr>
                        <td><?= inv_h(inv_format_no($row['invoice_no'] !== null ? (int) $row['invoice_no'] : null)) ?></td>
                        <td><?= inv_h(inv_month_label($row['billing_month'])) ?></td>
                        <td><?= inv_h($clientNames[(int) $row['client_id']] ?? '') ?></td>
                        <td class="status-<?= inv_h($row['status']) ?>"><?= inv_h(inv_status_label($row['status'])) ?></td>
                        <td><?= inv_h($row['issue_date']) ?></td>
                        <td class="num"><?= inv_yen((int) $row['total_incl']) ?></td>
                        <td class="num"><?= inv_yen((int) $row['tax']) ?></td>
                        <td><?= inv_h($row['issued_at'] ?? '') ?></td>
                        <td><a href="/admin/invoice.php?id=<?= (int) $row['id'] ?>"><?= $row['status'] === 'draft' ? '確認・修正' : '詳細' ?></a> | <a href="/admin/invoice_print.php?id=<?= (int) $row['id'] ?>" target="_blank" rel="noopener">印刷</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
