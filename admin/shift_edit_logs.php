<?php
require_once __DIR__ . '/../includes/auth.php';

$admin = require_login('admin');
$pdo = getPdo();

const SHIFT_FIELD_LABELS = [
    'work_date' => '勤務日',
    'start_time' => '開始時刻',
    'end_time' => '終了時刻',
    'note' => '備考',
    'categories' => '業務種別',
];

const ACTION_LABELS = [
    'create' => '新規登録',
    'update' => '更新',
    'delete' => '削除',
];

$logsStmt = $pdo->query(
    "SELECT l.id, l.shift_id, l.action, l.field_name, l.old_value, l.new_value, l.edited_at,
            editor.name AS editor_name,
            s.work_date AS current_work_date
     FROM shift_edit_logs l
     INNER JOIN employees editor ON editor.id = l.edited_by
     LEFT JOIN shifts s ON s.id = l.shift_id
     ORDER BY l.edited_at DESC
     LIMIT 200"
);
$logs = $logsStmt->fetchAll();

function shift_log_target_date(array $log): string
{
    if ($log['field_name'] === 'work_date') {
        return ($log['old_value'] ?? '?') . ' → ' . ($log['new_value'] ?? '?');
    }
    if ($log['current_work_date'] !== null) {
        return $log['current_work_date'];
    }
    // シフト削除済みの場合、summary文字列の先頭(YYYY-MM-DD)から日付を推定する
    $summary = $log['old_value'] ?? $log['new_value'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $summary, $m)) {
        return $m[0];
    }

    return '-';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>シフト編集履歴 | 管理者</title>
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
    </style>
</head>
<body>
<header>
    <h1>シフト編集履歴</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<p class="notice">従業員自身によるシフトの新規登録・変更・削除の履歴です（直近200件）。不審な操作がないかご確認ください。</p>

<?php if (empty($logs)): ?>
    <p class="notice">編集履歴はまだありません。</p>
<?php else: ?>
    <table class="logs">
        <thead>
            <tr>
                <th>編集日時</th>
                <th>対象日</th>
                <th>操作</th>
                <th>項目</th>
                <th>変更前</th>
                <th>変更後</th>
                <th>編集した従業員</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['edited_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(shift_log_target_date($log), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="action-badge action-<?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ACTION_LABELS[$log['action']] ?? $log['action'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= $log['field_name'] !== null ? htmlspecialchars(SHIFT_FIELD_LABELS[$log['field_name']] ?? $log['field_name'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                    <td class="old-value"><?= htmlspecialchars($log['old_value'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="new-value"><?= htmlspecialchars($log['new_value'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($log['editor_name'], ENT_QUOTES, 'UTF-8') ?>さん</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>
