<?php
require_once __DIR__ . '/../includes/auth.php';

$admin = require_login('admin');
$pdo = getPdo();

const CC_FIELD_LABELS = [
    'facility_id' => '施設',
    'pickup_date' => '集荷日',
    'pickup_bag_count' => '集荷リネン袋数',
    'pickup_time' => '集荷時間',
    'pickup_employee_id' => '集荷担当者',
    'issued_bag_orange' => 'リネン袋交付数（オレンジ）',
    'issued_bag_yellow' => 'リネン袋交付数（黄）',
    'issued_bag_blue' => 'リネン袋交付数（青）',
    'issued_laundry_net_count' => '洗濯ネット交付数',
    'arrival_bag_count' => '到着リネン袋数',
    'arrival_date' => '到着日',
    'arrival_time' => '到着時間',
    'arrival_employee_id' => '到着担当者',
    'arrival_facility_id' => '到着クリーニング所',
    'dispatch_bag_count' => '発送リネン袋数',
    'dispatch_date' => '発送日',
    'dispatch_time' => '発送時間',
    'dispatch_employee_id' => '発送担当者',
    'dispatch_facility_id' => '発送元クリーニング所',
    'return_bag_count' => '返却リネン袋数',
    'return_date' => '返却日',
    'return_time' => '返却時間',
    'return_employee_id' => '返却担当者',
    'remarks' => '備考',
];

const CC_ACTION_LABELS = [
    'create' => '追加',
    'update' => '修正',
    'delete' => '削除',
];

const CC_EMPLOYEE_FIELDS = ['pickup_employee_id', 'arrival_employee_id', 'dispatch_employee_id', 'return_employee_id'];
const CC_FACILITY_FIELDS = ['arrival_facility_id', 'dispatch_facility_id'];

function format_cc_log_value(?string $fieldName, ?string $value, array $facilityNames, array $employeeNames): string
{
    if ($value === null) {
        return '-';
    }
    if ($fieldName === 'facility_id' || in_array($fieldName, CC_FACILITY_FIELDS, true)) {
        return $facilityNames[(int) $value] ?? ('ID:' . $value);
    }
    if (in_array($fieldName, CC_EMPLOYEE_FIELDS, true)) {
        return $employeeNames[(int) $value] ?? ('ID:' . $value);
    }
    if (in_array($fieldName, ['pickup_time', 'arrival_time', 'dispatch_time', 'return_time'], true)) {
        return substr($value, 0, 5);
    }
    return $value;
}

$facilityNamesStmt = $pdo->query('SELECT id, name FROM facilities');
$facilityNames = [];
foreach ($facilityNamesStmt->fetchAll() as $row) {
    $facilityNames[(int) $row['id']] = $row['name'];
}

$employeeNamesStmt = $pdo->query('SELECT id, name FROM employees');
$employeeNames = [];
foreach ($employeeNamesStmt->fetchAll() as $row) {
    $employeeNames[(int) $row['id']] = $row['name'];
}

$logsStmt = $pdo->query(
    "SELECT l.id, l.action, l.field_name, l.old_value, l.new_value, l.edited_at,
            cc.facility_id, cc.pickup_date,
            editor.name AS editor_name
     FROM collection_cycle_edit_logs l
     INNER JOIN collection_cycles cc ON cc.id = l.collection_cycle_id
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
    <title>集荷記録修正履歴 | 管理者</title>
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
    <h1>集荷記録修正履歴</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/collection_records.php">集荷・配送記録簿</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<p class="notice">従業員・管理者による集荷・配送記録の追加・修正・削除履歴です（直近200件）。</p>

<?php if (empty($logs)): ?>
    <p class="notice">修正履歴はまだありません。</p>
<?php else: ?>
    <table class="logs">
        <thead>
            <tr>
                <th>修正日時</th>
                <th>対象施設</th>
                <th>対象集荷日</th>
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
                    <td><?= htmlspecialchars($facilityNames[(int) $log['facility_id']] ?? ('ID:' . $log['facility_id']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($log['pickup_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="action-badge action-<?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(CC_ACTION_LABELS[$log['action']] ?? $log['action'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars(CC_FIELD_LABELS[$log['field_name']] ?? $log['field_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="old-value"><?= htmlspecialchars(format_cc_log_value($log['field_name'], $log['old_value'], $facilityNames, $employeeNames), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="new-value"><?= htmlspecialchars(format_cc_log_value($log['field_name'], $log['new_value'], $facilityNames, $employeeNames), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($log['editor_name'], ENT_QUOTES, 'UTF-8') ?>さん</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>
