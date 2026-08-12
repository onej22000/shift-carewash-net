<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();
$isSharedAccount = (int) ($staff['is_shared_account'] ?? 0) === 1;

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
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title><?= $isSharedAccount ? 'フトン巻きのジロー ダッシュボード' : $scheduleDateLabel . 'の集荷予定' ?> | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; }
        table.cycles { border-collapse: collapse; width: 100%; }
        table.cycles th, table.cycles td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
        table.cycles th { background: #f5f5f5; }
        .jiro-checklist-totals table.cycles th { width: 90px; }
        .links a { display: inline-block; margin-right: 12px; }
        .shared-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .shared-action { display: block; padding: 18px; border: 1px solid #78bde8; border-radius: 14px; background: linear-gradient(145deg, #f2faff 0%, #d8efff 100%); box-shadow: 0 7px 16px rgba(30,55,90,.13); text-align: center; font-weight: bold; text-decoration: none; color: #0b5ed7; }
        .schedule-nav { display: flex; gap: 10px; margin: 8px 0 20px; flex-wrap: wrap; }
        .schedule-nav a { padding: 10px 18px; border: 1px solid #0b5ed7; border-radius: 12px; color: #0b5ed7; text-decoration: none; font-weight: bold; background: #fff; }
        .schedule-nav a.active { color: #fff; background: #0b5ed7; }
        @media (max-width: 900px) { .shared-actions { grid-template-columns: minmax(0, 1fr); } }
    </style>
</head>
<body>
<header>
    <h1><?= $isSharedAccount ? 'フトン巻きのジロー ダッシュボード' : htmlspecialchars($scheduleDateLabel, ENT_QUOTES, 'UTF-8') . 'の集荷予定（' . htmlspecialchars($viewDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8') . '）' ?></h1>
    <nav><a href="/staff/dashboard.php">通常画面に戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if ($isSharedAccount): ?>
    <section class="shared-actions">
        <a class="shared-action" href="/staff/collection_headcount.php">洗濯ネット・到着リネン袋数登録</a>
        <a class="shared-action" href="/staff/work_records.php">作業実績登録</a>
    </section>
<?php endif; ?>

<nav class="schedule-nav" aria-label="集荷予定日の切替">
    <?php foreach ($scheduleDateLabels as $offset => $label): ?>
        <a href="/staff/jiro_dashboard.php?day=<?= $offset ?>" class="<?= $viewOffset === $offset ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</nav>

<?php require __DIR__ . '/../includes/jiro_checklist_table.php'; ?>
</body>
</html>
