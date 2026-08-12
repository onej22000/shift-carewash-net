<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

$facilitiesStmt = $pdo->query('SELECT id, name FROM facilities ORDER BY name');
$facilities = $facilitiesStmt->fetchAll();
$validFacilityIds = array_map('intval', array_column($facilities, 'id'));
$facilityNamesById = array_column($facilities, 'name', 'id');

$facilityId = (int) ($_GET['facility_id'] ?? 0);

$yearMonth = (string) ($_GET['month'] ?? (new DateTime())->format('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
    $yearMonth = (new DateTime())->format('Y-m');
}
[$rangeStartStr, $rangeEndStr] = get_month_range($yearMonth);
$monthStart = DateTime::createFromFormat('Y-m-d', $rangeStartStr);
$prevMonth = (clone $monthStart)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthStart)->modify('+1 month')->format('Y-m');

$selectedFacilityName = $facilityNamesById[$facilityId] ?? '';
$records = [];

if (in_array($facilityId, $validFacilityIds, true)) {
    $recordsStmt = $pdo->prepare(
        'SELECT cc.*,
                pe.name AS pickup_employee_name,
                ae.name AS arrival_employee_name,
                de.name AS dispatch_employee_name,
                re.name AS return_employee_name,
                af.name AS arrival_facility_name,
                df.name AS dispatch_facility_name
         FROM collection_cycles cc
         LEFT JOIN employees pe ON pe.id = cc.pickup_employee_id
         LEFT JOIN employees ae ON ae.id = cc.arrival_employee_id
         LEFT JOIN employees de ON de.id = cc.dispatch_employee_id
         LEFT JOIN employees re ON re.id = cc.return_employee_id
         LEFT JOIN facilities af ON af.id = cc.arrival_facility_id
         LEFT JOIN facilities df ON df.id = cc.dispatch_facility_id
         WHERE cc.facility_id = :facility_id AND cc.pickup_date BETWEEN :start_date AND :end_date AND cc.deleted_at IS NULL
         ORDER BY cc.pickup_date ASC, cc.id ASC'
    );
    $recordsStmt->execute([
        ':facility_id' => $facilityId,
        ':start_date' => $rangeStartStr,
        ':end_date' => $rangeEndStr,
    ]);
    $records = $recordsStmt->fetchAll();
}

function cr_bag($count)
{
    return $count === null ? '-' : (int) $count . '袋';
}

function cr_time($time)
{
    return $time === null ? '-' : substr($time, 0, 5);
}

function cr_issued(array $record): string
{
    $parts = [];
    if ($record['issued_bag_orange'] !== null) {
        $parts[] = 'オレンジ' . (int) $record['issued_bag_orange'];
    }
    if ($record['issued_bag_yellow'] !== null) {
        $parts[] = '黄' . (int) $record['issued_bag_yellow'];
    }
    if ($record['issued_bag_blue'] !== null) {
        $parts[] = '青' . (int) $record['issued_bag_blue'];
    }
    if ($record['issued_laundry_net_count'] !== null) {
        $parts[] = 'ネット' . (int) $record['issued_laundry_net_count'];
    }
    return empty($parts) ? '-' : implode('・', $parts);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title>集荷記録簿 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        .filter-row { margin-bottom: 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .month-nav a { margin-right: 8px; }
        table.record-table { border-collapse: collapse; width: 100%; font-size: 0.8em; }
        table.record-table th, table.record-table td { border: 1px solid #ccc; padding: 4px 6px; text-align: center; }
        table.record-table th { background: #f5f5f5; }
    </style>
</head>
<body>
<header>
    <h1>集荷記録簿</h1>
    <nav><a href="/staff/collection_entry.php">記録を入力する</a> | <a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<form method="get" action="/staff/collection_records.php" class="filter-row">
    <label for="facility_id">施設:</label>
    <select id="facility_id" name="facility_id" onchange="this.form.submit()">
        <option value="">選択してください</option>
        <?php foreach ($facilities as $facility): ?>
            <option value="<?= (int) $facility['id'] ?>" <?= (int) $facility['id'] === $facilityId ? 'selected' : '' ?>>
                <?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" name="month" value="<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">
</form>

<div class="month-nav">
    <a href="?facility_id=<?= $facilityId ?>&month=<?= htmlspecialchars($prevMonth, ENT_QUOTES, 'UTF-8') ?>">← 前月</a>
    <strong><?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?></strong>
    <a href="?facility_id=<?= $facilityId ?>&month=<?= htmlspecialchars($nextMonth, ENT_QUOTES, 'UTF-8') ?>">次月 →</a>
</div>

<?php if ($facilityId <= 0 || $selectedFacilityName === ''): ?>
    <p class="notice">施設を選択してください。</p>
<?php else: ?>
    <h2><?= htmlspecialchars($selectedFacilityName, ENT_QUOTES, 'UTF-8') ?>　<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>分</h2>

    <?php if (empty($records)): ?>
        <p class="notice">対象月の記録はありません。</p>
    <?php else: ?>
        <table class="record-table">
            <thead>
                <tr>
                    <th rowspan="2">集荷日</th>
                    <th colspan="4">集荷</th>
                    <th colspan="4">クリーニング所到着</th>
                    <th colspan="4">クリーニング所発送</th>
                    <th colspan="3">返却</th>
                    <th rowspan="2">備考</th>
                </tr>
                <tr>
                    <th>リネン袋数</th><th>時間</th><th>担当者</th><th>交付袋・ネット</th>
                    <th>リネン袋数</th><th>到着日</th><th>時間</th><th>担当者・クリーニング所</th>
                    <th>リネン袋数</th><th>発送日</th><th>時間</th><th>担当者・クリーニング所</th>
                    <th>リネン袋数</th><th>返却日</th><th>時間</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['pickup_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= cr_bag($record['pickup_bag_count']) ?></td>
                        <td><?= cr_time($record['pickup_time']) ?></td>
                        <td><?= htmlspecialchars($record['pickup_employee_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(cr_issued($record), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= cr_bag($record['arrival_bag_count']) ?></td>
                        <td><?= htmlspecialchars($record['arrival_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= cr_time($record['arrival_time']) ?></td>
                        <td><?= htmlspecialchars($record['arrival_employee_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?><?= $record['arrival_facility_name'] !== null ? '（' . htmlspecialchars($record['arrival_facility_name'], ENT_QUOTES, 'UTF-8') . '）' : '' ?></td>
                        <td><?= cr_bag($record['dispatch_bag_count']) ?></td>
                        <td><?= htmlspecialchars($record['dispatch_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= cr_time($record['dispatch_time']) ?></td>
                        <td><?= htmlspecialchars($record['dispatch_employee_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?><?= $record['dispatch_facility_name'] !== null ? '（' . htmlspecialchars($record['dispatch_facility_name'], ENT_QUOTES, 'UTF-8') . '）' : '' ?></td>
                        <td><?= cr_bag($record['return_bag_count']) ?></td>
                        <td><?= htmlspecialchars($record['return_date'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= cr_time($record['return_time']) ?></td>
                        <td><?= htmlspecialchars($record['remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
