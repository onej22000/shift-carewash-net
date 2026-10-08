<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// staff/work_speed.php（薄いラッパー、admin/collection_headcount.phpと同じ方式）経由で
// 読み込まれた場合、「日別サイクル明細」をstaff権限でも閲覧できるようにする。表示される内容は
// 常にこのファイルと同一（従業員別・施設別サイクル明細は下記$showEmployeeSpeedTable/
// $showCycleDetailTableで元々非表示、staff向けにはさらに念のため!$isStaffViewでも二重にガードする）。
$isStaffView = defined('WORK_SPEED_STAFF_VIEW') && WORK_SPEED_STAFF_VIEW;
$admin = require_login($isStaffView ? 'staff' : 'admin');
$dashboardPath = $isStaffView ? '/staff/dashboard.php' : '/admin/dashboard.php';
$logoutPath = $isStaffView ? '/staff/logout.php' : '/admin/logout.php';
$pageTitle = '作業速度分析';
$pdo = getPdo();

$periodLabels = [
    'all' => '全期間',
    '7' => '直近7日',
    '30' => '直近30日',
];

$period = (string) ($_GET['period'] ?? 'all');
if (!isset($periodLabels[$period])) {
    $period = 'all';
}

$stageParams = [];
$stageDateCondition = '';
if ($period !== 'all') {
    $end = (new DateTime())->format('Y-m-d');
    $start = (new DateTime())->modify('-' . ((int) $period - 1) . ' days')->format('Y-m-d');
    $stageDateCondition = 'AND record_date BETWEEN :start AND :end';
    $stageParams = [':start' => $start, ':end' => $end];
} else {
    $start = '2000-01-01';
    $end = '2099-12-31';
}

// 「従業員別 1人あたり平均所要時間」表は2026-08-14に非表示化。データ取得・計算ロジック自体は
// 将来的な復活・別用途での利用に備えてそのまま残し、下部のHTML出力のみこのフラグで条件分岐する。
$showEmployeeSpeedTable = false;

// ---- 従業員別: 参加セッションごとの実働時間（work_stage_record_employees.started_at〜
// work_stage_records.completed_atの実測）----
// 同じセッションを2名で処理しても1名で処理しても、各自の開始時刻はresolve_work_stage_started_at()で
// 個別に算出済み（本人の直前セッションの完了時刻、無ければ当日の洗濯代行出勤時刻）のため、
// 「セッション単位で1つに丸めて人数按分する」旧方式（work_minutes÷person_count）より正確。
// ただしこの参加者テーブルは2026-08-06以降に登録された記録にしかデータが無いため、
// それ以前の記録や、参加者未選択のまま登録された記録は対象外になる。
$sessionStageCondition = $stageDateCondition !== '' ? str_replace('record_date', 'wsr.record_date', $stageDateCondition) : '';
$sessionStmt = $pdo->prepare(
    "SELECT wse.employee_id, TIMESTAMPDIFF(MINUTE, wse.started_at, wsr.completed_at) AS session_minutes
     FROM work_stage_record_employees wse
     INNER JOIN work_stage_records wsr ON wsr.id = wse.work_stage_record_id
     WHERE wsr.stage = 'wash' AND wsr.deleted_at IS NULL AND wsr.completed_at IS NOT NULL $sessionStageCondition"
);
$sessionStmt->execute($stageParams);

// 「施設別・日別 サイクル明細」表は2026-08-14に非表示化。理由: 作業時間の算出が
// resolve_work_stage_started_at()ベース（work_stage_recordsの登録間隔）のため、後からまとめて
// 入力した場合に実際の作業時間ではなく登録間隔を拾ってしまう可能性があり、1施設1サイクル単位
// では不正確になり得る。また1日に複数施設をまたぐ場合、実際の出退勤時間を施設ごとに按分する
// 合理的な方法がない。データ取得・計算ロジック自体は将来的な復活・別用途に備えて残し、
// 下部のHTML出力のみこのフラグで条件分岐する。
$showCycleDetailTable = false;

// 「日別サイクル明細」の説明バナー（黄色い注記文）は2026-08-14に非表示化。
// テキスト自体は残し、表示のみこのフラグで条件分岐する。
$showByDayNotice = false;

// 洗濯代行の実働メンバーのみに絞る（2026-08-14、明示的な指定により固定）。
const WORK_SPEED_TARGET_EMPLOYEE_NAMES = ['山本真実', '山本真栄', '森香奈子', '渡邊友梨'];
$employeesStmt = $pdo->prepare(
    "SELECT id, name FROM employees
     WHERE role = 'staff' AND is_shared_account = 0
       AND name IN (" . implode(',', array_fill(0, count(WORK_SPEED_TARGET_EMPLOYEE_NAMES), '?')) . ")
     ORDER BY name"
);
$employeesStmt->execute(WORK_SPEED_TARGET_EMPLOYEE_NAMES);
$employees = $employeesStmt->fetchAll();

$sessionStatsByEmployee = [];
foreach ($sessionStmt->fetchAll() as $row) {
    $employeeId = (int) $row['employee_id'];
    $minutes = max(0, (int) $row['session_minutes']);
    if (!isset($sessionStatsByEmployee[$employeeId])) {
        $sessionStatsByEmployee[$employeeId] = ['total_minutes' => 0, 'session_count' => 0];
    }
    $sessionStatsByEmployee[$employeeId]['total_minutes'] += $minutes;
    $sessionStatsByEmployee[$employeeId]['session_count']++;
}

$employeeSpeed = [];
foreach ($employees as $employee) {
    $employeeId = (int) $employee['id'];
    $stats = $sessionStatsByEmployee[$employeeId] ?? ['total_minutes' => 0, 'session_count' => 0];

    $employeeSpeed[] = [
        'name' => $employee['name'],
        'total_minutes' => $stats['total_minutes'],
        'session_count' => $stats['session_count'],
    ];
}

// ---- 施設別・日別 サイクル明細一覧（1サイクル1行）----
// 施設別・区分別の按分集計（旧calc_facility_category_work_stats()、廃止）に代えて、
// collection_cycles ⇔ work_stage_records.collection_cycle_id の直接紐付けに基づく明細を出す。
// 1サイクルにつきwork_stage_recordsは最大1件（admin/collection_headcount.phpのcomplete_workが
// 重複登録をブロックしているため）だが、その1件に複数参加者がいることはある。
// resolve_work_stage_started_at()はstarted_atを参加者ごとに個別解決するため、実際には
// 参加者間でstarted_atが一致しない（実データで確認済み）。作業時間は「その作業自体にかかった
// 実時間」を1本の値で示すため、合算ではなく、参加者のうち最も早いstarted_at（＝completed_atとの
// 差分が最大になる参加者のTIMESTAMPDIFF）を採用する（2026-08-14、合算方式から変更）。
// 作業氏名は従来通り参加者全員を「・」区切りで連結する。
$cycleDetailStmt = $pdo->prepare(
    "SELECT cc.id AS cycle_id, f.name AS facility_name, cc.pickup_date,
            cc.pickup_bag_count, cc.return_ready_laundry_net_count,
            wsr.id AS wsr_id, wsr.completed_at AS wsr_completed_at
     FROM collection_cycles cc
     INNER JOIN facilities f ON f.id = cc.facility_id
     LEFT JOIN work_stage_records wsr ON wsr.collection_cycle_id = cc.id AND wsr.deleted_at IS NULL
     WHERE cc.deleted_at IS NULL AND f.facility_type != 'クリーニング所'
           AND cc.pickup_date BETWEEN :start AND :end
           AND (f.onboarding_start_date IS NULL OR cc.pickup_date >= f.onboarding_start_date)
     ORDER BY f.name, cc.pickup_date"
);
$cycleDetailStmt->execute([':start' => $start, ':end' => $end]);
$cycleDetailRows = $cycleDetailStmt->fetchAll();

$wsrIds = array_values(array_unique(array_filter(array_map(
    static fn (array $row): ?int => $row['wsr_id'] !== null ? (int) $row['wsr_id'] : null,
    $cycleDetailRows
))));

$workStatsByWsrId = [];
if (!empty($wsrIds)) {
    $placeholders = implode(',', array_fill(0, count($wsrIds), '?'));
    $participantStmt = $pdo->prepare(
        "SELECT wse.work_stage_record_id, e.name AS employee_name,
                TIMESTAMPDIFF(MINUTE, wse.started_at, wsr.completed_at) AS session_minutes
         FROM work_stage_record_employees wse
         INNER JOIN work_stage_records wsr ON wsr.id = wse.work_stage_record_id
         INNER JOIN employees e ON e.id = wse.employee_id
         WHERE wse.work_stage_record_id IN ($placeholders)
         ORDER BY e.name"
    );
    $participantStmt->execute($wsrIds);
    foreach ($participantStmt->fetchAll() as $row) {
        $wsrId = (int) $row['work_stage_record_id'];
        if (!isset($workStatsByWsrId[$wsrId])) {
            $workStatsByWsrId[$wsrId] = ['elapsed_minutes' => 0, 'names' => []];
        }
        $workStatsByWsrId[$wsrId]['elapsed_minutes'] = max($workStatsByWsrId[$wsrId]['elapsed_minutes'], max(0, (int) $row['session_minutes']));
        $workStatsByWsrId[$wsrId]['names'][] = $row['employee_name'];
    }
}

// ---- 日別サイクル明細一覧（1作業日1行、全施設集約）----
// 集荷日の翌日以降に洗濯する場合があるため、collection_cycles.pickup_dateではなく、
// 洗濯ネット数・返却リネン袋数と同じ画面で作業登録した日
// （work_stage_records.record_date）を集計日として使用する。
// 2026-08-14修正: 交付のみ（空袋・空ネット納品のみで、pickup_bag_count・arrival_bag_countが
// どちらもNULL＝物理的に何も動いていない）のサイクルは「実務が発生した日」に含めない。
// 集荷リネン袋数（合計）は、manual_register経由（collection_headcount.php上部フォーム）で
// 作成された一部のレコードがpickup_bag_countを持たずarrival_bag_countのみに値が入るため、
// COALESCE(pickup_bag_count, arrival_bag_count)の合計に変更。
$dailyTotalsStmt = $pdo->prepare(
    "SELECT wsr.record_date AS work_date,
            COUNT(DISTINCT cc.facility_id) AS facility_count,
            SUM(COALESCE(cc.pickup_bag_count, cc.arrival_bag_count)) AS pickup_bag_total,
            SUM(cc.return_ready_laundry_net_count) AS net_total
     FROM work_stage_records wsr
     INNER JOIN collection_cycles cc ON cc.id = wsr.collection_cycle_id
     INNER JOIN facilities f ON f.id = cc.facility_id
     WHERE wsr.deleted_at IS NULL AND wsr.stage = 'wash'
           AND cc.deleted_at IS NULL AND f.facility_type != 'クリーニング所'
           AND wsr.record_date BETWEEN :start AND :end
           AND (cc.pickup_bag_count IS NOT NULL OR cc.arrival_bag_count IS NOT NULL)
           AND (f.onboarding_start_date IS NULL OR cc.pickup_date >= f.onboarding_start_date)
     GROUP BY wsr.record_date
     ORDER BY wsr.record_date"
);
$dailyTotalsStmt->execute([':start' => $start, ':end' => $end]);
$dailyTotalsRows = $dailyTotalsStmt->fetchAll();

// 作業時間・作業氏名は、2026-09-28に「洗濯代行」区分の打刻（attendance）ベースに一本化した。
// （以前は作業氏名＝退勤時の作業実績入力・人数確認画面の参加者（work_stage_record_employees）、
// 作業時間＝その日の最も早い出勤〜最も遅い退勤の幅だった。退勤時の入力は同日に廃止）
// - 作業時間: その日に洗濯代行で打刻し退勤済み（status = 'done'）の全従業員の実働時間
//   （attendance.work_minutes、休憩控除後）の合計＝延べ時間。退勤前の人の分は含めない。
// - 作業氏名: その日に洗濯代行で出勤した全従業員（退勤済みか否かを問わない）を重複なく列挙する。
// 過去日付もこの集計で表示される（保存済みの値を読むだけで、再集計のためのデータ更新は不要）。
// 2026-10-08修正: 打刻のcategoryだけで判定すると、打刻後に時刻だけ修正されて区分が残った打刻
// （例: 2026-09-28 山本真実 attendance#287。洗濯代行で出勤→10/02に店舗分の打刻を削除し、
// こちらの時刻を店舗シフト13:00〜16:00に合わせて修正したため、区分だけ洗濯代行のまま）を
// 洗濯代行として拾ってしまう。そこで、打刻をシフト（予定）に紐付け、紐付いたシフトの区分で判定する。
// attendance.shift_idは出勤時に保存されておらず全件NULLのため、同じ従業員・同じ日のシフトのうち
// 打刻時間帯との重なりが最も長いものを紐付け先とする（重なるシフトが無ければ「区分不明」）。
// - 紐付いたシフトの区分が洗濯代行のみ → 対象
// - 紐付いたシフトが洗濯代行を含む複合区分 → 打刻の区分（出勤時に選択）も洗濯代行なら対象
// - それ以外（店舗・集荷のシフト）→ 対象外
// - 区分不明の打刻は集計から除外し、件数を画面下に注記する
$dailyAttendanceStmt = $pdo->prepare(
    "SELECT a.id, a.employee_id, DATE(a.clock_in_at) AS work_day, e.name AS employee_name,
            a.category, a.clock_in_at, a.clock_out_at, a.status, a.work_minutes
     FROM attendance a
     INNER JOIN employees e ON e.id = a.employee_id
     WHERE a.deleted_at IS NULL
           AND DATE(a.clock_in_at) BETWEEN :start AND :end
     ORDER BY a.clock_in_at"
);
$dailyAttendanceStmt->execute([':start' => $start, ':end' => $end]);
$dailyAttendanceRows = $dailyAttendanceStmt->fetchAll();

$shiftsForAttendanceStmt = $pdo->prepare(
    'SELECT employee_id, work_date, start_time, end_time, categories
     FROM shifts
     WHERE work_date BETWEEN :start AND :end'
);
$shiftsForAttendanceStmt->execute([':start' => $start, ':end' => $end]);
$shiftsForAttendance = [];
foreach ($shiftsForAttendanceStmt->fetchAll() as $row) {
    $shiftsForAttendance[(int) $row['employee_id']][$row['work_date']][] = $row;
}

$nowTimestamp = time();
$dailyWorkStatsByDate = [];
$unlinkedAttendanceRows = [];
foreach ($dailyAttendanceRows as $row) {
    $date = $row['work_day'];
    $inTimestamp = strtotime($row['clock_in_at']);
    $outTimestamp = $row['clock_out_at'] !== null ? strtotime($row['clock_out_at']) : max($inTimestamp, $nowTimestamp);

    $linkedShift = null;
    $linkedOverlap = 0;
    foreach ($shiftsForAttendance[(int) $row['employee_id']][$date] ?? [] as $shift) {
        $shiftStart = strtotime($date . ' ' . $shift['start_time']);
        $shiftEnd = strtotime($date . ' ' . $shift['end_time']);
        if ($shiftEnd <= $shiftStart) {
            $shiftEnd += 86400; // 日付をまたぐシフト
        }
        // 退勤前（勤務中）で出勤直後の打刻も紐付くよう、重なり0秒でも出勤時刻がシフト内なら1秒扱い
        $overlap = min($outTimestamp, $shiftEnd) - max($inTimestamp, $shiftStart);
        if ($overlap <= 0 && $inTimestamp >= $shiftStart && $inTimestamp < $shiftEnd) {
            $overlap = 1;
        }
        if ($overlap > $linkedOverlap) {
            $linkedOverlap = $overlap;
            $linkedShift = $shift;
        }
    }

    if ($linkedShift === null) {
        $unlinkedAttendanceRows[] = $row;
        continue;
    }

    $shiftCategories = categories_from_value($linkedShift['categories']);
    if (!in_array('洗濯代行', $shiftCategories, true)) {
        continue;
    }
    if (count($shiftCategories) > 1 && $row['category'] !== '洗濯代行') {
        continue;
    }

    if (!isset($dailyWorkStatsByDate[$date])) {
        $dailyWorkStatsByDate[$date] = ['names' => [], 'work_minutes' => null];
    }
    $dailyWorkStatsByDate[$date]['names'][$row['employee_name']] = true;
    if ($row['status'] === 'done' && $row['work_minutes'] !== null) {
        $dailyWorkStatsByDate[$date]['work_minutes'] = ($dailyWorkStatsByDate[$date]['work_minutes'] ?? 0) + (int) $row['work_minutes'];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($isStaffView): ?>
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <?php endif; ?>
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | <?= $isStaffView ? 'シフト管理' : '管理者' ?></title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        .period-nav { margin-bottom: 16px; }
        .period-nav a { margin-right: 12px; padding: 4px 10px; border: 1px solid #ccc; border-radius: 12px; text-decoration: none; color: #222; }
        .period-nav a.active { background: #0b5ed7; color: #fff; border-color: #0b5ed7; }
        section { margin-bottom: 32px; }
        table.speed { border-collapse: collapse; width: 100%; }
        table.speed th, table.speed td { border: 1px solid #ccc; padding: 8px; text-align: right; }
        table.speed th:first-child, table.speed td:first-child { text-align: left; }
        table.speed th { background: #f5f5f5; }
        table.speed tfoot th, table.speed tfoot td { background: #eef3fb; font-weight: bold; }
        .total-col { font-weight: bold; }
    </style>
</head>
<body>
<header>
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん<?= $isStaffView ? '' : '（管理者）' ?> | <a href="<?= htmlspecialchars($dashboardPath, ENT_QUOTES, 'UTF-8') ?>">ダッシュボード</a> | <a href="<?= htmlspecialchars($logoutPath, ENT_QUOTES, 'UTF-8') ?>">ログアウト</a></nav>
</header>

<div class="period-nav">
    <?php foreach ($periodLabels as $key => $label): ?>
        <a href="?period=<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" class="<?= $period === (string) $key ? 'active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</div>

<?php if ($showEmployeeSpeedTable && !$isStaffView): ?>
<section class="by-employee">
    <h2>従業員別 1人あたり平均所要時間</h2>
    <p class="notice">参加者ごとの開始時刻（本人の直前セッションの完了時刻、無ければ当日の洗濯代行出勤時刻）〜作業完了時刻の実測に基づく集計です。
        同じセッションを複数名で処理しても、各自の実際の開始時刻をもとに個別に計算するため、以前の「実働時間合計÷作業人数合計」による按分より正確です。
        参加した従業員を選択して登録された記録（2026-08-06以降）のみが対象です。</p>

    <?php if (empty($employees)): ?>
        <p class="notice">従業員が登録されていません。</p>
    <?php else: ?>
        <table class="speed">
            <thead>
                <tr>
                    <th>従業員</th>
                    <th>実働時間合計</th>
                    <th>セッション数</th>
                    <th>1セッションあたり平均</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employeeSpeed as $data): ?>
                    <tr>
                        <td><?= htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format($data['total_minutes'] / 60, 2) ?>時間</td>
                        <td><?= $data['session_count'] ?>件</td>
                        <td class="total-col">
                            <?= $data['session_count'] > 0 ? number_format($data['total_minutes'] / $data['session_count'] / 60, 2) . '時間' : '-' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($showCycleDetailTable && !$isStaffView): ?>
<section class="by-cycle">
    <h2>施設別・日別 サイクル明細</h2>
    <p class="notice">
        集荷サイクル（collection_cycles）を施設・集荷日ごとに1行で並べた明細です。
        作業時間は、そのサイクルに紐づく作業登録（work_stage_records.collection_cycle_id）の
        参加者のうち最も早い開始時刻（本人の同日の直前セッション完了時刻、無ければ当日の洗濯代行出勤時刻）〜
        作業完了時刻の1本の実時間です（参加人数によらず、その作業自体にかかった実時間を示します）。
        作業氏名は参加者全員を「・」区切りで表示しています。作業登録が未登録のサイクルは「-」で表示されます。
    </p>

    <?php if (empty($cycleDetailRows)): ?>
        <p class="notice">対象期間に集荷サイクルがありません。</p>
    <?php else: ?>
        <table class="speed">
            <thead>
                <tr>
                    <th>施設名</th>
                    <th>集荷日</th>
                    <th>集荷リネン袋数</th>
                    <th>洗濯ネット数</th>
                    <th>作業時間</th>
                    <th>作業氏名</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cycleDetailRows as $row): ?>
                    <?php $workStats = $row['wsr_id'] !== null ? ($workStatsByWsrId[(int) $row['wsr_id']] ?? null) : null; ?>
                    <tr>
                        <td><?= htmlspecialchars($row['facility_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['pickup_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $row['pickup_bag_count'] !== null ? (int) $row['pickup_bag_count'] . '袋' : '-' ?></td>
                        <td><?= $row['return_ready_laundry_net_count'] !== null ? (int) $row['return_ready_laundry_net_count'] . '枚' : '-' ?></td>
                        <td class="total-col">
                            <?= $workStats !== null ? number_format($workStats['elapsed_minutes'] / 60, 2) . '時間' : '-' ?>
                        </td>
                        <td><?= $workStats !== null ? htmlspecialchars(implode('・', $workStats['names']), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="by-day">
    <h2>日別サイクル明細</h2>
    <?php if ($showByDayNotice): ?>
    <p class="notice">
        作業登録日（work_stage_records.record_date）ごとに、その日に作業登録した全施設・全サイクル（交付のみ＝
        pickup_bag_count・arrival_bag_countが両方NULLのサイクルを除く）を集約した1行です。
        作業時間は、その日に「洗濯代行」区分で打刻し退勤済みの全従業員の実働時間（休憩控除後）の合計
        （延べ時間）です。作業氏名は、その日に「洗濯代行」区分で出勤した全従業員を重複なく列挙しています。
    </p>
    <?php endif; ?>

    <?php if (empty($dailyTotalsRows)): ?>
        <p class="notice">対象期間に集荷サイクルがありません。</p>
    <?php else: ?>
        <table class="speed">
            <thead>
                <tr>
                    <th>日付</th>
                    <th>施設数</th>
                    <th>集荷リネン袋数（合計）</th>
                    <th>洗濯ネット数（合計）</th>
                    <th>作業時間</th>
                    <th>作業氏名</th>
                    <th>1人あたり枚/時（枚/人・時）</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailyTotalsRows as $row): ?>
                    <?php
                    $dayStats = $dailyWorkStatsByDate[$row['work_date']] ?? null;
                    $dayNames = $dayStats !== null ? array_keys($dayStats['names']) : [];
                    sort($dayNames);
                    $dayWorkMinutes = $dayStats !== null ? $dayStats['work_minutes'] : null;
                    // 洗濯ネット数（合計）÷ 延べ作業時間。表示用に丸めた時間ではなく分の合計から計算する。
                    $dayNetsPerPersonHour = ($row['net_total'] !== null && $dayWorkMinutes !== null && $dayWorkMinutes > 0)
                        ? (int) $row['net_total'] / ($dayWorkMinutes / 60)
                        : null;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['work_date'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) $row['facility_count'] ?></td>
                        <td><?= $row['pickup_bag_total'] !== null ? (int) $row['pickup_bag_total'] . '袋' : '-' ?></td>
                        <td><?= $row['net_total'] !== null ? (int) $row['net_total'] . '枚' : '-' ?></td>
                        <td class="total-col">
                            <?= $dayWorkMinutes !== null ? number_format($dayWorkMinutes / 60, 2) . '時間' : '-' ?>
                        </td>
                        <td><?= !empty($dayNames) ? htmlspecialchars(implode('・', $dayNames), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= $dayNetsPerPersonHour !== null ? number_format($dayNetsPerPersonHour, 1) . '枚/人・時' : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($unlinkedAttendanceRows)): ?>
        <p class="notice">
            区分不明の打刻: <?= count($unlinkedAttendanceRows) ?>件（同じ日に時間帯の重なるシフトが無いため区分を判定できず、作業時間・作業氏名の集計から除外しています）<br>
            <?php foreach ($unlinkedAttendanceRows as $row): ?>
                <?= htmlspecialchars($row['work_day'] . ' ' . $row['employee_name'] . ' ' . substr($row['clock_in_at'], 11, 5) . '〜' . ($row['clock_out_at'] !== null ? substr($row['clock_out_at'], 11, 5) : '') . '（打刻区分: ' . ($row['category'] ?? '未選択') . '）', ENT_QUOTES, 'UTF-8') ?><br>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
</section>
</body>
</html>
