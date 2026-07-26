<?php
// 集荷・配送記録簿PDFの生成ロジック（admin/collection_records_pdf.phpの共通処理）。
// mPDF・日本語フォントの設定はincludes/shifts_pdf_render.phpと同じ構成を踏襲する。
// 認証チェック（require_login）は呼び出し元で行い、このファイルはPDF内容にのみ責任を持つ。

require_once __DIR__ . '/../vendor/autoload.php';

function ccpdf_esc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ccpdf_date_label(?string $dateStr): string
{
    if ($dateStr === null || $dateStr === '') {
        return '';
    }
    $weekdayLabels = ['日', '月', '火', '水', '木', '金', '土'];
    $date = new DateTime($dateStr);
    return (int) $date->format('n') . '/' . (int) $date->format('j') . '(' . $weekdayLabels[(int) $date->format('w')] . ')';
}

function ccpdf_time_label(?string $timeStr): string
{
    return $timeStr === null ? '' : substr($timeStr, 0, 5);
}

function ccpdf_bag_count_label(?int $count): string
{
    return $count === null ? '' : $count . '袋';
}

/**
 * 施設×対象月の集荷・配送記録簿PDFを生成し、そのままダウンロードレスポンスとして出力する（呼び出し元に戻らない想定）。
 * $monthParamは"YYYY-MM"形式。不正な値の場合は当月にフォールバックする。
 */
function render_collection_records_pdf(PDO $pdo, int $facilityId, string $monthParam): void
{
    if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
        $monthParam = (new DateTime('today'))->format('Y-m');
    }
    [$rangeStartStr, $rangeEndStr] = get_month_range($monthParam);
    [$yearPart, $monthPart] = explode('-', $monthParam);

    $facilityStmt = $pdo->prepare('SELECT id, name FROM facilities WHERE id = :id');
    $facilityStmt->execute([':id' => $facilityId]);
    $facility = $facilityStmt->fetch();

    if ($facility === false) {
        http_response_code(404);
        die('対象の施設が見つかりません。');
    }

    $recordsStmt = $pdo->prepare(
        'SELECT cc.*,
                pe.name AS pickup_employee_name,
                ae.name AS arrival_employee_name,
                de.name AS dispatch_employee_name,
                re.name AS return_employee_name
         FROM collection_cycles cc
         LEFT JOIN employees pe ON pe.id = cc.pickup_employee_id
         LEFT JOIN employees ae ON ae.id = cc.arrival_employee_id
         LEFT JOIN employees de ON de.id = cc.dispatch_employee_id
         LEFT JOIN employees re ON re.id = cc.return_employee_id
         WHERE cc.facility_id = :facility_id AND cc.pickup_date BETWEEN :start_date AND :end_date
         ORDER BY cc.pickup_date ASC, cc.id ASC'
    );
    $recordsStmt->execute([
        ':facility_id' => $facilityId,
        ':start_date' => $rangeStartStr,
        ':end_date' => $rangeEndStr,
    ]);
    $records = $recordsStmt->fetchAll();

    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset="UTF-8">
    <style>
        body { font-family: ipag; font-size: 8pt; }
        h1 { font-size: 13pt; text-align: center; margin: 0 0 4pt; }
        h2 { font-size: 10pt; text-align: center; margin: 0 0 8pt; font-weight: normal; }
        table.record-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.record-table th, table.record-table td {
            border: 0.5pt solid #999; padding: 2pt 3pt; text-align: center; vertical-align: middle; word-break: break-all;
        }
        table.record-table th { background: #eef2f8; font-weight: bold; }
        col.col-date { width: 8%; }
        col.col-count { width: 6.5%; }
        col.col-time { width: 6%; }
        col.col-person { width: 7%; }
        col.col-remarks { width: 10%; }
    </style>
    </head>
    <body>
    <h1>集荷・配送記録簿</h1>
    <h2><?= ccpdf_esc($facility['name']) ?>　<?= (int) $yearPart ?>年<?= (int) $monthPart ?>月分</h2>

    <table class="record-table">
        <colgroup>
            <col class="col-date">
            <col class="col-count"><col class="col-time"><col class="col-person">
            <col class="col-count"><col class="col-time"><col class="col-person">
            <col class="col-count"><col class="col-date"><col class="col-time"><col class="col-person">
            <col class="col-count"><col class="col-time"><col class="col-person">
            <col class="col-remarks">
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2">集荷日</th>
                <th colspan="3">集荷</th>
                <th colspan="3">クリーニング所到着</th>
                <th colspan="4">クリーニング所発送</th>
                <th colspan="3">返却</th>
                <th rowspan="2">備考</th>
            </tr>
            <tr>
                <th>リネン袋数</th><th>時間</th><th>担当者</th>
                <th>リネン袋数</th><th>時間</th><th>担当者</th>
                <th>リネン袋数</th><th>発送日</th><th>時間</th><th>担当者</th>
                <th>リネン袋数</th><th>時間</th><th>担当者</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($records)): ?>
                <tr><td colspan="15">対象月の記録はありません。</td></tr>
            <?php endif; ?>
            <?php foreach ($records as $record): ?>
                <tr>
                    <td><?= ccpdf_esc(ccpdf_date_label($record['pickup_date'])) ?></td>
                    <td><?= ccpdf_esc(ccpdf_bag_count_label($record['pickup_bag_count'] !== null ? (int) $record['pickup_bag_count'] : null)) ?></td>
                    <td><?= ccpdf_esc(ccpdf_time_label($record['pickup_time'])) ?></td>
                    <td><?= ccpdf_esc($record['pickup_employee_name'] ?? '') ?></td>
                    <td><?= ccpdf_esc(ccpdf_bag_count_label($record['arrival_bag_count'] !== null ? (int) $record['arrival_bag_count'] : null)) ?></td>
                    <td><?= ccpdf_esc(ccpdf_time_label($record['arrival_time'])) ?></td>
                    <td><?= ccpdf_esc($record['arrival_employee_name'] ?? '') ?></td>
                    <td><?= ccpdf_esc(ccpdf_bag_count_label($record['dispatch_bag_count'] !== null ? (int) $record['dispatch_bag_count'] : null)) ?></td>
                    <td><?= ccpdf_esc(ccpdf_date_label($record['dispatch_date'])) ?></td>
                    <td><?= ccpdf_esc(ccpdf_time_label($record['dispatch_time'])) ?></td>
                    <td><?= ccpdf_esc($record['dispatch_employee_name'] ?? '') ?></td>
                    <td><?= ccpdf_esc(ccpdf_bag_count_label($record['return_bag_count'] !== null ? (int) $record['return_bag_count'] : null)) ?></td>
                    <td><?= ccpdf_esc(ccpdf_time_label($record['return_time'])) ?></td>
                    <td><?= ccpdf_esc($record['return_employee_name'] ?? '') ?></td>
                    <td><?= ccpdf_esc($record['remarks'] ?? '') ?></td>
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
        'format' => 'A4-L',
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

    $filename = sprintf('集荷配送記録簿_%s_%s.pdf', $facility['name'], $monthParam);

    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
}
