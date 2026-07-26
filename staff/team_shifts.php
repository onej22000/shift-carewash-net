<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

$flash = pop_flash();

// ---- 表示範囲（カレンダー / 週 / 半月 / 月）の決定 ----
$view = (string) ($_GET['view'] ?? 'calendar');
if (!in_array($view, ['calendar', 'week', 'half', 'month'], true)) {
    $view = 'calendar';
}

$anchorDate = DateTime::createFromFormat('Y-m-d', (string) ($_GET['date'] ?? ''));
if ($anchorDate === false) {
    $anchorDate = new DateTime('today');
}
$anchorDate->setTime(0, 0, 0);
$anchorDateStr = $anchorDate->format('Y-m-d');
$todayStr = (new DateTime('today'))->format('Y-m-d');

$viewRange = resolve_shift_view_range($view, $anchorDate);
$rangeStart = $viewRange['rangeStart'];
$rangeEnd = $viewRange['rangeEnd'];
$prevDate = $viewRange['prevDate'];
$nextDate = $viewRange['nextDate'];
$viewLabel = $viewRange['viewLabel'];
$monthStart = $viewRange['monthStart'];

$rangeStartStr = $rangeStart->format('Y-m-d');
$rangeEndStr = $rangeEnd->format('Y-m-d');

// ---- カレンダー表示専用：選択日の決定 ----
$selectedDateStr = '';
if ($view === 'calendar') {
    $selectedDateStr = resolve_calendar_selected_date((string) ($_GET['selected'] ?? ''), $rangeStartStr, $rangeEndStr, $todayStr);
}

$pageUrl = '/staff/team_shifts.php?view=' . $view . '&date=' . $anchorDateStr;
if ($view === 'calendar') {
    $pageUrl .= '&selected=' . $selectedDateStr;
}

// ---- 従業員一覧（氏名・状態のみ。時給等は一切取得しない） ----
$employeesStmt = $pdo->query(
    "SELECT id, name, status FROM employees WHERE role = 'staff' AND status IN ('active','invited') ORDER BY name"
);
$employees = $employeesStmt->fetchAll();

// ---- グリッド表示用データの取得 ----
$dates = [];
$cursor = clone $rangeStart;
while ($cursor <= $rangeEnd) {
    $dates[] = clone $cursor;
    $cursor->modify('+1 day');
}

$shiftsByEmployeeDate = [];
if (!empty($employees)) {
    $stmt = $pdo->prepare(
        'SELECT s.id, s.employee_id, s.work_date, s.start_time, s.end_time, s.break_minutes, s.note, s.categories
         FROM shifts s
         WHERE s.work_date BETWEEN :start AND :end
         ORDER BY s.work_date, s.start_time'
    );
    $stmt->execute([
        ':start' => $rangeStart->format('Y-m-d'),
        ':end' => $rangeEnd->format('Y-m-d'),
    ]);
    foreach ($stmt->fetchAll() as $row) {
        $shiftsByEmployeeDate[(int) $row['employee_id']][$row['work_date']][] = $row;
    }
}

$holidayDates = fetch_holiday_dates($pdo, $rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d'));
$facilityOnboardingLabels = fetch_facility_onboarding_labels($pdo, $rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d'));
$weekdayLabels = ['月', '火', '水', '木', '金', '土', '日'];

// ---- カレンダー表示専用：シフトが1件でもある日付の集合・氏名一覧・週ごとの行組み替え（共通関数、追加クエリなし） ----
$datesWithShift = build_dates_with_shift($shiftsByEmployeeDate);
$employeeNamesByDate = $view === 'calendar' ? build_calendar_day_employee_names($employees, $shiftsByEmployeeDate) : [];
$calendarWeeks = $view === 'calendar' ? build_calendar_weeks($monthStart, $dates) : [];

/**
 * 受託開始日ラベル（例：「アルク枚方長尾開始」）を日付マスの上部にタグとして描画する。
 * その日が受託開始日の施設が複数あれば、それぞれ1タグずつ表示する。
 *
 * @param list<string> $facilityNames
 */
function render_onboarding_tags(array $facilityNames): void
{
    foreach ($facilityNames as $facilityName) {
        ?>
        <div class="onboarding-tag"><?= htmlspecialchars($facilityName, ENT_QUOTES, 'UTF-8') ?>開始</div>
        <?php
    }
}

function render_readonly_shift_block(array $shift, ?string $editUrl = null): void
{
    $workMinutes = calc_work_minutes($shift['start_time'], $shift['end_time'], (int) $shift['break_minutes']);
    $categories = categories_from_value($shift['categories']);
    $stripeStyle = category_stripe_style($categories);
    ?>
    <div class="shift-entry">
        <div class="category-stripe" style="<?= htmlspecialchars($stripeStyle, ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="shift-entry-body">
            <div class="shift-time">
                <?= htmlspecialchars(substr($shift['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>〜<?= htmlspecialchars(substr($shift['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php if (!empty($categories)): ?>
                <div class="category-badges">
                    <?php foreach ($categories as $category): ?>
                        <span class="category-badge" style="background:<?= htmlspecialchars(CATEGORY_COLORS[$category] ?? CATEGORY_COLOR_NONE, ENT_QUOTES, 'UTF-8') ?>;"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="shift-sub">実働<?= htmlspecialchars(format_minutes_as_hours($workMinutes), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if (!empty($shift['note'])): ?>
                <div class="shift-note"><?= htmlspecialchars($shift['note'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($editUrl !== null): ?>
                <div class="shift-edit-link"><a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>">編集</a></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * カレンダー表示の「選択日の全員シフト一覧」パネルを1日分描画する。
 * 既存の$shiftsByEmployeeDateとrender_readonly_shift_block()を再利用し、新規データ取得は行わない。
 * 権限はマトリクス表（従来の週/半月/月表示）と同一：自分の今日以降のシフトのみ編集・追加可、他人・過去日は閲覧のみ。
 */
function render_readonly_day_detail(
    string $dateStr,
    array $employees,
    array $shiftsByEmployeeDate,
    int $staffId,
    string $todayStr,
    string $pageUrl,
    bool $isSelected
): void {
    $dateObj = new DateTime($dateStr);
    $weekdayLabels = ['月', '火', '水', '木', '金', '土', '日'];
    $weekdayIndex = (int) $dateObj->format('N');
    $isFutureOrToday = $dateStr >= $todayStr;
    $backParam = '&back=' . urlencode($pageUrl);
    ?>
    <div class="day-detail" id="day-detail-<?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?>" style="display:<?= $isSelected ? 'block' : 'none' ?>;">
        <h3><?= $dateObj->format('n月j日') ?>（<?= $weekdayLabels[$weekdayIndex - 1] ?>）の全員シフト</h3>
        <?php
        $anyRow = false;
        foreach ($employees as $employee):
            $employeeId = (int) $employee['id'];
            $isOwnRow = $employeeId === $staffId;
            $showAddLink = $isOwnRow && $isFutureOrToday;
            $shiftsForDate = $shiftsByEmployeeDate[$employeeId][$dateStr] ?? [];
            if (empty($shiftsForDate) && !$showAddLink) {
                continue;
            }
            $anyRow = true;
            ?>
            <div class="day-detail-employee">
                <div class="employee-name"><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?><?= $employee['status'] === 'invited' ? '（招待中）' : '' ?></div>
                <?php foreach ($shiftsForDate as $shift): ?>
                    <?php
                    $isStoreShift = categories_include_store(categories_from_value($shift['categories']));
                    $editUrl = ($isOwnRow && $isFutureOrToday && !$isStoreShift)
                        ? '/staff/shift_edit.php?id=' . (int) $shift['id'] . $backParam
                        : null;
                    render_readonly_shift_block($shift, $editUrl);
                    ?>
                <?php endforeach; ?>
                <?php if ($showAddLink): ?>
                    <div class="add-shift-link">
                        <a href="/staff/shift_edit.php?date=<?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?><?= $backParam ?>">＋シフト追加</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$anyRow): ?>
            <p class="notice">この日のシフトはありません。</p>
        <?php endif; ?>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>全員のシフト表 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .calendar-nav { margin-bottom: 8px; }
        .calendar-nav a { margin-right: 8px; }
        .pdf-export-form { margin: 10px 0; padding: 8px 12px; background: #f8fafc; border: 1px solid #ddd; border-radius: 6px; display: flex; align-items: center; gap: 8px; font-size: 0.9em; }
        .pdf-export-form label { font-weight: bold; }
        .grid-scroll { overflow-x: auto; max-width: 100%; border: 1px solid #ccc; }
        table.shift-grid { border-collapse: collapse; width: max-content; min-width: 100%; }
        table.shift-grid th, table.shift-grid td { border: 1px solid #ccc; vertical-align: top; padding: 4px; }
        table.shift-grid th { background: #f5f5f5; }
        table.shift-grid th.date-col, table.shift-grid td.date-col { min-width: 110px; }
        table.shift-grid th.employee-col, table.shift-grid td.employee-col {
            position: sticky; left: 0; background: #fff; z-index: 2; min-width: 130px; text-align: left; font-weight: bold;
        }
        table.shift-grid th.employee-col { background: #f5f5f5; z-index: 3; }
        table.shift-grid th.today, table.shift-grid td.today { background: #eef6ff; }
        table.shift-grid th.sat { color: #0b5ed7; }
        table.shift-grid th.sun-holiday { color: #d9362e; }
        table.shift-grid td.date-cell { height: 70px; }
        .shift-entry { display: flex; align-items: stretch; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 4px; font-size: 0.85em; background: #fff; overflow: hidden; }
        .category-stripe { width: 6px; flex-shrink: 0; }
        .shift-entry-body { padding: 4px; flex: 1; min-width: 0; }
        .shift-sub { font-size: 0.9em; color: #555; }
        .category-badges { margin-top: 2px; }
        .category-badge { display: inline-block; font-size: 0.75em; color: #fff; border-radius: 3px; padding: 1px 5px; margin-right: 3px; margin-bottom: 2px; }
        .category-swatch { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 4px; }
        .category-legend { margin: 8px 0; font-size: 0.85em; color: #555; }
        .category-legend .legend-item { display: inline-flex; align-items: center; margin-right: 16px; }
        .shift-edit-link { margin-top: 2px; font-size: 0.9em; }
        .add-shift-link { margin-top: 4px; font-size: 0.85em; }

        /* ---- カレンダー表示 ---- */
        table.calendar-month { border-collapse: collapse; width: 100%; max-width: 480px; table-layout: fixed; }
        table.calendar-month th, table.calendar-month td { border: 1px solid #ddd; text-align: center; padding: 0; }
        table.calendar-month th { background: #f5f5f5; padding: 6px 0; font-weight: normal; font-size: 0.85em; }
        table.calendar-month th.cal-sat { color: #0b5ed7; }
        table.calendar-month th.cal-sun { color: #d9362e; }
        table.calendar-month td.cal-pad { background: #fafafa; }
        table.calendar-month td.cal-day { min-height: 52px; height: auto; vertical-align: top; cursor: pointer; position: relative; padding-bottom: 4px; }
        table.calendar-month td.cal-day:hover { background: #f8fbff; }
        table.calendar-month td.cal-day .cal-day-number { display: block; padding: 4px 6px 2px; font-size: 0.9em; }
        table.calendar-month td.cal-today { background: #eef6ff; }
        table.calendar-month td.cal-selected { background: #0b5ed7; }
        table.calendar-month td.cal-selected .cal-day-number { color: #fff; font-weight: bold; }
        table.calendar-month td.cal-day .cal-day-names { padding: 0 3px; }
        table.calendar-month td.cal-day .cal-day-name-row {
            display: flex; align-items: center; gap: 2px; text-align: left;
            font-size: 0.62em; line-height: 1.3; white-space: normal; word-break: break-all; margin-bottom: 1px;
        }
        table.calendar-month td.cal-day .cal-day-name-dot { display: inline-block; flex-shrink: 0; width: 5px; height: 5px; border-radius: 50%; }
        table.calendar-month td.cal-selected .cal-day-name-row { color: #fff; }
        .day-detail { margin-top: 16px; border-top: 1px solid #ccc; padding-top: 12px; }
        .day-detail h3 { font-size: 1.05em; margin: 0 0 8px; }
        .day-detail-employee { margin-bottom: 10px; }
        .day-detail-employee .employee-name { margin-bottom: 4px; }
        .onboarding-tag {
            display: block; background: #ff8f00; color: #fff; font-size: 0.65em; font-weight: bold;
            padding: 1px 4px; border-radius: 3px; margin: 2px 2px 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
    </style>
</head>
<body>
<header>
    <h1>全員のシフト表</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<p class="notice">自分の今日以降のシフトのみ編集・削除・追加ができます。他の従業員のシフトや過去日付は閲覧のみです。</p>

<div class="calendar-nav">
    <a href="?view=<?= $view ?>&date=<?= $prevDate ?>">← 前<?= $viewLabel ?></a>
    <a href="?view=<?= $view ?>&date=<?= $todayStr ?>">今日</a>
    <a href="?view=<?= $view ?>&date=<?= $nextDate ?>">次<?= $viewLabel ?> →</a>
    |
    <a href="?view=calendar&date=<?= $anchorDateStr ?>"<?= $view === 'calendar' ? ' style="font-weight:bold;"' : '' ?>>カレンダー表示</a>
    <a href="?view=week&date=<?= $anchorDateStr ?>"<?= $view === 'week' ? ' style="font-weight:bold;"' : '' ?>>週表示</a>
    <a href="?view=half&date=<?= $anchorDateStr ?>"<?= $view === 'half' ? ' style="font-weight:bold;"' : '' ?>>半月表示</a>
    <a href="?view=month&date=<?= $anchorDateStr ?>"<?= $view === 'month' ? ' style="font-weight:bold;"' : '' ?>>月表示</a>
</div>

<form action="/staff/shifts_pdf.php" method="GET" target="_blank" class="pdf-export-form">
    <label for="pdf-month">月間シフト表PDF出力：</label>
    <input type="month" id="pdf-month" name="month" value="<?= htmlspecialchars($monthStart->format('Y-m'), ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit">PDF出力</button>
</form>

<div class="category-legend">
    <?php foreach (SHIFT_CATEGORIES as $category): ?>
        <span class="legend-item">
            <span class="category-swatch" style="background:<?= htmlspecialchars(CATEGORY_COLORS[$category] ?? CATEGORY_COLOR_NONE, ENT_QUOTES, 'UTF-8') ?>;"></span>
            <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
        </span>
    <?php endforeach; ?>
</div>

<?php if (empty($employees)): ?>
    <p class="notice">従業員が登録されていません。</p>
<?php elseif ($view === 'calendar'): ?>
    <table class="calendar-month">
        <thead>
            <tr>
                <?php foreach (['日', '月', '火', '水', '木', '金', '土'] as $i => $label): ?>
                    <th class="<?= $i === 0 ? 'cal-sun' : ($i === 6 ? 'cal-sat' : '') ?>"><?= $label ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($calendarWeeks as $week): ?>
                <tr>
                    <?php foreach ($week as $d): ?>
                        <?php if ($d === null): ?>
                            <td class="cal-pad"></td>
                        <?php else: ?>
                            <?php
                            $dateStr = $d->format('Y-m-d');
                            $classes = ['cal-day'];
                            if ($dateStr === $todayStr) {
                                $classes[] = 'cal-today';
                            }
                            if ($dateStr === $selectedDateStr) {
                                $classes[] = 'cal-selected';
                            }
                            if (isset($datesWithShift[$dateStr])) {
                                $classes[] = 'cal-has-shift';
                            }
                            ?>
                            <td class="<?= implode(' ', $classes) ?>" data-date="<?= $dateStr ?>" onclick="selectDate('<?= $dateStr ?>')">
                                <?php render_onboarding_tags($facilityOnboardingLabels[$dateStr] ?? []); ?>
                                <span class="cal-day-number"><?= $d->format('j') ?></span>
                                <div class="cal-day-names">
                                    <?php foreach ($employeeNamesByDate[$dateStr] ?? [] as $entry): ?>
                                        <div class="cal-day-name-row">
                                            <?php if (!empty($entry['categories'])): ?>
                                                <?php foreach ($entry['categories'] as $category): ?>
                                                    <span class="cal-day-name-dot" style="background:<?= htmlspecialchars(CATEGORY_COLORS[$category] ?? CATEGORY_COLOR_NONE, ENT_QUOTES, 'UTF-8') ?>;"></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="cal-day-name-dot" style="background:<?= htmlspecialchars(CATEGORY_COLOR_NONE, ENT_QUOTES, 'UTF-8') ?>;"></span>
                                            <?php endif; ?>
                                            <span><?= htmlspecialchars($entry['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php foreach ($dates as $d): ?>
        <?php
        $dateStr = $d->format('Y-m-d');
        render_readonly_day_detail($dateStr, $employees, $shiftsByEmployeeDate, (int) $staff['id'], $todayStr, $pageUrl, $dateStr === $selectedDateStr);
        ?>
    <?php endforeach; ?>
<?php else: ?>
    <div class="grid-scroll">
        <table class="shift-grid">
            <thead>
                <tr>
                    <th class="employee-col">従業員</th>
                    <?php foreach ($dates as $d): ?>
                        <?php
                        $dateStr = $d->format('Y-m-d');
                        $weekdayIndex = (int) $d->format('N');
                        $classes = ['date-col'];
                        if ($dateStr === $todayStr) {
                            $classes[] = 'today';
                        } elseif ($weekdayIndex === 6) {
                            $classes[] = 'sat';
                        } elseif ($weekdayIndex === 7 || is_holiday_in_set($dateStr, $holidayDates)) {
                            $classes[] = 'sun-holiday';
                        }
                        ?>
                        <th class="<?= implode(' ', $classes) ?>">
                            <?= $d->format('n/j') ?>（<?= $weekdayLabels[$weekdayIndex - 1] ?>）
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $employee): ?>
                    <?php $employeeId = (int) $employee['id']; ?>
                    <tr>
                        <td class="employee-col">
                            <?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?><?= $employee['status'] === 'invited' ? '（招待中）' : '' ?>
                        </td>
                        <?php foreach ($dates as $d): ?>
                            <?php
                            $dateStr = $d->format('Y-m-d');
                            $isOwnCell = $employeeId === (int) $staff['id'];
                            $isFutureOrToday = $dateStr >= $todayStr;
                            $backParam = '&back=' . urlencode($pageUrl);
                            ?>
                            <td class="date-cell<?= $dateStr === $todayStr ? ' today' : '' ?>">
                                <?php render_onboarding_tags($facilityOnboardingLabels[$dateStr] ?? []); ?>
                                <?php foreach ($shiftsByEmployeeDate[$employeeId][$dateStr] ?? [] as $shift): ?>
                                    <?php
                                    $isStoreShift = categories_include_store(categories_from_value($shift['categories']));
                                    $editUrl = ($isOwnCell && $isFutureOrToday && !$isStoreShift)
                                        ? '/staff/shift_edit.php?id=' . (int) $shift['id'] . $backParam
                                        : null;
                                    render_readonly_shift_block($shift, $editUrl);
                                    ?>
                                <?php endforeach; ?>
                                <?php if ($isOwnCell && $isFutureOrToday): ?>
                                    <div class="add-shift-link">
                                        <a href="/staff/shift_edit.php?date=<?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?><?= $backParam ?>">＋シフト追加</a>
                                    </div>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
function selectDate(dateStr) {
    document.querySelectorAll('.cal-day.cal-selected').forEach(function (el) {
        el.classList.remove('cal-selected');
    });
    var cell = document.querySelector('.cal-day[data-date="' + dateStr + '"]');
    if (cell) {
        cell.classList.add('cal-selected');
    }
    document.querySelectorAll('.day-detail').forEach(function (el) {
        el.style.display = 'none';
    });
    var detail = document.getElementById('day-detail-' + dateStr);
    if (detail) {
        detail.style.display = 'block';
    }
}
</script>
</body>
</html>
