<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/lead_common.php';

$admin = require_login('admin');
$pdo = getPdo();

$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, LEAD_STATUSES, true)) {
    $status = '';
}
$keyword = trim((string) ($_GET['q'] ?? ''));

// 見積書の件数・最新の発行日・最新の見積金額（最新＝発行日が新しい順、同日は見積番号が大きい方）
$sql = "SELECT l.id, l.name, l.address, l.status, l.updated_at,
               COUNT(q.id) AS quote_count,
               MAX(q.issue_date) AS latest_issue_date,
               (SELECT q2.total_incl FROM quotations q2 WHERE q2.lead_id = l.id
                ORDER BY q2.issue_date DESC, q2.quote_no DESC LIMIT 1) AS latest_total
        FROM leads l
        LEFT JOIN quotations q ON q.lead_id = l.id";
$where = [];
$params = [];
if ($status !== '') {
    $where[] = 'l.status = :status';
    $params[':status'] = $status;
}
if ($keyword !== '') {
    $where[] = 'l.name LIKE :kw';
    $params[':kw'] = '%' . addcslashes($keyword, '%_\\') . '%';
}
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' GROUP BY l.id ORDER BY l.updated_at DESC, l.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();

$counts = array_fill_keys(LEAD_STATUSES, 0);
foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM leads GROUP BY status') as $row) {
    $counts[$row['status']] = (int) $row['n'];
}

$flash = pop_flash();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>営業先一覧 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 14px; }
        .toolbar input[type="search"] { width: 220px; padding: 5px; }
        .status-tabs a { display: inline-block; padding: 4px 10px; border: 1px solid #ccc; border-radius: 14px; margin-right: 4px; text-decoration: none; color: #333; font-size: 0.9em; }
        .status-tabs a.current { background: #0b5ed7; border-color: #0b5ed7; color: #fff; }
        .table-wrap { overflow-x: auto; }
        table.list { border-collapse: collapse; width: 100%; max-width: 1000px; }
        table.list th, table.list td { border: 1px solid #ccc; padding: 7px 9px; text-align: left; white-space: nowrap; }
        table.list th { background: #f5f5f5; }
        table.list td.num { text-align: right; }
        .status-badge { display: inline-block; font-size: 0.82em; padding: 2px 9px; border-radius: 10px; }
        .status-提案済み { background: #e8eef7; color: #2c4a75; }
        .status-検討中 { background: #fff3cd; color: #856404; }
        .status-成約 { background: #e6f4ea; color: #1e7e34; }
        .status-失注 { background: #eee; color: #777; }
        .muted { color: #666; }
    </style>
</head>
<body>
<header>
    <h1>営業先一覧</h1>
    <nav>ログイン中: <?= lead_esc($admin['name']) ?>さん（管理者） | <a href="/admin/quotations.php">見積書一覧</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= lead_esc($flash['type']) ?>"><?= lead_esc($flash['message']) ?></p>
<?php endif; ?>

<p class="muted">見積書を発行すると、宛先が自動でここに追加されます（同じ宛先への2回目以降の発行は既存の営業先に紐づきます）。</p>

<div class="toolbar">
    <div class="status-tabs">
        <a href="/admin/leads.php<?= $keyword !== '' ? '?q=' . urlencode($keyword) : '' ?>" class="<?= $status === '' ? 'current' : '' ?>">すべて（<?= array_sum($counts) ?>）</a>
        <?php foreach (LEAD_STATUSES as $s): ?>
            <a href="/admin/leads.php?status=<?= urlencode($s) ?><?= $keyword !== '' ? '&amp;q=' . urlencode($keyword) : '' ?>" class="<?= $status === $s ? 'current' : '' ?>"><?= lead_esc($s) ?>（<?= $counts[$s] ?>）</a>
        <?php endforeach; ?>
    </div>
    <form method="get" action="/admin/leads.php">
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= lead_esc($status) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= lead_esc($keyword) ?>" placeholder="施設名で検索">
        <button type="submit">検索</button>
        <?php if ($keyword !== ''): ?><a href="/admin/leads.php<?= $status !== '' ? '?status=' . urlencode($status) : '' ?>">解除</a><?php endif; ?>
    </form>
</div>

<?php if ($leads === []): ?>
    <p class="muted"><?= $status !== '' || $keyword !== '' ? '条件に合う営業先はありません。' : 'まだ営業先はありません。' ?></p>
<?php else: ?>
    <div class="table-wrap">
    <table class="list">
        <thead>
            <tr>
                <th>施設名・会社名</th>
                <th>ステータス</th>
                <th>見積件数</th>
                <th>最新の発行日</th>
                <th>最新の見積金額（税込・月額）</th>
                <th>最終更新</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($leads as $l): ?>
            <tr>
                <td><a href="/admin/lead_view.php?id=<?= (int) $l['id'] ?>"><?= lead_esc($l['name']) ?></a><?php if ((string) $l['address'] !== ''): ?><br><span class="muted"><?= lead_esc($l['address']) ?></span><?php endif; ?></td>
                <td><span class="status-badge status-<?= lead_esc($l['status']) ?>"><?= lead_esc($l['status']) ?></span></td>
                <td class="num"><?= (int) $l['quote_count'] ?> 件</td>
                <td><?= lead_esc($l['latest_issue_date'] ?? '-') ?></td>
                <td class="num"><?= $l['latest_total'] !== null ? '¥' . number_format((int) $l['latest_total']) : '-' ?></td>
                <td><?= lead_esc(substr((string) $l['updated_at'], 0, 16)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted"><?= count($leads) ?>件</p>
<?php endif; ?>
</body>
</html>
