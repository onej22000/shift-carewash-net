<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/consumable_stock_common.php';

$staff = require_login('staff');
$pdo = getPdo();

// 従業員向けの消耗品品目登録画面。admin/consumable_items.phpの従業員版。
// 新規登録のみ可能とし、既存品目の編集・無効化・有効化は管理者画面
// （admin/consumable_items.php）のみで行う（誤操作で既存の在庫連携を壊さないため）。

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } elseif ((string) ($_POST['action'] ?? '') === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));

        $usageType = trim((string) ($_POST['usage_type'] ?? ''));
        $usageType = array_key_exists($usageType, CONSUMABLE_ITEM_USAGE_TYPE_LABELS) ? $usageType : null;

        $sortOrderRaw = trim((string) ($_POST['sort_order'] ?? ''));
        $sortOrder = $sortOrderRaw === '' || !preg_match('/^\d+$/', $sortOrderRaw) ? null : (int) $sortOrderRaw;

        if ($name === '') {
            $errorMessage = '品目名を入力してください。';
        } elseif (mb_strlen($name) > 100) {
            $errorMessage = '品目名は100文字以内で入力してください。';
        } elseif ($usageType === null) {
            $errorMessage = '用途区分を選択してください。';
        } elseif ($sortOrder === null) {
            $errorMessage = '表示順は0以上の整数で入力してください。';
        } else {
            $itemKey = 'item_' . bin2hex(random_bytes(4));
            $stmt = $pdo->prepare(
                'INSERT INTO consumable_items (item_key, name, usage_type, sort_order, is_active, created_by)
                 VALUES (:item_key, :name, :usage_type, :sort_order, 1, :created_by)'
            );
            $stmt->execute([
                ':item_key' => $itemKey,
                ':name' => $name,
                ':usage_type' => $usageType,
                ':sort_order' => $sortOrder,
                ':created_by' => $staff['id'],
            ]);
            set_flash('success', htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . 'を登録しました。');
            header('Location: /staff/consumable_items.php');
            exit;
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();

// ---- 品目一覧の取得 ----
$items = get_consumable_items($pdo);

// ---- フォームの初期値決定 ----
$formName = '';
$formUsageType = 'none';
// 新規登録時の表示順は、既存の最大値+10を初期提案する（並び順の末尾に自然に追加されるように）。
$nextSortOrder = 10;
foreach ($items as $item) {
    if ((int) $item['sort_order'] + 10 > $nextSortOrder) {
        $nextSortOrder = (int) $item['sort_order'] + 10;
    }
}
$formSortOrder = (string) $nextSortOrder;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
    $formName = (string) ($_POST['name'] ?? '');
    $formUsageType = (string) ($_POST['usage_type'] ?? '');
    $formSortOrder = (string) ($_POST['sort_order'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>消耗品品目登録 | 従業員</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; }
        .form-row { margin-bottom: 8px; }
        .form-row label { display: inline-block; width: 110px; vertical-align: top; }
        .form-row input[type="text"], .form-row input[type="number"] { width: 260px; max-width: 100%; }
        table.items { border-collapse: collapse; width: 100%; max-width: 760px; }
        table.items th, table.items td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        table.items th { background: #f5f5f5; }
        .status-badge { display: inline-block; font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
        .status-active { background: #e6f4ea; color: #1e7e34; }
        .status-disabled { background: #eee; color: #777; }
        .usage-badge { display: inline-block; font-size: 0.8em; padding: 2px 8px; border-radius: 10px; background: #e8eef7; color: #2c4a75; }
        tr.disabled-row { color: #999; }
    </style>
</head>
<body>
<header>
    <h1>消耗品品目登録</h1>
    <nav><a href="/staff/consumable_stock.php">消耗品在庫管理に戻る</a> | <a href="/staff/dashboard.php">ダッシュボード</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="item-form">
    <h2>新規品目の登録</h2>
    <fieldset>
        <form method="post" action="/staff/consumable_items.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-row">
                <label for="name">品目名</label>
                <input type="text" id="name" name="name" maxlength="100" value="<?= htmlspecialchars($formName, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label>用途区分</label>
                <?php foreach (CONSUMABLE_ITEM_USAGE_TYPE_LABELS as $usageTypeKey => $usageTypeLabel): ?>
                    <label style="width:auto; margin-right:12px; display:inline-block;">
                        <input type="radio" name="usage_type" value="<?= htmlspecialchars($usageTypeKey, ENT_QUOTES, 'UTF-8') ?>" <?= $formUsageType === $usageTypeKey ? 'checked' : '' ?> required>
                        <?= htmlspecialchars($usageTypeLabel, ENT_QUOTES, 'UTF-8') ?>
                    </label>
                <?php endforeach; ?>
                <div style="font-size:0.8em;color:#777;">集荷時に施設等へ渡す品目は「集荷用」、返却時に使う品目は「返却用」を選んでください。どちらでもない場合は「該当なし」を選んでください。</div>
            </div>

            <div class="form-row">
                <label for="sort_order">表示順</label>
                <input type="number" id="sort_order" name="sort_order" min="0" step="1" value="<?= htmlspecialchars($formSortOrder, ENT_QUOTES, 'UTF-8') ?>" required>
                <div style="font-size:0.8em;color:#777;">在庫管理画面での表示順（数値が小さいほど上に表示されます）。</div>
            </div>

            <button type="submit">登録する</button>
        </form>
    </fieldset>
</section>

<section class="item-list">
    <h2>品目一覧</h2>
    <p class="notice">品目の編集・無効化は管理者のみ行えます。修正が必要な場合は管理者に依頼してください。</p>
    <?php if (empty($items)): ?>
        <p class="notice">品目が登録されていません。上のフォームから追加してください。</p>
    <?php else: ?>
        <table class="items">
            <thead>
                <tr>
                    <th>表示順</th>
                    <th>品目名</th>
                    <th>用途区分</th>
                    <th>状態</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr class="<?= (int) $item['is_active'] === 1 ? '' : 'disabled-row' ?>">
                        <td><?= (int) $item['sort_order'] ?></td>
                        <td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="usage-badge"><?= htmlspecialchars(CONSUMABLE_ITEM_USAGE_TYPE_LABELS[$item['usage_type']] ?? $item['usage_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td>
                            <?php if ((int) $item['is_active'] === 1): ?>
                                <span class="status-badge status-active">有効</span>
                            <?php else: ?>
                                <span class="status-badge status-disabled">無効</span>
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
