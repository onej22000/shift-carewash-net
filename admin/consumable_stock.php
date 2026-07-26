<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

const CONSUMABLE_ITEM_LABELS = [
    'linen_bag_orange' => 'リネン袋（オレンジ）',
    'linen_bag_yellow' => 'リネン袋（黄）',
    'linen_bag_blue' => 'リネン袋（青）',
    'laundry_net' => '洗濯ネット',
];

function parse_consumable_stock_input(array $post): array
{
    $itemType = (string) ($post['item_type'] ?? '');

    $quantityRaw = trim((string) ($post['quantity'] ?? ''));
    $quantity = $quantityRaw === '' || !preg_match('/^-?\d+$/', $quantityRaw) ? null : (int) $quantityRaw;

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
    if ($quantity === null || $quantity === 0) {
        $errors[] = '増減数は0以外の整数を入力してください。';
    }
    if ($transactionDate === false || $transactionDate === null) {
        $errors[] = '発生日の形式が正しくありません。';
    }

    return [
        [
            'item_type' => $itemType,
            'quantity' => $quantity,
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

        if ($action === 'create' || $action === 'update') {
            [$values, $parseErrors] = parse_consumable_stock_input($_POST);

            if (!empty($parseErrors)) {
                $errorMessage = implode(' ', $parseErrors);
            } elseif ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO consumable_stock_transactions (item_type, quantity, transaction_date, note, created_by)
                     VALUES (:item_type, :quantity, :transaction_date, :note, :created_by)'
                );
                $stmt->execute([
                    ':item_type' => $values['item_type'],
                    ':quantity' => $values['quantity'],
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
                         SET item_type = :item_type, quantity = :quantity, transaction_date = :transaction_date, note = :note
                         WHERE id = :id'
                    );
                    $updateStmt->execute([
                        ':item_type' => $values['item_type'],
                        ':quantity' => $values['quantity'],
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
$stockTotals = array_fill_keys(array_keys(CONSUMABLE_ITEM_LABELS), 0);
$totalsStmt = $pdo->query(
    'SELECT item_type, SUM(quantity) AS total
     FROM consumable_stock_transactions
     WHERE canceled_at IS NULL
     GROUP BY item_type'
);
foreach ($totalsStmt->fetchAll() as $row) {
    $stockTotals[$row['item_type']] = (int) $row['total'];
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
$formQuantity = '';
$formTransactionDate = (new DateTime())->format('Y-m-d');
$formNote = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
    $formAction = (string) ($_POST['action'] ?? 'create');
    $formId = $formAction === 'update' ? (int) ($_POST['id'] ?? 0) : null;
    $formItemType = (string) ($_POST['item_type'] ?? '');
    $formQuantity = (string) ($_POST['quantity'] ?? '');
    $formTransactionDate = (string) ($_POST['transaction_date'] ?? '');
    $formNote = (string) ($_POST['note'] ?? '');
} elseif ($editingRecord !== null) {
    $formAction = 'update';
    $formId = (int) $editingRecord['id'];
    $formItemType = $editingRecord['item_type'];
    $formQuantity = (string) $editingRecord['quantity'];
    $formTransactionDate = $editingRecord['transaction_date'];
    $formNote = (string) ($editingRecord['note'] ?? '');
}

// ---- 一覧の取得 ----
$listStmt = $pdo->query(
    "SELECT t.id, t.item_type, t.quantity, t.transaction_date, t.note, t.canceled_at, t.created_at,
            creator.name AS created_by_name, canceler.name AS canceled_by_name
     FROM consumable_stock_transactions t
     INNER JOIN employees creator ON creator.id = t.created_by
     LEFT JOIN employees canceler ON canceler.id = t.canceled_by
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
        .stock-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
        .stock-card { border: 1px solid #ccc; border-radius: 8px; padding: 12px; background: #fff; }
        .stock-card .label { font-size: 0.85em; color: #555; }
        .stock-card .value { font-size: 1.6em; font-weight: bold; margin-top: 4px; }
        .stock-card .value.negative { color: #b3261e; }
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
    <div class="stock-summary">
        <?php foreach (CONSUMABLE_ITEM_LABELS as $itemType => $label): ?>
            <div class="stock-card">
                <div class="label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="value <?= $stockTotals[$itemType] < 0 ? 'negative' : '' ?>"><?= (int) $stockTotals[$itemType] ?>枚</div>
            </div>
        <?php endforeach; ?>
    </div>
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
                <label for="quantity">増減数</label>
                <input type="number" id="quantity" name="quantity" step="1" value="<?= htmlspecialchars($formQuantity, ENT_QUOTES, 'UTF-8') ?>" required>
                <div style="font-size:0.8em;color:#777;">入庫・購入等はプラス、使用・廃棄等はマイナスで入力してください。</div>
            </div>

            <div class="form-row">
                <label for="transaction_date">発生日</label>
                <input type="date" id="transaction_date" name="transaction_date" value="<?= htmlspecialchars($formTransactionDate, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label for="note">理由・備考</label>
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
                    <th>品目</th>
                    <th>増減数</th>
                    <th>理由・備考</th>
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
                        <td><?= htmlspecialchars(CONSUMABLE_ITEM_LABELS[$record['item_type']] ?? $record['item_type'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="<?= (int) $record['quantity'] >= 0 ? 'qty-positive' : 'qty-negative' ?>">
                            <?= (int) $record['quantity'] >= 0 ? '+' : '' ?><?= (int) $record['quantity'] ?>
                        </td>
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
                                <a href="/admin/consumable_stock.php?edit=<?= (int) $record['id'] ?>">編集</a>
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
