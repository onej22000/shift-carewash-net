<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/quotation_common.php';

$admin = require_login('admin');
$pdo = getPdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。画面を再読み込みしてやり直してください。');
    } elseif (($_POST['action'] ?? '') === 'delete') {
        $target = quotation_fetch($pdo, (int) ($_POST['id'] ?? 0));
        if ($target === null) {
            set_flash('error', '削除対象の見積書が見つかりません。');
        } else {
            $pdo->prepare('DELETE FROM quotations WHERE id = :id')->execute([':id' => $target['id']]);
            set_flash('success', '見積書 ' . $target['quote_no'] . '（' . $target['recipient_name'] . '）を削除しました。');
        }
    }
    header('Location: /admin/quotations.php');
    exit;
}

$keyword = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT id, quote_no, recipient_name, recipient_honorific, resident_count, has_odor_mini_option, total_incl, issue_date, valid_until
        FROM quotations';
$params = [];
if ($keyword !== '') {
    $sql .= ' WHERE recipient_name LIKE :kw1 OR quote_no LIKE :kw2';
    $like = '%' . addcslashes($keyword, '%_\\') . '%';
    $params = [':kw1' => $like, ':kw2' => $like];
}
$sql .= ' ORDER BY issue_date DESC, quote_no DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$quotations = $stmt->fetchAll();

$today = (new DateTime('today'))->format('Y-m-d');
$flash = pop_flash();
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>見積書一覧 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 14px; }
        .toolbar a.button { display: inline-block; padding: 7px 14px; border-radius: 4px; background: #0b5ed7; color: #fff; text-decoration: none; }
        .toolbar input[type="search"] { width: 240px; padding: 5px; }
        .table-wrap { overflow-x: auto; }
        table.list { border-collapse: collapse; width: 100%; max-width: 980px; }
        table.list th, table.list td { border: 1px solid #ccc; padding: 7px 9px; text-align: left; white-space: nowrap; }
        table.list th { background: #f5f5f5; }
        table.list td.num { text-align: right; }
        tr.expired td { color: #888; }
        .badge { display: inline-block; font-size: 0.78em; padding: 1px 7px; border-radius: 10px; background: #eee; color: #777; margin-left: 4px; }
        .badge.option { background: #e8eef7; color: #2c4a75; }
        .inline-form { display: inline; }
        button.danger { border: 1px solid #b3261e; color: #b3261e; background: #fff; border-radius: 4px; cursor: pointer; }
        .muted { color: #666; }
    </style>
</head>
<body>
<header>
    <h1>見積書一覧</h1>
    <nav>ログイン中: <?= quotation_esc($admin['name']) ?>さん（管理者） | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= quotation_esc($flash['type']) ?>"><?= quotation_esc($flash['message']) ?></p>
<?php endif; ?>

<div class="toolbar">
    <a class="button" href="/admin/quotation_edit.php">＋ 見積書を作成</a>
    <form method="get" action="/admin/quotations.php">
        <input type="search" name="q" value="<?= quotation_esc($keyword) ?>" placeholder="施設名・見積番号で検索">
        <button type="submit">検索</button>
        <?php if ($keyword !== ''): ?><a href="/admin/quotations.php">解除</a><?php endif; ?>
    </form>
</div>

<?php if ($quotations === []): ?>
    <p class="muted"><?= $keyword !== '' ? '条件に合う見積書はありません。' : 'まだ見積書はありません。' ?></p>
<?php else: ?>
    <div class="table-wrap">
    <table class="list">
        <thead>
            <tr>
                <th>見積番号</th>
                <th>宛先</th>
                <th>入居者数</th>
                <th>金額（税込・月額）</th>
                <th>発行日</th>
                <th>有効期限</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($quotations as $q): ?>
            <?php $expired = $q['valid_until'] < $today; ?>
            <tr<?= $expired ? ' class="expired"' : '' ?>>
                <td><a href="/admin/quotation_view.php?id=<?= (int) $q['id'] ?>"><?= quotation_esc($q['quote_no']) ?></a></td>
                <td><?= quotation_esc($q['recipient_name']) ?> <?= quotation_esc($q['recipient_honorific']) ?></td>
                <td class="num"><?= number_format((int) $q['resident_count']) ?> 名</td>
                <td class="num"><?= quotation_esc(quotation_yen((int) $q['total_incl'])) ?><?php if ((int) $q['has_odor_mini_option'] === 1): ?><span class="badge option">汚物mini</span><?php endif; ?></td>
                <td><?= quotation_esc($q['issue_date']) ?></td>
                <td><?= quotation_esc($q['valid_until']) ?><?php if ($expired): ?><span class="badge">期限切れ</span><?php endif; ?></td>
                <td>
                    <a href="/admin/quotation_view.php?id=<?= (int) $q['id'] ?>">表示</a> |
                    <a href="/admin/quotation_view.php?id=<?= (int) $q['id'] ?>&amp;format=pdf">PDF</a> |
                    <form class="inline-form" method="post" action="/admin/quotations.php" onsubmit="return confirm('見積書 <?= quotation_esc($q['quote_no']) ?> を削除します。元に戻せません。よろしいですか？');">
                        <input type="hidden" name="csrf_token" value="<?= quotation_esc($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $q['id'] ?>">
                        <button type="submit" class="danger">削除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted"><?= count($quotations) ?>件</p>
<?php endif; ?>
</body>
</html>
