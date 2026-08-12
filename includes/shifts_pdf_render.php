<?php
// 月間シフト表PDFの生成ロジック（admin/shifts_pdf.php・staff/shifts_pdf.phpの共通処理）。
// 認証チェック（require_login）は呼び出し元（admin/staffそれぞれ）で行い、このファイルはPDF内容にのみ責任を持つ。
// PDF内容は管理者・従業員のどちらから呼んでも完全に同一（会社全体・対象月の全従業員シフト）。

require_once __DIR__ . '/../vendor/autoload.php';

function pdf_esc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * 1件のシフトの「時間帯＋区分タグ」ブロックのHTMLを組み立てる。
 * 区分は複数選択されている場合があるため、SHIFT_CATEGORIES順に並べてタグを複数出す。
 */
function render_shift_block(array $shift, bool $plain = false): string
{
    $categories = array_values(array_intersect(SHIFT_CATEGORIES, categories_from_value($shift['categories'])));
    if (in_array('集荷', $categories, true)) {
        $className = 'shift-name-pickup';
    } elseif (in_array('洗濯代行', $categories, true)) {
        $className = 'shift-name-laundry';
    } else {
        $className = 'shift-name-store';
    }

    $timeLabel = substr($shift['start_time'], 0, 5) . '〜' . substr($shift['end_time'], 0, 5);
    return '<div class="shift-name ' . ($plain ? 'shift-name-plain' : $className) . '">' . pdf_esc($timeLabel) . '</div>';
}

/**
 * PDFの日付欄に表示する施設開始イベントを返す。
 * 受託開始日は「オープン」、その日より後の最初の設定集荷日は「洗濯代行開始」とする。
 *
 * @return array<string, list<string>> 日付(Y-m-d) => 表示文言一覧
 */
function fetch_pdf_facility_start_labels(PDO $pdo, string $startDate, string $endDate): array
{
    $stmt = $pdo->query(
        "SELECT name, onboarding_start_date, pickup_schedule
         FROM facilities
         WHERE is_active = 1 AND onboarding_start_date IS NOT NULL"
    );
    $scheduleWeekdays = [
        '月・木' => [1, 4],
        '火・金' => [2, 5],
        '水・土' => [3, 6],
    ];
    $labelsByDate = [];

    foreach ($stmt->fetchAll() as $row) {
        $openDate = (string) $row['onboarding_start_date'];
        if ($openDate >= $startDate && $openDate <= $endDate) {
            $labelsByDate[$openDate][] = $row['name'] . 'オープン';
        }

        $weekdays = $scheduleWeekdays[$row['pickup_schedule']] ?? [];
        if (!$weekdays) {
            continue;
        }
        $firstPickupDate = new DateTime($openDate);
        do {
            $firstPickupDate->modify('+1 day');
        } while (!in_array((int) $firstPickupDate->format('N'), $weekdays, true));

        $firstPickupDateStr = $firstPickupDate->format('Y-m-d');
        if ($firstPickupDateStr >= $startDate && $firstPickupDateStr <= $endDate) {
            $labelsByDate[$firstPickupDateStr][] = $row['name'] . '洗濯代行開始';
        }
    }

    return $labelsByDate;
}

/**
 * 月間シフト表PDFを生成し、そのままダウンロードレスポンスとして出力する（呼び出し元に戻らない想定）。
 * $monthParamは"YYYY-MM"形式。不正な値の場合は当月にフォールバックする。
 */
function render_monthly_shift_pdf(PDO $pdo, string $monthParam, bool $splitByCategory = false): void
{
    if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
        $monthParam = (new DateTime('today'))->format('Y-m');
    }
    [$rangeStartStr, $rangeEndStr] = get_month_range($monthParam);
    [$yearPart, $monthPart] = explode('-', $monthParam);

    // ---- 対象月にシフトが入っている従業員のみを表の対象にする ----
    $employeesStmt = $pdo->prepare(
        "SELECT DISTINCT e.id, e.name
         FROM shifts s
         JOIN employees e ON e.id = s.employee_id
         WHERE s.work_date BETWEEN :start_date AND :end_date
         ORDER BY e.name"
    );
    $employeesStmt->execute([':start_date' => $rangeStartStr, ':end_date' => $rangeEndStr]);
    $employees = $employeesStmt->fetchAll();

    if (empty($employees)) {
        http_response_code(404);
        die('対象月にシフトが登録されている従業員がいません。');
    }

    // ---- シフト取得（同一従業員・同一日に複数件持ちうる） ----
    $shiftsStmt = $pdo->prepare(
        "SELECT employee_id, work_date, start_time, end_time, categories
         FROM shifts
         WHERE work_date BETWEEN :start_date AND :end_date
         ORDER BY employee_id, work_date, start_time"
    );
    $shiftsStmt->execute([':start_date' => $rangeStartStr, ':end_date' => $rangeEndStr]);

    $shiftsByEmployeeDate = [];
    foreach ($shiftsStmt->fetchAll() as $row) {
        $shiftsByEmployeeDate[(int) $row['employee_id']][$row['work_date']][] = $row;
    }

    // ---- 取引先施設の受託開始日（該当日の日付セルに1行だけ表示。既存のシフト表画面と同じデータソース） ----
    $facilityStartLabels = fetch_pdf_facility_start_labels($pdo, $rangeStartStr, $rangeEndStr);

    // ---- 祝日一覧（土日以外の休日判定用） ----
    $holidayDates = fetch_holiday_dates($pdo, $rangeStartStr, $rangeEndStr);

    // ---- 従業員数に応じて列幅・フォントサイズを調整（A4縦1ページに収める） ----
    $employeeCount = count($employees);
    if ($employeeCount <= 6) {
        $bodyFontSizePt = 8;
    } elseif ($employeeCount <= 10) {
        $bodyFontSizePt = 7;
    } elseif ($employeeCount <= 14) {
        $bodyFontSizePt = 6;
    } else {
        $bodyFontSizePt = 5;
    }
    $dateColWidthPercent = 11;
    $employeeColWidthPercent = (100 - $dateColWidthPercent) / $employeeCount;

    $weekdayLabels = ['日', '月', '火', '水', '木', '金', '土'];

    // ---- 日付ごとの行を構築 ----
    $dates = [];
    $cursor = new DateTime($rangeStartStr);
    $rangeEndDate = new DateTime($rangeEndStr);
    while ($cursor <= $rangeEndDate) {
        $dates[] = clone $cursor;
        $cursor->modify('+1 day');
    }

    $renderTable = static function (?string $onlyCategory = null) use (
        $employees,
        $dates,
        $holidayDates,
        $weekdayLabels,
        $facilityStartLabels,
        $shiftsByEmployeeDate
    ): string {
        ob_start();
        ?>
        <table class="shift-table">
            <thead><tr>
                <th class="date-cell">日付</th>
                <?php foreach ($employees as $employee): ?>
                    <th class="emp-col"><?= pdf_esc($employee['name']) ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($dates as $date): ?>
                <?php
                $dateStr = $date->format('Y-m-d');
                $dow = (int) $date->format('w');
                $isHoliday = is_holiday_in_set($dateStr, $holidayDates);
                $rowClass = $dow === 6 ? 'row-sat' : ($isHoliday ? 'row-sun-or-holiday' : '');
                $labelClass = $dow === 6 ? 'is-sat' : ($isHoliday ? 'is-sun-or-holiday' : '');
                $facilityStartEvents = $facilityStartLabels[$dateStr] ?? [];
                ?>
                <tr class="<?= $rowClass ?>">
                    <td class="date-cell">
                        <span class="date-label <?= $labelClass ?>"><?= (int) $date->format('n') ?>/<?= (int) $date->format('j') ?>(<?= $weekdayLabels[$dow] ?>)</span>
                        <?php foreach ($facilityStartEvents as $eventLabel): ?>
                            <span class="facility-start-line"><?= pdf_esc($eventLabel) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <?php foreach ($employees as $employee): ?>
                        <?php
                        $dayShifts = $shiftsByEmployeeDate[(int) $employee['id']][$dateStr] ?? [];
                        if ($onlyCategory !== null) {
                            $dayShifts = array_values(array_filter(
                                $dayShifts,
                                static fn(array $shift): bool => in_array(
                                    $onlyCategory,
                                    categories_from_value($shift['categories']),
                                    true
                                )
                            ));
                        }
                        ?>
                        <td class="emp-col">
                            <?php foreach ($dayShifts as $shift): ?>
                                <?= render_shift_block($shift, $onlyCategory !== null) ?>
                            <?php endforeach; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return (string) ob_get_clean();
    };

    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset="UTF-8">
    <style>
        body { font-family: ipag; font-size: <?= $bodyFontSizePt ?>pt; }
        h1 { font-size: 13pt; text-align: center; margin: 0 0 4pt; }
        table.shift-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.shift-table th, table.shift-table td {
            border: 0.5pt solid #999; padding: 1.5pt 2pt; vertical-align: top; word-break: break-all;
        }
        table.shift-table th { background: #eef2f8; text-align: center; font-weight: normal; }
        td.date-cell { width: <?= $dateColWidthPercent ?>%; white-space: nowrap; }
        th.emp-col, td.emp-col { width: <?= $employeeColWidthPercent ?>%; }
        .date-label { font-weight: normal; }
        .date-label.is-sat { color: #0b5ed7; }
        .date-label.is-sun-or-holiday { color: #d9362e; }
        tr.row-sat td.date-cell { background: #eef6ff; }
        tr.row-sun-or-holiday td.date-cell { background: #fdecea; }
        .facility-start-line {
            display: block; margin-top: 1pt; font-size: 0.85em; font-weight: normal;
            color: #000; background: #fff; padding: 0.5pt 2pt;
        }
        .shift-name { display: block; padding: 2pt 1pt; margin-bottom: 1pt; font-weight: normal; text-align: center; }
        .shift-name:last-child { margin-bottom: 0; }
        .shift-name-pickup { background: #000; color: #fff; font-weight: bold; }
        .shift-name-laundry { background: #e2e2e2; color: #000; font-weight: normal; }
        .shift-name-store { background: #fff; color: #000; font-weight: normal; }
        .shift-name-plain { background: #fff; color: #000; font-weight: normal; }
        h2.category-title { font-size: 11pt; text-align: center; font-weight: normal; margin: 0 0 4pt; }
        .category-page { page-break-before: always; }
        .split-pdf table.shift-table th,
        .split-pdf table.shift-table td,
        .split-pdf tr.row-sat td.date-cell,
        .split-pdf tr.row-sun-or-holiday td.date-cell { background: #fff; color: #000; font-weight: normal; }
        .split-pdf h1,
        .split-pdf .date-label,
        .split-pdf .date-label.is-sat,
        .split-pdf .date-label.is-sun-or-holiday { color: #000; font-weight: normal; }
    </style>
    </head>
    <body class="<?= $splitByCategory ? 'split-pdf' : '' ?>">
    <h1><?= (int) $yearPart ?>年<?= (int) $monthPart ?>月のシフト　<?= pdf_esc(COMPANY_NAME . '　' . STORE_NAME) ?></h1>
    <?php if ($splitByCategory): ?>
        <?php foreach (['店舗', '洗濯代行', '集荷'] as $categoryIndex => $category): ?>
            <section class="<?= $categoryIndex > 0 ? 'category-page' : '' ?>">
                <h2 class="category-title"><?= pdf_esc($category) ?>シフト</h2>
                <?= $renderTable($category) ?>
            </section>
        <?php endforeach; ?>
    <?php else: ?>
        <?= $renderTable(null) ?>
    <?php endif; ?>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    $defaultConfigVars = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $fontDirs = $defaultConfigVars['fontDir'];
    $defaultFontVars = (new \Mpdf\Config\FontVariables())->getDefaults();
    $fontData = $defaultFontVars['fontdata'];

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-P',
        'margin_left' => 8,
        'margin_right' => 8,
        'margin_top' => 8,
        'margin_bottom' => 8,
        'margin_header' => 0,
        'margin_footer' => 0,
        'fontDir' => array_merge($fontDirs, [__DIR__ . '/fonts']),
        'fontdata' => $fontData + [
            'ipag' => ['R' => 'ipag.ttf'],
        ],
        'default_font' => 'ipag',
    ]);

    $mpdf->WriteHTML($html);

    $filename = sprintf(
        $splitByCategory ? '区分別シフト表_%s-%s_%s_%s.pdf' : 'シフト表_%s-%s_%s_%s.pdf',
        $rangeStartStr,
        $rangeEndStr,
        COMPANY_NAME,
        STORE_NAME
    );

    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
}
