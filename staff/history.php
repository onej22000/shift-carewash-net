<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

$todayStr = (new DateTime('today'))->format('Y-m-d');

$selectedDate = DateTime::createFromFormat('Y-m-d', (string) ($_GET['date'] ?? ''));
if ($selectedDate === false) {
    $selectedDate = new DateTime('today');
}
$selectedDate->setTime(0, 0, 0);
$selectedDateStr = $selectedDate->format('Y-m-d');
$prevDateStr = (clone $selectedDate)->modify('-1 day')->format('Y-m-d');
$nextDateStr = (clone $selectedDate)->modify('+1 day')->format('Y-m-d');

// 本人（session上のemployee_id）の記録のみを対象とする。employee_idを外部から受け取ることはない。
$stmt = $pdo->prepare(
    'SELECT id, clock_in_at, clock_out_at, work_minutes, status, total_break_minutes
     FROM attendance
     WHERE employee_id = :employee_id AND DATE(clock_in_at) = :date
       AND deleted_at IS NULL
     ORDER BY clock_in_at'
);
$stmt->execute([':employee_id' => $staff['id'], ':date' => $selectedDateStr]);
$records = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>打刻履歴 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .date-nav { margin-bottom: 16px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .date-nav a { padding: 4px 10px; border: 1px solid #ccc; border-radius: 12px; text-decoration: none; color: #222; }
        .date-nav form { display: inline-flex; gap: 6px; align-items: center; }
        table.simple { border-collapse: collapse; width: 100%; }
        table.simple th, table.simple td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        table.simple th { background: #f5f5f5; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
    </style>
</head>
<body>
<header>
    <h1>打刻履歴</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>

<div class="date-nav">
    <a href="?date=<?= $prevDateStr ?>">← 前日</a>
    <a href="?date=<?= $todayStr ?>">今日</a>
    <a href="?date=<?= $nextDateStr ?>">翌日 →</a>
    <form method="get" action="/staff/history.php">
        <input type="date" name="date" value="<?= htmlspecialchars($selectedDateStr, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">表示</button>
    </form>
</div>

<h2><?= htmlspecialchars($selectedDateStr, ENT_QUOTES, 'UTF-8') ?>の打刻</h2>

<p><a href="/staff/attendance_add.php?date=<?= htmlspecialchars($selectedDateStr, ENT_QUOTES, 'UTF-8') ?>">＋ この日の打刻を追加</a></p>

<?php if (empty($records)): ?>
    <p class="notice">この日の打刻記録はありません。</p>
<?php else: ?>
    <table class="simple">
        <thead>
            <tr>
                <th>出勤</th>
                <th>退勤</th>
                <th>休憩</th>
                <th>実働</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($records as $record): ?>
                <tr>
                    <td><?= htmlspecialchars(substr($record['clock_in_at'], 11, 5), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $record['clock_out_at'] !== null ? htmlspecialchars(substr($record['clock_out_at'], 11, 5), ENT_QUOTES, 'UTF-8') : '(勤務中)' ?></td>
                    <td><?= $record['total_break_minutes'] !== null ? (int) $record['total_break_minutes'] . '分' : '-' ?></td>
                    <td><?= $record['work_minutes'] !== null ? htmlspecialchars(format_minutes_as_hours((int) $record['work_minutes']), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                    <td><a href="/staff/attendance_edit.php?id=<?= (int) $record['id'] ?>">編集</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>
