<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

function calc_wage_summary(PDO $pdo, array $employee, string $yearMonth): array
{
    [$monthStart, $monthEnd] = get_month_range($yearMonth);

    $stmt = $pdo->prepare(
        "SELECT DATE(clock_in_at) AS work_day, clock_in_at, clock_out_at, work_minutes
         FROM attendance
         WHERE employee_id = :employee_id AND status = 'done'
           AND deleted_at IS NULL
           AND DATE(clock_in_at) BETWEEN :start AND :end"
    );
    $stmt->execute([':employee_id' => $employee['id'], ':start' => $monthStart, ':end' => $monthEnd]);

    $dailyMinutes = [];
    $dailyNightMinutes = [];
    foreach ($stmt->fetchAll() as $row) {
        $workMinutes = (int) $row['work_minutes'];
        $dailyMinutes[$row['work_day']] = ($dailyMinutes[$row['work_day']] ?? 0) + $workMinutes;
        $dailyNightMinutes[$row['work_day']] = ($dailyNightMinutes[$row['work_day']] ?? 0)
            + calc_record_night_work_minutes($row['clock_in_at'], $row['clock_out_at'], $workMinutes);
    }

    return calc_wage_breakdown_from_daily_minutes($pdo, $employee, $dailyMinutes, $dailyNightMinutes);
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $postYearMonth = (string) ($_POST['year_month'] ?? '');

        if (($action === 'confirm' || $action === 'reconfirm') && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $postYearMonth)) {
            $empStmt = $pdo->prepare("SELECT id, name, hourly_wage_weekday, hourly_wage_holiday FROM employees WHERE id = :id AND role = 'staff'");
            $empStmt->execute([':id' => $employeeId]);
            $employee = $empStmt->fetch();

            if ($employee === false) {
                $errorMessage = '従業員が見つかりません。';
            } else {
                $summary = calc_wage_summary($pdo, $employee, $postYearMonth);
                $totalWage = $summary['grand_total_wage'];

                $existingStmt = $pdo->prepare(
                    'SELECT id FROM monthly_wages WHERE employee_id = :employee_id AND `year_month` = :year_month'
                );
                $existingStmt->execute([':employee_id' => $employeeId, ':year_month' => $postYearMonth]);
                $existing = $existingStmt->fetch();

                if ($existing !== false && $action !== 'reconfirm') {
                    $errorMessage = 'この月は既に確定済みです。再確定する場合は「再確定する」を選択してください。';
                } else {
                    $confirmedAt = (new DateTime())->format('Y-m-d H:i:s');

                    if ($existing !== false) {
                        $saveStmt = $pdo->prepare(
                            'UPDATE monthly_wages
                             SET total_work_minutes = :total_minutes, hourly_wage_weekday = :hourly_wage_weekday,
                                 hourly_wage_holiday = :hourly_wage_holiday, total_wage = :total_wage,
                                 weekday_regular_minutes = :weekday_regular_minutes, weekday_overtime_minutes = :weekday_overtime_minutes,
                                 holiday_regular_minutes = :holiday_regular_minutes, holiday_overtime_minutes = :holiday_overtime_minutes,
                                 night_minutes = :night_minutes,
                                 weekday_wage = :weekday_wage, weekday_overtime_wage = :weekday_overtime_wage,
                                 holiday_wage = :holiday_wage, holiday_overtime_wage = :holiday_overtime_wage,
                                 night_wage = :night_wage,
                                 confirmed_at = :confirmed_at, confirmed_by = :confirmed_by
                             WHERE id = :id'
                        );
                        $saveStmt->execute([
                            ':total_minutes' => $summary['total_minutes'],
                            ':hourly_wage_weekday' => (int) $employee['hourly_wage_weekday'],
                            ':hourly_wage_holiday' => (int) $employee['hourly_wage_holiday'],
                            ':total_wage' => $totalWage,
                            ':weekday_regular_minutes' => $summary['weekday_regular_minutes'],
                            ':weekday_overtime_minutes' => $summary['weekday_overtime_minutes'],
                            ':holiday_regular_minutes' => $summary['holiday_regular_minutes'],
                            ':holiday_overtime_minutes' => $summary['holiday_overtime_minutes'],
                            ':night_minutes' => $summary['night_minutes'],
                            ':weekday_wage' => $summary['weekday_wage'],
                            ':weekday_overtime_wage' => $summary['weekday_overtime_wage'],
                            ':holiday_wage' => $summary['holiday_wage'],
                            ':holiday_overtime_wage' => $summary['holiday_overtime_wage'],
                            ':night_wage' => $summary['night_wage'],
                            ':confirmed_at' => $confirmedAt,
                            ':confirmed_by' => $admin['id'],
                            ':id' => $existing['id'],
                        ]);
                        set_flash('success', htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') . 'さんの' . $postYearMonth . 'を再確定しました。');
                    } else {
                        $saveStmt = $pdo->prepare(
                            'INSERT INTO monthly_wages (
                                 employee_id, `year_month`, total_work_minutes, hourly_wage_weekday, hourly_wage_holiday, total_wage,
                                 weekday_regular_minutes, weekday_overtime_minutes, holiday_regular_minutes, holiday_overtime_minutes,
                                 night_minutes,
                                 weekday_wage, weekday_overtime_wage, holiday_wage, holiday_overtime_wage,
                                 night_wage,
                                 confirmed_at, confirmed_by
                             )
                             VALUES (
                                 :employee_id, :year_month, :total_minutes, :hourly_wage_weekday, :hourly_wage_holiday, :total_wage,
                                 :weekday_regular_minutes, :weekday_overtime_minutes, :holiday_regular_minutes, :holiday_overtime_minutes,
                                 :night_minutes,
                                 :weekday_wage, :weekday_overtime_wage, :holiday_wage, :holiday_overtime_wage,
                                 :night_wage,
                                 :confirmed_at, :confirmed_by
                             )'
                        );
                        $saveStmt->execute([
                            ':employee_id' => $employeeId,
                            ':year_month' => $postYearMonth,
                            ':total_minutes' => $summary['total_minutes'],
                            ':hourly_wage_weekday' => (int) $employee['hourly_wage_weekday'],
                            ':hourly_wage_holiday' => (int) $employee['hourly_wage_holiday'],
                            ':total_wage' => $totalWage,
                            ':weekday_regular_minutes' => $summary['weekday_regular_minutes'],
                            ':weekday_overtime_minutes' => $summary['weekday_overtime_minutes'],
                            ':holiday_regular_minutes' => $summary['holiday_regular_minutes'],
                            ':holiday_overtime_minutes' => $summary['holiday_overtime_minutes'],
                            ':night_minutes' => $summary['night_minutes'],
                            ':weekday_wage' => $summary['weekday_wage'],
                            ':weekday_overtime_wage' => $summary['weekday_overtime_wage'],
                            ':holiday_wage' => $summary['holiday_wage'],
                            ':holiday_overtime_wage' => $summary['holiday_overtime_wage'],
                            ':night_wage' => $summary['night_wage'],
                            ':confirmed_at' => $confirmedAt,
                            ':confirmed_by' => $admin['id'],
                        ]);
                        set_flash('success', htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') . 'さんの' . $postYearMonth . 'の賃金を確定しました。');
                    }

                    header('Location: /admin/wages.php?month=' . urlencode($postYearMonth) . '&employee_id=' . $employeeId);
                    exit;
                }
            }
        } else {
            $errorMessage = '対象年月の形式が正しくありません。';
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();

$yearMonth = (string) ($_GET['month'] ?? (new DateTime())->format('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
    $yearMonth = (new DateTime())->format('Y-m');
}

$prevMonth = (DateTime::createFromFormat('Y-m-d', $yearMonth . '-01'))->modify('-1 month')->format('Y-m');
$nextMonth = (DateTime::createFromFormat('Y-m-d', $yearMonth . '-01'))->modify('+1 month')->format('Y-m');

$employeesStmt = $pdo->query("SELECT id, name, hourly_wage_weekday, hourly_wage_holiday, status FROM employees WHERE role = 'staff' ORDER BY name");
$employees = $employeesStmt->fetchAll();

$confirmedStmt = $pdo->prepare(
    'SELECT employee_id, total_work_minutes, hourly_wage_weekday, hourly_wage_holiday, total_wage,
            weekday_regular_minutes, weekday_overtime_minutes, holiday_regular_minutes, holiday_overtime_minutes,
            night_minutes,
            weekday_wage, weekday_overtime_wage, holiday_wage, holiday_overtime_wage, night_wage, confirmed_at
     FROM monthly_wages WHERE `year_month` = :year_month'
);
$confirmedStmt->execute([':year_month' => $yearMonth]);
$confirmedByEmployee = [];
foreach ($confirmedStmt->fetchAll() as $row) {
    $confirmedByEmployee[(int) $row['employee_id']] = $row;
}

$summaries = [];
foreach ($employees as $employee) {
    $summaries[(int) $employee['id']] = calc_wage_summary($pdo, $employee, $yearMonth);
}

$selectedEmployeeId = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : null;
$selectedEmployee = null;
foreach ($employees as $employee) {
    if ((int) $employee['id'] === $selectedEmployeeId) {
        $selectedEmployee = $employee;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>賃金確認 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        section { margin-bottom: 24px; }
        .month-nav { margin-bottom: 12px; }
        .month-nav a { margin-right: 12px; }
        .month-nav form { display: inline-block; margin-left: 8px; }
        table.wages { border-collapse: collapse; width: 100%; }
        table.wages th, table.wages td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        table.wages th { background: #f5f5f5; }
        .status-badge { display: inline-block; font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
        .status-provisional { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #e6f4ea; color: #1e7e34; }
        .detail-section { border: 1px solid #ccc; border-radius: 8px; padding: 16px; margin-top: 16px; }
        .amount { font-size: 1.3em; font-weight: bold; }
        .amount.provisional { color: #856404; }
        .amount.confirmed { color: #1e7e34; }
        .confirmed-meta { font-size: 0.85em; color: #555; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
    </style>
</head>
<body>
<header>
    <h1>賃金確認</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<div class="month-nav">
    <a href="?month=<?= htmlspecialchars($prevMonth, ENT_QUOTES, 'UTF-8') ?>">← 前月</a>
    <strong><?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?></strong>
    <a href="?month=<?= htmlspecialchars($nextMonth, ENT_QUOTES, 'UTF-8') ?>">次月 →</a>
    <form method="get" action="/admin/wages.php">
        <input type="month" name="month" value="<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">表示</button>
    </form>
</div>

<section class="employee-summary">
    <h2>従業員一覧（<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>）</h2>

    <?php if (empty($employees)): ?>
        <p class="notice">従業員が登録されていません。</p>
    <?php else: ?>
        <table class="wages">
            <thead>
                <tr>
                    <th>氏名</th>
                    <th>出勤日数</th>
                    <th>労働時間</th>
                    <th>残業時間</th>
                    <th>深夜労働時間</th>
                    <th>時給</th>
                    <th>基本給</th>
                    <th>残業手当</th>
                    <th>深夜手当</th>
                    <th>合計</th>
                    <th>状態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $employee): ?>
                    <?php
                    $employeeId = (int) $employee['id'];
                    $summary = $summaries[$employeeId];
                    $confirmed = $confirmedByEmployee[$employeeId] ?? null;
                    // 通勤手当・手当その他は従業員マスタに項目が無いため、現時点では常に0円として扱う
                    // （表示列からは外すが、合計への合算はこれまで通り行う）
                    $commutingAllowance = 0;
                    $otherAllowance = 0;
                    $provisionalTotal = $summary['grand_total_wage'] + $commutingAllowance + $otherAllowance;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $summary['attendance_days'] ?>日</td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($summary['total_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($summary['overtime_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($summary['night_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format((int) $employee['hourly_wage_weekday']) ?>円</td>
                        <td><?= number_format($summary['base_wage']) ?>円</td>
                        <td><?= number_format($summary['overtime_wage']) ?>円</td>
                        <td><?= number_format($summary['night_wage']) ?>円</td>
                        <td>
                            <?php if ($confirmed !== null): ?>
                                <?= number_format((int) $confirmed['total_wage'] + $commutingAllowance + $otherAllowance) ?>円
                            <?php else: ?>
                                <?= number_format($provisionalTotal) ?>円
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($confirmed !== null): ?>
                                <span class="status-badge status-confirmed">確定済み</span>
                            <?php else: ?>
                                <span class="status-badge status-provisional">未確定</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?month=<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>&employee_id=<?= $employeeId ?>">詳細・確定</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="weekday-summary">
    <h2>平日集計（<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>）</h2>

    <?php if (empty($employees)): ?>
        <p class="notice">従業員が登録されていません。</p>
    <?php else: ?>
        <table class="wages">
            <thead>
                <tr>
                    <th>氏名</th>
                    <th>出勤日数</th>
                    <th>労働時間</th>
                    <th>残業時間</th>
                    <th>深夜労働時間</th>
                    <th>時給</th>
                    <th>基本給</th>
                    <th>残業手当</th>
                    <th>深夜手当</th>
                    <th>合計</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $employee): ?>
                    <?php
                    $employeeId = (int) $employee['id'];
                    $summary = $summaries[$employeeId];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $summary['weekday_attendance_days'] ?>日</td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($summary['weekday_total_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($summary['weekday_overtime_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($summary['weekday_night_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format((int) $employee['hourly_wage_weekday']) ?>円</td>
                        <td><?= number_format($summary['weekday_wage']) ?>円</td>
                        <td><?= number_format($summary['weekday_overtime_wage']) ?>円</td>
                        <td><?= number_format($summary['weekday_night_wage']) ?>円</td>
                        <td><?= number_format($summary['weekday_total_wage']) ?>円</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="holiday-summary">
    <h2>土日祝集計（<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>）</h2>

    <?php if (empty($employees)): ?>
        <p class="notice">従業員が登録されていません。</p>
    <?php else: ?>
        <table class="wages">
            <thead>
                <tr>
                    <th>氏名</th>
                    <th>出勤日数</th>
                    <th>労働時間</th>
                    <th>残業時間</th>
                    <th>深夜労働時間</th>
                    <th>時給</th>
                    <th>基本給</th>
                    <th>残業手当</th>
                    <th>深夜手当</th>
                    <th>合計</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $employee): ?>
                    <?php
                    $employeeId = (int) $employee['id'];
                    $summary = $summaries[$employeeId];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $summary['holiday_attendance_days'] ?>日</td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($summary['holiday_total_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($summary['holiday_overtime_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(format_minutes_as_hours($summary['holiday_night_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format((int) $employee['hourly_wage_holiday']) ?>円</td>
                        <td><?= number_format($summary['holiday_wage']) ?>円</td>
                        <td><?= number_format($summary['holiday_overtime_wage']) ?>円</td>
                        <td><?= number_format($summary['holiday_night_wage']) ?>円</td>
                        <td><?= number_format($summary['holiday_total_wage']) ?>円</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php if ($selectedEmployee !== null): ?>
    <?php
    $employeeId = (int) $selectedEmployee['id'];
    $summary = $summaries[$employeeId];
    $confirmed = $confirmedByEmployee[$employeeId] ?? null;
    $provisionalWage = $summary['grand_total_wage'];
    [$detailMonthStart, $detailMonthEnd] = get_month_range($yearMonth);
    $categoryMinutes = calc_category_minutes($pdo, $employeeId, $detailMonthStart, $detailMonthEnd);
    ?>
    <section class="detail-section">
        <h2><?= htmlspecialchars($selectedEmployee['name'], ENT_QUOTES, 'UTF-8') ?>さんの<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>内訳</h2>

        <?php if (empty($summary['daily'])): ?>
            <p class="notice">この月の確定した打刻（退勤済み）データはありません。</p>
        <?php else: ?>
            <table class="wages">
                <thead>
                    <tr>
                        <th>日付</th>
                        <th>区分</th>
                        <th>実働時間</th>
                        <th>金額</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summary['daily'] as $row): ?>
                        <?php $isHoliday = is_holiday_date($pdo, $row['work_day']); ?>
                        <tr>
                            <td><?= htmlspecialchars($row['work_day'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $isHoliday ? '土日祝' : '平日' ?></td>
                            <td><?= htmlspecialchars(format_minutes_as_hours((int) $row['day_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= number_format((int) $row['day_wage']) ?>円</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p>月間合計実働: <strong><?= htmlspecialchars(format_minutes_as_hours($summary['total_minutes']), ENT_QUOTES, 'UTF-8') ?></strong>（平日時給<?= number_format((int) $selectedEmployee['hourly_wage_weekday']) ?>円 / 土日祝時給<?= number_format((int) $selectedEmployee['hourly_wage_holiday']) ?>円）</p>

        <h3>残業・平日休日内訳</h3>
        <table class="wages">
            <thead>
                <tr>
                    <th>区分</th>
                    <th>時間</th>
                    <th>賃金</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>平日勤務時間</td>
                    <td><?= htmlspecialchars(format_minutes_as_hours($summary['weekday_regular_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= number_format($summary['weekday_wage']) ?>円</td>
                </tr>
                <tr>
                    <td>平日残業時間</td>
                    <td><?= htmlspecialchars(format_minutes_as_hours($summary['weekday_overtime_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= number_format($summary['weekday_overtime_wage']) ?>円</td>
                </tr>
                <tr>
                    <td>休日勤務時間</td>
                    <td><?= htmlspecialchars(format_minutes_as_hours($summary['holiday_regular_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= number_format($summary['holiday_wage']) ?>円</td>
                </tr>
                <tr>
                    <td>休日残業時間</td>
                    <td><?= htmlspecialchars(format_minutes_as_hours($summary['holiday_overtime_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= number_format($summary['holiday_overtime_wage']) ?>円</td>
                </tr>
                <tr>
                    <td>深夜労働時間（22:00〜翌5:00、加算分）</td>
                    <td><?= htmlspecialchars(format_minutes_as_hours($summary['night_minutes']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= number_format($summary['night_wage']) ?>円</td>
                </tr>
            </tbody>
        </table>
        <p class="notice">深夜手当は上記の勤務時間・残業時間に含まれる深夜（22:00〜翌5:00）分に対する割増分のみを別建てで加算したものです（二重計上ではありません）。</p>

        <h3>業務種別ごとの時間（参考値・都度計算）</h3>
        <p class="notice">1シフトに複数の業務種別が選択されている場合は、優先順位（店舗＞洗濯代行＞集荷）で1つの種別にまとめて計上しています。同日に複数シフトがある場合は、各シフトの予定時間比率で実働時間を按分しています。シフトのない予定外出勤の時間はどの種別にも計上されません。この内訳は月次確定の対象外で、常に最新データから計算した参考値です。</p>
        <table class="wages">
            <thead>
                <tr>
                    <?php foreach (SHIFT_CATEGORIES as $category): ?>
                        <th><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <?php foreach (SHIFT_CATEGORIES as $category): ?>
                        <td><?= htmlspecialchars(format_minutes_as_hours($categoryMinutes[$category]), ENT_QUOTES, 'UTF-8') ?></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>

        <?php if ($confirmed !== null): ?>
            <p class="amount confirmed">確定支給額: <?= number_format((int) $confirmed['total_wage']) ?>円</p>
            <p class="confirmed-meta">
                確定日時: <?= htmlspecialchars($confirmed['confirmed_at'], ENT_QUOTES, 'UTF-8') ?> /
                確定時の実働: <?= htmlspecialchars(format_minutes_as_hours((int) $confirmed['total_work_minutes']), ENT_QUOTES, 'UTF-8') ?> /
                確定時の平日時給: <?= number_format((int) $confirmed['hourly_wage_weekday']) ?>円 /
                確定時の土日祝時給: <?= number_format((int) $confirmed['hourly_wage_holiday']) ?>円
            </p>
            <p class="confirmed-meta">
                確定時の平日勤務: <?= htmlspecialchars(format_minutes_as_hours((int) $confirmed['weekday_regular_minutes']), ENT_QUOTES, 'UTF-8') ?>（<?= number_format((int) $confirmed['weekday_wage']) ?>円） /
                確定時の平日残業: <?= htmlspecialchars(format_minutes_as_hours((int) $confirmed['weekday_overtime_minutes']), ENT_QUOTES, 'UTF-8') ?>（<?= number_format((int) $confirmed['weekday_overtime_wage']) ?>円） /
                確定時の休日勤務: <?= htmlspecialchars(format_minutes_as_hours((int) $confirmed['holiday_regular_minutes']), ENT_QUOTES, 'UTF-8') ?>（<?= number_format((int) $confirmed['holiday_wage']) ?>円） /
                確定時の休日残業: <?= htmlspecialchars(format_minutes_as_hours((int) $confirmed['holiday_overtime_minutes']), ENT_QUOTES, 'UTF-8') ?>（<?= number_format((int) $confirmed['holiday_overtime_wage']) ?>円） /
                確定時の深夜労働: <?= htmlspecialchars(format_minutes_as_hours((int) $confirmed['night_minutes']), ENT_QUOTES, 'UTF-8') ?>（<?= number_format((int) $confirmed['night_wage']) ?>円）
            </p>

            <?php if ((int) $confirmed['total_work_minutes'] !== $summary['total_minutes']
                || (int) $confirmed['night_minutes'] !== $summary['night_minutes']
                || (int) $confirmed['hourly_wage_weekday'] !== (int) $selectedEmployee['hourly_wage_weekday']
                || (int) $confirmed['hourly_wage_holiday'] !== (int) $selectedEmployee['hourly_wage_holiday']): ?>
                <p class="notice">確定後にシフト・打刻・時給の変更があり、現在の実績（暫定<?= number_format($provisionalWage) ?>円）と確定済みの金額が一致していません。必要であれば再確定してください。</p>
            <?php endif; ?>

            <form method="post" action="/admin/wages.php" onsubmit="return confirm('確定済みの金額を現在の実績で上書きします。よろしいですか？');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="reconfirm">
                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                <input type="hidden" name="year_month" value="<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit">再確定する</button>
            </form>
        <?php else: ?>
            <p class="amount provisional">暫定支給額: <?= number_format($provisionalWage) ?>円（未確定）</p>

            <form method="post" action="/admin/wages.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="confirm">
                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                <input type="hidden" name="year_month" value="<?= htmlspecialchars($yearMonth, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit">この内容で確定する</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
</body>
</html>
