<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

$facilitiesStmt = $pdo->query('SELECT id, name FROM facilities ORDER BY name');
$facilities = $facilitiesStmt->fetchAll();
$validFacilityIds = array_map('intval', array_column($facilities, 'id'));
$facilityNamesById = array_column($facilities, 'name', 'id');

const CONSUMABLE_ITEM_LABELS = [
    'linen_bag_orange' => 'リネン袋（オレンジ／集荷用）',
    'linen_bag_yellow' => 'リネン袋（黄／集荷用）',
    'linen_bag_blue' => 'リネン袋（青／返却用）',
    'laundry_net' => '洗濯ネット',
];

const CONSUMABLE_STOCK_LOCATION_LABELS = [
    'warehouse' => '倉庫',
    'jiro' => 'フトン巻きのジロー',
];

const CONSUMABLE_REASON_LABELS = [
    'purchase' => '購入',
    'return_from_facility' => '施設等からの返却',
    'disposal' => '廃棄',
    'loss' => '紛失',
    'issuance_to_facility' => '施設等への交付',
    'stock_adjustment' => '実在庫への補正',
];

// この理由の場合のみ対象施設等の選択を必須にする（購入・廃棄・紛失は施設に紐づかない）
const CONSUMABLE_REASONS_REQUIRING_FACILITY = ['return_from_facility', 'issuance_to_facility'];

// 増減理由ごとに許される増減数の符号。矛盾する符号での登録（例：「施設等への交付」を選びながら
// プラスの数量を入力し、在庫が誤って増える方向に記録される）を防ぐための整合性チェックに使う。
const CONSUMABLE_REASON_SIGN = [
    'purchase' => 'positive',
    'return_from_facility' => 'positive',
    'disposal' => 'negative',
    'loss' => 'negative',
    'issuance_to_facility' => 'negative',
];

function parse_consumable_stock_input(array $post, array $validFacilityIds): array
{
    $itemType = (string) ($post['item_type'] ?? '');
    $stockLocation = (string) ($post['stock_location'] ?? 'warehouse');

    // 選択した場所の在庫について、入力は常に正数（増減の大きさ）のみを受け付け、
    // 実際の符号（＋／－）は増減理由から自動的に決定する（下記 CONSUMABLE_REASON_SIGN 参照）。
    $quantityRaw = trim((string) ($post['quantity'] ?? ''));
    $quantityMagnitude = $quantityRaw === '' || !preg_match('/^\d+$/', $quantityRaw) ? null : (int) $quantityRaw;

    $reason = (string) ($post['reason'] ?? '');
    $reason = array_key_exists($reason, CONSUMABLE_REASON_SIGN) ? $reason : null;

    $facilityIdRaw = trim((string) ($post['facility_id'] ?? ''));
    $facilityId = $facilityIdRaw === '' ? null : (int) $facilityIdRaw;
    if ($facilityId !== null && !in_array($facilityId, $validFacilityIds, true)) {
        $facilityId = false;
    }

    $transactionDateRaw = trim((string) ($post['transaction_date'] ?? ''));
    $transactionDate = null;
    if ($transactionDateRaw !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $transactionDateRaw);
        $transactionDate = $dt !== false ? $dt->format('Y-m-d') : false;
    }

    $note = trim((string) ($post['note'] ?? ''));
    $note = $note === '' ? null : $note;

    $errors = [];
    if (!array_key_exists($itemType, CONSUMABLE_ITEM_LABELS)) {
        $errors[] = '品目を選択してください。';
    }
    if (!array_key_exists($stockLocation, CONSUMABLE_STOCK_LOCATION_LABELS)) {
        $errors[] = '在庫場所を選択してください。';
    }
    if ($quantityMagnitude === null || $quantityMagnitude === 0) {
        $errors[] = '増減数は1以上の整数を正の数で入力してください。';
    }
    if ($reason === null) {
        $errors[] = '増減理由を選択してください。';
    }
    if ($facilityId === false) {
        $errors[] = '対象施設等が正しくありません。';
    } elseif ($reason !== null && $facilityId === null && in_array($reason, CONSUMABLE_REASONS_REQUIRING_FACILITY, true)) {
        $errors[] = '「' . CONSUMABLE_REASON_LABELS[$reason] . '」を選択した場合は対象施設等を選択してください。';
    }
    if ($transactionDate === false || $transactionDate === null) {
        $errors[] = '発生日の形式が正しくありません。';
    }

    // 理由が施設等に紐づかない場合（購入・廃棄・紛失）は施設等の指定を無視する
    if ($reason !== null && !in_array($reason, CONSUMABLE_REASONS_REQUIRING_FACILITY, true)) {
        $facilityId = null;
    }

    // 入力された正数の増減幅に、増減理由に応じた符号を自動で付与する
    $quantity = null;
    if ($quantityMagnitude !== null && $reason !== null && isset(CONSUMABLE_REASON_SIGN[$reason])) {
        $quantity = CONSUMABLE_REASON_SIGN[$reason] === 'negative' ? -$quantityMagnitude : $quantityMagnitude;
    }

    return [
        [
            'item_type' => $itemType,
            'stock_location' => $stockLocation,
            'quantity' => $quantity,
            'reason' => $reason,
            'facility_id' => $facilityId === false ? null : $facilityId,
            'transaction_date' => $transactionDate,
            'note' => $note,
        ],
        $errors,
    ];
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'adjust_stock') {
            $itemType = (string) ($_POST['item_type'] ?? '');
            $stockLocation = (string) ($_POST['stock_location'] ?? '');
            $actualQuantityRaw = trim((string) ($_POST['actual_quantity'] ?? ''));
            $actualQuantity = preg_match('/^\d+$/', $actualQuantityRaw) ? (int) $actualQuantityRaw : null;
            $adjustmentNote = trim((string) ($_POST['note'] ?? ''));

            if (!array_key_exists($itemType, CONSUMABLE_ITEM_LABELS)
                || !array_key_exists($stockLocation, CONSUMABLE_STOCK_LOCATION_LABELS)
                || $actualQuantity === null) {
                $errorMessage = '在庫場所・品目・現在の実数を正しく入力してください。';
            } else {
                $currentStmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(quantity), 0)
                     FROM consumable_stock_transactions
                     WHERE stock_location = :stock_location AND item_type = :item_type AND canceled_at IS NULL'
                );
                $currentStmt->execute([':stock_location' => $stockLocation, ':item_type' => $itemType]);
                $currentQuantity = (int) $currentStmt->fetchColumn();
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
                        ':created_by' => $admin['id'],
                    ]);
                    set_flash('success', CONSUMABLE_STOCK_LOCATION_LABELS[$stockLocation] . 'の実在庫に補正しました。');
                } else {
                    set_flash('success', '入力された実数は現在庫と同じため、変更はありません。');
                }
                header('Location: /admin/consumable_stock.php');
                exit;
            }
        } elseif ($action === 'create' || $action === 'update') {
            [$values, $parseErrors] = parse_consumable_stock_input($_POST, $validFacilityIds);

            if (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } elseif ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO consumable_stock_transactions (item_type, stock_location, quantity, reason, facility_id, transaction_date, note, created_by)
                     VALUES (:item_type, :stock_location, :quantity, :reason, :facility_id, :transaction_date, :note, :created_by)'
                );
                $stmt->execute([
                    ':item_type' => $values['item_type'],
                    ':stock_location' => $values['stock_location'],
                    ':quantity' => $values['quantity'],
                    ':reason' => $values['reason'],
                    ':facility_id' => $values['facility_id'],
                    ':transaction_date' => $values['transaction_date'],
                    ':note' => $values['note'],
                    ':created_by' => $admin['id'],
                ]);
                set_flash('success', '在庫記録を登録しました。');
                header('Location: /admin/consumable_stock.php');
                exit;
            } else {
                $recordId = (int) ($_POST['id'] ?? 0);
                $recordStmt = $pdo->prepare('SELECT * FROM consumable_stock_transactions WHERE id = :id AND canceled_at IS NULL');
                $recordStmt->execute([':id' => $recordId]);
                $record = $recordStmt->fetch();

                if ($record === false) {
                    $errorMessage = '対象の在庫記録が見つかりません（取り消し済みの記録は編集できません）。';
                } else {
                    $updateStmt = $pdo->prepare(
                        'UPDATE consumable_stock_transactions
                         SET item_type = :item_type, stock_location = :stock_location, quantity = :quantity, reason = :reason, facility_id = :facility_id,
                             transaction_date = :transaction_date, note = :note
                         WHERE id = :id'
                    );
                    $updateStmt->execute([
                        ':item_type' => $values['item_type'],
                        ':stock_location' => $values['stock_location'],
                        ':quantity' => $values['quantity'],
                        ':reason' => $values['reason'],
                        ':facility_id' => $values['facility_id'],
                        ':transaction_date' => $values['transaction_date'],
                        ':note' => $values['note'],
                        ':id' => $recordId,
                    ]);
                    set_flash('success', '在庫記録を更新しました。');
                    header('Location: /admin/consumable_stock.php');
                    exit;
                }
            }
        } elseif ($action === 'cancel') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare(
                'UPDATE consumable_stock_transactions
                 SET canceled_at = NOW(), canceled_by = :canceled_by
                 WHERE id = :id AND canceled_at IS NULL'
            );
            $stmt->execute([':canceled_by' => $admin['id'], ':id' => $recordId]);
            set_flash('success', '在庫記録を取り消しました。');
            header('Location: /admin/consumable_stock.php');
            exit;
        } elseif ($action === 'restore') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare(
                'UPDATE consumable_stock_transactions
                 SET canceled_at = NULL, canceled_by = NULL
                 WHERE id = :id AND canceled_at IS NOT NULL'
            );
            $stmt->execute([':id' => $recordId]);
            set_flash('success', '取り消しを取り消しました。');
            header('Location: /admin/consumable_stock.php');
            exit;
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();

// ---- 現在庫の集計 ----
$stockTotals = [];
foreach (CONSUMABLE_STOCK_LOCATION_LABELS as $locationKey => $_locationLabel) {
    $stockTotals[$locationKey] = array_fill_keys(array_keys(CONSUMABLE_ITEM_LABELS), 0);
}
$totalsStmt = $pdo->query(
    'SELECT stock_location, item_type, SUM(quantity) AS total
     FROM consumable_stock_transactions
     WHERE canceled_at IS NULL
     GROUP BY stock_location, item_type'
);
foreach ($totalsStmt->fetchAll() as $row) {
    $stockTotals[$row['stock_location']][$row['item_type']] = (int) $row['total'];
}

// ---- 編集対象の読み込み ----
$editingRecord = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM consumable_stock_transactions WHERE id = :id AND canceled_at IS NULL');
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $editingRecord = $row;
    }
}

$formAction = 'create';
$formId = null;
$formItemType = '';
$formStockLocation = 'warehouse';
$formQuantity = '';
$formReason = '';
$formFacilityId = '';
$formTransactionDate = (new DateTime())->format('Y-m-d');
$formNote = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
    $formAction = (string) ($_POST['action'] ?? 'create');
    $formId = $formAction === 'update' ? (int) ($_POST['id'] ?? 0) : null;
    $formItemType = (string) ($_POST['item_type'] ?? '');
    $formStockLocation = (string) ($_POST['stock_location'] ?? 'warehouse');
    $formQuantity = (string) ($_POST['quantity'] ?? '');
    $formReason = (string) ($_POST['reason'] ?? '');
    $formFacilityId = (string) ($_POST['facility_id'] ?? '');
    $formTransactionDate = (string) ($_POST['transaction_date'] ?? '');
    $formNote = (string) ($_POST['note'] ?? '');
} elseif ($editingRecord !== null) {
    $formAction = 'update';
    $formId = (int) $editingRecord['id'];
    $formItemType = $editingRecord['item_type'];
    $formStockLocation = $editingRecord['stock_location'];
    // DB上は符号付きで保存されているが、入力欄は常に正数（大きさ）のみを表示する
    $formQuantity = (string) abs((int) $editingRecord['quantity']);
    $formReason = $editingRecord['reason'];
    $formFacilityId = $editingRecord['facility_id'] !== null ? (string) $editingRecord['facility_id'] : '';
    $formTransactionDate = $editingRecord['transaction_date'];
    $formNote = (string) ($editingRecord['note'] ?? '');
}

// ---- 一覧の取得 ----
$listStmt = $pdo->query(
    "SELECT t.id, t.item_type, t.stock_location, t.quantity, t.reason, t.facility_id, t.transaction_date, t.note, t.canceled_at, t.created_at,
            creator.name AS created_by_name, canceler.name AS canceled_by_name, f.name AS facility_name
     FROM consumable_stock_transactions t
     INNER JOIN employees creator ON creator.id = t.created_by
     LEFT JOIN employees canceler ON canceler.id = t.canceled_by
     LEFT JOIN facilities f ON f.id = t.facility_id
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
    <title>消耗品在庫管理 | 管理者</title>
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
        .form-row { margin-bottom: 8px; }
        .form-row label { display: inline-block; width: 110px; vertical-align: top; }
        .form-row input[type="text"], .form-row input[type="date"], .form-row input[type="number"], .form-row select { width: 260px; }
        table.stock-table { border-collapse: collapse; width: 100%; max-width: 900px; }
        table.stock-table th, table.stock-table td { border: 1px solid #ccc; padding: 8px 10px; text-align: right; }
        table.stock-table th:first-child, table.stock-table td:first-child { text-align: left; }
        table.stock-table th { background: #f5f5f5; }
        .stock-value { font-size: 1.15em; font-weight: bold; }
        .stock-value.negative { color: #b3261e; }
        table.records { border-collapse: collapse; width: 100%; }
        table.records th, table.records td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
        table.records th { background: #f5f5f5; }
        .inline-form { display: inline; }
        .qty-positive { color: #1e7e34; }
        .qty-negative { color: #b3261e; }
        .status-badge { display: inline-block; font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
        .status-active { background: #e6f4ea; color: #1e7e34; }
        .status-canceled { background: #eee; color: #777; }
        tr.canceled-row { color: #999; }
    </style>
</head>
<body>
<header>
    <h1>消耗品在庫管理</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/facilities.php">施設管理</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
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
            <tr><th>品目</th><th>倉庫在庫</th><th>フトン巻きのジロー在庫</th><th>合計在庫</th></tr>
        </thead>
        <tbody>
        <?php foreach (CONSUMABLE_ITEM_LABELS as $itemType => $label): ?>
            <?php
            $warehouseStock = $stockTotals['warehouse'][$itemType];
            $jiroStock = $stockTotals['jiro'][$itemType];
            $combinedStock = $warehouseStock + $jiroStock;
            ?>
            <tr>
                <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
                <td class="stock-value <?= $warehouseStock < 0 ? 'negative' : '' ?>"><?= (int) $warehouseStock ?>枚</td>
                <td class="stock-value <?= $jiroStock < 0 ? 'negative' : '' ?>"><?= (int) $jiroStock ?>枚</td>
                <td class="stock-value <?= $combinedStock < 0 ? 'negative' : '' ?>"><?= (int) $combinedStock ?>枚</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="stock-adjustment">
    <h2>実在庫に合わせて補正</h2>
    <p>現物を数えた結果を入力してください。現在庫との差分だけが補正履歴として記録され、自動増減の履歴は残ります。</p>
    <fieldset>
        <form method="post" action="/admin/consumable_stock.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="adjust_stock">
            <div class="form-row">
                <label for="adjust_stock_location">在庫場所</label>
                <select id="adjust_stock_location" name="stock_location" required>
                    <?php foreach (CONSUMABLE_STOCK_LOCATION_LABELS as $locationKey => $locationLabel): ?>
                        <option value="<?= htmlspecialchars($locationKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($locationLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="adjust_item_type">品目</label>
                <select id="adjust_item_type" name="item_type" required>
                    <?php foreach (CONSUMABLE_ITEM_LABELS as $itemType => $label): ?>
                        <option value="<?= htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="actual_quantity">現在の実数</label>
                <input type="number" id="actual_quantity" name="actual_quantity" min="0" step="1" required> 枚
            </div>
            <div class="form-row">
                <label for="adjust_note">備考</label>
                <input type="text" id="adjust_note" name="note" maxlength="180" placeholder="棚卸し、破損確認など">
            </div>
            <button type="submit">実在庫に補正する</button>
        </form>
    </fieldset>
</section>

<section class="stock-form">
    <h2><?= $formAction === 'update' ? '在庫記録の編集' : '在庫記録の追加' ?></h2>
    <fieldset>
        <form method="post" action="/admin/consumable_stock.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= $formAction === 'update' ? 'update' : 'create' ?>">
            <?php if ($formAction === 'update'): ?>
                <input type="hidden" name="id" value="<?= (int) $formId ?>">
            <?php endif; ?>

            <div class="form-row">
                <label for="item_type">品目</label>
                <select id="item_type" name="item_type" required>
                    <option value="">選択してください</option>
                    <?php foreach (CONSUMABLE_ITEM_LABELS as $itemType => $label): ?>
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

            <button type="submit"><?= $formAction === 'update' ? '更新する' : '登録する' ?></button>
            <?php if ($formAction === 'update'): ?>
                <a href="/admin/consumable_stock.php">キャンセル</a>
            <?php endif; ?>
        </form>
    </fieldset>
</section>

<section class="record-list">
    <h2>在庫増減履歴</h2>
    <?php if (empty($records)): ?>
        <p class="notice">在庫記録がありません。上のフォームから追加してください。</p>
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
                    <th>状態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <?php $isCanceled = $record['canceled_at'] !== null; ?>
                    <tr class="<?= $isCanceled ? 'canceled-row' : '' ?>">
                        <td><?= htmlspecialchars($record['transaction_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(CONSUMABLE_STOCK_LOCATION_LABELS[$record['stock_location']] ?? $record['stock_location'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(CONSUMABLE_ITEM_LABELS[$record['item_type']] ?? $record['item_type'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="<?= (int) $record['quantity'] >= 0 ? 'qty-positive' : 'qty-negative' ?>">
                            <?= (int) $record['quantity'] >= 0 ? '+' : '' ?><?= (int) $record['quantity'] ?>
                        </td>
                        <td><?= htmlspecialchars(CONSUMABLE_REASON_LABELS[$record['reason']] ?? $record['reason'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $record['facility_name'] !== null ? htmlspecialchars($record['facility_name'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= $record['note'] !== null ? htmlspecialchars($record['note'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= htmlspecialchars($record['created_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($isCanceled): ?>
                                <span class="status-badge status-canceled">取消済み</span>
                            <?php else: ?>
                                <span class="status-badge status-active">有効</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$isCanceled): ?>
                                <?php if ($record['reason'] !== 'stock_adjustment'): ?>
                                    <a href="/admin/consumable_stock.php?edit=<?= (int) $record['id'] ?>">編集</a>
                                <?php endif; ?>
                                <form method="post" action="/admin/consumable_stock.php" class="inline-form" onsubmit="return confirm('この在庫記録を取り消しますか？');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                    <button type="submit">取り消し</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/admin/consumable_stock.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                    <button type="submit">取り消しを戻す</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
</body>
</html>
