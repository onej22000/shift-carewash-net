<?php
require_once __DIR__ . '/../includes/auth.php';

$admin = require_login('admin');
$pdo = getPdo();

const FIELD_LABELS = [
    'clock_in_at' => '出勤時刻',
    'clock_out_at' => '退勤時刻',
    'break_start_at' => '休憩開始時刻',
    'break_end_at' => '休憩終了時刻',
    'total_break_minutes' => '休憩合計（分）',
    'reason' => '削除理由',
];

const ROLE_LABELS = [
    'admin' => '管理者',
    'staff' => '従業員',
];

const ACTION_LABELS = [
    'create' => '追加',
    'update' => '修正',
    'delete' => '削除',
    'auto_break' => '休憩自動計算',
    'month_end_correction' => '月末自動補正',
];

$logsStmt = $pdo->query(
    "SELECT l.id, l.action, l.field_name, l.old_value, l.new_value, l.edited_at,
            owner.name AS owner_name,
            editor.name AS editor_name, editor.role AS editor_role,
            DATE(a.clock_in_at) AS work_date
     FROM attendance_edit_logs l
     INNER JOIN attendance a ON a.id = l.attendance_id
     INNER JOIN employees owner ON owner.id = a.employee_id
     INNER JOIN employees editor ON editor.id = l.edited_by
     ORDER BY l.edited_at DESC
     LIMIT 200"
);
$logs = $logsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>打刻修正履歴 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        table.logs { border-collapse: collapse; width: 100%; }
        table.logs th, table.logs td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
        table.logs th { background: #f5f5f5; }
        .old-value { color: #b3261e; text-decoration: line-through; }
        .new-value { color: #1e7e34; font-weight: bold; }
        .action-badge { display: inline-block; font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
        .action-create { background: #e6f4ea; color: #1e7e34; }
        .action-update { background: #fff3cd; color: #856404; }
        .action-delete { background: #fdecea; color: #b3261e; }
        .action-auto_break { background: #e7f1ff; color: #0b5ed7; }
        .action-month_end_correction { background: #f1e7ff; color: #6f1ed7; }
    </style>
</head>
<body>
<header>
    <h1>打刻修正履歴</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<p class="notice">従業員自身、または管理者による打刻（出退勤・休憩）の修正履歴です（直近200件）。不審な修正がないかご確認ください。</p>

<?php if (empty($logs)): ?>
    <p class="notice">修正履歴はまだありません。</p>
<?php else: ?>
    <table class="logs">
        <thead>
            <tr>
                <th>修正日時</th>
                <th>対象従業員</th>
                <th>対象日</th>
                <th>操作</th>
                <th>項目</th>
                <th>変更前</th>
                <th>変更後</th>
                <th>編集者</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['edited_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($log['owner_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($log['work_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="action-badge action-<?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ACTION_LABELS[$log['action']] ?? $log['action'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars(FIELD_LABELS[$log['field_name']] ?? $log['field_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="old-value"><?= htmlspecialchars($log['old_value'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="new-value"><?= htmlspecialchars($log['new_value'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($log['editor_name'], ENT_QUOTES, 'UTF-8') ?>さん（<?= htmlspecialchars(ROLE_LABELS[$log['editor_role']] ?? $log['editor_role'], ENT_QUOTES, 'UTF-8') ?>）</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>
