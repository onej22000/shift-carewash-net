<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();
$viewOffset = (int) ($_GET['day'] ?? 0);
if ($viewOffset < 0 || $viewOffset > 2) {
    $viewOffset = 0;
}
$scheduleDateLabels = [0 => '本日', 1 => '翌日', 2 => '翌々日'];
$scheduleDateLabel = $scheduleDateLabels[$viewOffset];
$viewDate = (new DateTime('today'))->modify('+' . $viewOffset . ' days');
$checklist = build_jiro_checklist_data($pdo, $viewDate);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($scheduleDateLabel, ENT_QUOTES, 'UTF-8') ?>の集荷予定 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        h1 { font-size: 1.3em; margin: 0; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; }
        table.cycles { border-collapse: collapse; width: 100%; }
        table.cycles th, table.cycles td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
        table.cycles th { background: #f5f5f5; }
        .jiro-checklist-totals table.cycles th { width: 90px; }
        table.cycles td.row-issued-orange { background: #ffe0b2; }
        table.cycles td.row-return { background: #0b5ed7; color: #fff; }
        .schedule-nav { display: flex; gap: 10px; margin: 8px 0 20px; flex-wrap: wrap; }
        .schedule-nav a { padding: 10px 18px; border: 1px solid #0b5ed7; border-radius: 12px; color: #0b5ed7; text-decoration: none; font-weight: bold; background: #fff; }
        .schedule-nav a.active { color: #fff; background: #0b5ed7; }
    </style>
</head>
<body>
<header>
    <h1><?= htmlspecialchars($scheduleDateLabel, ENT_QUOTES, 'UTF-8') ?>の集荷予定（<?= htmlspecialchars($viewDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>）</h1>
    <nav><a href="/admin/dashboard.php">ダッシュボードに戻る</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>
<nav class="schedule-nav" aria-label="集荷予定日の切り替え">
    <?php foreach ($scheduleDateLabels as $offset => $label): ?>
        <a href="/admin/jiro_dashboard.php?day=<?= $offset ?>" class="<?= $viewOffset === $offset ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</nav>
<?php require __DIR__ . '/../includes/jiro_checklist_table.php'; ?>
</body>
</html>
