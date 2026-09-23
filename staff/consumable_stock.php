<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/consumable_stock_common.php';

$staff = require_login('staff');
$pdo = getPdo();

// 消耗品品目マスタ（consumable_items、管理者の /admin/consumable_items.php で管理）から取得する。
// $itemLabels は無効化された品目も含む全品目（履歴表示用）、$activeItemLabels は有効な品目のみ
// （現在庫の集計・新規登録フォームの対象用）。品目一覧・在庫計算・増減理由等の定数は
// includes/consumable_stock_common.php に共通化してある（admin/consumable_stock.phpと同じ物を使う）。
$itemLabels = get_consumable_item_labels($pdo);
$activeItemLabels = get_consumable_item_labels($pdo, true);

const CONSUMABLE_EDIT_TYPE_LABELS = [
    'purchase' => '購入（在庫を増やす）',
    'issuance_to_facility' => '施設等への交付（在庫を減らす）',
    'return_from_facility' => '施設等からの返却（在庫を増やす）',
    'damage' => '破損（在庫を減らす）',
    'loss' => '紛失（在庫を減らす）',
];

$facilitiesStmt = $pdo->query('SELECT id, name FROM facilities WHERE is_active = 1 ORDER BY name');
$facilities = $facilitiesStmt->fetchAll();
$validFacilityIds = array_map('intval', array_column($facilities, 'id'));

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } elseif ((string) ($_POST['action'] ?? '') === 'adjust_stock') {
        $itemType = (string) ($_POST['item_type'] ?? '');
        $stockLocation = (string) ($_POST['stock_location'] ?? '');
        $actualQuantityRaw = trim((string) ($_POST['actual_quantity'] ?? ''));
        $actualQuantity = preg_match('/^\d+$/', $actualQuantityRaw) ? (int) $actualQuantityRaw : null;
        $adjustmentNote = trim((string) ($_POST['note'] ?? ''));

        if (!array_key_exists($itemType, $activeItemLabels)
            || !array_key_exists($stockLocation, CONSUMABLE_STOCK_LOCATION_LABELS)
            || $actualQuantity === null) {
            $errorMessage = '在庫場所・品目・現在の実数を正しく入力してください。';
        } else {
            $currentQuantity = get_effective_consumable_stock($pdo, $stockLocation, $itemType);
            $delta = $actualQuantity - $currentQuantity;

            if ($delta !== 0) {
                $note = '実在庫への補正（' . $currentQuantity . '枚 → ' . $actualQuantity . '枚）';
                if ($adjustmentNote !== '') {
                    $note .= '：' . $adjustmentNote;
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO consumable_stock_transactions
                        (item_type, stock_location, quantity, reason, transaction_date, note, created_by)
                     VALUES
                        (:item_type, :stock_location, :quantity, 'stock_adjustment', :transaction_date, :note, :created_by)"
                );
                $stmt->execute([
                    ':item_type' => $itemType,
                    ':stock_location' => $stockLocation,
                    ':quantity' => $delta,
                    ':transaction_date' => (new DateTime())->format('Y-m-d'),
                    ':note' => $note,
                    ':created_by' => $staff['id'],
                ]);
                set_flash('success', CONSUMABLE_STOCK_LOCATION_LABELS[$stockLocation] . 'の実在庫に補正しました。');
            } else {
                set_flash('success', '入力された実数は現在庫と同じため、変更はありません。');
            }
            header('Location: /staff/consumable_stock.php');
            exit;
        }
    } elseif ((string) ($_POST['action'] ?? '') === 'create') {
        // 在庫記録の追加（入庫）・交付（出庫）。admin/consumable_stock.phpの新規登録と
        // 同じ共通処理（parse_consumable_stock_input/ensure_consumable_stock_sufficient/
        // insert_consumable_stock_transaction、includes/consumable_stock_common.php）を使う。
        [$createValues, $createParseErrors] = parse_consumable_stock_input($_POST, $validFacilityIds, array_keys($activeItemLabels));

        if (empty($createParseErrors)) {
            $insufficientStockError = ensure_consumable_stock_sufficient(
                $pdo,
                $createValues['stock_location'],
                $createValues['item_type'],
                (int) $createValues['quantity']
            );
            if ($insufficientStockError !== null) {
                $createParseErrors[] = $insufficientStockError;
            }
        }

        if (!empty($createParseErrors)) {
            $errorMessage = implode(' ', $createParseErrors);
        } else {
            insert_consumable_stock_transaction($pdo, $createValues, $staff['id']);
            set_flash('success', '在庫記録を登録しました。');
            header('Location: /staff/consumable_stock.php');
            exit;
        }
    } elseif ((string) ($_POST['action'] ?? '') === 'update_transaction') {
        $transactionId = (int) ($_POST['transaction_id'] ?? 0);
        $itemType = (string) ($_POST['item_type'] ?? '');
        $stockLocation = (string) ($_POST['stock_location'] ?? '');
        $transactionType = (string) ($_POST['transaction_type'] ?? '');
        $quantityRaw = trim((string) ($_POST['quantity'] ?? ''));
        $quantity = preg_match('/^[1-9]\d*$/', $quantityRaw) ? (int) $quantityRaw : null;
        $transactionDateRaw = trim((string) ($_POST['transaction_date'] ?? ''));
        $transactionDateValue = DateTime::createFromFormat('Y-m-d', $transactionDateRaw);
        $transactionDate = $transactionDateValue !== false && $transactionDateValue->format('Y-m-d') === $transactionDateRaw
            ? $transactionDateRaw
            : null;
        $facilityId = (int) ($_POST['facility_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        $needsFacility = in_array($transactionType, ['issuance_to_facility', 'return_from_facility'], true);

        $targetStmt = $pdo->prepare(
            "SELECT id FROM consumable_stock_transactions
             WHERE id = :id AND canceled_at IS NULL AND note LIKE '%集荷記録簿から登録%'"
        );
        $targetStmt->execute([':id' => $transactionId]);
        $targetExists = $targetStmt->fetchColumn() !== false;

        if (!$targetExists) {
            $errorMessage = '修正対象の履歴が見つかりません。';
        } elseif (!array_key_exists($itemType, $activeItemLabels)
            || !array_key_exists($stockLocation, CONSUMABLE_STOCK_LOCATION_LABELS)) {
            $errorMessage = '在庫場所と品目を正しく選択してください。';
        } elseif (!array_key_exists($transactionType, CONSUMABLE_EDIT_TYPE_LABELS)) {
            $errorMessage = '登録内容を正しく選択してください。';
        } elseif ($quantity === null || $transactionDate === null) {
            $errorMessage = '数量と日付を正しく入力してください。';
        } elseif ($needsFacility && !in_array($facilityId, $validFacilityIds, true)) {
            $errorMessage = '交付・返却の対象施設を選択してください。';
        } else {
            $reason = $transactionType === 'damage' ? 'disposal' : $transactionType;
            $signedQuantity = in_array($transactionType, ['purchase', 'return_from_facility'], true) ? $quantity : -$quantity;
            $facilityIdForUpdate = $needsFacility ? $facilityId : null;
            $notePrefix = $transactionType === 'damage' ? '破損' : CONSUMABLE_EDIT_TYPE_LABELS[$transactionType];
            $storedNote = $notePrefix . '（集荷記録簿から登録）' . ($note !== '' ? '：' . $note : '');

            $updateStmt = $pdo->prepare(
                'UPDATE consumable_stock_transactions
                 SET item_type = :item_type, stock_location = :stock_location, quantity = :quantity,
                     reason = :reason, facility_id = :facility_id, transaction_date = :transaction_date, note = :note
                 WHERE id = :id AND canceled_at IS NULL'
            );
            $updateStmt->execute([
                ':item_type' => $itemType,
                ':stock_location' => $stockLocation,
                ':quantity' => $signedQuantity,
                ':reason' => $reason,
                ':facility_id' => $facilityIdForUpdate,
                ':transaction_date' => $transactionDate,
                ':note' => $storedNote,
                ':id' => $transactionId,
            ]);
            set_flash('success', '在庫履歴を修正し、現在庫へ反映しました。');
            header('Location: /staff/consumable_stock.php#transaction-' . $transactionId);
            exit;
        }
    } elseif ((string) ($_POST['action'] ?? '') === 'delete_transaction') {
        $transactionId = (int) ($_POST['transaction_id'] ?? 0);
        $deleteStmt = $pdo->prepare(
            "UPDATE consumable_stock_transactions
             SET canceled_at = :canceled_at, canceled_by = :canceled_by
             WHERE id = :id AND canceled_at IS NULL AND note LIKE '%集荷記録簿から登録%'"
        );
        $deleteStmt->execute([
            ':canceled_at' => (new DateTime())->format('Y-m-d H:i:s'),
            ':canceled_by' => $staff['id'],
            ':id' => $transactionId,
        ]);
        set_flash($deleteStmt->rowCount() === 1 ? 'success' : 'error', $deleteStmt->rowCount() === 1
            ? '在庫履歴を削除し、現在庫へ反映しました。'
            : '削除対象の履歴が見つかりません。');
        header('Location: /staff/consumable_stock.php#stock-history');
        exit;
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();

// ---- 現在庫の集計 ----
$stockTotals = calc_consumable_stock_totals($pdo, array_keys($activeItemLabels));

// ---- 在庫記録の追加フォームの初期値（admin/consumable_stock.phpの新規登録フォームと同じ形） ----
$formItemType = '';
$formStockLocation = 'warehouse';
$formQuantity = '';
$formReason = '';
$formFacilityId = '';
$formTransactionDate = (new DateTime())->format('Y-m-d');
$formNote = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '' && (string) ($_POST['action'] ?? '') === 'create') {
    $formItemType = (string) ($_POST['item_type'] ?? '');
    $formStockLocation = (string) ($_POST['stock_location'] ?? 'warehouse');
    $formQuantity = (string) ($_POST['quantity'] ?? '');
    $formReason = (string) ($_POST['reason'] ?? '');
    $formFacilityId = (string) ($_POST['facility_id'] ?? '');
    $formTransactionDate = (string) ($_POST['transaction_date'] ?? '');
    $formNote = (string) ($_POST['note'] ?? '');
}

// ---- 一覧の取得（取り消し済みの記録は従業員には表示しない） ----
$listStmt = $pdo->query(
    "SELECT t.id, t.item_type, t.stock_location, t.quantity, t.reason, t.facility_id, t.transaction_date, t.note, t.created_at,
            creator.name AS created_by_name, creator.role AS created_by_role, f.name AS facility_name
     FROM consumable_stock_transactions t
     INNER JOIN employees creator ON creator.id = t.created_by
     LEFT JOIN facilities f ON f.id = t.facility_id
     WHERE t.canceled_at IS NULL
     ORDER BY t.transaction_date DESC, t.id DESC
     LIMIT 300"
);
$records = $listStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title>消耗品在庫管理 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; }
        .form-row { margin-bottom: 10px; }
        .form-row label { display: block; margin-bottom: 3px; font-weight: bold; }
        .form-row input, .form-row select { box-sizing: border-box; width: 100%; max-width: 360px; padding: 7px; }
        table.stock-table { border-collapse: collapse; width: 100%; }
        table.stock-table th, table.stock-table td { border: 1px solid #ccc; padding: 7px 6px; text-align: right; font-size: 0.88em; }
        table.stock-table th:first-child, table.stock-table td:first-child { text-align: left; }
        table.stock-table th { background: #f5f5f5; }
        .stock-value { font-weight: bold; }
        .stock-value.negative { color: #b3261e; }
        .stock-display { white-space: nowrap; }
        .stock-edit-button { margin-left: 5px; font-size: 0.8em; font-weight: normal; }
        .stock-edit-form { display: none; align-items: center; justify-content: flex-end; gap: 3px; }
        .stock-edit-form input[type="number"] { width: 65px; padding: 4px; text-align: right; }
        .stock-editing .stock-display { display: none; }
        .stock-editing .stock-edit-form { display: inline-flex; }
        table.records { border-collapse: collapse; width: 100%; }
        table.records th, table.records td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
        table.records th { background: #f5f5f5; }
        .qty-positive { color: #1e7e34; }
        .qty-negative { color: #b3261e; }
        .record-actions { white-space: nowrap; }
        .record-actions form { display: inline; }
        .record-edit-row { display: none; background: #fafafa; }
        .record-edit-row.is-open { display: table-row; }
        .record-edit-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; }
        .record-edit-grid label { display: block; font-size: 0.82em; font-weight: bold; margin-bottom: 2px; }
        .record-edit-grid input, .record-edit-grid select { box-sizing: border-box; width: 100%; padding: 5px; }
        .danger { color: #b3261e; }
        .role-badge { font-size: 0.85em; color: #777; }
    </style>
</head>
<body>
<header>
    <h1>消耗品在庫管理</h1>
    <nav><a href="/staff/consumable_items.php">品目管理</a> | <a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="stock-overview">
    <h2>現在庫</h2>
    <table class="stock-table">
        <thead>
            <tr><th>品目</th><th>倉庫＋車在庫</th><th>フトン巻きのジロー在庫</th><th>合計在庫</th></tr>
        </thead>
        <tbody>
        <?php foreach ($activeItemLabels as $itemType => $label): ?>
            <?php
            $warehouseStock = $stockTotals['warehouse'][$itemType];
            $jiroStock = $stockTotals['jiro'][$itemType];
            $combinedStock = $warehouseStock + $jiroStock;
            ?>
            <tr>
                <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
                <?php foreach (['warehouse' => $warehouseStock, 'jiro' => $jiroStock] as $locationKey => $locationStock): ?>
                    <td class="stock-value <?= $locationStock < 0 ? 'negative' : '' ?>">
                        <span class="stock-display"><?= (int) $locationStock ?>枚 <button type="button" class="stock-edit-button" onclick="toggleStockEdit(this, true)">編集</button></span>
                        <form method="post" action="/staff/consumable_stock.php" class="stock-edit-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="adjust_stock">
                            <input type="hidden" name="stock_location" value="<?= htmlspecialchars($locationKey, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="item_type" value="<?= htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="note" value="現在庫表から修正">
                            <input type="number" name="actual_quantity" min="0" step="1" value="<?= (int) $locationStock ?>" required aria-label="<?= htmlspecialchars($label . '・' . CONSUMABLE_STOCK_LOCATION_LABELS[$locationKey] . 'の実数', ENT_QUOTES, 'UTF-8') ?>">
                            <span>枚</span><button type="submit">保存</button><button type="button" onclick="toggleStockEdit(this, false)">取消</button>
                        </form>
                    </td>
                <?php endforeach; ?>
                <td class="stock-value <?= $combinedStock < 0 ? 'negative' : '' ?>"><?= (int) $combinedStock ?>枚</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="stock-form">
    <h2>在庫記録の追加（入庫・交付）</h2>
    <fieldset>
        <form method="post" action="/staff/consumable_stock.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-row">
                <label for="item_type">品目</label>
                <select id="item_type" name="item_type" required>
                    <option value="">選択してください</option>
                    <?php foreach ($activeItemLabels as $itemType => $label): ?>
                        <option value="<?= htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') ?>" <?= $formItemType === $itemType ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="stock_location">在庫場所</label>
                <select id="stock_location" name="stock_location" required>
                    <?php foreach (CONSUMABLE_STOCK_LOCATION_LABELS as $locationKey => $locationLabel): ?>
                        <option value="<?= htmlspecialchars($locationKey, ENT_QUOTES, 'UTF-8') ?>" <?= $formStockLocation === $locationKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars($locationLabel, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="quantity">増減数</label>
                <input type="number" id="quantity" name="quantity" step="1" min="1" value="<?= htmlspecialchars($formQuantity, ENT_QUOTES, 'UTF-8') ?>" required>
                <div style="font-size:0.8em;color:#777;">常に正の数（増減の大きさ）を入力してください。在庫が増えるか減るかは増減理由から自動判定されます：購入・施設等からの返却＝在庫＋／廃棄・紛失・施設等への交付＝在庫－。</div>
            </div>

            <div class="form-row">
                <label for="reason">増減理由</label>
                <select id="reason" name="reason" required>
                    <option value="">選択してください</option>
                    <?php foreach (CONSUMABLE_REASON_SIGN as $reasonKey => $_reasonSign): ?>
                        <?php $reasonLabel = CONSUMABLE_REASON_LABELS[$reasonKey]; ?>
                        <option value="<?= htmlspecialchars($reasonKey, ENT_QUOTES, 'UTF-8') ?>" <?= $formReason === $reasonKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars($reasonLabel, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="facility_id">対象施設等</label>
                <select id="facility_id" name="facility_id">
                    <option value="">（該当なし）</option>
                    <?php foreach ($facilities as $facility): ?>
                        <option value="<?= (int) $facility['id'] ?>" <?= $formFacilityId === (string) $facility['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:0.8em;color:#777;">「施設等からの返却」「施設等への交付」を選んだ場合は必須です。</div>
            </div>

            <div class="form-row">
                <label for="transaction_date">発生日</label>
                <input type="date" id="transaction_date" name="transaction_date" value="<?= htmlspecialchars($formTransactionDate, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label for="note">備考</label>
                <input type="text" id="note" name="note" maxlength="255" value="<?= htmlspecialchars($formNote, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <button type="submit">登録する</button>
        </form>
    </fieldset>
</section>

<section class="record-list" id="stock-history">
    <h2>在庫増減履歴</h2>
    <?php if (empty($records)): ?>
        <p class="notice">在庫記録がありません。</p>
    <?php else: ?>
        <table class="records">
            <thead>
                <tr>
                    <th>発生日</th>
                    <th>在庫場所</th>
                    <th>品目</th>
                    <th>増減数</th>
                    <th>理由</th>
                    <th>対象施設等</th>
                    <th>備考</th>
                    <th>登録者</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <?php
                    $isManualEditable = str_contains((string) ($record['note'] ?? ''), '集荷記録簿から登録');
                    $recordType = $record['reason'];
                    if ($recordType === 'disposal' && str_starts_with((string) ($record['note'] ?? ''), '破損')) {
                        $recordType = 'damage';
                    }
                    $recordQuantity = abs((int) $record['quantity']);
                    $userNote = '';
                    $separatorPosition = mb_strpos((string) ($record['note'] ?? ''), '：');
                    if ($separatorPosition !== false) {
                        $userNote = mb_substr((string) $record['note'], $separatorPosition + 1);
                    }
                    ?>
                    <tr id="transaction-<?= (int) $record['id'] ?>">
                        <td><?= htmlspecialchars($record['transaction_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <?php
                        $locationLabel = CONSUMABLE_STOCK_LOCATION_LABELS[$record['stock_location']] ?? $record['stock_location'];
                        if ($record['stock_location'] === 'warehouse' && $record['facility_name'] === JIRO_FACILITY_NAME) {
                            $locationLabel = $record['reason'] === 'issuance_to_facility'
                                ? '倉庫＋車 → フトン巻きのジロー'
                                : ($record['reason'] === 'return_from_facility' ? 'フトン巻きのジロー → 倉庫＋車' : $locationLabel);
                        }
                        ?>
                        <td><?= htmlspecialchars($locationLabel, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($itemLabels[$record['item_type']] ?? $record['item_type'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="<?= (int) $record['quantity'] >= 0 ? 'qty-positive' : 'qty-negative' ?>">
                            <?= (int) $record['quantity'] >= 0 ? '+' : '' ?><?= (int) $record['quantity'] ?>
                        </td>
                        <td><?= htmlspecialchars(CONSUMABLE_REASON_LABELS[$record['reason']] ?? $record['reason'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $record['facility_name'] !== null ? htmlspecialchars($record['facility_name'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= $record['note'] !== null ? htmlspecialchars($record['note'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td>
                            <?= htmlspecialchars($record['created_by_name'], ENT_QUOTES, 'UTF-8') ?>
                            <span class="role-badge">(<?= htmlspecialchars(CONSUMABLE_STOCK_ROLE_LABELS[$record['created_by_role']] ?? $record['created_by_role'], ENT_QUOTES, 'UTF-8') ?>)</span>
                        </td>
                        <td class="record-actions">
                            <?php if ($isManualEditable): ?>
                                <button type="button" onclick="toggleTransactionEdit(<?= (int) $record['id'] ?>)">編集</button>
                                <form method="post" action="/staff/consumable_stock.php#stock-history" onsubmit="return confirm('この履歴を削除し、現在庫へ反映しますか？');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="delete_transaction">
                                    <input type="hidden" name="transaction_id" value="<?= (int) $record['id'] ?>">
                                    <button type="submit" class="danger">削除</button>
                                </form>
                            <?php else: ?>
                                <span title="自動連携の記録は元の集荷・施設記録から修正してください">自動連携</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($isManualEditable): ?>
                        <tr class="record-edit-row" id="transaction-edit-<?= (int) $record['id'] ?>">
                            <td colspan="9">
                                <form method="post" action="/staff/consumable_stock.php#transaction-<?= (int) $record['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update_transaction">
                                    <input type="hidden" name="transaction_id" value="<?= (int) $record['id'] ?>">
                                    <div class="record-edit-grid">
                                        <div><label>日付</label><input type="date" name="transaction_date" value="<?= htmlspecialchars($record['transaction_date'], ENT_QUOTES, 'UTF-8') ?>" required></div>
                                        <div><label>在庫場所</label><select name="stock_location" required><?php foreach (CONSUMABLE_STOCK_LOCATION_LABELS as $locationKey => $label): ?><option value="<?= htmlspecialchars($locationKey, ENT_QUOTES, 'UTF-8') ?>" <?= $locationKey === $record['stock_location'] ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div><label>品目</label><select name="item_type" required><?php foreach ($activeItemLabels as $itemType => $label): ?><option value="<?= htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') ?>" <?= $itemType === $record['item_type'] ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div><label>登録内容</label><select name="transaction_type" required><?php foreach (CONSUMABLE_EDIT_TYPE_LABELS as $typeKey => $label): ?><option value="<?= htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8') ?>" <?= $typeKey === $recordType ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div><label>数量（枚）</label><input type="number" name="quantity" min="1" step="1" value="<?= $recordQuantity ?>" required></div>
                                        <div><label>対象施設</label><select name="facility_id"><option value="">購入・破損・紛失は選択不要</option><?php foreach ($facilities as $facility): ?><option value="<?= (int) $facility['id'] ?>" <?= (int) $facility['id'] === (int) $record['facility_id'] ? 'selected' : '' ?>><?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                                        <div><label>備考</label><input type="text" name="note" maxlength="255" value="<?= htmlspecialchars($userNote, ENT_QUOTES, 'UTF-8') ?>"></div>
                                    </div>
                                    <p><button type="submit">修正を保存</button> <button type="button" onclick="toggleTransactionEdit(<?= (int) $record['id'] ?>)">閉じる</button></p>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<script>
function toggleStockEdit(button, editing) {
    const cell = button.closest('td');
    cell.classList.toggle('stock-editing', editing);
    if (editing) cell.querySelector('input[name="actual_quantity"]').select();
}
function toggleTransactionEdit(id) {
    document.getElementById('transaction-edit-' + id)?.classList.toggle('is-open');
}
</script>
</body>
</html>
