<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll.php';

$admin = require_login('admin');
$pdo = getPdo();

$settings = pay_settings($pdo);
$slipId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$runId = isset($_GET['run_id']) ? (int) $_GET['run_id'] : 0;

$sql = 'SELECT s.*, e.name AS current_name, r.work_month, r.period_start, r.period_end, r.pay_date, r.status
        FROM pay_slips s JOIN pay_runs r ON r.id = s.run_id JOIN employees e ON e.id = s.employee_id ';
if ($slipId > 0) {
    $stmt = $pdo->prepare($sql . 'WHERE s.id = :id');
    $stmt->execute([':id' => $slipId]);
} else {
    $stmt = $pdo->prepare($sql . 'WHERE s.run_id = :run_id ORDER BY s.employee_id');
    $stmt->execute([':run_id' => $runId]);
}
$slips = $stmt->fetchAll();

function jp_date(string $date): string
{
    $d = new DateTime($date);
    return $d->format('Y') . '年' . (int) $d->format('n') . '月' . (int) $d->format('j') . '日';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>給与明細 | 管理者</title>
    <style>
        @page { size: A4; margin: 14mm; }
        body { font-family: sans-serif; color: #111; margin: 16px; }
        .toolbar { margin-bottom: 12px; }
        .slip { max-width: 180mm; margin: 0 auto 24px; padding: 8mm; border: 1px solid #bbb; page-break-after: always; break-after: page; }
        .slip:last-of-type { page-break-after: auto; break-after: auto; }
        .slip h1 { font-size: 1.4em; text-align: center; letter-spacing: 0.3em; margin: 0 0 6mm; }
        .head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 5mm; gap: 8mm; }
        .head .name { font-size: 1.25em; border-bottom: 1px solid #111; padding: 0 2mm 1mm; }
        .head .meta { text-align: right; font-size: 0.9em; line-height: 1.6; }
        .stamp { display: inline-block; border: 2px solid #b3261e; color: #b3261e; padding: 1mm 3mm; font-weight: bold; margin-bottom: 3mm; }
        .cols { display: flex; gap: 5mm; align-items: flex-start; }
        .cols > div { flex: 1; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4mm; font-size: 0.9em; }
        th, td { border: 1px solid #555; padding: 1.5mm 2mm; }
        th { background: #eee; text-align: left; font-weight: normal; }
        td.num { text-align: right; white-space: nowrap; }
        tr.total td, tr.total th { font-weight: bold; background: #f6f6f6; }
        .net { border: 2px solid #111; padding: 3mm; display: flex; justify-content: space-between; font-size: 1.2em; font-weight: bold; margin-top: 2mm; }
        .note { font-size: 0.85em; margin-top: 3mm; white-space: pre-wrap; }
        .small { font-size: 0.8em; color: #444; }
        @media print {
            body { margin: 0; }
            .toolbar { display: none; }
            .slip { border: none; padding: 0; margin: 0; max-width: none; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">印刷・PDF保存</button>
    <a href="/admin/payroll.php<?= !empty($slips) ? '?run_id=' . (int) $slips[0]['run_id'] : '' ?>">給与計算に戻る</a>
    <?php if ($settings['company_name'] === ''): ?><span style="color:#b3261e;">会社名が未設定です（給与設定・税額表で入力してください）</span><?php endif; ?>
</div>

<?php if (empty($slips)): ?>
    <p>明細が見つかりません。</p>
<?php endif; ?>

<?php foreach ($slips as $slip): ?>
    <?php
    $snapshot = json_decode((string) $slip['employee_snapshot'], true) ?: [];
    $name = $snapshot['name'] ?? $slip['current_name'];
    [$workYear, $workMonthNumber] = array_map('intval', explode('-', $slip['work_month']));
    $paymentLines = pay_slip_payment_lines($slip);
    $deductionLines = pay_slip_deduction_lines($slip);
    $overtimeMinutes = (int) $slip['minutes_overtime_daily'] + (int) $slip['minutes_overtime_weekly'];
    $isOfficer = $slip['employment_type'] === 'officer';
    if ($isOfficer) {
        // 役員は雇用保険の対象外のため控除欄に出さない
        $deductionLines = array_values(array_filter($deductionLines, static fn (array $line): bool => $line[0] !== '雇用保険料'));
    }
    ?>
    <div class="slip">
        <?php if ($slip['status'] === 'draft'): ?><div class="stamp">下書き（未確定）</div><?php elseif ($slip['status'] === 'void'): ?><div class="stamp">取消済み</div><?php endif; ?>
        <h1><?= $isOfficer ? '役員報酬明細' : '給与明細書' ?></h1>
        <div class="head">
            <div class="name"><?= pay_h($name) ?> 様</div>
            <div class="meta">
                <?= $workYear ?>年<?= $workMonthNumber ?>月分<?php if (!$isOfficer): ?>（計算期間 <?= pay_h(jp_date($slip['period_start'])) ?>〜<?= pay_h(jp_date($slip['period_end'])) ?>）<?php endif; ?><br>
                支給日 <?= pay_h(jp_date($slip['pay_date'])) ?><br>
                <?= pay_h($settings['company_name']) ?>
            </div>
        </div>

        <?php if (!$isOfficer): ?>
        <table>
            <tr><th>出勤日数</th><th>うち土日祝</th><th>労働時間</th><th>洗濯代行</th><th>店舗</th><th>集荷</th><th>時間外</th><th>深夜</th></tr>
            <tr>
                <td class="num"><?= (int) $slip['work_days'] ?>日</td>
                <td class="num"><?= (int) $slip['holiday_work_days'] ?>日</td>
                <td class="num"><?= pay_h(pay_minutes_label((int) $slip['minutes_total'])) ?></td>
                <td class="num"><?= pay_h(pay_minutes_label((int) $slip['minutes_laundry'])) ?></td>
                <td class="num"><?= pay_h(pay_minutes_label((int) $slip['minutes_store'])) ?></td>
                <td class="num"><?= pay_h(pay_minutes_label((int) $slip['minutes_pickup'])) ?></td>
                <td class="num"><?= pay_h(pay_minutes_label($overtimeMinutes)) ?></td>
                <td class="num"><?= pay_h(pay_minutes_label((int) $slip['minutes_night'])) ?></td>
            </tr>
        </table>
        <p class="small">洗濯代行・店舗・集荷の時間は所定内（時間外を除く）。時間外は1日8時間超<?= (int) $slip['minutes_overtime_weekly'] > 0 ? '・週40時間超（うち週' . pay_h(pay_minutes_label((int) $slip['minutes_overtime_weekly'])) . '）' : '' ?>。</p>
        <?php endif; ?>

        <div class="cols">
            <div>
                <table>
                    <tr><th colspan="3">支給</th></tr>
                    <?php foreach ($paymentLines as [$label, $amount, $note]): ?>
                        <tr><td><?= pay_h($label) ?></td><td class="small"><?= pay_h($note) ?></td><td class="num"><?= number_format($amount) ?>円</td></tr>
                    <?php endforeach; ?>
                    <tr class="total"><td colspan="2">総支給額</td><td class="num"><?= number_format((int) $slip['gross_total']) ?>円</td></tr>
                </table>
            </div>
            <div>
                <table>
                    <tr><th colspan="2">控除</th></tr>
                    <?php foreach ($deductionLines as [$label, $amount]): ?>
                        <tr><td><?= pay_h($label) ?></td><td class="num"><?= number_format($amount) ?>円</td></tr>
                    <?php endforeach; ?>
                    <tr class="total"><td>控除合計</td><td class="num"><?= number_format((int) $slip['deduction_total']) ?>円</td></tr>
                </table>
                <table>
                    <tr><td>課税対象額</td><td class="num"><?= number_format((int) $slip['taxable_amount']) ?>円</td></tr>
                    <?php if (!$isOfficer): ?><tr><td>非課税通勤手当</td><td class="num"><?= number_format((int) $slip['commute_nontax']) ?>円</td></tr><?php endif; ?>
                </table>
            </div>
        </div>

        <div class="net"><span>差引支給額</span><span><?= number_format((int) $slip['net_pay']) ?>円</span></div>
        <?php $siNote = pay_slip_si_note($slip); ?>
        <?php if ($siNote !== ''): ?><div class="note"><?= pay_h($siNote) ?></div><?php endif; ?>
        <?php if (!empty($slip['note'])): ?><div class="note">備考: <?= pay_h($slip['note']) ?></div><?php endif; ?>
    </div>
<?php endforeach; ?>
</body>
</html>
