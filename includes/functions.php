<?php

const BOARD_TYPES = [
    'all' => '全員用連絡掲示板',
    'driver' => '集荷ドライバー連絡掲示板',
    'laundry' => '洗濯スタッフ連絡掲示板',
];

// consumable_stock_transactions.item_type → facilities側の交付累計カラム名。
// linen_bag_blueはfacilitiesに対応カラムが無いため意図的に含めない。
const ITEM_TYPE_TO_FACILITY_ISSUED_COLUMN = [
    'linen_bag_orange' => 'issued_linen_bag_orange',
    'linen_bag_yellow' => 'issued_linen_bag_yellow',
    'linen_bag_blue' => 'issued_linen_bag_blue',
    'laundry_net' => 'issued_laundry_net_count',
];

const SHIFT_CATEGORIES = ['店舗', '洗濯代行', '集荷']; // 優先順位順（業務種別按分で使用）
const CATEGORY_COLORS = [
    '店舗' => '#0b5ed7',
    '洗濯代行' => '#1e7e34',
    '集荷' => '#c9720f',
];
const CATEGORY_COLOR_NONE = '#ccc';

const FACILITY_PICKUP_SCHEDULES = ['月・木', '火・金', '水・土'];

const REGULAR_WORK_MINUTES_PER_DAY = 8 * 60;
const OVERTIME_WAGE_MULTIPLIER = 1.25;
const NIGHT_START_HOUR = 22;
const NIGHT_END_HOUR = 5;
const NIGHT_WAGE_PREMIUM_MULTIPLIER = 0.25;

const CALENDAR_BASE_URL = 'https://shift.carewash.net';
const CALENDAR_TOKEN_BYTES = 32; // hex化で64文字

/**
 * 従業員がまだカレンダー購読トークンを持っていなければ発行する（初回アクセス時等に使用）。
 * 既に持っていればそれをそのまま返す。
 */
function ensure_calendar_token(PDO $pdo, int $employeeId): string
{
    $stmt = $pdo->prepare('SELECT calendar_token FROM employees WHERE id = :id');
    $stmt->execute([':id' => $employeeId]);
    $existing = $stmt->fetchColumn();

    if (is_string($existing) && $existing !== '') {
        return $existing;
    }

    return regenerate_calendar_token($pdo, $employeeId);
}

/**
 * 新しいトークンを発行して保存し、旧トークンを失効させる（URL漏えい時の対処用）。
 * トークンにUNIQUE制約があるため、衝突時（天文学的に低確率）は数回まで再生成して再試行する。
 */
function regenerate_calendar_token(PDO $pdo, int $employeeId): string
{
    $updateStmt = $pdo->prepare('UPDATE employees SET calendar_token = :token WHERE id = :id');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $token = bin2hex(random_bytes(CALENDAR_TOKEN_BYTES));
        try {
            $updateStmt->execute([':token' => $token, ':id' => $employeeId]);
            return $token;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // UNIQUE制約違反（衝突）。次のループで別トークンを生成して再試行。
        }
    }

    throw new RuntimeException('カレンダートークンの発行に失敗しました。');
}

function calendar_ics_url(string $token): string
{
    return CALENDAR_BASE_URL . '/calendar.ics.php?token=' . $token;
}

function categories_from_value(string $categoriesValue): array
{
    return $categoriesValue === '' ? [] : explode(',', $categoriesValue);
}

/**
 * 区分配列（categories_from_value()の戻り値）に「店舗」が含まれるかどうかを判定する。
 * find_month_end_correction_candidates()（店舗区分限定の出勤時刻自動補正）で既に使っている
 * 「店舗が含まれていれば店舗扱い」というメンバーシップ判定（複合区分でも店舗が1つでもあれば
 * 店舗扱い）を、従業員によるシフト編集・削除・新規作成の制限にも同じ基準で適用するための共通化。
 */
function categories_include_store(array $categories): bool
{
    return in_array('店舗', $categories, true);
}

function resolve_shift_category(array $categories): ?string
{
    foreach (SHIFT_CATEGORIES as $category) {
        if (in_array($category, $categories, true)) {
            return $category;
        }
    }

    return null;
}

function category_stripe_style(array $categories): string
{
    if (empty($categories)) {
        return 'background:' . CATEGORY_COLOR_NONE . ';';
    }

    $colors = array_map(static fn (string $c): string => CATEGORY_COLORS[$c] ?? CATEGORY_COLOR_NONE, $categories);

    if (count($colors) === 1) {
        return 'background:' . $colors[0] . ';';
    }

    $segment = 100 / count($colors);
    $stops = [];
    foreach ($colors as $i => $color) {
        $stops[] = $color . ' ' . ($i * $segment) . '% ' . (($i + 1) * $segment) . '%';
    }

    return 'background: linear-gradient(to bottom, ' . implode(', ', $stops) . ');';
}

function normalize_time_to_minutes(string $time): ?int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches)) {
        return null;
    }

    return ((int) $matches[1]) * 60 + (int) $matches[2];
}

function calc_work_minutes(string $startTime, string $endTime, int $breakMinutes): int
{
    $start = normalize_time_to_minutes($startTime);
    $end = normalize_time_to_minutes($endTime);

    if ($start === null || $end === null) {
        return 0;
    }

    $diffMinutes = $end - $start;
    if ($diffMinutes < 0) {
        $diffMinutes += 24 * 60;
    }

    return max(0, $diffMinutes - $breakMinutes);
}

/**
 * [clock_in_at, clock_out_at) の生の時間帯と、22:00〜翌5:00の深夜帯との重複分数を計算する。
 * 深夜帯は日ごとに [その日22:00, 翌日5:00) として扱う（日をまたいでも二重計上されない）。
 */
function calc_night_overlap_minutes(DateTime $clockInAt, DateTime $clockOutAt): int
{
    if ($clockOutAt <= $clockInAt) {
        return 0;
    }

    $overlapMinutes = 0;
    $cursor = (clone $clockInAt)->modify('-1 day')->setTime(0, 0);
    $lastDay = (clone $clockOutAt)->setTime(0, 0);

    while ($cursor <= $lastDay) {
        $nightStart = (clone $cursor)->setTime(NIGHT_START_HOUR, 0);
        $nightEnd = (clone $cursor)->modify('+1 day')->setTime(NIGHT_END_HOUR, 0);

        $overlapStart = max($clockInAt, $nightStart);
        $overlapEnd = min($clockOutAt, $nightEnd);

        if ($overlapEnd > $overlapStart) {
            $overlapMinutes += (int) round(($overlapEnd->getTimestamp() - $overlapStart->getTimestamp()) / 60);
        }

        $cursor->modify('+1 day');
    }

    return $overlapMinutes;
}

/**
 * 打刻記録1件分の深夜労働時間（実働分、休憩控除後）を按分計算する。
 * 休憩の具体的な時間帯までは記録されていないため、打刻区間全体に対する深夜帯の重複比率を
 * 実働時間（休憩控除後）に乗じて近似する（業務種別の按分計算と同じ考え方）。
 */
function calc_record_night_work_minutes(string $clockInAt, string $clockOutAt, int $workMinutes): int
{
    if ($workMinutes <= 0) {
        return 0;
    }

    $start = new DateTime($clockInAt);
    $end = new DateTime($clockOutAt);
    $rawSpanMinutes = ($end->getTimestamp() - $start->getTimestamp()) / 60;

    if ($rawSpanMinutes <= 0) {
        return 0;
    }

    $nightRawMinutes = calc_night_overlap_minutes($start, $end);

    return (int) round($workMinutes * $nightRawMinutes / $rawSpanMinutes);
}

function format_minutes_as_hours(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    return sprintf('%d時間%02d分', $hours, $mins);
}

function calc_legal_break_minutes(int $totalRawMinutes): int
{
    if ($totalRawMinutes > 8 * 60) {
        return 60;
    }
    if ($totalRawMinutes > 6 * 60) {
        return 45;
    }

    return 0;
}

/**
 * 月次チェックで法定休憩へ補正する店舗打刻を返す。
 * 同一従業員・同一日の「店舗」打刻だけを通算し、不足がある場合だけ店舗打刻へ割り当てる。
 * 「集荷」「洗濯代行」は勤務時間・休憩時間とも判定対象に含めず、本人の入力値をそのまま採用する。
 *
 * @return list<array{attendance_id:int,employee_id:int,employee_name:string,work_date:string,old_break_minutes:int,new_break_minutes:int,raw_minutes:int}>
 */
function find_month_end_break_correction_candidates(PDO $pdo, string $yearMonth): array
{
    [$startDate, $endDate] = get_month_range($yearMonth);
    $stmt = $pdo->prepare(
        "SELECT a.id, a.employee_id, e.name AS employee_name, a.category,
                a.clock_in_at, a.clock_out_at, a.total_break_minutes
           FROM attendance a
           JOIN employees e ON e.id = a.employee_id
          WHERE a.deleted_at IS NULL
            AND a.clock_out_at IS NOT NULL
            AND a.category = '店舗'
            AND DATE(a.clock_in_at) BETWEEN :start_date AND :end_date
          ORDER BY a.employee_id, a.clock_in_at"
    );
    $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);

    $days = [];
    foreach ($stmt->fetchAll() as $row) {
        $date = substr((string) $row['clock_in_at'], 0, 10);
        $key = (int) $row['employee_id'] . ':' . $date;
        $clockIn = new DateTime((string) $row['clock_in_at']);
        $clockOut = new DateTime((string) $row['clock_out_at']);
        $rawMinutes = max(0, (int) round(($clockOut->getTimestamp() - $clockIn->getTimestamp()) / 60));
        $row['raw_minutes'] = $rawMinutes;
        $days[$key]['employee_id'] = (int) $row['employee_id'];
        $days[$key]['employee_name'] = (string) $row['employee_name'];
        $days[$key]['work_date'] = $date;
        $days[$key]['rows'][] = $row;
    }

    $candidates = [];
    foreach ($days as $day) {
        $totalRaw = 0;
        $totalBreak = 0;
        foreach ($day['rows'] as $row) {
            $totalRaw += (int) $row['raw_minutes'];
            $totalBreak += (int) ($row['total_break_minutes'] ?? 0);
        }

        $deficit = calc_legal_break_minutes($totalRaw) - $totalBreak;
        if ($deficit <= 0) {
            continue;
        }

        // 終業に近い店舗打刻から不足分を割り当てる。
        foreach (array_reverse($day['rows']) as $row) {
            if ($deficit <= 0) {
                break;
            }
            $oldBreak = (int) ($row['total_break_minutes'] ?? 0);
            $capacity = max(0, (int) $row['raw_minutes'] - $oldBreak);
            $addition = min($deficit, $capacity);
            if ($addition <= 0) {
                continue;
            }
            $candidates[] = [
                'attendance_id' => (int) $row['id'],
                'employee_id' => (int) $day['employee_id'],
                'employee_name' => (string) $day['employee_name'],
                'work_date' => (string) $day['work_date'],
                'old_break_minutes' => $oldBreak,
                'new_break_minutes' => $oldBreak + $addition,
                'raw_minutes' => (int) $row['raw_minutes'],
            ];
            $deficit -= $addition;
        }
    }

    return $candidates;
}

function get_month_range(string $yearMonth): array
{
    $start = DateTime::createFromFormat('Y-m-d', $yearMonth . '-01');
    $end = (clone $start)->modify('last day of this month');

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function is_holiday_date(PDO $pdo, string $date): bool
{
    $dayOfWeek = (int) (new DateTime($date))->format('N'); // 6=土, 7=日
    if ($dayOfWeek >= 6) {
        return true;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM holidays WHERE `date` = :date');
    $stmt->execute([':date' => $date]);

    return $stmt->fetchColumn() !== false;
}

function hourly_wage_for_date(PDO $pdo, array $employee, string $date): int
{
    return is_holiday_date($pdo, $date)
        ? (int) $employee['hourly_wage_holiday']
        : (int) $employee['hourly_wage_weekday'];
}

function fetch_holiday_dates(PDO $pdo, string $startDate, string $endDate): array
{
    $stmt = $pdo->prepare('SELECT `date` FROM holidays WHERE `date` BETWEEN :start AND :end');
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);

    return array_fill_keys(array_column($stmt->fetchAll(), 'date'), true);
}

/**
 * シフト表（admin/shifts.php・staff/team_shifts.php、カレンダー/週/半月/月表示すべて）で、
 * 「受託開始日」当日のマスに施設名のラベルを出すために使う。無効化済み施設は対象外。
 *
 * @return array<string, list<string>> 日付(Y-m-d) => その日が受託開始日の施設名一覧
 */
function fetch_facility_onboarding_labels(PDO $pdo, string $startDate, string $endDate): array
{
    $stmt = $pdo->prepare(
        'SELECT name, onboarding_start_date
         FROM facilities
         WHERE is_active = 1 AND onboarding_start_date BETWEEN :start AND :end'
    );
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);

    $labelsByDate = [];
    foreach ($stmt->fetchAll() as $row) {
        $labelsByDate[$row['onboarding_start_date']][] = $row['name'];
    }

    return $labelsByDate;
}

function is_holiday_in_set(string $date, array $holidayDates): bool
{
    $dayOfWeek = (int) (new DateTime($date))->format('N'); // 6=土, 7=日
    return $dayOfWeek >= 6 || isset($holidayDates[$date]);
}

function hourly_wage_for_date_in_set(array $employee, string $date, array $holidayDates): int
{
    return is_holiday_in_set($date, $holidayDates)
        ? (int) $employee['hourly_wage_holiday']
        : (int) $employee['hourly_wage_weekday'];
}

/**
 * $shiftsByDateの各シフト行に'categories'キー（shifts.categoriesの値）が含まれていれば、
 * 区分別内訳（category_wage、SHIFT_CATEGORIES区分ごとの金額、合計は必ずtotal_wageと一致）も返す。
 * 含まれていない場合はcategory_wageは空配列になる。
 */
function calc_shift_wage_summary(array $shiftsByDate, array $employee, array $holidayDates): array
{
    $totalMinutes = 0;
    $totalWage = 0;
    $categoryWageAccum = array_fill_keys(SHIFT_CATEGORIES, 0.0);
    $hasCategoryData = false;

    foreach ($shiftsByDate as $date => $shiftsForDate) {
        $dayMinutes = 0;
        $dayCategoryMinutes = array_fill_keys(SHIFT_CATEGORIES, 0);
        foreach ($shiftsForDate as $shift) {
            $shiftMinutes = calc_work_minutes($shift['start_time'], $shift['end_time'], (int) $shift['break_minutes']);
            $dayMinutes += $shiftMinutes;
            if (array_key_exists('categories', $shift)) {
                $hasCategoryData = true;
                $category = resolve_shift_category(categories_from_value($shift['categories']));
                if ($category !== null) {
                    $dayCategoryMinutes[$category] += $shiftMinutes;
                }
            }
        }
        $rate = hourly_wage_for_date_in_set($employee, $date, $holidayDates);
        $dayWage = (int) round($rate * $dayMinutes / 60);
        $totalMinutes += $dayMinutes;
        $totalWage += $dayWage;

        if ($dayMinutes > 0) {
            foreach (SHIFT_CATEGORIES as $category) {
                $categoryWageAccum[$category] += $dayWage * $dayCategoryMinutes[$category] / $dayMinutes;
            }
        }
    }

    return [
        'total_minutes' => $totalMinutes,
        'total_wage' => $totalWage,
        'category_wage' => $hasCategoryData ? distribute_category_wage_rounding($categoryWageAccum, $totalWage) : [],
    ];
}

/**
 * 区分別金額（日ごとの按分値を積み上げた浮動小数）を丸め、丸め誤差が既存の合計額と必ず一致するよう
 * 最大区分に差分を寄せる（区分別内訳の合計が既存の「見込み額」「賃金」等の合計と食い違わないようにするため）。
 *
 * @param array<string,float> $categoryWageAccum
 * @return array<string,int>
 */
function distribute_category_wage_rounding(array $categoryWageAccum, int $totalWage): array
{
    $rounded = array_map(static fn (float $v): int => (int) floor($v + 1e-9), $categoryWageAccum);
    $remainder = $totalWage - array_sum($rounded);
    if ($remainder !== 0 && !empty($rounded)) {
        $largestCategory = array_key_first($rounded);
        foreach ($rounded as $category => $wage) {
            if ($wage > $rounded[$largestCategory]) {
                $largestCategory = $category;
            }
        }
        $rounded[$largestCategory] += $remainder;
    }

    return $rounded;
}

/**
 * @param array<string,int> $dailyMinutes 日付(Y-m-d) => 実働分
 * @param array<string,int> $dailyNightMinutes 日付(Y-m-d) => 深夜（22:00〜翌5:00）実働分。
 *        打刻の時刻情報が無い場合（シフト予定ベースの見込み計算など）は省略でき、その場合は深夜手当0円として扱う。
 * @param array<string, array<string,int>> $dailyCategoryMinutes 日付(Y-m-d) => 区分(SHIFT_CATEGORIES) => 実働分。
 *        指定した場合、その日の実働分に占める区分ごとの割合でday_wage（所定内＋残業。深夜手当は含まない）を按分し、
 *        戻り値のcategory_wageに区分別の金額（合計は必ずtotal_wageと一致）を追加する。省略時はcategory_wageは空。
 */
function calc_wage_breakdown_from_daily_minutes(PDO $pdo, array $employee, array $dailyMinutes, array $dailyNightMinutes = [], array $dailyCategoryMinutes = []): array
{
    ksort($dailyMinutes);

    $daily = [];
    $totalMinutes = 0;
    $totalWage = 0;
    $categoryWageAccum = array_fill_keys(SHIFT_CATEGORIES, 0.0);
    $weekdayRegularMinutes = 0;
    $weekdayOvertimeMinutes = 0;
    $holidayRegularMinutes = 0;
    $holidayOvertimeMinutes = 0;
    $weekdayWage = 0;
    $weekdayOvertimeWage = 0;
    $holidayWage = 0;
    $holidayOvertimeWage = 0;
    $nightMinutes = 0;
    $nightWage = 0;
    $weekdayAttendanceDays = 0;
    $holidayAttendanceDays = 0;
    $weekdayNightMinutes = 0;
    $weekdayNightWage = 0;
    $holidayNightMinutes = 0;
    $holidayNightWage = 0;

    foreach ($dailyMinutes as $date => $dayMinutes) {
        $dayMinutes = (int) $dayMinutes;
        $isHoliday = is_holiday_date($pdo, $date);
        $rate = $isHoliday ? (int) $employee['hourly_wage_holiday'] : (int) $employee['hourly_wage_weekday'];

        $regularMinutes = min($dayMinutes, REGULAR_WORK_MINUTES_PER_DAY);
        $overtimeMinutes = max(0, $dayMinutes - REGULAR_WORK_MINUTES_PER_DAY);
        $regularWage = (int) round($rate * $regularMinutes / 60);
        $overtimeWage = (int) round($rate * $overtimeMinutes / 60 * OVERTIME_WAGE_MULTIPLIER);
        $dayWage = $regularWage + $overtimeWage;

        $dayNightMinutes = (int) ($dailyNightMinutes[$date] ?? 0);
        $dayNightWage = (int) round($rate * $dayNightMinutes / 60 * NIGHT_WAGE_PREMIUM_MULTIPLIER);

        $daily[] = [
            'work_day' => $date,
            'day_minutes' => $dayMinutes,
            'is_holiday' => $isHoliday,
            'regular_minutes' => $regularMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'night_minutes' => $dayNightMinutes,
            'day_wage' => $dayWage,
            'night_wage' => $dayNightWage,
        ];

        $totalMinutes += $dayMinutes;
        $totalWage += $dayWage;
        $nightMinutes += $dayNightMinutes;
        $nightWage += $dayNightWage;

        if ($dayMinutes > 0 && isset($dailyCategoryMinutes[$date])) {
            foreach ($dailyCategoryMinutes[$date] as $category => $categoryMinutes) {
                $categoryWageAccum[$category] = ($categoryWageAccum[$category] ?? 0.0) + $dayWage * $categoryMinutes / $dayMinutes;
            }
        }

        if ($isHoliday) {
            $holidayRegularMinutes += $regularMinutes;
            $holidayOvertimeMinutes += $overtimeMinutes;
            $holidayWage += $regularWage;
            $holidayOvertimeWage += $overtimeWage;
            $holidayAttendanceDays++;
            $holidayNightMinutes += $dayNightMinutes;
            $holidayNightWage += $dayNightWage;
        } else {
            $weekdayRegularMinutes += $regularMinutes;
            $weekdayOvertimeMinutes += $overtimeMinutes;
            $weekdayWage += $regularWage;
            $weekdayOvertimeWage += $overtimeWage;
            $weekdayAttendanceDays++;
            $weekdayNightMinutes += $dayNightMinutes;
            $weekdayNightWage += $dayNightWage;
        }
    }

    return [
        'daily' => $daily,
        'attendance_days' => count($daily),
        'total_minutes' => $totalMinutes,
        'total_wage' => $totalWage,
        'weekday_regular_minutes' => $weekdayRegularMinutes,
        'weekday_overtime_minutes' => $weekdayOvertimeMinutes,
        'holiday_regular_minutes' => $holidayRegularMinutes,
        'holiday_overtime_minutes' => $holidayOvertimeMinutes,
        'night_minutes' => $nightMinutes,
        'weekday_wage' => $weekdayWage,
        'weekday_overtime_wage' => $weekdayOvertimeWage,
        'holiday_wage' => $holidayWage,
        'holiday_overtime_wage' => $holidayOvertimeWage,
        'night_wage' => $nightWage,
        'base_wage' => $weekdayWage + $holidayWage,
        'overtime_wage' => $weekdayOvertimeWage + $holidayOvertimeWage,
        'overtime_minutes' => $weekdayOvertimeMinutes + $holidayOvertimeMinutes,
        'grand_total_wage' => $totalWage + $nightWage,
        'weekday_attendance_days' => $weekdayAttendanceDays,
        'holiday_attendance_days' => $holidayAttendanceDays,
        'weekday_total_minutes' => $weekdayRegularMinutes + $weekdayOvertimeMinutes,
        'holiday_total_minutes' => $holidayRegularMinutes + $holidayOvertimeMinutes,
        'weekday_night_minutes' => $weekdayNightMinutes,
        'holiday_night_minutes' => $holidayNightMinutes,
        'weekday_night_wage' => $weekdayNightWage,
        'holiday_night_wage' => $holidayNightWage,
        'weekday_total_wage' => $weekdayWage + $weekdayOvertimeWage + $weekdayNightWage,
        'holiday_total_wage' => $holidayWage + $holidayOvertimeWage + $holidayNightWage,
        'category_wage' => empty($dailyCategoryMinutes) ? [] : distribute_category_wage_rounding($categoryWageAccum, $totalWage),
    ];
}

/**
 * 交通費の月間計上額を計算する。
 * 日額区分: その月の実際の出勤日数（attendanceの日数）× 日額
 * 月額区分: 出勤日数に関わらず固定額
 */
function calc_commute_allowance_total(array $employee, int $attendanceDays): int
{
    $amount = (int) ($employee['commute_allowance_amount'] ?? 0);

    if (($employee['commute_allowance_type'] ?? 'daily') === 'monthly') {
        return $amount;
    }

    return $amount * $attendanceDays;
}

function get_employee_allowances(PDO $pdo, int $employeeId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, monthly_amount FROM employee_allowances WHERE employee_id = :employee_id ORDER BY id'
    );
    $stmt->execute([':employee_id' => $employeeId]);
    return $stmt->fetchAll();
}

function sum_allowance_amounts(array $allowances): int
{
    $total = 0;
    foreach ($allowances as $allowance) {
        $total += (int) $allowance['monthly_amount'];
    }
    return $total;
}

const MONTH_END_CORRECTION_EARLY_CLOCK_IN_GRACE_MINUTES = 5;

/**
 * 月末チェック（店舗区分限定の出勤時刻自動補正）の対象を洗い出す。
 * プレビュー表示と実行処理の両方から呼ばれ、常に同じロジックで再計算することで両者の整合性を保つ
 * （実行時にクライアントから送られた一覧を信用せず、サーバー側で都度再計算するため改ざん・データ不整合を防げる）。
 *
 * ルール：シフトの予定出勤時刻の5分前より早く打刻していた場合のみ、予定出勤時刻の5分前に補正する。
 * 5分前〜予定時刻の間、および予定時刻より後（遅刻）は対象外。
 * 同日に「店舗」区分のシフトが複数ある場合は、そのうち最も早い開始時刻を予定出勤時刻として扱う。
 *
 * @return list<array{attendance_id:int, employee_id:int, employee_name:string, work_date:string, shift_start_time:string, old_clock_in_at:string, new_clock_in_at:string, clock_out_at:?string, total_break_minutes:?int}>
 */
function find_month_end_correction_candidates(PDO $pdo, string $yearMonth): array
{
    [$monthStart, $monthEnd] = get_month_range($yearMonth);

    $shiftsStmt = $pdo->prepare(
        'SELECT employee_id, work_date, start_time, categories
         FROM shifts
         WHERE work_date BETWEEN :start AND :end'
    );
    $shiftsStmt->execute([':start' => $monthStart, ':end' => $monthEnd]);

    $storeShiftStartByEmployeeDate = [];
    foreach ($shiftsStmt->fetchAll() as $shift) {
        if (!categories_include_store(categories_from_value($shift['categories']))) {
            continue;
        }
        $employeeId = (int) $shift['employee_id'];
        $date = $shift['work_date'];
        $current = $storeShiftStartByEmployeeDate[$employeeId][$date] ?? null;
        if ($current === null || $shift['start_time'] < $current) {
            $storeShiftStartByEmployeeDate[$employeeId][$date] = $shift['start_time'];
        }
    }

    if (empty($storeShiftStartByEmployeeDate)) {
        return [];
    }

    $attendanceStmt = $pdo->prepare(
        'SELECT a.id, a.employee_id, e.name AS employee_name, a.clock_in_at, a.clock_out_at, a.total_break_minutes
         FROM attendance a
         INNER JOIN employees e ON e.id = a.employee_id
         WHERE a.deleted_at IS NULL AND DATE(a.clock_in_at) BETWEEN :start AND :end
         ORDER BY a.clock_in_at'
    );
    $attendanceStmt->execute([':start' => $monthStart, ':end' => $monthEnd]);

    $candidates = [];
    foreach ($attendanceStmt->fetchAll() as $row) {
        $employeeId = (int) $row['employee_id'];
        $workDate = substr($row['clock_in_at'], 0, 10);
        $shiftStartTime = $storeShiftStartByEmployeeDate[$employeeId][$workDate] ?? null;
        if ($shiftStartTime === null) {
            continue;
        }

        $scheduledStart = new DateTime($workDate . ' ' . $shiftStartTime);
        $threshold = (clone $scheduledStart)->modify('-' . MONTH_END_CORRECTION_EARLY_CLOCK_IN_GRACE_MINUTES . ' minutes');
        $actualClockIn = new DateTime($row['clock_in_at']);

        if ($actualClockIn >= $threshold) {
            continue; // 5分前以降（定刻含む）または遅刻：対象外
        }

        $candidates[] = [
            'attendance_id' => (int) $row['id'],
            'employee_id' => $employeeId,
            'employee_name' => $row['employee_name'],
            'work_date' => $workDate,
            'shift_start_time' => $shiftStartTime,
            'old_clock_in_at' => $row['clock_in_at'],
            'new_clock_in_at' => $threshold->format('Y-m-d H:i:s'),
            'clock_out_at' => $row['clock_out_at'],
            'total_break_minutes' => $row['total_break_minutes'] !== null ? (int) $row['total_break_minutes'] : null,
        ];
    }

    return $candidates;
}

/**
 * @return array{total: array<string,int>, category: array<string, array<string,int>>}
 *         total: 日付(Y-m-d) => 予定実働分（法定休憩控除後）
 *         category: 日付(Y-m-d) => 区分(SHIFT_CATEGORIES) => 予定実働分（法定休憩控除後）。
 *         法定休憩は、recalculate_daily_breaks()がその日最後のシフト（開始時刻が最も遅いもの）に
 *         寄せる仕様と揃えるため、区分別内訳でも最後のシフトの区分から差し引く。
 */
function calc_planned_minutes_by_day(PDO $pdo, int $employeeId, string $startDate, string $endDate): array
{
    $stmt = $pdo->prepare(
        'SELECT work_date, start_time, end_time, categories
         FROM shifts
         WHERE employee_id = :employee_id AND work_date BETWEEN :start AND :end
         ORDER BY work_date, start_time, id'
    );
    $stmt->execute([':employee_id' => $employeeId, ':start' => $startDate, ':end' => $endDate]);

    $shiftsByDay = [];
    foreach ($stmt->fetchAll() as $shift) {
        $shiftsByDay[$shift['work_date']][] = $shift;
    }

    $plannedMinutesByDay = [];
    $plannedCategoryMinutesByDay = [];
    foreach ($shiftsByDay as $date => $dayShifts) {
        $rawMinutes = 0;
        $rawCategoryMinutes = array_fill_keys(SHIFT_CATEGORIES, 0);
        foreach ($dayShifts as $shift) {
            $minutes = calc_work_minutes($shift['start_time'], $shift['end_time'], 0);
            $rawMinutes += $minutes;
            $category = resolve_shift_category(categories_from_value($shift['categories']));
            if ($category !== null) {
                $rawCategoryMinutes[$category] += $minutes;
            }
        }

        $legalBreak = calc_legal_break_minutes($rawMinutes);
        $plannedMinutesByDay[$date] = max(0, $rawMinutes - $legalBreak);

        if ($legalBreak > 0) {
            $lastShift = end($dayShifts);
            $lastCategory = resolve_shift_category(categories_from_value($lastShift['categories']));
            if ($lastCategory !== null) {
                $rawCategoryMinutes[$lastCategory] = max(0, $rawCategoryMinutes[$lastCategory] - $legalBreak);
            }
        }
        $plannedCategoryMinutesByDay[$date] = $rawCategoryMinutes;
    }

    return ['total' => $plannedMinutesByDay, 'category' => $plannedCategoryMinutesByDay];
}

function calc_shift_wage_estimate(PDO $pdo, array $employee, string $yearMonth): array
{
    [$monthStart, $monthEnd] = get_month_range($yearMonth);
    $planned = calc_planned_minutes_by_day($pdo, (int) $employee['id'], $monthStart, $monthEnd);

    return calc_wage_breakdown_from_daily_minutes($pdo, $employee, $planned['total'], [], $planned['category']);
}

function calc_category_minutes(PDO $pdo, int $employeeId, string $startDate, string $endDate): array
{
    $attendanceStmt = $pdo->prepare(
        "SELECT DATE(clock_in_at) AS work_day, SUM(work_minutes) AS day_minutes
         FROM attendance
         WHERE employee_id = :employee_id AND status = 'done'
           AND deleted_at IS NULL
           AND DATE(clock_in_at) BETWEEN :start AND :end
         GROUP BY DATE(clock_in_at)"
    );
    $attendanceStmt->execute([':employee_id' => $employeeId, ':start' => $startDate, ':end' => $endDate]);
    $actualMinutesByDay = [];
    foreach ($attendanceStmt->fetchAll() as $row) {
        $actualMinutesByDay[$row['work_day']] = (int) $row['day_minutes'];
    }

    $shiftsStmt = $pdo->prepare(
        'SELECT work_date, start_time, end_time, categories
         FROM shifts
         WHERE employee_id = :employee_id AND work_date BETWEEN :start AND :end'
    );
    $shiftsStmt->execute([':employee_id' => $employeeId, ':start' => $startDate, ':end' => $endDate]);

    $shiftsByDay = [];
    foreach ($shiftsStmt->fetchAll() as $shift) {
        $shiftsByDay[$shift['work_date']][] = [
            'planned_minutes' => calc_work_minutes($shift['start_time'], $shift['end_time'], 0),
            'category' => resolve_shift_category(categories_from_value($shift['categories'])),
        ];
    }

    $categoryMinutes = array_fill_keys(SHIFT_CATEGORIES, 0.0);

    foreach ($actualMinutesByDay as $day => $dayActualMinutes) {
        if ($dayActualMinutes <= 0 || empty($shiftsByDay[$day])) {
            continue; // シフトのない予定外出勤はどの業務種別にも計上しない
        }

        $totalPlannedMinutes = array_sum(array_column($shiftsByDay[$day], 'planned_minutes'));
        if ($totalPlannedMinutes <= 0) {
            continue;
        }

        foreach ($shiftsByDay[$day] as $shift) {
            if ($shift['category'] === null) {
                continue; // 業務種別未選択のシフトはどの種別にも計上しない
            }
            $categoryMinutes[$shift['category']] +=
                $dayActualMinutes * $shift['planned_minutes'] / $totalPlannedMinutes;
        }
    }

    foreach ($categoryMinutes as $category => $minutes) {
        // 業務種別合計が実働合計を超えないよう切り捨てで丸める
        $categoryMinutes[$category] = (int) floor($minutes + 1e-9);
    }

    return $categoryMinutes;
}

/**
 * 施設別・区分別の合計作業時間・作業効率を算出する。
 * attendanceには打刻区分（category）はあっても施設の紐付けが無く、work_stage_recordsには施設・区分・人数はあっても
 * 時間が無いため、calc_category_minutes()と同じ考え方（実働時間を人数比で施設に按分）で組み合わせる。
 * 区分での按分・グルーピングは、attendance.category（その日の主な打刻区分、1シフト1値）ではなく
 * work_stage_records.category（工程ごとの実際の区分。集荷は自動で'集荷'が付与されるため、
 * 例えば区分「洗濯代行」で出退勤した日でも集荷分は別区分として按分される）を基準にする。
 * 実働時間（attendance.work_minutes）は、その日の全work_stage_records行（区分問わず）の人数比で按分する。
 * 対象は、従業員・日付の組み合わせで出退勤実績（attendance.work_minutes）と作業実績（work_stage_records）が
 * 両方存在する日のみ（work_speed.php等の既存の「1人あたり平均所要時間」と同じ絞り込み方針）。
 * 作業効率は既存指標を踏襲し「合計作業時間 ÷ 合計人数」（1人あたり平均所要分）とする。
 *
 * @return array<string, array<string, array{total_minutes:int, total_people:int, efficiency_minutes_per_person:?float}>>
 *         施設名 => 区分 => 集計
 */
function calc_facility_category_work_stats(PDO $pdo, string $startDate, string $endDate): array
{
    // attendance.categoryは「その日の主な打刻区分」の1値であり、work_stage_records側の
    // 区分（工程ごと、集荷は常に'集荷'を自動付与）とは一致しないことがある（例：区分「洗濯代行」で
    // 出退勤した日でも、その日の集荷作業はwork_stage_records側でcategory='集荷'として記録される）。
    // そのため実働時間の按分キーにはattendance.categoryを使わず、従業員・日単位の実働時間合計を
    // その日の全work_stage_records行（区分問わず）の人数比で按分し、出力のグルーピングのみ
    // work_stage_records.category（実際にその工程が何の区分だったか）を用いる。
    $attendanceStmt = $pdo->prepare(
        "SELECT employee_id, DATE(clock_in_at) AS work_day, SUM(work_minutes) AS day_minutes
         FROM attendance
         WHERE status = 'done' AND deleted_at IS NULL
           AND DATE(clock_in_at) BETWEEN :start AND :end
         GROUP BY employee_id, DATE(clock_in_at)"
    );
    $attendanceStmt->execute([':start' => $startDate, ':end' => $endDate]);

    $minutesByEmployeeDay = [];
    foreach ($attendanceStmt->fetchAll() as $row) {
        $minutesByEmployeeDay[(int) $row['employee_id']][$row['work_day']] = (int) $row['day_minutes'];
    }

    $stageStmt = $pdo->prepare(
        "SELECT w.employee_id, w.category, w.record_date, w.facility_id, f.name AS facility_name, SUM(w.person_count) AS people
         FROM work_stage_records w
         INNER JOIN facilities f ON f.id = w.facility_id
         WHERE w.category IS NOT NULL AND w.deleted_at IS NULL AND w.record_date BETWEEN :start AND :end
         GROUP BY w.employee_id, w.category, w.record_date, w.facility_id, f.name"
    );
    $stageStmt->execute([':start' => $startDate, ':end' => $endDate]);

    $stageRows = $stageStmt->fetchAll();

    // 従業員・日ごとの人数合計（区分問わず、按分比の分母）を先に集計
    $totalPeopleByEmployeeDay = [];
    foreach ($stageRows as $row) {
        $employeeId = (int) $row['employee_id'];
        $totalPeopleByEmployeeDay[$employeeId][$row['record_date']] =
            ($totalPeopleByEmployeeDay[$employeeId][$row['record_date']] ?? 0) + (int) $row['people'];
    }

    $stats = [];
    foreach ($stageRows as $row) {
        $employeeId = (int) $row['employee_id'];
        $category = $row['category'];
        $day = $row['record_date'];
        $people = (int) $row['people'];

        $dayMinutes = $minutesByEmployeeDay[$employeeId][$day] ?? null;
        $dayTotalPeople = $totalPeopleByEmployeeDay[$employeeId][$day] ?? 0;
        if ($dayMinutes === null || $dayTotalPeople <= 0) {
            continue; // その日の出退勤実績が無い（対象外、既存指標と同じ絞り込み）
        }

        $allocatedMinutes = $dayMinutes * $people / $dayTotalPeople;

        if (!isset($stats[$row['facility_name']][$category])) {
            $stats[$row['facility_name']][$category] = ['total_minutes' => 0.0, 'total_people' => 0];
        }
        $stats[$row['facility_name']][$category]['total_minutes'] += $allocatedMinutes;
        $stats[$row['facility_name']][$category]['total_people'] += $people;
    }

    foreach ($stats as $facilityName => $categories) {
        foreach ($categories as $category => $data) {
            $totalMinutes = (int) round($data['total_minutes']);
            $totalPeople = $data['total_people'];
            $stats[$facilityName][$category] = [
                'total_minutes' => $totalMinutes,
                'total_people' => $totalPeople,
                'efficiency_minutes_per_person' => $totalPeople > 0 ? $totalMinutes / $totalPeople : null,
            ];
        }
    }

    return $stats;
}

const CC_TRAVEL_STAGE_LABELS = ['pickup' => '集荷', 'arrival' => '到着', 'dispatch' => '発送', 'return' => '返却'];

/**
 * 集荷・配送記録簿（collection_cycles）の各工程（集荷/到着/発送/返却）の日時・担当者・施設から、
 * 施設間の移動時間を算出する。同一従業員が同一日に記録した工程を時刻順に並べ、連続する2工程の
 * 施設が異なる場合にその間の経過時間を「移動」とみなし、その間に取った休憩（attendance_breaks）の
 * 重複分を差し引く。日付・時刻・担当者・施設のいずれかが未設定の工程（quick入力の一部項目が
 * 空欄のまま等）は対象外とする（場所や時刻を特定できないものを移動時間の算出に混ぜないため）。
 *
 * @return list<array{
 *   employee_id:int, employee_name:string, date:string,
 *   from_stage:string, from_facility:string, from_time:string,
 *   to_stage:string, to_facility:string, to_time:string,
 *   raw_minutes:int, break_minutes:int, travel_minutes:int
 * }> 日付降順、同日内は従業員名・時刻順
 */
function calc_travel_segments(PDO $pdo, string $startDate, string $endDate, ?int $employeeId = null): array
{
    $employeeFilterSql = $employeeId !== null ? 'AND employee_id = :employee_id' : '';

    $eventsStmt = $pdo->prepare(
        "SELECT employee_id, event_date, event_time, facility_id, stage FROM (
            SELECT pickup_employee_id AS employee_id, pickup_date AS event_date, pickup_time AS event_time,
                   facility_id, 'pickup' AS stage
            FROM collection_cycles
            WHERE deleted_at IS NULL AND pickup_employee_id IS NOT NULL
              AND pickup_date IS NOT NULL AND pickup_time IS NOT NULL
            UNION ALL
            SELECT arrival_employee_id, arrival_date, arrival_time, arrival_facility_id, 'arrival'
            FROM collection_cycles
            WHERE deleted_at IS NULL AND arrival_employee_id IS NOT NULL
              AND arrival_date IS NOT NULL AND arrival_time IS NOT NULL AND arrival_facility_id IS NOT NULL
            UNION ALL
            SELECT dispatch_employee_id, dispatch_date, dispatch_time, dispatch_facility_id, 'dispatch'
            FROM collection_cycles
            WHERE deleted_at IS NULL AND dispatch_employee_id IS NOT NULL
              AND dispatch_date IS NOT NULL AND dispatch_time IS NOT NULL AND dispatch_facility_id IS NOT NULL
            UNION ALL
            SELECT return_employee_id, return_date, return_time, facility_id, 'return'
            FROM collection_cycles
            WHERE deleted_at IS NULL AND return_employee_id IS NOT NULL
              AND return_date IS NOT NULL AND return_time IS NOT NULL
        ) events
        WHERE event_date BETWEEN :start AND :end $employeeFilterSql
        ORDER BY employee_id, event_date, event_time"
    );
    $params = [':start' => $startDate, ':end' => $endDate];
    if ($employeeId !== null) {
        $params[':employee_id'] = $employeeId;
    }
    $eventsStmt->execute($params);
    $events = $eventsStmt->fetchAll();

    if (empty($events)) {
        return [];
    }

    $involvedEmployeeIds = array_values(array_unique(array_map(static fn (array $e): int => (int) $e['employee_id'], $events)));

    $facilityNamesStmt = $pdo->query('SELECT id, name FROM facilities');
    $facilityNames = array_column($facilityNamesStmt->fetchAll(), 'name', 'id');

    $employeeNamesStmt = $pdo->query('SELECT id, name FROM employees');
    $employeeNames = array_column($employeeNamesStmt->fetchAll(), 'name', 'id');

    // 休憩は日をまたぐ想定が無いため、対象日の前後1日分だけ余裕を持たせて取得する
    // （深夜勤務等で休憩終了がevent_dateの翌日にわずかにずれ込むケースを取りこぼさないため）。
    $breakRangeStart = (new DateTime($startDate))->modify('-1 day')->format('Y-m-d 00:00:00');
    $breakRangeEnd = (new DateTime($endDate))->modify('+1 day')->format('Y-m-d 23:59:59');
    $breaksPlaceholders = implode(',', array_fill(0, count($involvedEmployeeIds), '?'));
    $breaksStmt = $pdo->prepare(
        "SELECT employee_id, break_start_at, break_end_at
         FROM attendance_breaks
         WHERE employee_id IN ($breaksPlaceholders) AND break_start_at BETWEEN ? AND ?
         ORDER BY employee_id, break_start_at"
    );
    $breaksStmt->execute([...$involvedEmployeeIds, $breakRangeStart, $breakRangeEnd]);
    $breaksByEmployee = [];
    foreach ($breaksStmt->fetchAll() as $row) {
        $breaksByEmployee[(int) $row['employee_id']][] = $row;
    }

    // 従業員・日付ごとにグルーピングして時刻順に並べる（SQL側は既にemployee_id, event_date, event_time順）。
    $eventsByEmployeeDate = [];
    foreach ($events as $event) {
        $eventsByEmployeeDate[(int) $event['employee_id']][$event['event_date']][] = $event;
    }

    $segments = [];
    foreach ($eventsByEmployeeDate as $empId => $byDate) {
        foreach ($byDate as $date => $dayEvents) {
            for ($i = 0; $i < count($dayEvents) - 1; $i++) {
                $from = $dayEvents[$i];
                $to = $dayEvents[$i + 1];
                if ((int) $from['facility_id'] === (int) $to['facility_id']) {
                    continue; // 同一施設内の連続工程は「移動」ではない
                }

                $fromDt = new DateTime($date . ' ' . $from['event_time']);
                $toDt = new DateTime($date . ' ' . $to['event_time']);
                $rawMinutes = max(0, (int) round(($toDt->getTimestamp() - $fromDt->getTimestamp()) / 60));

                $breakMinutes = 0;
                foreach ($breaksByEmployee[$empId] ?? [] as $break) {
                    $breakStart = new DateTime($break['break_start_at']);
                    $breakEnd = $break['break_end_at'] !== null ? new DateTime($break['break_end_at']) : $toDt;
                    $overlapStart = max($fromDt, $breakStart);
                    $overlapEnd = min($toDt, $breakEnd);
                    if ($overlapEnd > $overlapStart) {
                        $breakMinutes += (int) round(($overlapEnd->getTimestamp() - $overlapStart->getTimestamp()) / 60);
                    }
                }

                $segments[] = [
                    'employee_id' => $empId,
                    'employee_name' => $employeeNames[$empId] ?? ('ID:' . $empId),
                    'date' => $date,
                    'from_stage' => CC_TRAVEL_STAGE_LABELS[$from['stage']] ?? $from['stage'],
                    'from_facility' => $facilityNames[(int) $from['facility_id']] ?? ('ID:' . $from['facility_id']),
                    'from_time' => substr($from['event_time'], 0, 5),
                    'to_stage' => CC_TRAVEL_STAGE_LABELS[$to['stage']] ?? $to['stage'],
                    'to_facility' => $facilityNames[(int) $to['facility_id']] ?? ('ID:' . $to['facility_id']),
                    'to_time' => substr($to['event_time'], 0, 5),
                    'raw_minutes' => $rawMinutes,
                    'break_minutes' => $breakMinutes,
                    'travel_minutes' => max(0, $rawMinutes - $breakMinutes),
                ];
            }
        }
    }

    usort($segments, static function (array $a, array $b): int {
        $dateCmp = strcmp($b['date'], $a['date']);
        if ($dateCmp !== 0) {
            return $dateCmp;
        }
        $nameCmp = strcmp($a['employee_name'], $b['employee_name']);
        if ($nameCmp !== 0) {
            return $nameCmp;
        }
        return strcmp($a['from_time'], $b['from_time']);
    });

    return $segments;
}

const MISSED_CLOCK_TRACKING_START_DATE = '2026-07-05';

/**
 * シフトはあるのに打刻が漏れている（記録が無い、または出勤したまま退勤していない）日を検出する。
 * MISSED_CLOCK_TRACKING_START_DATE以降・当日までが対象。当日分は最後のシフトの終了予定時刻を過ぎている場合のみ含める。
 * 戻り値は直近の日付が先頭になるよう降順ソート。各要素の attendance_id は自己編集リンク用（記録が無い日はnull）。
 */
function find_missed_clock_dates(PDO $pdo, int $employeeId, string $today): array
{
    if ($today < MISSED_CLOCK_TRACKING_START_DATE) {
        return [];
    }

    $shiftDatesStmt = $pdo->prepare(
        'SELECT work_date, MAX(end_time) AS last_end_time
         FROM shifts
         WHERE employee_id = :employee_id
           AND work_date >= :cutoff AND work_date <= :today
         GROUP BY work_date'
    );
    $shiftDatesStmt->execute([
        ':employee_id' => $employeeId,
        ':cutoff' => MISSED_CLOCK_TRACKING_START_DATE,
        ':today' => $today,
    ]);
    $shiftDates = $shiftDatesStmt->fetchAll();

    if (empty($shiftDates)) {
        return [];
    }

    $now = new DateTime();
    $targetDates = [];
    foreach ($shiftDates as $row) {
        $workDate = $row['work_date'];
        if ($workDate === $today) {
            $shiftEnd = new DateTime($workDate . ' ' . $row['last_end_time']);
            if ($now < $shiftEnd) {
                continue; // 本日分：退勤予定時刻をまだ過ぎていない
            }
        }
        $targetDates[] = $workDate;
    }

    if (empty($targetDates)) {
        return [];
    }

    $attendanceStmt = $pdo->prepare(
        'SELECT id, DATE(clock_in_at) AS work_day, status
         FROM attendance
         WHERE employee_id = :employee_id
           AND deleted_at IS NULL
           AND DATE(clock_in_at) BETWEEN :start AND :end
         ORDER BY clock_in_at'
    );
    $attendanceStmt->execute([
        ':employee_id' => $employeeId,
        ':start' => MISSED_CLOCK_TRACKING_START_DATE,
        ':end' => $today,
    ]);

    $attendanceByDay = [];
    foreach ($attendanceStmt->fetchAll() as $row) {
        $attendanceByDay[$row['work_day']][] = $row;
    }

    $missed = [];
    foreach ($targetDates as $workDate) {
        $records = $attendanceByDay[$workDate] ?? [];

        $hasDone = false;
        foreach ($records as $record) {
            if ($record['status'] === 'done') {
                $hasDone = true;
                break;
            }
        }
        if ($hasDone) {
            continue;
        }

        $lastRecord = end($records);
        $missed[] = [
            'date' => $workDate,
            'attendance_id' => $lastRecord !== false ? (int) $lastRecord['id'] : null,
        ];
    }

    usort($missed, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

    return $missed;
}

function is_month_confirmed(PDO $pdo, int $employeeId, string $yearMonth): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM monthly_wages WHERE employee_id = :employee_id AND `year_month` = :year_month'
    );
    $stmt->execute([':employee_id' => $employeeId, ':year_month' => $yearMonth]);

    return $stmt->fetchColumn() !== false;
}

function to_datetime_local(?string $value): string
{
    if ($value === null) {
        return '';
    }

    return str_replace(' ', 'T', substr($value, 0, 16));
}

function recompute_total_break_minutes(?int $currentTotal, ?string $oldStart, ?string $oldEnd, ?string $newStart, ?string $newEnd): ?int
{
    $oldDuration = ($oldStart !== null && $oldEnd !== null)
        ? max(0, (int) round(((new DateTime($oldEnd))->getTimestamp() - (new DateTime($oldStart))->getTimestamp()) / 60))
        : 0;
    $newDuration = ($newStart !== null && $newEnd !== null)
        ? max(0, (int) round(((new DateTime($newEnd))->getTimestamp() - (new DateTime($newStart))->getTimestamp()) / 60))
        : 0;

    if ($oldDuration === 0 && $newDuration === 0) {
        return $currentTotal;
    }

    // break_start_at/break_end_atが未記録のまま合計だけが設定されているケース（休憩自動計算の適用時）は、
    // 積み増しではなく置き換えとして扱う（裏付けとなる区間が無い合計を新しい区間入力に差し替える）。
    $baselineToSubtract = ($oldStart === null && $oldEnd === null) ? ($currentTotal ?? 0) : $oldDuration;

    return max(0, ($currentTotal ?? 0) - $baselineToSubtract + $newDuration);
}

function recalculate_daily_breaks(PDO $pdo, int $employeeId, string $workDate): void
{
    $stmt = $pdo->prepare(
        'SELECT id, start_time, end_time
         FROM shifts
         WHERE employee_id = :employee_id AND work_date = :work_date
         ORDER BY start_time, id'
    );
    $stmt->execute([
        ':employee_id' => $employeeId,
        ':work_date' => $workDate,
    ]);
    $shifts = $stmt->fetchAll();

    if (empty($shifts)) {
        return;
    }

    $totalRawMinutes = 0;
    foreach ($shifts as $shift) {
        $totalRawMinutes += calc_work_minutes($shift['start_time'], $shift['end_time'], 0);
    }

    $legalBreak = calc_legal_break_minutes($totalRawMinutes);
    $lastShift = end($shifts);
    $lastShiftId = (int) $lastShift['id'];

    $updateStmt = $pdo->prepare('UPDATE shifts SET break_minutes = :break_minutes WHERE id = :id');
    foreach ($shifts as $shift) {
        $breakForShift = ((int) $shift['id'] === $lastShiftId) ? $legalBreak : 0;
        $updateStmt->execute([
            ':break_minutes' => $breakForShift,
            ':id' => $shift['id'],
        ]);
    }
}

/**
 * シフト表（週・半月・月・カレンダー）の表示範囲を決定する。
 * admin/shifts.php と staff/team_shifts.php の両方から共有され、
 * 前月・次月移動やレンジの定義がどちらか一方だけ変更されて食い違うことを防ぐ。
 *
 * @return array{rangeStart:DateTime,rangeEnd:DateTime,prevDate:string,nextDate:string,viewLabel:string,monthStart:DateTime}
 */
function resolve_shift_view_range(string $view, DateTime $anchorDate): array
{
    if ($view === 'week') {
        $rangeStart = clone $anchorDate;
        $rangeStart->modify('-' . ((int) $rangeStart->format('N') - 1) . ' days');
        $rangeEnd = (clone $rangeStart)->modify('+6 days');
        $prevDate = (clone $anchorDate)->modify('-7 days')->format('Y-m-d');
        $nextDate = (clone $anchorDate)->modify('+7 days')->format('Y-m-d');
        $viewLabel = '週';
        $monthStart = (clone $anchorDate)->modify('first day of this month');
    } elseif ($view === 'half') {
        $monthStart = (clone $anchorDate)->modify('first day of this month');
        $day = (int) $anchorDate->format('j');
        if ($day <= 15) {
            $rangeStart = clone $monthStart;
            $rangeEnd = (clone $monthStart)->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('n'), 15);
            $prevDate = (clone $monthStart)->modify('-1 month')->modify('+15 days')->format('Y-m-d');
            $nextDate = (clone $monthStart)->modify('+15 days')->format('Y-m-d');
        } else {
            $rangeStart = (clone $monthStart)->modify('+15 days');
            $rangeEnd = (clone $monthStart)->modify('last day of this month');
            $prevDate = $monthStart->format('Y-m-d');
            $nextDate = (clone $monthStart)->modify('+1 month')->format('Y-m-d');
        }
        $viewLabel = '半月';
    } else {
        // 'month'と'calendar'は同じ月レンジ（当月1日〜末日）を共有する。
        $monthStart = (clone $anchorDate)->modify('first day of this month');
        $rangeStart = clone $monthStart;
        $rangeEnd = (clone $anchorDate)->modify('last day of this month');
        $prevDate = (clone $anchorDate)->modify('first day of last month')->format('Y-m-d');
        $nextDate = (clone $anchorDate)->modify('first day of next month')->format('Y-m-d');
        $viewLabel = '月';
    }

    return [
        'rangeStart' => $rangeStart,
        'rangeEnd' => $rangeEnd,
        'prevDate' => $prevDate,
        'nextDate' => $nextDate,
        'viewLabel' => $viewLabel,
        'monthStart' => $monthStart,
    ];
}

/**
 * カレンダー表示専用：選択日を決定する（当日がレンジ内ならそれを既定選択、そうでなければレンジ初日）。
 */
function resolve_calendar_selected_date(?string $requestedDate, string $rangeStartStr, string $rangeEndStr, string $todayStr): string
{
    $requestedDate = (string) $requestedDate;
    $dateObj = DateTime::createFromFormat('Y-m-d', $requestedDate);
    if ($dateObj === false || $requestedDate < $rangeStartStr || $requestedDate > $rangeEndStr) {
        return ($todayStr >= $rangeStartStr && $todayStr <= $rangeEndStr) ? $todayStr : $rangeStartStr;
    }

    return $requestedDate;
}

/**
 * カレンダー表示専用：日〜土・週ごとの行に組み替える（先頭・末尾は空セルで埋める）。
 *
 * @param DateTime[] $dates
 * @return array<int, array<int, DateTime|null>>
 */
function build_calendar_weeks(DateTime $monthStart, array $dates): array
{
    $weeks = [];
    $sundayStartIndex = (int) $monthStart->format('N') % 7; // 0=日,1=月,...,6=土
    $week = array_fill(0, $sundayStartIndex, null);
    foreach ($dates as $d) {
        $week[] = $d;
        if (count($week) === 7) {
            $weeks[] = $week;
            $week = [];
        }
    }
    if (!empty($week)) {
        while (count($week) < 7) {
            $week[] = null;
        }
        $weeks[] = $week;
    }

    return $weeks;
}

/**
 * カレンダー表示専用：シフトが1件でもある日付の集合を、既存の$shiftsByEmployeeDateから導出する（追加クエリなし）。
 *
 * @return array<string,true>
 */
function build_dates_with_shift(array $shiftsByEmployeeDate): array
{
    $datesWithShift = [];
    foreach ($shiftsByEmployeeDate as $byDate) {
        foreach ($byDate as $dateStr => $shiftsForDate) {
            if (!empty($shiftsForDate)) {
                $datesWithShift[$dateStr] = true;
            }
        }
    }

    return $datesWithShift;
}

/**
 * カレンダー表示専用：日付ごとに「その日シフトが入っている従業員の氏名＋区分」一覧を、
 * 既存の$shiftsByEmployeeDateから導出する（追加クエリなし）。
 * 同一従業員が同日に複数シフトを持つ場合も1エントリにまとめ、区分は重複を除いてSHIFT_CATEGORIES優先順に並べる。
 * 省略は一切行わない（全員分を返す）。
 *
 * @param array<int,array{id:int|string,name:string}> $employees 表示順（氏名順）の従業員一覧
 * @return array<string, list<array{name:string, categories:list<string>}>>
 */
function build_calendar_day_employee_names(array $employees, array $shiftsByEmployeeDate): array
{
    $namesByDate = [];
    foreach ($employees as $employee) {
        $employeeId = (int) $employee['id'];
        if (!isset($shiftsByEmployeeDate[$employeeId])) {
            continue;
        }
        foreach ($shiftsByEmployeeDate[$employeeId] as $dateStr => $shiftsForDate) {
            if (empty($shiftsForDate)) {
                continue;
            }
            $categories = [];
            foreach ($shiftsForDate as $shift) {
                foreach (categories_from_value($shift['categories']) as $category) {
                    if (!in_array($category, $categories, true)) {
                        $categories[] = $category;
                    }
                }
            }
            $namesByDate[$dateStr][] = [
                'name' => $employee['name'],
                'categories' => array_values(array_intersect(SHIFT_CATEGORIES, $categories)),
            ];
        }
    }

    return $namesByDate;
}

/**
 * vehicle_checks 1件分のヘッダ＋明細を、vehicle_check_history の before_data/after_data（JSON）用にまとめる。
 */
function build_vehicle_check_snapshot(PDO $pdo, int $vehicleCheckId): ?array
{
    $headerStmt = $pdo->prepare(
        'SELECT employee_id, vehicle_id, check_date, checked_at, alcohol_value, overall_status, notes
         FROM vehicle_checks WHERE id = :id'
    );
    $headerStmt->execute([':id' => $vehicleCheckId]);
    $header = $headerStmt->fetch();
    if ($header === false) {
        return null;
    }

    $resultsStmt = $pdo->prepare(
        'SELECT item_id, result, issue_note FROM vehicle_check_results WHERE vehicle_check_id = :id ORDER BY item_id'
    );
    $resultsStmt->execute([':id' => $vehicleCheckId]);

    return ['header' => $header, 'results' => $resultsStmt->fetchAll()];
}

/**
 * vehicle_check_history に1件記録する（酒気帯び記録を含むため物理削除禁止。取り消しも必ずこの履歴を残す）。
 */
function record_vehicle_check_history(
    PDO $pdo,
    int $vehicleCheckId,
    string $action,
    int $changedBy,
    string $changedByRole,
    ?array $beforeData,
    ?array $afterData
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO vehicle_check_history (vehicle_check_id, action, changed_by, changed_by_role, before_data, after_data, changed_at)
         VALUES (:vehicle_check_id, :action, :changed_by, :changed_by_role, :before_data, :after_data, :changed_at)'
    );
    $stmt->execute([
        ':vehicle_check_id' => $vehicleCheckId,
        ':action' => $action,
        ':changed_by' => $changedBy,
        ':changed_by_role' => $changedByRole,
        ':before_data' => $beforeData !== null ? json_encode($beforeData, JSON_UNESCAPED_UNICODE) : null,
        ':after_data' => $afterData !== null ? json_encode($afterData, JSON_UNESCAPED_UNICODE) : null,
        ':changed_at' => (new DateTime())->format('Y-m-d H:i:s'),
    ]);
}

/**
 * vehicle_maintenance 1件分を、vehicle_maintenance_history の before_data/after_data（JSON）用にまとめる。
 */
function build_vehicle_maintenance_snapshot(PDO $pdo, int $vehicleMaintenanceId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT vehicle_id, shaken_date, shaken_expiry, jibaiseki_company, jibaiseki_start, jibaiseki_end,
                ninni_company, ninni_start, ninni_end, oil_change_date, battery_change_date, battery_type,
                tire_change_date_front_right, tire_change_date_front_left,
                tire_change_date_rear_left, tire_change_date_rear_right, notes
         FROM vehicle_maintenance WHERE id = :id'
    );
    $stmt->execute([':id' => $vehicleMaintenanceId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * vehicle_maintenance_history に1件記録する。
 */
function record_vehicle_maintenance_history(
    PDO $pdo,
    int $vehicleMaintenanceId,
    string $action,
    int $changedBy,
    string $changedByRole,
    ?array $beforeData,
    ?array $afterData
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO vehicle_maintenance_history (vehicle_maintenance_id, action, changed_by, changed_by_role, before_data, after_data, changed_at)
         VALUES (:vehicle_maintenance_id, :action, :changed_by, :changed_by_role, :before_data, :after_data, :changed_at)'
    );
    $stmt->execute([
        ':vehicle_maintenance_id' => $vehicleMaintenanceId,
        ':action' => $action,
        ':changed_by' => $changedBy,
        ':changed_by_role' => $changedByRole,
        ':before_data' => $beforeData !== null ? json_encode($beforeData, JSON_UNESCAPED_UNICODE) : null,
        ':after_data' => $afterData !== null ? json_encode($afterData, JSON_UNESCAPED_UNICODE) : null,
        ':changed_at' => (new DateTime())->format('Y-m-d H:i:s'),
    ]);
}

/**
 * $fieldToItemType の各フィールドについて $before→$after の差分を、消耗品在庫
 * （consumable_stock_transactions）の減産・増産として自動記録する共通処理。
 * 値が増えた分（交付が増えた）だけ在庫はマイナス、減った分（訂正等）はプラスになる。
 * $beforeがNULL、またはフィールドが存在しない場合は0として扱う（新規登録時）。
 * facilities.issued_*（施設への基準交付数）専用。collection_cyclesの交付は
 * record_collection_cycle_issuance_stock_adjustment()を使う（削除時の扱いが異なるため）。
 */
function record_consumable_stock_issuance_delta(PDO $pdo, array $fieldToItemType, ?array $before, array $after, string $reason, ?int $facilityId, string $note, int $createdBy): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO consumable_stock_transactions (item_type, quantity, reason, facility_id, transaction_date, note, created_by)
         VALUES (:item_type, :quantity, :reason, :facility_id, :transaction_date, :note, :created_by)'
    );
    $today = (new DateTime())->format('Y-m-d');

    foreach ($fieldToItemType as $field => $itemType) {
        $oldValue = (int) ($before[$field] ?? 0);
        $newValue = (int) ($after[$field] ?? 0);
        $delta = $newValue - $oldValue;
        if ($delta === 0) {
            continue;
        }

        $stmt->execute([
            ':item_type' => $itemType,
            ':quantity' => -$delta,
            ':reason' => $reason,
            ':facility_id' => $facilityId,
            ':transaction_date' => $today,
            ':note' => $note,
            ':created_by' => $createdBy,
        ]);
    }
}

/**
 * facilities.issued_linen_bag_orange/yellow/blue・issued_laundry_net_count（施設への基準交付数）の
 * 新規登録・変更差分を消耗品在庫に自動反映する。
 */
function record_facility_issuance_stock_adjustment(PDO $pdo, ?array $before, array $after, int $facilityId, string $facilityName, int $createdBy): void
{
    record_consumable_stock_issuance_delta($pdo, [
        'issued_linen_bag_orange' => 'linen_bag_orange',
        'issued_linen_bag_yellow' => 'linen_bag_yellow',
        'issued_linen_bag_blue' => 'linen_bag_blue',
        'issued_laundry_net_count' => 'laundry_net',
    ], $before, $after, 'issuance_to_facility', $facilityId, $facilityName . 'への交付（自動記録）', $createdBy);
}

/**
 * collection_cycles.issued_bag_orange/yellow/blue・issued_laundry_net_count（集荷時に施設へ
 * 渡した交換用の袋・ネット数）の新規登録・変更を消耗品在庫に自動反映する。
 *
 * facilities版とは異なり、増えた分だけを一方向に減産する（$before→$afterで値が
 * 減った場合や$after=NULLは何もしない）。集荷記録の削除・訂正による在庫の自動的な
 * 戻し処理はここでは行わない —— 削除時はcancel_collection_cycle_issuance_stock_transactions()
 * で「その記録が生んだ減産取引自体を取り消す」方式を使う（新たに戻し取引を作ると、
 * 削除→作り直しのたびに±が二重に積み上がり相殺してしまうバグの原因になっていたため）。
 * 交付数を訂正して増やした場合は、その増加分だけ追加で減産する。減らした場合は
 * 何もしないため、必要なら消耗品在庫管理画面から手動で調整する。
 *
 * 同じ増加分だけ、施設マスタ（facilities）側の交付累計カラムにも加算する
 * （自社在庫は減、施設側在庫は増）。
 */
function record_collection_cycle_issuance_stock_adjustment(
    PDO $pdo,
    ?array $before,
    array $after,
    int $facilityId,
    string $facilityName,
    int $collectionCycleId,
    int $createdBy
): void {
    $fieldToItemType = [
        'issued_bag_orange' => 'linen_bag_orange',
        'issued_bag_yellow' => 'linen_bag_yellow',
        'issued_bag_blue' => 'linen_bag_blue',
        'issued_laundry_net_count' => 'laundry_net',
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO consumable_stock_transactions
            (item_type, quantity, reason, facility_id, collection_cycle_id, transaction_date, note, created_by)
         VALUES
            (:item_type, :quantity, \'issuance_to_facility\', :facility_id, :collection_cycle_id, :transaction_date, :note, :created_by)'
    );
    $today = (new DateTime())->format('Y-m-d');
    $note = $facilityName . 'への交付（自動記録・集荷記録）';

    foreach ($fieldToItemType as $field => $itemType) {
        $oldValue = (int) ($before[$field] ?? 0);
        $newValue = (int) ($after[$field] ?? 0);
        $increase = $newValue - $oldValue;
        if ($increase <= 0) {
            continue;
        }

        $stmt->execute([
            ':item_type' => $itemType,
            ':quantity' => -$increase,
            ':facility_id' => $facilityId,
            ':collection_cycle_id' => $collectionCycleId,
            ':transaction_date' => $today,
            ':note' => $note,
            ':created_by' => $createdBy,
        ]);

        $facilityColumn = ITEM_TYPE_TO_FACILITY_ISSUED_COLUMN[$itemType] ?? null;
        if ($facilityColumn !== null) {
            $facilityStmt = $pdo->prepare(
                "UPDATE facilities SET {$facilityColumn} = COALESCE({$facilityColumn}, 0) + :increase WHERE id = :facility_id"
            );
            $facilityStmt->execute([':increase' => $increase, ':facility_id' => $facilityId]);
        }
    }
}

/**
 * 集荷・配送記録（collection_cycles）が削除された際、その記録がfacilities.issued_*に
 * 加算した分を差し引く。consumable_stock_transactions側の「まだ取り消されていない
 * この記録由来の交付取引」を(item_type, facility_id)単位で合算して使うため、
 * facility_idが編集で変更されていた場合でも当時加算した施設に対して正しく戻せる。
 * 呼び出しは必ずcancel_collection_cycle_issuance_stock_transactions()より前に行うこと
 * （取消後はcanceled_at IS NOT NULLとなり合計から漏れるため）。
 */
function reverse_collection_cycle_facility_issuance(PDO $pdo, int $collectionCycleId): void
{
    $sumStmt = $pdo->prepare(
        "SELECT item_type, facility_id, SUM(quantity) AS total_quantity
         FROM consumable_stock_transactions
         WHERE collection_cycle_id = :collection_cycle_id AND reason = 'issuance_to_facility' AND canceled_at IS NULL
         GROUP BY item_type, facility_id"
    );
    $sumStmt->execute([':collection_cycle_id' => $collectionCycleId]);

    foreach ($sumStmt->fetchAll() as $row) {
        $facilityColumn = ITEM_TYPE_TO_FACILITY_ISSUED_COLUMN[$row['item_type']] ?? null;
        if ($facilityColumn === null) {
            continue;
        }
        // quantityは交付分がマイナスで記録されているため、符号反転すると加算した増加分になる。
        $increaseToReverse = -(int) $row['total_quantity'];
        if ($increaseToReverse <= 0) {
            continue;
        }

        $updateStmt = $pdo->prepare(
            "UPDATE facilities SET {$facilityColumn} = GREATEST(0, COALESCE({$facilityColumn}, 0) - :decrease) WHERE id = :facility_id"
        );
        $updateStmt->execute([':decrease' => $increaseToReverse, ':facility_id' => (int) $row['facility_id']]);
    }
}

/**
 * 集荷・配送記録（collection_cycles）が削除された際、その記録から自動生成された
 * 消耗品在庫の減産取引（collection_cycle_idで紐づくもの）を取り消す。新たに戻し
 * （プラス）の取引を作るのではなく、元の減産取引自体をcanceled_atで無効化することで
 * 二重の増減や相殺が発生しない。
 */
function cancel_collection_cycle_issuance_stock_transactions(PDO $pdo, int $collectionCycleId, int $canceledBy): void
{
    $stmt = $pdo->prepare(
        'UPDATE consumable_stock_transactions
         SET canceled_at = :canceled_at, canceled_by = :canceled_by
         WHERE collection_cycle_id = :collection_cycle_id AND canceled_at IS NULL'
    );
    $stmt->execute([
        ':canceled_at' => (new DateTime())->format('Y-m-d H:i:s'),
        ':canceled_by' => $canceledBy,
        ':collection_cycle_id' => $collectionCycleId,
    ]);
}

/**
 * vehicle_alert_settings の閾値と vehicle_maintenance の最新有効レコードから、
 * 車検・自賠責・任意保険（期限型）およびオイル・タイヤ交換（経過日数型）の警告を算出する。
 *
 * @return list<array{vehicle_id:int, vehicle_label:string, alert_type:string, label:string, detail:string}>
 */
function calc_vehicle_alerts(PDO $pdo, string $today): array
{
    $thresholdStmt = $pdo->query('SELECT alert_type, threshold_days FROM vehicle_alert_settings WHERE is_active = 1');
    $thresholds = array_column($thresholdStmt->fetchAll(), 'threshold_days', 'alert_type');

    $vehiclesStmt = $pdo->query(
        "SELECT v.id, v.plate_number, v.vehicle_name,
                vm.shaken_expiry, vm.jibaiseki_end, vm.ninni_end, vm.oil_change_date, vm.battery_change_date,
                vm.tire_change_date_front_right, vm.tire_change_date_front_left,
                vm.tire_change_date_rear_left, vm.tire_change_date_rear_right
         FROM vehicles v
         LEFT JOIN vehicle_maintenance vm ON vm.id = (
             SELECT id FROM vehicle_maintenance
             WHERE vehicle_id = v.id AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1
         )
         WHERE v.is_active = 1"
    );
    $vehicles = $vehiclesStmt->fetchAll();

    $deadlineAlertLabels = [
        'shaken' => '車検期限',
        'jibaiseki' => '自賠責保険期限',
        'ninni' => '任意保険期限',
    ];
    $deadlineFields = [
        'shaken' => 'shaken_expiry',
        'jibaiseki' => 'jibaiseki_end',
        'ninni' => 'ninni_end',
    ];
    $elapsedAlertLabels = [
        'oil' => 'オイル交換',
        'battery' => 'バッテリー交換',
    ];
    $elapsedFields = [
        'oil' => 'oil_change_date',
        'battery' => 'battery_change_date',
    ];
    // タイヤは車輪位置ごとに交換日が別なので、4輪それぞれ独立して閾値判定する（アラート閾値自体はvehicle_alert_settingsの'tire'を共用）。
    $tireWheelLabels = [
        'tire_change_date_front_right' => 'タイヤ交換（前輪右）',
        'tire_change_date_front_left' => 'タイヤ交換（前輪左）',
        'tire_change_date_rear_left' => 'タイヤ交換（後輪左）',
        'tire_change_date_rear_right' => 'タイヤ交換（後輪右）',
    ];

    $todayDate = new DateTime($today);
    $alerts = [];

    foreach ($vehicles as $vehicle) {
        $vehicleLabel = $vehicle['plate_number'] . ($vehicle['vehicle_name'] !== null ? '（' . $vehicle['vehicle_name'] . '）' : '');

        foreach ($deadlineFields as $alertType => $field) {
            if (!isset($thresholds[$alertType]) || $vehicle[$field] === null) {
                continue;
            }
            $expiryDate = new DateTime($vehicle[$field]);
            $daysUntil = (int) $todayDate->diff($expiryDate)->format('%r%a');
            if ($daysUntil <= (int) $thresholds[$alertType]) {
                $alerts[] = [
                    'vehicle_id' => (int) $vehicle['id'],
                    'vehicle_label' => $vehicleLabel,
                    'alert_type' => $alertType,
                    'label' => $deadlineAlertLabels[$alertType],
                    'detail' => $daysUntil < 0
                        ? '期限切れ（' . $vehicle[$field] . '）'
                        : '期限まであと' . $daysUntil . '日（' . $vehicle[$field] . '）',
                ];
            }
        }

        foreach ($elapsedFields as $alertType => $field) {
            if (!isset($thresholds[$alertType]) || $vehicle[$field] === null) {
                continue;
            }
            $lastDate = new DateTime($vehicle[$field]);
            $elapsedDays = (int) $lastDate->diff($todayDate)->format('%a');
            if ($elapsedDays >= (int) $thresholds[$alertType]) {
                $alerts[] = [
                    'vehicle_id' => (int) $vehicle['id'],
                    'vehicle_label' => $vehicleLabel,
                    'alert_type' => $alertType,
                    'label' => $elapsedAlertLabels[$alertType],
                    'detail' => '前回から' . $elapsedDays . '日経過（' . $vehicle[$field] . '）',
                ];
            }
        }

        if (isset($thresholds['tire'])) {
            foreach ($tireWheelLabels as $field => $label) {
                if ($vehicle[$field] === null) {
                    continue;
                }
                $lastDate = new DateTime($vehicle[$field]);
                $elapsedDays = (int) $lastDate->diff($todayDate)->format('%a');
                if ($elapsedDays >= (int) $thresholds['tire']) {
                    $alerts[] = [
                        'vehicle_id' => (int) $vehicle['id'],
                        'vehicle_label' => $vehicleLabel,
                        'alert_type' => 'tire',
                        'label' => $label,
                        'detail' => '前回から' . $elapsedDays . '日経過（' . $vehicle[$field] . '）',
                    ];
                }
            }
        }
    }

    return $alerts;
}

/**
 * 指定した掲示板種別の投稿一覧を新しい順で取得する。管理者・従業員どちらも投稿者になりうるため
 * employeesを2回（投稿者・最終編集者）JOINする。
 */
function fetch_board_posts(PDO $pdo, string $boardType): array
{
    $stmt = $pdo->prepare(
        'SELECT p.id, p.content, p.created_at, p.updated_at,
                creator.name AS created_by_name, editor.name AS updated_by_name
         FROM board_posts p
         INNER JOIN employees creator ON creator.id = p.created_by
         LEFT JOIN employees editor ON editor.id = p.updated_by
         WHERE p.board_type = :board_type AND p.deleted_at IS NULL
         ORDER BY p.created_at DESC, p.id DESC'
    );
    $stmt->execute([':board_type' => $boardType]);

    return $stmt->fetchAll();
}

/**
 * work_stage_record_employees.started_at の算出: その従業員自身の直前のセッション
 * （work_stage_records.completed_at が今回より前で最も新しいもの）のcompleted_atを使う。
 * その日まだ何も参加していなければ、当日の洗濯代行区分での出勤時刻（attendance.clock_in_at）を使う。
 * どちらも無い場合は今回のcompleted_at自体を使う（開始時刻不明のまま作業時間0にするよりまし）。
 */
function resolve_work_stage_started_at(PDO $pdo, int $employeeId, DateTime $completedAt): string
{
    $completedAtStr = $completedAt->format('Y-m-d H:i:s');

    $prevStmt = $pdo->prepare(
        'SELECT wsr.completed_at
         FROM work_stage_record_employees wse
         INNER JOIN work_stage_records wsr ON wsr.id = wse.work_stage_record_id
         WHERE wse.employee_id = :employee_id
           AND wsr.completed_at IS NOT NULL AND wsr.completed_at < :completed_at
           AND wsr.deleted_at IS NULL
         ORDER BY wsr.completed_at DESC
         LIMIT 1'
    );
    $prevStmt->execute([':employee_id' => $employeeId, ':completed_at' => $completedAtStr]);
    $prevCompletedAt = $prevStmt->fetchColumn();
    if ($prevCompletedAt !== false) {
        return $prevCompletedAt;
    }

    $clockInStmt = $pdo->prepare(
        "SELECT clock_in_at
         FROM attendance
         WHERE employee_id = :employee_id AND category = '洗濯代行'
           AND DATE(clock_in_at) = :work_date AND deleted_at IS NULL
         ORDER BY clock_in_at ASC
         LIMIT 1"
    );
    $clockInStmt->execute([':employee_id' => $employeeId, ':work_date' => $completedAt->format('Y-m-d')]);
    $clockInAt = $clockInStmt->fetchColumn();

    return $clockInAt !== false ? $clockInAt : $completedAtStr;
}

/**
 * work_stage_records 1件（1セッション）の参加者を work_stage_record_employees に記録する。
 * completed_at はセッション共通の1つ、started_at は参加者ごとにresolve_work_stage_started_atで算出する。
 *
 * @param list<int> $employeeIds
 */
function record_work_stage_employees(PDO $pdo, int $workStageRecordId, array $employeeIds, DateTime $completedAt): void
{
    $insertStmt = $pdo->prepare(
        'INSERT INTO work_stage_record_employees (work_stage_record_id, employee_id, started_at)
         VALUES (:work_stage_record_id, :employee_id, :started_at)'
    );

    foreach (array_unique($employeeIds) as $employeeId) {
        $startedAt = resolve_work_stage_started_at($pdo, $employeeId, $completedAt);
        $insertStmt->execute([
            ':work_stage_record_id' => $workStageRecordId,
            ':employee_id' => $employeeId,
            ':started_at' => $startedAt,
        ]);
    }
}

/**
 * 本日の曜日に対応するfacilities.pickup_scheduleの値を返す（日曜は集荷日設定が無いためnull）。
 */
function todays_pickup_schedule_label(DateTime $today): ?string
{
    $map = [
        '1' => '月・木', '4' => '月・木',
        '2' => '火・金', '5' => '火・金',
        '3' => '水・土', '6' => '水・土',
    ];
    return $map[$today->format('N')] ?? null;
}

/**
 * 集荷ドライバーの出発前チェックリスト（staff/jiro_dashboard.phpの一覧、staff/dashboard.phpの
 * サマリー表示）用のデータを組み立てる。
 *
 * 「返却は次回集荷と同じ訪問で行われることが多い」という業務フローのため、本日の集荷予定施設
 * （facilities.pickup_scheduleが本日に該当・is_active=1）を上部に、それ以外で返却準備完了だが
 * ドライバー未確定の施設（集荷が予定通りに進まなかった積み残しや変則訪問）を下部に分けて返す。
 * 積み残し分を取りこぼさないよう、下部は施設のis_active状態に関わらず対象にする。
 *
 * 前回集荷袋数の色（オレンジ/黄）はfacilities.issued_linen_bag_orange/issued_linen_bag_yellowの
 * うちどちらが設定されているかで判定する（施設ごとに1色運用のため）。返却は常に青袋。
 */
function build_jiro_checklist_data(PDO $pdo, DateTime $today): array
{
    $todayScheduleLabel = todays_pickup_schedule_label($today);

    $facilitiesStmt = $pdo->query(
        "SELECT id, name, is_active, issued_linen_bag_orange, issued_linen_bag_yellow
         FROM facilities
         WHERE facility_type = '介護施設'"
    );
    $facilitiesById = array_column($facilitiesStmt->fetchAll(), null, 'id');

    $todayFacilityIdSet = [];
    if ($todayScheduleLabel !== null) {
        $todayStmt = $pdo->prepare(
            "SELECT id
             FROM facilities
             WHERE facility_type = '介護施設'
               AND is_active = 1
               AND pickup_schedule = :schedule
               AND (onboarding_start_date IS NULL OR onboarding_start_date < :target_date)"
        );
        $todayStmt->execute([
            ':schedule' => $todayScheduleLabel,
            ':target_date' => $today->format('Y-m-d'),
        ]);
        $todayFacilityIdSet = array_flip(array_map('intval', array_column($todayStmt->fetchAll(), 'id')));
    }

    // 施設ごとの直近の集荷リネン袋数（pickup_bag_count入力済みの最新サイクル）。
    // 「最新」はpickup_date基準（idではない）。管理者がadmin/collection_records.phpで過去日付の
    // サイクルを後から手入力した場合、id順とpickup_date順がずれることがあるため。
    $latestPickupStmt = $pdo->query(
        'SELECT cc.facility_id, cc.pickup_bag_count
         FROM collection_cycles cc
         WHERE cc.deleted_at IS NULL AND cc.pickup_bag_count IS NOT NULL
           AND cc.id = (
               SELECT cc2.id FROM collection_cycles cc2
               WHERE cc2.facility_id = cc.facility_id AND cc2.deleted_at IS NULL AND cc2.pickup_bag_count IS NOT NULL
               ORDER BY cc2.pickup_date DESC, cc2.id DESC
               LIMIT 1
           )'
    );
    $latestPickupByFacility = [];
    foreach ($latestPickupStmt->fetchAll() as $row) {
        $latestPickupByFacility[(int) $row['facility_id']] = (int) $row['pickup_bag_count'];
    }

    // 集荷実績が一度もない施設の判定用（pickup_bag_countの有無に関わらず、サイクル自体が存在するか）
    $historyStmt = $pdo->query('SELECT DISTINCT facility_id FROM collection_cycles WHERE deleted_at IS NULL');
    $facilityIdsWithHistory = array_flip(array_map('intval', array_column($historyStmt->fetchAll(), 'facility_id')));

    // 洗濯代行が返却準備完了を登録済みだが、ドライバーがまだ確認・確定していない分の合計（施設ごと）
    $readyStmt = $pdo->query(
        'SELECT facility_id, SUM(return_ready_bag_count) AS total
         FROM collection_cycles
         WHERE return_ready_bag_count IS NOT NULL AND return_bag_count IS NULL AND deleted_at IS NULL
         GROUP BY facility_id'
    );
    $pendingReturnByFacility = [];
    foreach ($readyStmt->fetchAll() as $row) {
        $pendingReturnByFacility[(int) $row['facility_id']] = (int) $row['total'];
    }

    $buildRow = static function (array $facility) use ($latestPickupByFacility, $facilityIdsWithHistory, $pendingReturnByFacility): array {
        $facilityId = (int) $facility['id'];
        $lastPickupBagCount = $latestPickupByFacility[$facilityId] ?? null;
        $lastPickupColor = $facility['issued_linen_bag_orange'] !== null
            ? 'オレンジ'
            : ($facility['issued_linen_bag_yellow'] !== null ? '黄' : null);
        $returnReadyTotal = $pendingReturnByFacility[$facilityId] ?? 0;

        return [
            'facility_id' => $facilityId,
            'facility_name' => $facility['name'],
            'has_history' => isset($facilityIdsWithHistory[$facilityId]),
            'last_pickup_bag_count' => $lastPickupBagCount,
            'last_pickup_color' => $lastPickupColor,
            'return_ready_total' => $returnReadyTotal,
            'row_total' => (int) $lastPickupBagCount + $returnReadyTotal,
        ];
    };

    $todayRows = [];
    foreach ($todayFacilityIdSet as $facilityId => $unused) {
        if (isset($facilitiesById[$facilityId])) {
            $todayRows[] = $buildRow($facilitiesById[$facilityId]);
        }
    }
    usort($todayRows, static fn (array $a, array $b): int => $a['facility_name'] <=> $b['facility_name']);

    // 「その他の返却待ち」：返却準備完了・未確定の施設のうち、本日の集荷予定に含まれないもの
    $otherRows = [];
    foreach ($pendingReturnByFacility as $facilityId => $total) {
        if (isset($todayFacilityIdSet[$facilityId]) || !isset($facilitiesById[$facilityId])) {
            continue;
        }
        $otherRows[] = $buildRow($facilitiesById[$facilityId]);
    }
    usort($otherRows, static fn (array $a, array $b): int => $a['facility_name'] <=> $b['facility_name']);

    $totals = ['orange' => 0, 'yellow' => 0, 'blue' => 0, 'total' => 0];
    foreach (array_merge($todayRows, $otherRows) as $row) {
        if ($row['last_pickup_color'] === 'オレンジ') {
            $totals['orange'] += (int) $row['last_pickup_bag_count'];
        } elseif ($row['last_pickup_color'] === '黄') {
            $totals['yellow'] += (int) $row['last_pickup_bag_count'];
        }
        $totals['blue'] += $row['return_ready_total'];
        $totals['total'] += $row['row_total'];
    }

    return [
        'target_date' => $today->format('Y-m-d'),
        'today_schedule_label' => $todayScheduleLabel,
        'today_rows' => $todayRows,
        'other_rows' => $otherRows,
        'totals' => $totals,
    ];
}
/**
 * 共用アカウント画面に表示する、本日勤務中のスタッフを取得する。
 *
 * @return array<int,array<string,mixed>>
 */
function find_open_attendance_today(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT a.id, a.employee_id, e.name AS employee_name, a.category,
                a.clock_in_at, a.break_start_at, a.break_end_at
           FROM attendance a
           JOIN employees e ON e.id = a.employee_id
          WHERE a.status = 'working'
            AND DATE(a.clock_in_at) = CURDATE()
            AND a.deleted_at IS NULL
          ORDER BY a.clock_in_at, a.id"
    );

    return $stmt->fetchAll();
}
