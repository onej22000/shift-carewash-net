<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

$checklist = build_jiro_checklist_data($pdo, new DateTime());
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>集荷チェックリスト | シフト管理</title>
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
    </style>
</head>
<body>
<header>
    <h1>本日の集荷チェックリスト（<?= (new DateTime())->format('Y-m-d') ?>）</h1>
    <nav><?php if ((int) ($staff['is_shared_account'] ?? 0) !== 1): ?><a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <?php endif; ?><a href="/staff/logout.php">ログアウト</a></nav>
</header>

<p class="notice">出発前に、各施設へ持っていくリネン袋数の目安を確認できます。前回集荷袋数は次回の空袋補充の目安、返却リネン袋数（青）は洗濯代行が登録済みでまだドライバー未確認の分です。</p>

<?php require __DIR__ . '/../includes/jiro_checklist_table.php'; ?>

<section class="links">
    <p><a href="/staff/collection_headcount.php">人数確認・返却準備完了の登録はこちら</a></p>
</section>
</body>
</html>
