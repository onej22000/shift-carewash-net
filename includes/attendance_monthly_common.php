<?php
/**
 * 月間打刻実績（admin/attendance_monthly.php・staff/attendance_monthly.php）で共有する
 * 対象月の決定・予定（shifts）/実績（attendance）の取得・実働時間計算・日セルの描画。
 * 認証（管理者/従業員のセッション区別）はここでは扱わず、呼び出し元が
 * require_login('admin'|'staff') 済みであることを前提とする。
 * 従業員画面から呼ぶ場合は、$employeeId に必ずセッション上の本人IDを渡すこと
 * （nullを渡すと全従業員分を返すため、管理者画面以外から null を渡してはならない）。
 */

/**
 * ?month=YYYY-MM から対象月を決め、月初・月末・前月・次月などをまとめて返す。
 * 不正な値の場合は当月にフォールバックする。
 */
function resolve_attendance_month(?string $rawMonth): array
{
    $yearMonth = (string) ($rawMonth ?? (new DateTime())->format('Y-m'));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
        $yearMonth = (new DateTime())->format('Y-m');
    }

    $monthStart = DateTime::createFromFormat('Y-m-d', $yearMonth . '-01');
    $monthStart->setTime(0, 0, 0);
    $monthEnd = (clone $monthStart)->modify('last day of this month');

    $dates = [];
    $cursor = clone $monthStart;
    while ($cursor <= $monthEnd) {
        $dates[] = clone $cursor;
        $cursor->modify('+1 day');
    }

    return [
        'year_month' => $yearMonth,
        'month_start' => $monthStart,
        'month_end' => $monthEnd,
        'start_str' => $monthStart->format('Y-m-d'),
        'end_str' => $monthEnd->format('Y-m-d'),
        'prev_month' => (clone $monthStart)->modify('-1 month')->format('Y-m'),
        'next_month' => (clone $monthStart)->modify('+1 month')->format('Y-m'),
        'dates' => $dates,
    ];
}

/**
 * 出勤〜退勤の拘束時間から休憩合計を引いた実働分数を返す（退勤未打刻ならnull）。
 * attendance.work_minutes に保存する値の計算式で、管理者画面の新規追加・修正はこれを使う。
 */
function calc_attendance_work_minutes(string $clockInAt, ?string $clockOutAt, ?int $totalBreakMinutes): ?int
{
    if ($clockOutAt === null) {
        return null;
    }

    $ci = new DateTime($clockInAt);
    $co = new DateTime($clockOutAt);
    $rawMinutes = max(0, (int) round(($co->getTimestamp() - $ci->getTimestamp()) / 60));

    return max(0, $rawMinutes - ($totalBreakMinutes ?? 0));
}

/**
 * 対象期間のシフト（予定）を [employee_id][work_date][] の形で返す。
 * $employeeId を指定した場合はその従業員の分だけをSQL側で絞り込む。
 */
function fetch_monthly_shifts_by_employee_date(PDO $pdo, string $startDate, string $endDate, ?int $employeeId = null): array
{
    $sql = 'SELECT employee_id, work_date, start_time, end_time, categories
            FROM shifts
            WHERE work_date BETWEEN :start AND :end';
    $params = [':start' => $startDate, ':end' => $endDate];
    if ($employeeId !== null) {
        $sql .= ' AND employee_id = :employee_id';
        $params[':employee_id'] = $employeeId;
    }
    $sql .= ' ORDER BY work_date, start_time';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $shiftsByEmployeeDate = [];
    foreach ($stmt->fetchAll() as $row) {
        $shiftsByEmployeeDate[(int) $row['employee_id']][$row['work_date']][] = $row;
    }

    return $shiftsByEmployeeDate;
}

/**
 * 対象期間の打刻（実績、論理削除済みを除く）を [employee_id][出勤日][] の形で返し、
 * 退勤済み（status=done）レコードの実働・休憩の合計分数も併せて返す。
 * $employeeId を指定した場合はその従業員の分だけをSQL側で絞り込む。
 *
 * @return array{0: array, 1: int, 2: int} [打刻一覧, 合計実働分, 合計休憩分]
 */
function fetch_monthly_attendance_by_employee_date(PDO $pdo, string $startDate, string $endDate, ?int $employeeId = null): array
{
    $sql = 'SELECT id, employee_id, clock_in_at, clock_out_at, work_minutes, total_break_minutes, status
            FROM attendance
            WHERE DATE(clock_in_at) BETWEEN :start AND :end
              AND deleted_at IS NULL';
    $params = [':start' => $startDate, ':end' => $endDate];
    if ($employeeId !== null) {
        $sql .= ' AND employee_id = :employee_id';
        $params[':employee_id'] = $employeeId;
    }
    $sql .= ' ORDER BY clock_in_at';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $attendanceByEmployeeDate = [];
    $totalWorkMinutes = 0;
    $totalBreakMinutes = 0;
    foreach ($stmt->fetchAll() as $row) {
        $workDate = substr($row['clock_in_at'], 0, 10);
        $attendanceByEmployeeDate[(int) $row['employee_id']][$workDate][] = $row;
        if ($row['status'] === 'done' && $row['work_minutes'] !== null) {
            $totalWorkMinutes += (int) $row['work_minutes'];
            $totalBreakMinutes += (int) ($row['total_break_minutes'] ?? 0);
        }
    }

    return [$attendanceByEmployeeDate, $totalWorkMinutes, $totalBreakMinutes];
}

/**
 * 1日分の 予定/実績/休憩/実働 を描画する。
 * $editBaseUrl を渡すと実績をクリックで修正画面へ遷移させる（管理者画面用）。
 * null の場合は閲覧専用で、リンク・onclickを一切出力しない（従業員画面用）。
 */
function render_attendance_day_cell(array $shiftsForDay, array $attendanceForDay, ?string $editBaseUrl = null): void
{
    foreach ($shiftsForDay as $shift) {
        $categories = categories_from_value($shift['categories']);
        ?>
        <div class="plan-entry">
            <span class="entry-label">予定</span>
            <?= htmlspecialchars(substr($shift['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>〜<?= htmlspecialchars(substr($shift['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
            <?php foreach ($categories as $category): ?>
                <span class="category-badge" style="background:<?= htmlspecialchars(CATEGORY_COLORS[$category] ?? CATEGORY_COLOR_NONE, ENT_QUOTES, 'UTF-8') ?>;"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
        </div>
        <?php
    }

    if (!empty($shiftsForDay) && empty($attendanceForDay)) {
        ?>
        <div class="missing-punch">実績: 未打刻</div>
        <?php
    }

    foreach ($attendanceForDay as $record) {
        $inTime = substr($record['clock_in_at'], 11, 5);
        $outTime = $record['clock_out_at'] !== null ? substr($record['clock_out_at'], 11, 5) : null;
        ?>
        <?php if ($editBaseUrl !== null): ?>
        <div class="actual-entry" onclick="event.stopPropagation(); location.href='<?= htmlspecialchars($editBaseUrl . '&edit=' . (int) $record['id'], ENT_QUOTES, 'UTF-8') ?>';">
        <?php else: ?>
        <div class="actual-entry">
        <?php endif; ?>
            <span class="entry-label">実績</span>
            <?= htmlspecialchars($inTime, ENT_QUOTES, 'UTF-8') ?>〜<?= $outTime !== null ? htmlspecialchars($outTime, ENT_QUOTES, 'UTF-8') : '' ?>
            <?php if ($outTime === null): ?>
                <span class="working-badge">勤務中</span>
            <?php endif; ?>
            <?php if ($record['work_minutes'] !== null): ?>
                <div class="entry-sub">
                    休憩<?= $record['total_break_minutes'] !== null ? (int) $record['total_break_minutes'] : 0 ?>分 /
                    実働<?= htmlspecialchars(format_minutes_as_hours((int) $record['work_minutes']), ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
