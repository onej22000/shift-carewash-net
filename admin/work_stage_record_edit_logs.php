<?php
require_once __DIR__ . '/../includes/auth.php';

$admin = require_login('admin');
$pdo = getPdo();

const FIELD_LABELS = [
    'employee_id' => '従業員',
    'category' => '区分',
    'facility_id' => '施設',
    'collection_cycle_id' => '集荷サイクルID',
    'stage' => '工程',
    'person_count' => '人数',
    'record_date' => '作業日',
    'record_time' => '作業時刻',
    'completed_at' => '完了時刻',
    'employee_ids' => '参加した従業員',
];

const STAGE_VALUE_LABELS = [
    'pickup' => '集荷',
    'wash' => '洗濯',
    'dry' => '乾燥',
    'fold' => '畳み',
];

const ACTION_LABELS = [
    'create' => '追加',
    'update' => '修正',
    'delete' => '削除',
];

function format_wsr_log_value(?string $fieldName, ?string $value, array $employeeNames, array $facilityNames): string
{
    if ($value === null) {
        return '-';
    }
    if ($fieldName === 'employee_id') {
        return $employeeNames[(int) $value] ?? ('ID:' . $value);
    }
    if ($fieldName === 'facility_id') {
        return $facilityNames[(int) $value] ?? ('ID:' . $value);
    }
    if ($fieldName === 'stage') {
        return STAGE_VALUE_LABELS[$value] ?? $value;
    }
    if ($fieldName === 'employee_ids') {
        if ($value === '') {
            return '（参加者なし）';
        }
        $names = array_map(
            static fn (string $id): string => $employeeNames[(int) $id] ?? ('ID:' . $id),
            explode(',', $value)
        );
        return implode('・', $names);
    }
    return $value;
}

$employeeNamesStmt = $pdo->query('SELECT id, name FROM employees');
$employeeNames = [];
foreach ($employeeNamesStmt->fetchAll() as $row) {
    $employeeNames[(int) $row['id']] = $row['name'];
}

$facilityNamesStmt = $pdo->query('SELECT id, name FROM facilities');
$facilityNames = [];
foreach ($facilityNamesStmt->fetchAll() as $row) {
    $facilityNames[(int) $row['id']] = $row['name'];
}

$logsStmt = $pdo->query(
    "SELECT l.id, l.action, l.field_name, l.old_value, l.new_value, l.edited_at,
            w.record_date, w.employee_id AS owner_employee_id,
            editor.name AS editor_name
     FROM work_stage_record_edit_logs l
     INNER JOIN work_stage_records w ON w.id = l.work_stage_record_id
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
    <title>作業実績修正履歴 | 管理者</title>
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
    <h1>作業実績修正履歴</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/work_stage_records.php">作業実績の管理</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<p class="notice">管理者による作業実績（集荷・洗濯代行）の追加・修正・削除履歴です（直近200件）。洗濯・乾燥・畳みは2026-08-06に「洗濯」1工程へ統合しましたが、統合前の記録は乾燥・畳みのまま表示されます。</p>

<?php if (empty($logs)): ?>
    <p class="notice">修正履歴はまだありません。</p>
<?php else: ?>
    <table class="logs">
        <thead>
            <tr>
                <th>修正日時</th>
                <th>対象の作業日</th>
                <th>対象従業員</th>
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
                    <td><?= htmlspecialchars($log['record_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($employeeNames[(int) $log['owner_employee_id']] ?? ('ID:' . $log['owner_employee_id']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="action-badge action-<?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ACTION_LABELS[$log['action']] ?? $log['action'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars(FIELD_LABELS[$log['field_name']] ?? $log['field_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="old-value"><?= htmlspecialchars(format_wsr_log_value($log['field_name'], $log['old_value'], $employeeNames, $facilityNames), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="new-value"><?= htmlspecialchars(format_wsr_log_value($log['field_name'], $log['new_value'], $employeeNames, $facilityNames), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($log['editor_name'], ENT_QUOTES, 'UTF-8') ?>さん</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>
