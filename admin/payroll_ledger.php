<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll.php';

$admin = require_login('admin');
$pdo = getPdo();

/**
 * 賃金台帳（労働基準法第108条・施行規則第54条）。従業員ごと・年ごと（支給日の年）に、確定済みの給与を1〜12月で横に並べる。
 * 法定記載事項: 氏名、性別、賃金計算期間、労働日数、労働時間数、時間外労働時間数、休日労働時間数、深夜労働時間数、
 *              基本給・手当その他賃金の種類ごとの額、控除の種類ごとの額。
 * 役員は労働者ではないため賃金台帳には含めず、同じ形式の「役員報酬台帳」（kind=officer）に分ける。
 * どちらに載せるかは明細の計算時点の区分（pay_slips.employment_type）で決める。
 */

$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) (new DateTime())->format('Y');
$employeeId = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;
$isCsv = ($_GET['format'] ?? '') === 'csv';
$ledgerKind = ($_GET['kind'] ?? '') === 'officer' ? 'officer' : 'wage';
$employmentType = $ledgerKind === 'officer' ? 'officer' : 'employee';
$ledgerTitle = $ledgerKind === 'officer' ? '役員報酬台帳' : '賃金台帳';

$employeeOptions = $pdo->prepare(
    "SELECT DISTINCT e.id, e.name
     FROM employees e
     LEFT JOIN pay_employees p ON p.employee_id = e.id
     LEFT JOIN pay_slips s ON s.employee_id = e.id AND s.employment_type = :type
     LEFT JOIN pay_runs r ON r.id = s.run_id AND r.status = 'closed' AND YEAR(r.pay_date) = :year
     WHERE r.id IS NOT NULL
        OR (COALESCE(p.payroll_enabled, 1) = 1 AND e.status <> 'disabled' AND COALESCE(p.employment_type, 'employee') = :type2)
     ORDER BY e.id"
);
$employeeOptions->execute([':type' => $employmentType, ':year' => $year, ':type2' => $employmentType]);
$employeeOptions = $employeeOptions->fetchAll();
if (!in_array($employeeId, array_map(static fn (array $o): int => (int) $o['id'], $employeeOptions), true)) {
    $employeeId = 0;
}

$employee = null;
$slipsByMonth = [];
if ($employeeId > 0) {
    $stmt = $pdo->prepare('SELECT e.id, e.name, p.gender FROM employees e LEFT JOIN pay_employees p ON p.employee_id = e.id WHERE e.id = :id');
    $stmt->execute([':id' => $employeeId]);
    $employee = $stmt->fetch() ?: null;

    $stmt = $pdo->prepare(
        "SELECT s.*, r.work_month, r.period_start, r.period_end, r.pay_date
         FROM pay_slips s JOIN pay_runs r ON r.id = s.run_id
         WHERE s.employee_id = :employee_id AND s.employment_type = :type AND r.status = 'closed' AND YEAR(r.pay_date) = :year
         ORDER BY r.pay_date, r.id"
    );
    $stmt->execute([':employee_id' => $employeeId, ':type' => $employmentType, ':year' => $year]);
    foreach ($stmt->fetchAll() as $slip) {
        $slipsByMonth[(int) substr($slip['pay_date'], 5, 2)][] = $slip;
    }
}

/**
 * 台帳の行定義: [ラベル, 種類(text|minutes|days|yen), 値の取り出し関数]
 * 同じ支給月に確定済みの明細が複数ある場合（取消→再確定ではなく別の回が確定している場合）は合算する。
 */
$allowanceNames = [];
foreach ($slipsByMonth as $slips) {
    foreach ($slips as $slip) {
        foreach (json_decode((string) $slip['allowance_detail'], true) ?: [] as $allowance) {
            $allowanceNames[$allowance['name']] = true;
        }
    }
}
$allowanceAmount = static function (array $slip, string $name): int {
    $sum = 0;
    foreach (json_decode((string) $slip['allowance_detail'], true) ?: [] as $allowance) {
        if ($allowance['name'] === $name) {
            $sum += (int) $allowance['amount'];
        }
    }
    return $sum;
};

$officerRows = [
    ['支給日', 'text', static fn (array $s): string => $s['pay_date']],
    ['役員報酬', 'yen', static fn (array $s): int => (int) $s['pay_officer']],
    ['その他課税支給', 'yen', static fn (array $s): int => (int) $s['other_taxable']],
    ['その他非課税支給', 'yen', static fn (array $s): int => (int) $s['other_nontax']],
    ['総支給額', 'yen', static fn (array $s): int => (int) $s['gross_total']],
    ['健康保険料', 'yen', static fn (array $s): int => (int) $s['si_health']],
    ['介護保険料', 'yen', static fn (array $s): int => (int) $s['si_care']],
    ['子ども・子育て支援金', 'yen', static fn (array $s): int => (int) $s['si_child_support']],
    ['厚生年金保険料', 'yen', static fn (array $s): int => (int) $s['si_pension']],
    ['課税対象額', 'yen', static fn (array $s): int => (int) $s['taxable_amount']],
    ['源泉所得税', 'yen', static fn (array $s): int => (int) $s['withholding_tax']],
    ['住民税', 'yen', static fn (array $s): int => (int) $s['resident_tax']],
    ['その他控除', 'yen', static fn (array $s): int => (int) $s['other_deduction']],
    ['控除合計', 'yen', static fn (array $s): int => (int) $s['deduction_total']],
    ['差引支給額', 'yen', static fn (array $s): int => (int) $s['net_pay']],
];

$rows = [
    ['支給日', 'text', static fn (array $s): string => $s['pay_date']],
    ['賃金計算期間', 'text', static fn (array $s): string => substr($s['period_start'], 5) . '〜' . substr($s['period_end'], 5)],
    ['労働日数', 'days', static fn (array $s): int => (int) $s['work_days']],
    ['労働時間数', 'minutes', static fn (array $s): int => (int) $s['minutes_total']],
    ['時間外労働時間数', 'minutes', static fn (array $s): int => (int) $s['minutes_overtime_daily'] + (int) $s['minutes_overtime_weekly']],
    ['休日労働時間数', 'minutes', static fn (array $s): int => 0],
    ['深夜労働時間数', 'minutes', static fn (array $s): int => (int) $s['minutes_night']],
    ['基本給（洗濯代行）', 'yen', static fn (array $s): int => (int) $s['pay_laundry']],
    ['基本給（店舗）', 'yen', static fn (array $s): int => (int) $s['pay_store']],
    ['基本給（集荷）', 'yen', static fn (array $s): int => (int) $s['pay_pickup']],
    ['時間外手当', 'yen', static fn (array $s): int => (int) $s['pay_overtime']],
    ['深夜手当', 'yen', static fn (array $s): int => (int) $s['pay_night']],
];
foreach (array_keys($allowanceNames) as $name) {
    $rows[] = [$name, 'yen', static fn (array $s): int => $allowanceAmount($s, $name)];
}
$rows = array_merge($rows, [
    ['交通費', 'yen', static fn (array $s): int => (int) $s['commute_total']],
    ['駐車場代', 'yen', static fn (array $s): int => (int) $s['parking_total']],
    ['勤怠調整', 'yen', static fn (array $s): int => (int) $s['attendance_adjust']],
    ['その他課税支給', 'yen', static fn (array $s): int => (int) $s['other_taxable']],
    ['その他非課税支給', 'yen', static fn (array $s): int => (int) $s['other_nontax']],
    ['総支給額', 'yen', static fn (array $s): int => (int) $s['gross_total']],
    ['うち非課税通勤手当', 'yen', static fn (array $s): int => (int) $s['commute_nontax']],
    ['健康保険料', 'yen', static fn (array $s): int => (int) $s['si_health']],
    ['介護保険料', 'yen', static fn (array $s): int => (int) $s['si_care']],
    ['子ども・子育て支援金', 'yen', static fn (array $s): int => (int) $s['si_child_support']],
    ['厚生年金保険料', 'yen', static fn (array $s): int => (int) $s['si_pension']],
    ['雇用保険料', 'yen', static fn (array $s): int => (int) $s['emp_insurance']],
    ['課税対象額', 'yen', static fn (array $s): int => (int) $s['taxable_amount']],
    ['所得税', 'yen', static fn (array $s): int => (int) $s['withholding_tax']],
    ['住民税', 'yen', static fn (array $s): int => (int) $s['resident_tax']],
    ['その他控除', 'yen', static fn (array $s): int => (int) $s['other_deduction']],
    ['控除合計', 'yen', static fn (array $s): int => (int) $s['deduction_total']],
    ['差引支給額', 'yen', static fn (array $s): int => (int) $s['net_pay']],
]);
if ($ledgerKind === 'officer') {
    $rows = $officerRows;
}

/** 月ごとの値（合算）と年計。text は「、」でつなぐ */
$table = [];
foreach ($rows as [$label, $kind, $getter]) {
    $cells = [];
    $total = 0;
    for ($m = 1; $m <= 12; $m++) {
        $slips = $slipsByMonth[$m] ?? [];
        if (empty($slips)) {
            $cells[$m] = null;
            continue;
        }
        if ($kind === 'text') {
            $cells[$m] = implode('、', array_map($getter, $slips));
        } else {
            $value = array_sum(array_map($getter, $slips));
            $cells[$m] = $value;
            $total += $value;
        }
    }
    $table[] = ['label' => $label, 'kind' => $kind, 'cells' => $cells, 'total' => $kind === 'text' ? null : $total];
}

$format = static function (?string $kind, $value): string {
    if ($value === null) {
        return '';
    }
    if ($kind === 'minutes') {
        return sprintf('%d:%02d', intdiv((int) $value, 60), (int) $value % 60);
    }
    if ($kind === 'days') {
        return (string) (int) $value;
    }
    if ($kind === 'yen') {
        return number_format((int) $value);
    }
    return (string) $value;
};

if ($isCsv && $employee !== null) {
    $csvText = static function (string $value): string {
        // 表計算ソフトでの数式実行を防ぐ
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    };
    $fileName = ($ledgerKind === 'officer' ? 'yakuin_hoshu_daicho_' : 'chingin_daicho_') . $year . '_' . $employee['id'] . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$ledgerTitle, $year . '年（支給日基準）']);
    fputcsv($out, $ledgerKind === 'officer' ? ['氏名', $csvText($employee['name'])] : ['氏名', $csvText($employee['name']), '性別', PAY_GENDER_LABELS[$employee['gender']] ?? '未設定']);
    fputcsv($out, array_merge(['項目'], array_map(static fn (int $m): string => $m . '月', range(1, 12)), ['年計']));
    foreach ($table as $row) {
        $line = [$csvText($row['label'])];
        for ($m = 1; $m <= 12; $m++) {
            $value = $row['cells'][$m];
            $line[] = $value === null ? '' : ($row['kind'] === 'minutes' ? $format('minutes', $value) : ($row['kind'] === 'text' ? $csvText((string) $value) : (string) $value));
        }
        $line[] = $row['total'] === null ? '' : ($row['kind'] === 'minutes' ? $format('minutes', $row['total']) : (string) $row['total']);
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

pay_render_header($admin, $ledgerTitle, 'ledger');
?>
<form method="get" action="/admin/payroll_ledger.php">
    <select name="kind" onchange="this.form.employee_id.value = ''; this.form.submit();">
        <option value="wage" <?= $ledgerKind === 'wage' ? 'selected' : '' ?>>賃金台帳（従業員）</option>
        <option value="officer" <?= $ledgerKind === 'officer' ? 'selected' : '' ?>>役員報酬台帳（役員）</option>
    </select>
    <input type="number" name="year" value="<?= $year ?>" min="2020" max="2100" style="width:80px;">年（支給日の年）
    <select name="employee_id">
        <option value=""><?= $ledgerKind === 'officer' ? '役員を選択' : '従業員を選択' ?></option>
        <?php foreach ($employeeOptions as $opt): ?>
            <option value="<?= (int) $opt['id'] ?>" <?= (int) $opt['id'] === $employeeId ? 'selected' : '' ?>><?= pay_h($opt['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">表示</button>
</form>

<?php if ($employee !== null): ?>
    <?php if ($ledgerKind === 'officer'): ?>
    <h2><?= pay_h($employee['name']) ?> <?= $year ?>年 役員報酬台帳</h2>
    <?php else: ?>
    <h2><?= pay_h($employee['name']) ?>（性別: <?= pay_h(PAY_GENDER_LABELS[$employee['gender']] ?? '未設定') ?>）<?= $year ?>年</h2>
    <?php endif; ?>
    <?php if ($ledgerKind === 'wage' && $employee['gender'] === null): ?><p class="notice">性別が未設定です（法定記載事項）。従業員の給与設定で登録してください。</p><?php endif; ?>
    <?php if (empty($slipsByMonth)): ?>
        <p class="notice"><?= $year ?>年に支給日のある確定済みの給与はありません。</p>
    <?php else: ?>
        <p><a href="/admin/payroll_ledger.php?kind=<?= $ledgerKind ?>&year=<?= $year ?>&employee_id=<?= (int) $employee['id'] ?>&format=csv">CSVでダウンロード</a></p>
        <div class="scroll">
        <table class="grid">
            <thead><tr><th>項目</th><?php for ($m = 1; $m <= 12; $m++): ?><th class="num"><?= $m ?>月</th><?php endfor; ?><th class="num">年計</th></tr></thead>
            <tbody>
            <?php foreach ($table as $row): ?>
                <tr class="<?= in_array($row['label'], ['総支給額', '控除合計', '差引支給額'], true) ? 'total' : '' ?>">
                    <th><?= pay_h($row['label']) ?></th>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <td class="<?= $row['kind'] === 'text' ? '' : 'num' ?>"><?= pay_h($format($row['kind'], $row['cells'][$m])) ?></td>
                    <?php endfor; ?>
                    <td class="num"><?= pay_h($format($row['kind'], $row['total'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($ledgerKind === 'officer'): ?>
        <p class="small">確定済みの役員報酬だけを、支給日の月の列に表示しています。役員は労働者ではないため賃金台帳には含めていません。</p>
        <?php else: ?>
        <p class="small">確定済みの給与だけを、支給日の月の列に表示しています。労働時間は「時間:分」。休日労働時間数（法定休日の労働）は、週7日勤務の週を給与計算で確定不可にしているため常に0です。役員は「役員報酬台帳」に分けています。</p>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
