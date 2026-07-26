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
function render_shift_block(array $shift): string
{
    $timeLabel = substr($shift['start_time'], 0, 5) . '〜' . substr($shift['end_time'], 0, 5);
    $categories = array_values(array_intersect(SHIFT_CATEGORIES, categories_from_value($shift['categories'])));

    $tagsHtml = '';
    foreach ($categories as $category) {
        $color = CATEGORY_COLORS[$category] ?? CATEGORY_COLOR_NONE;
        $tagsHtml .= '<span class="cat-tag" style="background:' . $color . ';">' . pdf_esc($category) . '</span>';
    }

    return '<div class="shift-block"><div class="shift-time">' . pdf_esc($timeLabel) . '</div>' . $tagsHtml . '</div>';
}

/**
 * 月間シフト表PDFを生成し、そのままダウンロードレスポンスとして出力する（呼び出し元に戻らない想定）。
 * $monthParamは"YYYY-MM"形式。不正な値の場合は当月にフォールバックする。
 */
function render_monthly_shift_pdf(PDO $pdo, string $monthParam): void
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
    $facilityOnboardingLabels = fetch_facility_onboarding_labels($pdo, $rangeStartStr, $rangeEndStr);

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

    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset="UTF-8">
    <style>
        body { font-family: ipag; font-size: <?= $bodyFontSizePt ?>pt; }
        h1 { font-size: 13pt; text-align: center; margin: 0 0 4pt; }
        .legend { text-align: center; font-size: 7pt; margin-bottom: 6pt; }
        .legend .cat-tag { margin: 0 3pt; }
        table.shift-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.shift-table th, table.shift-table td {
            border: 0.5pt solid #999; padding: 1.5pt 2pt; vertical-align: top; word-break: break-all;
        }
        table.shift-table th { background: #eef2f8; text-align: center; font-weight: bold; }
        td.date-cell { width: <?= $dateColWidthPercent ?>%; white-space: nowrap; }
        th.emp-col, td.emp-col { width: <?= $employeeColWidthPercent ?>%; }
        .date-label { font-weight: bold; }
        .date-label.is-sat { color: #0b5ed7; }
        .date-label.is-sun-or-holiday { color: #d9362e; }
        tr.row-sat td.date-cell { background: #eef6ff; }
        tr.row-sun-or-holiday td.date-cell { background: #fdecea; }
        .onboarding-line {
            display: block; margin-top: 1pt; font-size: 0.85em; font-weight: bold;
            color: #fff; background: #ff8f00; padding: 0.5pt 2pt;
        }
        .shift-block { margin-bottom: 2pt; padding-bottom: 2pt; border-bottom: 0.5pt dotted #ccc; }
        .shift-block:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
        .shift-time { white-space: nowrap; }
        .cat-tag {
            display: inline-block; color: #fff; font-size: 0.8em; font-weight: bold;
            padding: 0.5pt 3pt; border-radius: 2pt; margin-top: 1pt;
        }
    </style>
    </head>
    <body>
    <h1><?= (int) $yearPart ?>年<?= (int) $monthPart ?>月のシフト　<?= pdf_esc(COMPANY_NAME . '　' . STORE_NAME) ?></h1>
    <div class="legend">
        <?php foreach (SHIFT_CATEGORIES as $category): ?>
            <span class="cat-tag" style="background:<?= CATEGORY_COLORS[$category] ?? CATEGORY_COLOR_NONE ?>;"><?= pdf_esc($category) ?></span>
        <?php endforeach; ?>
    </div>

    <table class="shift-table">
        <thead>
            <tr>
                <th class="date-cell">日付</th>
                <?php foreach ($employees as $employee): ?>
                    <th class="emp-col"><?= pdf_esc($employee['name']) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dates as $date): ?>
                <?php
                $dateStr = $date->format('Y-m-d');
                $dow = (int) $date->format('w');
                $isHoliday = is_holiday_in_set($dateStr, $holidayDates);
                $rowClass = $dow === 6 ? 'row-sat' : ($isHoliday ? 'row-sun-or-holiday' : '');
                $labelClass = $dow === 6 ? 'is-sat' : ($isHoliday ? 'is-sun-or-holiday' : '');
                $onboardingFacilities = $facilityOnboardingLabels[$dateStr] ?? [];
                ?>
                <tr class="<?= $rowClass ?>">
                    <td class="date-cell">
                        <span class="date-label <?= $labelClass ?>"><?= (int) $date->format('n') ?>/<?= (int) $date->format('j') ?>(<?= $weekdayLabels[$dow] ?>)</span>
                        <?php foreach ($onboardingFacilities as $facilityName): ?>
                            <span class="onboarding-line"><?= pdf_esc($facilityName) ?>　受託開始</span>
                        <?php endforeach; ?>
                    </td>
                    <?php foreach ($employees as $employee): ?>
                        <?php $dayShifts = $shiftsByEmployeeDate[(int) $employee['id']][$dateStr] ?? []; ?>
                        <td class="emp-col">
                            <?php foreach ($dayShifts as $shift): ?>
                                <?= render_shift_block($shift) ?>
                            <?php endforeach; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
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
        'シフト表_%s-%s_%s_%s.pdf',
        $rangeStartStr,
        $rangeEndStr,
        COMPANY_NAME,
        STORE_NAME
    );

    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
}
