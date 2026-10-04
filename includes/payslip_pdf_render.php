<?php
/**
 * 給与明細PDF（弥生給与の「給与明細書」と同じレイアウト。A5横・1人1ページ）。
 * admin/payroll_slip.php?id=◯&format=pdf（個別）と ?run_id=◯&format=pdf（給与計算の回の全員分、氏名順）から呼ばれる。
 * includes/payroll.php を読み込んだあとで使うこと。
 *
 * レイアウト: 左上の枠（タイトル・会社名・氏名・所属）、右上（支給日・受領印）、
 *             4列の表（勤怠／支給／控除／その他。見出し行と項目名はグレー背景）、最下部に備考枠。
 * タイトルは弥生と同じく支給月で表示する（2026年9月勤務・10月支給 →「2026年10月分給与」）。
 */

require_once __DIR__ . '/../vendor/autoload.php';

const PAYSLIP_PDF_BODY_ROWS = 17; // 各列の見出し行より下の行数（4列の下端をそろえる）

function payslip_pdf_esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** 分 → 「40:55」 */
function payslip_pdf_hours(int $minutes): string
{
    return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
}

/** 明細の社員コード・支払方法・所属・甲乙・扶養人数（計算時のスナップショット。古い明細で無い項目は現在の設定） */
function payslip_pdf_profile(PDO $pdo, array $slip): array
{
    $snapshot = json_decode((string) $slip['employee_snapshot'], true) ?: [];
    if (!array_key_exists('payment_method', $snapshot)) {
        $stmt = $pdo->prepare('SELECT employee_code, payment_method, department FROM pay_employees WHERE employee_id = :id');
        $stmt->execute([':id' => $slip['employee_id']]);
        $snapshot += $stmt->fetch() ?: [];
    }
    $terms = $snapshot['terms'] ?? null;
    return [
        'name' => (string) ($snapshot['name'] ?? $slip['current_name'] ?? ''),
        'employee_code' => (string) ($snapshot['employee_code'] ?? ''),
        'payment_method' => ($snapshot['payment_method'] ?? 'cash') === 'bank' ? 'bank' : 'cash',
        'department' => (string) ($snapshot['department'] ?? ''),
        'tax_column' => $terms === null ? '' : ($terms['tax_column'] === 'otsu' ? '乙欄' : '甲欄'),
        'dependents' => $terms === null ? '' : (string) (int) $terms['dependents'],
    ];
}

/** 支給年月（YYYYMM）。ファイル名用 */
function payslip_pdf_pay_month_code(string $payDate): string
{
    return substr($payDate, 0, 4) . substr($payDate, 5, 2);
}

/** 備考枠の文（社保の対象月、勤怠調整の理由、通勤手当の課税分、明細の備考） */
function payslip_pdf_remarks(array $slip): array
{
    $remarks = [];
    $siNote = pay_slip_si_note($slip);
    if ($siNote !== '') {
        $remarks[] = $siNote;
    }
    if ((int) $slip['attendance_adjust'] !== 0) {
        $remarks[] = '勤怠調整 ' . number_format((int) $slip['attendance_adjust']) . '円：' . (string) $slip['attendance_adjust_reason'];
    }
    $parkingTaxable = (int) $slip['commute_taxable'] - pay_slip_commute_split($slip)['commute_taxable'];
    if ($parkingTaxable > 0) {
        $remarks[] = '駐車場代のうち ' . number_format($parkingTaxable) . '円は非課税限度額を超えるため課税';
    }
    if (!empty($slip['note'])) {
        $remarks[] = (string) $slip['note'];
    }
    return $remarks;
}

/**
 * 1列分の表（見出し＋本文行）。$rows の各要素は次のいずれか:
 *   ['label' => 項目名, 'value' => 表示値]                 通常の行（項目名グレー・値は右寄せ）
 *   ['label' => 項目名, 'value' => 表示値, 'total' => true] 合計行（項目名を左右に割り付け）
 *   ['heading' => 見出し]                                  列内の見出し行（グレー・2列結合）
 *   ['spacer' => 高さmm]                                   罫線なしの間隔
 */
function payslip_pdf_column(string $heading, array $rows): string
{
    $html = '<table class="col"><tr><td class="head" colspan="2">' . $heading . '</td></tr>';
    foreach ($rows as $row) {
        if (isset($row['spacer'])) {
            $html .= '<tr><td class="spacer" colspan="2" style="height:' . $row['spacer'] . 'mm;"></td></tr>';
        } elseif (isset($row['heading'])) {
            $html .= '<tr><td class="head" colspan="2">' . $row['heading'] . '</td></tr>';
        } else {
            $label = !empty($row['total']) ? '<table class="split"><tr><td>' . payslip_pdf_esc(mb_substr($row['label'], 0, 1)) . '</td><td class="r">' . payslip_pdf_esc(mb_substr($row['label'], -1)) . '</td></tr></table>' : payslip_pdf_esc($row['label']);
            $html .= '<tr><td class="label">' . $label . '</td><td class="value">' . payslip_pdf_esc($row['value']) . '</td></tr>';
        }
    }
    return $html . '</table>';
}

/** 行の配列を $count 行まで空行で埋める（$count を超える場合はそのまま） */
function payslip_pdf_pad(array $rows, int $count): array
{
    while (count($rows) < $count) {
        $rows[] = ['label' => '', 'value' => ''];
    }
    return $rows;
}

/** 明細1人分のHTML（1ページ） */
function payslip_pdf_page_html(PDO $pdo, array $slip, array $settings): string
{
    $profile = payslip_pdf_profile($pdo, $slip);
    $isOfficer = ($slip['employment_type'] ?? 'employee') === 'officer';
    $payYear = (int) substr($slip['pay_date'], 0, 4);
    $payMonth = (int) substr($slip['pay_date'], 5, 2);
    $payDay = (int) substr($slip['pay_date'], 8, 2);
    $title = $payYear . '年' . $payMonth . '月分' . ($isOfficer ? '役員報酬' : '給与');
    $yen = static fn (int $amount): string => number_format($amount);

    // ① 勤怠（役員は空欄）
    $attendanceItems = [
        ['出勤日数', number_format((int) $slip['work_days'], 2)],
        ['実働時間', payslip_pdf_hours((int) $slip['minutes_total'])],
        ['洗濯代行時間', payslip_pdf_hours((int) $slip['minutes_laundry'])],
        ['店舗時間', payslip_pdf_hours((int) $slip['minutes_store'])],
        ['集荷時間', payslip_pdf_hours((int) $slip['minutes_pickup'])],
        ['休日勤務時間', payslip_pdf_hours((int) $slip['minutes_holiday'])],
        ['普通残業時間', payslip_pdf_hours(pay_slip_weekday_overtime_minutes($slip))],
        ['休日残業時間', payslip_pdf_hours((int) $slip['minutes_overtime_holiday'])],
        ['深夜時間', payslip_pdf_hours((int) $slip['minutes_night'])],
    ];
    $attendanceRows = [];
    foreach ($isOfficer ? [] : $attendanceItems as [$label, $value]) {
        $attendanceRows[] = ['label' => $label, 'value' => $value];
    }
    $attendanceRows = payslip_pdf_pad($attendanceRows, PAYSLIP_PDF_BODY_ROWS - 3);
    $attendanceRows[] = ['spacer' => 4.6];
    $attendanceRows[] = ['label' => '税額表', 'value' => $profile['tax_column']];
    $attendanceRows[] = ['label' => '扶養人数', 'value' => $profile['dependents']];

    // ② 支給
    $paymentRows = [];
    foreach (pay_slip_payment_lines($slip) as [$label, $amount]) {
        $paymentRows[] = ['label' => $label, 'value' => $yen($amount)];
    }
    $paymentRows = payslip_pdf_pad($paymentRows, PAYSLIP_PDF_BODY_ROWS - 1);
    $paymentRows[] = ['label' => '合計', 'value' => $yen((int) $slip['gross_total']), 'total' => true];

    // ③ 控除
    $deductionRows = [];
    foreach (pay_slip_deduction_lines($slip) as [$label, $amount]) {
        $deductionRows[] = ['label' => $label, 'value' => $yen($amount)];
    }
    $deductionRows = payslip_pdf_pad($deductionRows, PAYSLIP_PDF_BODY_ROWS - 1);
    $deductionRows[] = ['label' => '合計', 'value' => $yen((int) $slip['deduction_total']), 'total' => true];

    // ④ その他（年末調整還付は今は常に0。差引支給額を支払方法に応じて振込／現金に出す）
    $netPay = (int) $slip['net_pay'];
    $bankPay = $profile['payment_method'] === 'bank' ? $netPay : 0;
    $cashPay = $profile['payment_method'] === 'cash' ? $netPay : 0;
    $otherRows = [
        ['label' => '年末調整還付', 'value' => '0'],
        ['label' => '', 'value' => ''],
        ['label' => '', 'value' => ''],
        ['label' => '合計', 'value' => '0', 'total' => true],
        ['spacer' => 2.3],
        ['label' => '差引支給額', 'value' => $yen($netPay)],
        ['spacer' => 2.3],
        ['heading' => '振　込　支　給　額'],
        ['label' => '振込支給合計', 'value' => $yen($bankPay)],
        ['label' => '', 'value' => ''],
        ['label' => '', 'value' => ''],
        ['label' => '合計', 'value' => $yen($bankPay), 'total' => true],
        ['spacer' => 2.3],
        ['label' => '現金支給額', 'value' => $yen($cashPay)],
        ['spacer' => 2.3],
        ['label' => '現物支給額', 'value' => '0'],
        ['spacer' => 2.3],
        ['label' => '', 'value' => ''],
        ['label' => '', 'value' => ''],
    ];

    $remarks = payslip_pdf_remarks($slip);
    $nameLine = ($profile['employee_code'] !== '' ? '(' . $profile['employee_code'] . ')　' : '') . $profile['name'] . '　様';

    ob_start();
    ?>
<table class="top">
    <tr>
        <td class="titlebox">
            <table class="titlerow"><tr><td class="title"><?= payslip_pdf_esc($title) ?></td><td class="title r">明細書</td></tr></table>
            <div class="company"><?= payslip_pdf_esc($settings['company_name']) ?></div>
            <table class="who">
                <tr><td class="k">氏　名</td><td><?= payslip_pdf_esc($nameLine) ?></td></tr>
                <tr><td class="k">所　属</td><td><?= payslip_pdf_esc($profile['department']) ?></td></tr>
            </table>
        </td>
        <td class="paydate"><table class="paydate-line"><tr><td>支給日</td><td class="r"><?= $payYear ?>年<?= $payMonth ?>月<?= sprintf('%02d', $payDay) ?>日</td></tr></table></td>
        <td class="stampcell">
            <table class="stamp"><tr><td></td></tr></table>
            <div class="stamplabel">受領印</div>
        </td>
    </tr>
</table>
<table class="cols">
    <tr>
        <td class="c"><?= payslip_pdf_column('<table class="split"><tr><td>勤</td><td class="r">怠</td></tr></table>', $attendanceRows) ?></td>
        <td class="gap"></td>
        <td class="c"><?= payslip_pdf_column('<table class="split"><tr><td>支</td><td class="r">給</td></tr></table>', $paymentRows) ?></td>
        <td class="gap"></td>
        <td class="c"><?= payslip_pdf_column('<table class="split"><tr><td>控</td><td class="r">除</td></tr></table>', $deductionRows) ?></td>
        <td class="gap"></td>
        <td class="c"><?= payslip_pdf_column('<table class="split"><tr><td>そ</td><td class="m">の</td><td class="r">他</td></tr></table>', $otherRows) ?></td>
    </tr>
</table>
<table class="remarks"><tr><td><?= implode('<br>', array_map('payslip_pdf_esc', $remarks)) ?></td></tr></table>
    <?php
    return (string) ob_get_clean();
}

function payslip_pdf_css(): string
{
    return '
        body { font-family: ipag; font-size: 8.5pt; color: #000; }
        table { border-collapse: collapse; }
        td { padding: 0; vertical-align: middle; }
        td.r { text-align: right; }
        td.m { text-align: center; }
        table.top { width: 198mm; }
        td.titlebox { width: 112mm; height: 27mm; border: 0.25mm solid #000; padding: 2mm 4mm 1.5mm 8mm; vertical-align: top; }
        table.titlerow { width: 82mm; }
        td.title { font-size: 13pt; font-weight: bold; }
        div.company { margin: 1.5mm 0 1mm 4mm; font-size: 9pt; }
        table.who td { font-size: 9pt; padding: 0.3mm 0; }
        table.who td.k { width: 18mm; }
        td.paydate { width: 62mm; vertical-align: bottom; padding: 0 3mm 0.8mm 6mm; }
        table.paydate-line { width: 53mm; border-bottom: 0.25mm solid #000; font-size: 9pt; }
        td.stampcell { width: 24mm; vertical-align: top; text-align: right; }
        table.stamp { width: 18mm; border: 0.25mm solid #000; margin-left: 6mm; }
        table.stamp td { height: 18mm; }
        div.stamplabel { text-align: right; font-size: 8.5pt; margin-top: 3mm; }
        table.cols { width: 198mm; margin-top: 4mm; }
        td.c { width: 48mm; vertical-align: top; }
        td.gap { width: 2mm; }
        table.col { width: 48mm; }
        table.col td { height: 4.4mm; }
        table.col td.head { background: #cccccc; border: 0.25mm solid #000; padding: 0 1mm; }
        table.col td.label { width: 25mm; background: #cccccc; border: 0.25mm solid #000; padding: 0 0.8mm; white-space: nowrap; }
        table.col td.value { width: 23mm; border: 0.25mm solid #000; padding: 0 0.8mm; text-align: right; white-space: nowrap; }
        table.col td.spacer { border: none; padding: 0; }
        table.split { width: 100%; }
        table.split td { height: auto; border: none; background: transparent; padding: 0; }
        table.remarks { width: 198mm; margin-top: 4mm; }
        table.remarks td { height: 13mm; border: 0.25mm solid #000; padding: 1mm 2mm; vertical-align: top; font-size: 8pt; }
    ';
}

/**
 * 明細をPDFで出力する（1人1ページ）。$destination は \Mpdf\Output\Destination の定数（既定はブラウザに表示）。
 * 下書きは「下書き」、取消済みは「取消」の透かしを入れる。
 */
function render_payslip_pdf(PDO $pdo, array $slips, array $settings, string $filename, string $destination = \Mpdf\Output\Destination::INLINE): ?string
{
    $defaultConfigVars = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $fontDirs = $defaultConfigVars['fontDir'];
    $defaultFontVars = (new \Mpdf\Config\FontVariables())->getDefaults();
    $fontData = $defaultFontVars['fontdata'];

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A5-L',
        'margin_left' => 6,
        'margin_right' => 6,
        'margin_top' => 7,
        'margin_bottom' => 5,
        'margin_header' => 0,
        'margin_footer' => 0,
        'fontDir' => array_merge($fontDirs, [__DIR__ . '/fonts']),
        'fontdata' => $fontData + [
            'ipag' => ['R' => 'ipag.ttf'],
        ],
        'default_font' => 'ipag',
        'shrink_tables_to_fit' => 0,
    ]);
    $mpdf->SetTitle(pathinfo($filename, PATHINFO_FILENAME));
    $mpdf->WriteHTML(payslip_pdf_css(), \Mpdf\HTMLParserMode::HEADER_CSS);

    $status = $slips[0]['status'] ?? 'closed';
    if ($status !== 'closed') {
        $mpdf->SetWatermarkText($status === 'void' ? '取消' : '下書き', 0.08);
        $mpdf->showWatermarkText = true;
    }

    foreach (array_values($slips) as $index => $slip) {
        if ($index > 0) {
            $mpdf->AddPage();
        }
        $mpdf->WriteHTML(payslip_pdf_page_html($pdo, $slip, $settings), \Mpdf\HTMLParserMode::HTML_BODY);
    }

    return $mpdf->Output($filename, $destination);
}
