<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();
$csrfToken = csrf_token();

function sanitize_categories(array $rawCategories): array
{
    return array_values(array_intersect(SHIFT_CATEGORIES, $rawCategories));
}

/**
 * 保存後のリダイレクト先を、入力/更新された勤務日を基準に組み立てる。
 * $pageUrl（ページ読み込み時点のGETパラメータ由来）をそのまま使うと、
 * 表示中のカレンダーと異なる月日でシフトを保存した際に元の表示へ戻ってしまうため、
 * 保存対象の日付をそのままdate（カレンダー表示ならselectedも）に使う。
 */
function build_shift_redirect_url(string $view, string $dateStr): string
{
    $url = '/admin/shifts.php?view=' . $view . '&date=' . $dateStr;
    if ($view === 'calendar') {
        $url .= '&selected=' . $dateStr;
    }
    return $url;
}

/**
 * 時・分プルダウン（分は00/30のみ）から送信された値を"HH:MM"へ結合する。
 * 不正な値（未選択・分が00/30以外など）は空文字を返し、以降のvalidate_shift_input()の
 * 形式チェックで弾かれる。
 */
function combine_time_parts(string $hour, string $minute): string
{
    if (!preg_match('/^([01]\d|2[0-3])$/', $hour) || !in_array($minute, ['00', '30'], true)) {
        return '';
    }
    return $hour . ':' . $minute;
}

/**
 * calc_shift_wage_summary()等が返すcategory_wage（区分=>金額）を「店舗:1,000円 洗濯代行:500円 集荷:0円」
 * のような小さいテキストに整形する。category_wageが空（区分データ無し）の場合は空文字を返す。
 */
function render_category_wage_breakdown_html(array $categoryWage): string
{
    if (empty($categoryWage)) {
        return '';
    }
    $parts = [];
    foreach (SHIFT_CATEGORIES as $category) {
        $parts[] = htmlspecialchars($category, ENT_QUOTES, 'UTF-8') . ':' . number_format($categoryWage[$category] ?? 0) . '円';
    }
    $parts[] = '合計:' . number_format(array_sum($categoryWage)) . '円';
    return '<span class="category-wage-breakdown">（' . implode('　', $parts) . '）</span>';
}

function validate_shift_input(int $employeeId, string $workDate, string $startTime, string $endTime, array $validEmployeeIds): ?string
{
    if (!in_array($employeeId, $validEmployeeIds, true)) {
        return '選択された従業員が見つかりません。';
    }
    if (DateTime::createFromFormat('Y-m-d', $workDate) === false) {
        return '勤務日の形式が正しくありません。';
    }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $startTime)) {
        return '開始時刻の形式が正しくありません。';
    }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $endTime)) {
        return '終了時刻の形式が正しくありません。';
    }
    if (!preg_match('/:(00|30)$/', $startTime) || !preg_match('/:(00|30)$/', $endTime)) {
        return '開始時刻・終了時刻は00分または30分で入力してください。';
    }

    return null;
}

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

$pageUrl = '/admin/shifts.php?view=' . $view . '&date=' . $anchorDateStr;
if ($view === 'calendar') {
    $pageUrl .= '&selected=' . $selectedDateStr;
}

// ---- 従業員一覧（シフトを割り当てられるstaffのみ。adminアカウントは除外） ----
$employeesStmt = $pdo->query(
    "SELECT id, name, status, hourly_wage_weekday, hourly_wage_holiday
     FROM employees WHERE role = 'staff' AND status IN ('active','invited') ORDER BY name"
);
$employees = $employeesStmt->fetchAll();
$validEmployeeIds = array_map('intval', array_column($employees, 'id'));

$errorMessage = '';

// ---- POST処理（新規作成・更新・削除） ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // シフトコピー＆貼り付け機能（JS側からのfetch）用。ajax=1の場合はリダイレクトの代わりにJSONで応答する。
    $isAjax = ($_POST['ajax'] ?? '') === '1';

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => '不正なリクエストです。再度お試しください。']);
            exit;
        }
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'delete') {
            $shiftId = (int) ($_POST['shift_id'] ?? 0);

            $prevStmt = $pdo->prepare('SELECT employee_id, work_date FROM shifts WHERE id = :id');
            $prevStmt->execute([':id' => $shiftId]);
            $prevShift = $prevStmt->fetch();

            $stmt = $pdo->prepare('DELETE FROM shifts WHERE id = :id');
            $stmt->execute([':id' => $shiftId]);

            if ($prevShift !== false) {
                recalculate_daily_breaks($pdo, (int) $prevShift['employee_id'], $prevShift['work_date']);
            }

            set_flash('success', 'シフトを削除しました。');
            $redirectUrl = $prevShift !== false ? build_shift_redirect_url($view, $prevShift['work_date']) : $pageUrl;
            header('Location: ' . $redirectUrl);
            exit;
        }

        if ($action === 'create' || $action === 'update') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $workDate = (string) ($_POST['work_date'] ?? '');
            $startTime = combine_time_parts((string) ($_POST['start_time_hour'] ?? ''), (string) ($_POST['start_time_minute'] ?? ''));
            $endTime = combine_time_parts((string) ($_POST['end_time_hour'] ?? ''), (string) ($_POST['end_time_minute'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));
            $note = $note === '' ? null : $note;
            $categories = sanitize_categories((array) ($_POST['categories'] ?? []));
            $categoriesValue = implode(',', $categories);

            $validationError = validate_shift_input($employeeId, $workDate, $startTime, $endTime, $validEmployeeIds);

            if ($validationError !== null) {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => $validationError]);
                    exit;
                }
                $errorMessage = $validationError;
            } elseif ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO shifts (employee_id, work_date, start_time, end_time, note, categories, created_by)
                     VALUES (:employee_id, :work_date, :start_time, :end_time, :note, :categories, :created_by)'
                );
                $stmt->execute([
                    ':employee_id' => $employeeId,
                    ':work_date' => $workDate,
                    ':start_time' => $startTime,
                    ':end_time' => $endTime,
                    ':note' => $note,
                    ':categories' => $categoriesValue,
                    ':created_by' => $admin['id'],
                ]);

                recalculate_daily_breaks($pdo, $employeeId, $workDate);

                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => true,
                    ] + build_paste_ajax_response($pdo, $employeeId, $workDate, $anchorDate, $csrfToken, $pageUrl));
                    exit;
                }

                set_flash('success', 'シフトを登録しました。');
                header('Location: ' . build_shift_redirect_url($view, $workDate));
                exit;
            } else {
                $shiftId = (int) ($_POST['shift_id'] ?? 0);

                $prevStmt = $pdo->prepare('SELECT employee_id, work_date FROM shifts WHERE id = :id');
                $prevStmt->execute([':id' => $shiftId]);
                $prevShift = $prevStmt->fetch();

                $stmt = $pdo->prepare(
                    'UPDATE shifts
                     SET employee_id = :employee_id, work_date = :work_date, start_time = :start_time,
                         end_time = :end_time, note = :note, categories = :categories
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':employee_id' => $employeeId,
                    ':work_date' => $workDate,
                    ':start_time' => $startTime,
                    ':end_time' => $endTime,
                    ':note' => $note,
                    ':categories' => $categoriesValue,
                    ':id' => $shiftId,
                ]);

                recalculate_daily_breaks($pdo, $employeeId, $workDate);
                if ($prevShift !== false && ((int) $prevShift['employee_id'] !== $employeeId || $prevShift['work_date'] !== $workDate)) {
                    recalculate_daily_breaks($pdo, (int) $prevShift['employee_id'], $prevShift['work_date']);
                }

                set_flash('success', 'シフトを更新しました。');
                header('Location: ' . build_shift_redirect_url($view, $workDate));
                exit;
            }
        }
    }
}

$flash = pop_flash();

// ---- 編集対象の読み込み ----
$editingShift = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM shifts WHERE id = :id');
    $stmt->execute([':id' => $editId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $editingShift = $row;
    }
}

// ---- フォームの初期値決定（バリデーションエラー時の再表示 > 編集 > 新規） ----
$formAction = 'create';
$formShiftId = null;
$formEmployeeId = '';
$formWorkDate = $anchorDateStr;
$formStartTime = '';
$formEndTime = '';
$formNote = '';
$formCategories = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
    $formAction = (string) ($_POST['action'] ?? 'create');
    $formShiftId = $formAction === 'update' ? (int) ($_POST['shift_id'] ?? 0) : null;
    $formEmployeeId = (string) ($_POST['employee_id'] ?? '');
    $formWorkDate = (string) ($_POST['work_date'] ?? '');
    $formStartTime = combine_time_parts((string) ($_POST['start_time_hour'] ?? ''), (string) ($_POST['start_time_minute'] ?? ''));
    $formEndTime = combine_time_parts((string) ($_POST['end_time_hour'] ?? ''), (string) ($_POST['end_time_minute'] ?? ''));
    $formNote = (string) ($_POST['note'] ?? '');
    $formCategories = sanitize_categories((array) ($_POST['categories'] ?? []));
} elseif ($editingShift !== null) {
    $formAction = 'update';
    $formShiftId = (int) $editingShift['id'];
    $formEmployeeId = (string) $editingShift['employee_id'];
    $formWorkDate = $editingShift['work_date'];
    $formStartTime = substr($editingShift['start_time'], 0, 5);
    $formEndTime = substr($editingShift['end_time'], 0, 5);
    $formNote = (string) ($editingShift['note'] ?? '');
    $formCategories = categories_from_value($editingShift['categories']);
}

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

// ---- 当月累計シフト時間・見込み賃金（表示中のビューに関わらず「当月」全体を対象に集計） ----
$currentMonthStart = (clone $anchorDate)->modify('first day of this month')->format('Y-m-d');
$currentMonthEnd = (clone $anchorDate)->modify('last day of this month')->format('Y-m-d');
$currentMonthHolidayDates = fetch_holiday_dates($pdo, $currentMonthStart, $currentMonthEnd);

$monthlyShiftsByEmployeeDate = [];
if (!empty($employees)) {
    $monthlyStmt = $pdo->prepare(
        'SELECT employee_id, work_date, start_time, end_time, break_minutes, categories
         FROM shifts
         WHERE work_date BETWEEN :start AND :end'
    );
    $monthlyStmt->execute([':start' => $currentMonthStart, ':end' => $currentMonthEnd]);
    foreach ($monthlyStmt->fetchAll() as $row) {
        $monthlyShiftsByEmployeeDate[(int) $row['employee_id']][$row['work_date']][] = $row;
    }
}

/**
 * シフトのコピー＆貼り付け（AJAX作成）用のレスポンスを組み立てる。
 * 貼り付け先セルの最新シフト一覧（render_shift_blockと同じHTML）と、
 * 当月（$anchorDateの月）の見込み集計を返す。休憩時間の自動再計算は
 * recalculate_daily_breaks()側で完了している前提。
 *
 * @return array{cell_html:string, month_summary_html:string}
 */
function build_paste_ajax_response(PDO $pdo, int $employeeId, string $workDate, DateTime $anchorDate, string $csrfToken, string $pageUrl): array
{
    $cellStmt = $pdo->prepare(
        'SELECT id, employee_id, work_date, start_time, end_time, break_minutes, note, categories
         FROM shifts
         WHERE employee_id = :employee_id AND work_date = :work_date
         ORDER BY start_time, id'
    );
    $cellStmt->execute([':employee_id' => $employeeId, ':work_date' => $workDate]);

    ob_start();
    foreach ($cellStmt->fetchAll() as $shift) {
        render_shift_block($shift, $csrfToken, $pageUrl);
    }
    $cellHtml = ob_get_clean();

    $employeeStmt = $pdo->prepare(
        'SELECT id, hourly_wage_weekday, hourly_wage_holiday FROM employees WHERE id = :id'
    );
    $employeeStmt->execute([':id' => $employeeId]);
    $employeeRow = $employeeStmt->fetch();

    $monthStartStr = (clone $anchorDate)->modify('first day of this month')->format('Y-m-d');
    $monthEndStr = (clone $anchorDate)->modify('last day of this month')->format('Y-m-d');
    $monthHolidayDates = fetch_holiday_dates($pdo, $monthStartStr, $monthEndStr);

    $monthlyStmt = $pdo->prepare(
        'SELECT work_date, start_time, end_time, break_minutes, categories
         FROM shifts WHERE employee_id = :employee_id AND work_date BETWEEN :start AND :end'
    );
    $monthlyStmt->execute([':employee_id' => $employeeId, ':start' => $monthStartStr, ':end' => $monthEndStr]);
    $shiftsByDate = [];
    foreach ($monthlyStmt->fetchAll() as $row) {
        $shiftsByDate[$row['work_date']][] = $row;
    }

    $summary = calc_shift_wage_summary($shiftsByDate, $employeeRow, $monthHolidayDates);
    $monthSummaryHtml = '当月予定 ' . htmlspecialchars(format_minutes_as_hours($summary['total_minutes']), ENT_QUOTES, 'UTF-8') . '<br>'
        . '見込み' . number_format($summary['total_wage']) . '円'
        . render_category_wage_breakdown_html($summary['category_wage']);

    return [
        'cell_html' => $cellHtml,
        'month_summary_html' => $monthSummaryHtml,
    ];
}

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

function render_shift_block(array $shift, string $csrfToken, string $pageUrl): void
{
    $workMinutes = calc_work_minutes($shift['start_time'], $shift['end_time'], (int) $shift['break_minutes']);
    $editUrl = $pageUrl . '&edit=' . (int) $shift['id'];
    $categories = categories_from_value($shift['categories']);
    $stripeStyle = category_stripe_style($categories);
    ?>
    <div class="shift-entry"
         data-employee-id="<?= (int) $shift['employee_id'] ?>"
         data-work-date="<?= htmlspecialchars($shift['work_date'], ENT_QUOTES, 'UTF-8') ?>"
         data-edit-url="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>"
         data-shift-start="<?= htmlspecialchars(substr($shift['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>"
         data-shift-end="<?= htmlspecialchars(substr($shift['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>"
         data-shift-categories="<?= htmlspecialchars(implode(',', $categories), ENT_QUOTES, 'UTF-8') ?>"
         data-shift-note="<?= htmlspecialchars($shift['note'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
         onclick="handleShiftEntryClick(event, this);">
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
            <div class="shift-sub">休憩<?= (int) $shift['break_minutes'] ?>分 / 実働<?= htmlspecialchars(format_minutes_as_hours($workMinutes), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if (!empty($shift['note'])): ?>
                <div class="shift-note"><?= htmlspecialchars($shift['note'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="shift-actions">
                <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" onclick="event.stopPropagation();">編集</a>
                <button type="button" class="link-button copy-link" onclick="event.stopPropagation(); startCopyShift(this);">コピー</button>
                <form method="post" action="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>" class="inline-form" onclick="event.stopPropagation();" onsubmit="return confirm('このシフトを削除しますか？');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="shift_id" value="<?= (int) $shift['id'] ?>">
                    <button type="submit" class="link-button">削除</button>
                </form>
            </div>
        </div>
    </div>
    <?php
}

/**
 * カレンダー表示の「選択日の全員シフト一覧」パネルを1日分描画する。
 * $shiftsByEmployeeDateと render_shift_block() を再利用し、新規データ取得は行わない。
 */
function render_day_detail(
    string $dateStr,
    array $employees,
    array $shiftsByEmployeeDate,
    string $csrfToken,
    string $pageUrl,
    bool $isSelected
): void {
    $dateObj = new DateTime($dateStr);
    $weekdayLabels = ['月', '火', '水', '木', '金', '土', '日'];
    $weekdayIndex = (int) $dateObj->format('N');
    ?>
    <div class="day-detail" id="day-detail-<?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?>" style="display:<?= $isSelected ? 'block' : 'none' ?>;">
        <h3><?= $dateObj->format('n月j日') ?>（<?= $weekdayLabels[$weekdayIndex - 1] ?>）の全員シフト</h3>
        <?php
        $anyShift = false;
        foreach ($employees as $employee):
            $employeeId = (int) $employee['id'];
            $shiftsForDate = $shiftsByEmployeeDate[$employeeId][$dateStr] ?? [];
            if (empty($shiftsForDate)) {
                continue;
            }
            $anyShift = true;
            ?>
            <div class="day-detail-employee">
                <div class="employee-name"><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?><?= $employee['status'] === 'invited' ? '（招待中）' : '' ?></div>
                <?php foreach ($shiftsForDate as $shift): ?>
                    <?php render_shift_block($shift, $csrfToken, $pageUrl); ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$anyShift): ?>
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
    <title>シフト表作成 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; }
        .form-row { margin-bottom: 8px; }
        .form-row label { display: inline-block; width: 100px; }
        .estimate { font-weight: bold; color: #0b5ed7; }
        .calendar-nav { margin-bottom: 8px; }
        .calendar-nav a { margin-right: 8px; }
        .grid-scroll-top { overflow-x: auto; overflow-y: hidden; max-width: 100%; height: 16px; }
        .grid-scroll-top-inner { height: 1px; }
        .grid-scroll { overflow-x: auto; max-width: 100%; border: 1px solid #ccc; }
        table.shift-grid { border-collapse: collapse; width: max-content; min-width: 100%; }
        table.shift-grid th, table.shift-grid td { border: 1px solid #ccc; vertical-align: top; padding: 4px; }
        table.shift-grid th { background: #f5f5f5; }
        table.shift-grid th.date-col, table.shift-grid td.date-col { min-width: 110px; }
        table.shift-grid th.employee-col, table.shift-grid td.employee-col {
            position: sticky; left: 0; background: #fff; z-index: 2; min-width: 180px; text-align: left; font-weight: bold;
        }
        table.shift-grid th.employee-col { background: #f5f5f5; z-index: 3; }
        .employee-name { font-weight: bold; }
        .employee-month-summary { font-size: 0.75em; font-weight: normal; color: #0b5ed7; margin-top: 2px; line-height: 1.4; }
        .category-wage-breakdown { display: block; color: #666; font-weight: normal; }
        table.shift-grid th.today, table.shift-grid td.today { background: #eef6ff; }
        table.shift-grid th.sat { color: #0b5ed7; }
        table.shift-grid th.sun-holiday { color: #d9362e; }
        table.shift-grid td.date-cell { cursor: pointer; height: 70px; }
        table.shift-grid td.date-cell:hover { background: #f8fbff; }
        .shift-entry { display: flex; align-items: stretch; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 4px; font-size: 0.85em; background: #fff; cursor: pointer; overflow: hidden; }
        .category-stripe { width: 6px; flex-shrink: 0; }
        .shift-entry-body { padding: 4px; flex: 1; min-width: 0; }
        .shift-sub { font-size: 0.9em; color: #555; }
        .shift-actions a, .link-button { font-size: 0.85em; color: #0b5ed7; background: none; border: none; padding: 0; cursor: pointer; text-decoration: underline; margin-right: 8px; }
        .shift-actions .copy-link { color: #1e7e34; }
        .paste-banner {
            position: sticky; top: 0; z-index: 10; background: #0b5ed7; color: #fff;
            padding: 8px 12px; border-radius: 4px; margin-bottom: 8px; display: none;
            align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
        }
        .paste-banner.active { display: flex; }
        .paste-banner button { background: #fff; color: #0b5ed7; border: none; border-radius: 4px; padding: 4px 10px; cursor: pointer; }
        body.paste-mode table.shift-grid td.date-cell { cursor: copy; }
        body.paste-mode table.shift-grid td.date-cell:hover { background: #eafbe6; }
        .category-badges { margin-top: 2px; }
        .category-badge { display: inline-block; font-size: 0.75em; color: #fff; border-radius: 3px; padding: 1px 5px; margin-right: 3px; margin-bottom: 2px; }
        .form-row label.category-checkbox { display: inline-flex; align-items: center; font-weight: normal; width: auto; margin-right: 12px; }
        .category-swatch { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 4px; }
        .category-legend { margin: 8px 0; font-size: 0.85em; color: #555; }
        .category-legend .legend-item { display: inline-flex; align-items: center; margin-right: 16px; }
        .inline-form { display: inline; }
        .field-note { font-size: 0.85em; color: #555; margin: 4px 0 0 100px; }

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
        .pdf-export-form { margin: 10px 0; padding: 8px 12px; background: #f8fafc; border: 1px solid #ddd; border-radius: 6px; display: flex; align-items: center; gap: 8px; font-size: 0.9em; }
        .pdf-export-form label { font-weight: bold; }
    </style>
</head>
<body>
<header>
    <h1>シフト表作成</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="shift-form" id="shift-form-section">
    <h2><?= $formAction === 'update' ? 'シフト編集' : '新規シフト登録' ?></h2>

    <?php if (empty($employees)): ?>
        <p class="notice">従業員が登録されていません。従業員を登録すると、ここでシフトを割り当てられるようになります。</p>
    <?php endif; ?>

    <fieldset>
        <form method="post" action="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= $formAction === 'update' ? 'update' : 'create' ?>">
            <?php if ($formAction === 'update'): ?>
                <input type="hidden" name="shift_id" value="<?= (int) $formShiftId ?>">
            <?php endif; ?>

            <div class="form-row">
                <label for="employee_id">従業員</label>
                <select id="employee_id" name="employee_id" required <?= empty($employees) ? 'disabled' : '' ?>>
                    <option value="">選択してください</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>" <?= (string) $employee['id'] === $formEmployeeId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?><?= $employee['status'] === 'invited' ? '（招待中）' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="work_date">勤務日</label>
                <input type="date" id="work_date" name="work_date" value="<?= htmlspecialchars($formWorkDate, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <?php
            $formStartHour = $formStartTime !== '' ? substr($formStartTime, 0, 2) : '';
            $formStartMinute = $formStartTime !== '' ? substr($formStartTime, 3, 2) : '';
            $formEndHour = $formEndTime !== '' ? substr($formEndTime, 0, 2) : '';
            $formEndMinute = $formEndTime !== '' ? substr($formEndTime, 3, 2) : '';
            ?>
            <div class="form-row">
                <label for="start_time_hour">開始時刻</label>
                <select id="start_time_hour" name="start_time_hour" required onchange="updateEstimate()">
                    <option value="">--</option>
                    <?php for ($h = 0; $h < 24; $h++): $hh = sprintf('%02d', $h); ?>
                        <option value="<?= $hh ?>" <?= $hh === $formStartHour ? 'selected' : '' ?>><?= $hh ?></option>
                    <?php endfor; ?>
                </select>
                :
                <select id="start_time_minute" name="start_time_minute" required onchange="updateEstimate()">
                    <option value="">--</option>
                    <?php foreach (['00', '30'] as $mm): ?>
                        <option value="<?= $mm ?>" <?= $mm === $formStartMinute ? 'selected' : '' ?>><?= $mm ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="end_time_hour">終了時刻</label>
                <select id="end_time_hour" name="end_time_hour" required onchange="updateEstimate()">
                    <option value="">--</option>
                    <?php for ($h = 0; $h < 24; $h++): $hh = sprintf('%02d', $h); ?>
                        <option value="<?= $hh ?>" <?= $hh === $formEndHour ? 'selected' : '' ?>><?= $hh ?></option>
                    <?php endfor; ?>
                </select>
                :
                <select id="end_time_minute" name="end_time_minute" required onchange="updateEstimate()">
                    <option value="">--</option>
                    <?php foreach (['00', '30'] as $mm): ?>
                        <option value="<?= $mm ?>" <?= $mm === $formEndMinute ? 'selected' : '' ?>><?= $mm ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label>休憩時間</label>
                <span>労働基準法に基づき、その日の合計勤務時間から自動計算されます。</span>
            </div>

            <div class="form-row">
                <label>この枠の目安</label>
                <span id="estimate" class="estimate">-</span>
                <p class="field-note">保存後、同日の他シフトと合算して休憩・実働が確定します。</p>
            </div>

            <div class="form-row">
                <label>業務種別</label>
                <?php foreach (SHIFT_CATEGORIES as $category): ?>
                    <label class="category-checkbox">
                        <input type="checkbox" name="categories[]" value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= in_array($category, $formCategories, true) ? 'checked' : '' ?>>
                        <span class="category-swatch" style="background:<?= htmlspecialchars(CATEGORY_COLORS[$category] ?? CATEGORY_COLOR_NONE, ENT_QUOTES, 'UTF-8') ?>;"></span>
                        <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="form-row">
                <label for="note">備考</label>
                <input type="text" id="note" name="note" value="<?= htmlspecialchars($formNote, ENT_QUOTES, 'UTF-8') ?>" maxlength="255">
            </div>

            <button type="submit" <?= empty($employees) ? 'disabled' : '' ?>><?= $formAction === 'update' ? '更新する' : '登録する' ?></button>
            <?php if ($formAction === 'update'): ?>
                <a href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
            <?php endif; ?>
        </form>
    </fieldset>
</section>

<section class="calendar-section" id="calendar-section">
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

    <form action="/admin/shifts_pdf.php" method="GET" target="_blank" class="pdf-export-form">
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

    <div id="paste-banner" class="paste-banner">
        <span id="paste-banner-text"></span>
        <button type="button" onclick="cancelCopyShift()">コピー解除</button>
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
            render_day_detail($dateStr, $employees, $shiftsByEmployeeDate, $csrfToken, $pageUrl, $dateStr === $selectedDateStr);
            ?>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="grid-scroll-top" id="grid-scroll-top"><div class="grid-scroll-top-inner" id="grid-scroll-top-inner"></div></div>
        <div class="grid-scroll" id="grid-scroll">
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
                            <th class="<?= implode(' ', $classes) ?>" data-date="<?= $dateStr ?>">
                                <?= $d->format('n/j') ?>（<?= $weekdayLabels[$weekdayIndex - 1] ?>）
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $employee): ?>
                        <?php
                        $employeeId = (int) $employee['id'];
                        $monthSummary = calc_shift_wage_summary(
                            $monthlyShiftsByEmployeeDate[$employeeId] ?? [],
                            $employee,
                            $currentMonthHolidayDates
                        );
                        ?>
                        <tr>
                            <td class="employee-col">
                                <div class="employee-name"><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?><?= $employee['status'] === 'invited' ? '（招待中）' : '' ?></div>
                                <div class="employee-month-summary" id="month-summary-<?= $employeeId ?>">
                                    当月予定 <?= htmlspecialchars(format_minutes_as_hours($monthSummary['total_minutes']), ENT_QUOTES, 'UTF-8') ?><br>
                                    見込み<?= number_format($monthSummary['total_wage']) ?>円<?= render_category_wage_breakdown_html($monthSummary['category_wage']) ?>
                                </div>
                            </td>
                            <?php foreach ($dates as $d): ?>
                                <?php $dateStr = $d->format('Y-m-d'); ?>
                                <td class="date-cell<?= $dateStr === $todayStr ? ' today' : '' ?>" onclick="selectCell(<?= $employeeId ?>, '<?= $dateStr ?>', this)">
                                    <?php render_onboarding_tags($facilityOnboardingLabels[$dateStr] ?? []); ?>
                                    <?php foreach ($shiftsByEmployeeDate[$employeeId][$dateStr] ?? [] as $shift): ?>
                                        <?php render_shift_block($shift, $csrfToken, $pageUrl); ?>
                                    <?php endforeach; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script>
var PAGE_URL = <?= json_encode($pageUrl, JSON_UNESCAPED_SLASHES) ?>;
var CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
var copiedShift = null;

function selectCell(employeeId, dateStr, cellEl) {
    if (copiedShift) {
        pasteShift(employeeId, dateStr, cellEl);
        return;
    }
    document.getElementById('employee_id').value = employeeId;
    document.getElementById('work_date').value = dateStr;
    document.getElementById('shift-form-section').scrollIntoView({ behavior: 'smooth' });
}

function handleShiftEntryClick(event, entryEl) {
    event.stopPropagation();

    // 貼り付けモード中は、既にシフトが入っているセルをクリックした場合も
    // （クリックがこのシフトブロック自身に当たり編集ページへ飛んでしまわないよう）
    // 貼り付け処理に回す。そうしないと「上書き」対象セルには絶対に貼り付けできなくなる。
    if (copiedShift) {
        var cellEl = entryEl.closest('td.date-cell');
        var employeeId = parseInt(entryEl.dataset.employeeId, 10);
        var dateStr = entryEl.dataset.workDate;
        pasteShift(employeeId, dateStr, cellEl);
        return;
    }

    location.href = entryEl.dataset.editUrl;
}

function startCopyShift(button) {
    var entry = button.closest('.shift-entry');
    copiedShift = {
        employeeId: parseInt(entry.dataset.employeeId, 10),
        start: entry.dataset.shiftStart,
        end: entry.dataset.shiftEnd,
        categories: entry.dataset.shiftCategories ? entry.dataset.shiftCategories.split(',').filter(Boolean) : [],
        note: entry.dataset.shiftNote || ''
    };

    document.body.classList.add('paste-mode');
    var banner = document.getElementById('paste-banner');
    var text = document.getElementById('paste-banner-text');
    text.textContent = 'コピー中: ' + copiedShift.start + '〜' + copiedShift.end
        + (copiedShift.categories.length ? '（' + copiedShift.categories.join('・') + '）' : '')
        + ' ｜ 同じ従業員の貼り付け先セルをクリックしてください（続けて複数セルに貼り付け可能）';
    banner.classList.add('active');
}

function cancelCopyShift() {
    copiedShift = null;
    document.body.classList.remove('paste-mode');
    document.getElementById('paste-banner').classList.remove('active');
}

function pasteShift(employeeId, dateStr, cellEl) {
    if (!copiedShift) {
        return;
    }
    if (employeeId !== copiedShift.employeeId) {
        alert('コピー元と同じ従業員の別の日付にのみ貼り付けできます。');
        return;
    }
    if (cellEl && cellEl.querySelector('.shift-entry')) {
        if (!confirm('この日にはすでにシフトが登録されています。上書き（追加登録）しますか？')) {
            return;
        }
    }

    var formData = new URLSearchParams();
    formData.set('csrf_token', CSRF_TOKEN);
    formData.set('action', 'create');
    formData.set('ajax', '1');
    formData.set('employee_id', String(employeeId));
    formData.set('work_date', dateStr);
    formData.set('start_time', copiedShift.start);
    formData.set('end_time', copiedShift.end);
    formData.set('note', copiedShift.note);
    copiedShift.categories.forEach(function (category) {
        formData.append('categories[]', category);
    });

    fetch(PAGE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.success) {
                alert(data.error || '貼り付けに失敗しました。');
                return;
            }
            if (cellEl) {
                cellEl.innerHTML = data.cell_html;
            }
            var summaryEl = document.getElementById('month-summary-' + employeeId);
            if (summaryEl) {
                summaryEl.innerHTML = data.month_summary_html;
            }
        })
        .catch(function () {
            alert('貼り付けに失敗しました。通信エラーが発生しました。');
        });
}

(function () {
    var topBar = document.getElementById('grid-scroll-top');
    var topInner = document.getElementById('grid-scroll-top-inner');
    var bottomScroll = document.getElementById('grid-scroll');
    if (!topBar || !topInner || !bottomScroll) {
        return;
    }
    var table = bottomScroll.querySelector('table.shift-grid');
    if (!table) {
        return;
    }

    function syncWidth() {
        topInner.style.width = table.scrollWidth + 'px';
    }
    syncWidth();
    window.addEventListener('resize', syncWidth);

    var syncingFromTop = false;
    var syncingFromBottom = false;

    topBar.addEventListener('scroll', function () {
        if (syncingFromBottom) {
            syncingFromBottom = false;
            return;
        }
        syncingFromTop = true;
        bottomScroll.scrollLeft = topBar.scrollLeft;
    });
    bottomScroll.addEventListener('scroll', function () {
        if (syncingFromTop) {
            syncingFromTop = false;
            return;
        }
        syncingFromBottom = true;
        topBar.scrollLeft = bottomScroll.scrollLeft;
    });
})();

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

function updateEstimate() {
    var startHour = document.getElementById('start_time_hour').value;
    var startMinute = document.getElementById('start_time_minute').value;
    var endHour = document.getElementById('end_time_hour').value;
    var endMinute = document.getElementById('end_time_minute').value;
    var out = document.getElementById('estimate');

    if (!startHour || !startMinute || !endHour || !endMinute) {
        out.textContent = '-';
        return;
    }

    var sMin = parseInt(startHour, 10) * 60 + parseInt(startMinute, 10);
    var eMin = parseInt(endHour, 10) * 60 + parseInt(endMinute, 10);
    var diff = eMin - sMin;
    if (diff < 0) {
        diff += 24 * 60;
    }
    var h = Math.floor(diff / 60);
    var m = diff % 60;
    out.textContent = h + '時間' + (m < 10 ? '0' + m : m) + '分（休憩調整前）';
}
updateEstimate();

<?php if ($flash !== null): ?>
// 登録・更新・削除の直後（PRGリダイレクト後）は、保存対象の日付が
// カレンダー/グリッドのどの位置にあるか分かるよう、その日付までスクロールする。
// ページ自体はブラウザの既定動作で先頭にスクロールされるため、何もしないと
// 「シフト表セクションまでスクロールしないと保存結果が見えない＝先頭に戻ったように見える」
// という体感になっていた。
(function () {
    var params = new URLSearchParams(location.search);
    var focusDate = params.get(<?= json_encode($view === 'calendar' ? 'selected' : 'date') ?>);
    var calendarSection = document.getElementById('calendar-section');
    if (calendarSection) {
        calendarSection.scrollIntoView({ block: 'start' });
    }
    if (!focusDate) {
        return;
    }
    var grid = document.getElementById('grid-scroll');
    if (grid) {
        var col = grid.querySelector('th[data-date="' + focusDate + '"]');
        if (col) {
            col.scrollIntoView({ inline: 'center', block: 'nearest' });
        }
    }
})();
<?php endif; ?>
</script>
</body>
</html>
