<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll.php';

$admin = require_login('admin');
$pdo = getPdo();

const PAY_WEEKDAY_LABELS = ['日曜', '月曜', '火曜', '水曜', '木曜', '金曜', '土曜'];

function settings_redirect(string $anchor = ''): void
{
    header('Location: /admin/payroll_settings.php' . ($anchor !== '' ? '#' . $anchor : ''));
    exit;
}

/** 0以上の整数（文字列入力）。不正なら null */
function parse_non_negative_int($value): ?int
{
    $value = trim((string) $value);
    return preg_match('/^\d{1,9}$/', $value) ? (int) $value : null;
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'save_settings') {
                $companyName = trim((string) ($_POST['company_name'] ?? ''));
                $closingDay = parse_non_negative_int($_POST['closing_day'] ?? '');
                $payMonthOffset = parse_non_negative_int($_POST['pay_month_offset'] ?? '');
                $payDay = parse_non_negative_int($_POST['pay_day'] ?? '');
                $weekStartDow = parse_non_negative_int($_POST['week_start_dow'] ?? '');
                $overtimeRate = trim((string) ($_POST['overtime_rate'] ?? ''));
                $nightRate = trim((string) ($_POST['night_rate'] ?? ''));
                $publicLimit = parse_non_negative_int($_POST['public_transit_nontax_limit'] ?? '');
                $fiscalStartInput = trim((string) ($_POST['fiscal_year_start_month'] ?? ''));
                $fiscalStartMonth = $fiscalStartInput === '' ? null : parse_non_negative_int($fiscalStartInput);

                if (mb_strlen($companyName) > 100) {
                    throw new InvalidArgumentException('会社名は100文字以内で入力してください。');
                }
                if ($closingDay === null || $closingDay < 1 || $closingDay > 31 || $payDay === null || $payDay < 1 || $payDay > 31) {
                    throw new InvalidArgumentException('締日・支給日は1〜31（31=末日）で入力してください。');
                }
                if (!in_array($payMonthOffset, [0, 1], true) || $weekStartDow === null || $weekStartDow > 6) {
                    throw new InvalidArgumentException('支給月・週の起算曜日の指定が正しくありません。');
                }
                if (!preg_match('/^[1-2](\.\d{1,2})?$/', $overtimeRate) || !preg_match('/^0(\.\d{1,2})?$/', $nightRate)) {
                    throw new InvalidArgumentException('割増率は 時間外 1.00〜2.99、深夜 0.00〜0.99 の小数（小数第2位まで）で入力してください。');
                }
                if (pay_decimal_to_int($overtimeRate, 2) < 125 || pay_decimal_to_int($nightRate, 2) < 25) {
                    throw new InvalidArgumentException('法定の割増率（時間外1.25・深夜0.25）を下回る値は設定できません。');
                }
                if ($publicLimit === null) {
                    throw new InvalidArgumentException('通勤手当の非課税限度（公共交通機関）を0以上の整数で入力してください。');
                }
                if ($fiscalStartInput !== '' && ($fiscalStartMonth === null || $fiscalStartMonth < 1 || $fiscalStartMonth > 12)) {
                    throw new InvalidArgumentException('事業年度の開始月は1〜12で選択してください。');
                }
                $pdo->prepare(
                    'UPDATE pay_settings SET company_name = :company_name, closing_day = :closing_day, pay_month_offset = :pay_month_offset,
                     pay_day = :pay_day, week_start_dow = :week_start_dow, overtime_rate = :overtime_rate, night_rate = :night_rate,
                     public_transit_nontax_limit = :public_limit, fiscal_year_start_month = :fiscal_start, updated_at = NOW() WHERE id = 1'
                )->execute([
                    ':company_name' => $companyName, ':closing_day' => $closingDay, ':pay_month_offset' => $payMonthOffset,
                    ':pay_day' => $payDay, ':week_start_dow' => $weekStartDow, ':overtime_rate' => $overtimeRate,
                    ':night_rate' => $nightRate, ':public_limit' => $publicLimit, ':fiscal_start' => $fiscalStartMonth,
                ]);
                set_flash('success', '基本設定を保存しました。');
                settings_redirect('basic');
            } elseif ($action === 'wh_upload') {
                $tableYear = parse_non_negative_int($_POST['table_year'] ?? '');
                $file = $_FILES['excel'] ?? null;
                if ($tableYear === null || $tableYear < 2020 || $tableYear > 2100) {
                    throw new InvalidArgumentException('年分（西暦）を正しく入力してください。');
                }
                if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                    throw new InvalidArgumentException('Excelファイルを選択してください。');
                }
                if ($file['size'] > 5 * 1024 * 1024) {
                    throw new InvalidArgumentException('ファイルが大きすぎます（5MBまで）。');
                }
                $parsed = pay_parse_withholding_sheet(spreadsheet_read_first_sheet($file['tmp_name']));
                if (!empty($parsed['errors'])) {
                    throw new InvalidArgumentException("税額表として読み取れませんでした。\n" . implode("\n", $parsed['errors']));
                }
                $_SESSION['pay_wh_preview'] = [
                    'token' => bin2hex(random_bytes(16)),
                    'year' => $tableYear,
                    'file_name' => mb_substr((string) $file['name'], 0, 200),
                    'rows' => $parsed['rows'],
                ];
                settings_redirect('withholding');
            } elseif ($action === 'wh_confirm') {
                $preview = $_SESSION['pay_wh_preview'] ?? null;
                if ($preview === null || !hash_equals($preview['token'], (string) ($_POST['preview_token'] ?? ''))) {
                    throw new InvalidArgumentException('プレビューの有効期限が切れています。もう一度アップロードしてください。');
                }
                $count = pay_store_withholding_table($pdo, (int) $preview['year'], $preview['rows']);
                unset($_SESSION['pay_wh_preview']);
                set_flash('success', $preview['year'] . '年分の源泉徴収税額表を ' . $count . '行 登録しました（' . $preview['file_name'] . '）。');
                settings_redirect('withholding');
            } elseif ($action === 'wh_cancel') {
                unset($_SESSION['pay_wh_preview']);
                settings_redirect('withholding');
            } elseif ($action === 'rate_add') {
                $effectiveFrom = (string) ($_POST['effective_from'] ?? '');
                $rate = trim((string) ($_POST['employee_rate'] ?? ''));
                pay_assert_date($effectiveFrom, '適用開始日');
                if (!preg_match('/^0\.\d{1,5}$/', $rate)) {
                    throw new InvalidArgumentException('労働者負担率は 0.00500 のような小数（小数第5位まで）で入力してください。');
                }
                $pdo->prepare('INSERT INTO pay_emp_insurance_rates (effective_from, employee_rate) VALUES (:d, :r) ON DUPLICATE KEY UPDATE employee_rate = VALUES(employee_rate)')
                    ->execute([':d' => $effectiveFrom, ':r' => $rate]);
                set_flash('success', '雇用保険料率（' . $effectiveFrom . ' から ' . $rate . '）を登録しました。');
                settings_redirect('rates');
            } elseif ($action === 'rate_delete') {
                $pdo->prepare('DELETE FROM pay_emp_insurance_rates WHERE id = :id')->execute([':id' => (int) ($_POST['id'] ?? 0)]);
                set_flash('success', '雇用保険料率を削除しました。');
                settings_redirect('rates');
            } elseif ($action === 'minwage_add') {
                $prefecture = trim((string) ($_POST['prefecture'] ?? ''));
                $effectiveFrom = (string) ($_POST['effective_from'] ?? '');
                $hourly = parse_non_negative_int($_POST['hourly'] ?? '');
                pay_assert_date($effectiveFrom, '発効日');
                if ($prefecture === '' || mb_strlen($prefecture) > 10 || $hourly === null || $hourly === 0) {
                    throw new InvalidArgumentException('都道府県名（10文字以内）と時間額を入力してください。');
                }
                $pdo->prepare('INSERT INTO pay_min_wages (prefecture, effective_from, hourly) VALUES (:p, :d, :h) ON DUPLICATE KEY UPDATE hourly = VALUES(hourly)')
                    ->execute([':p' => $prefecture, ':d' => $effectiveFrom, ':h' => $hourly]);
                set_flash('success', $prefecture . 'の最低賃金（' . $effectiveFrom . ' 発効 ' . pay_yen($hourly) . '）を登録しました。');
                settings_redirect('minwage');
            } elseif ($action === 'minwage_delete') {
                $pdo->prepare('DELETE FROM pay_min_wages WHERE id = :id')->execute([':id' => (int) ($_POST['id'] ?? 0)]);
                set_flash('success', '最低賃金を削除しました。');
                settings_redirect('minwage');
            } elseif ($action === 'commute_add') {
                $effectiveFrom = (string) ($_POST['effective_from'] ?? '');
                $minKm = trim((string) ($_POST['min_km'] ?? ''));
                $maxKm = trim((string) ($_POST['max_km'] ?? ''));
                $limit = parse_non_negative_int($_POST['limit_amount'] ?? '');
                pay_assert_date($effectiveFrom, '適用開始日');
                if (!preg_match('/^\d{1,3}(\.\d)?$/', $minKm) || ($maxKm !== '' && !preg_match('/^\d{1,3}(\.\d)?$/', $maxKm)) || $limit === null) {
                    throw new InvalidArgumentException('距離（km、小数第1位まで）と限度額を正しく入力してください。');
                }
                if ($maxKm !== '' && pay_decimal_to_int($maxKm, 1) <= pay_decimal_to_int($minKm, 1)) {
                    throw new InvalidArgumentException('「未満」の距離は「以上」の距離より大きくしてください。');
                }
                $pdo->prepare('INSERT INTO pay_commute_limits (effective_from, min_km, max_km, limit_amount) VALUES (:d, :min, :max, :l)
                               ON DUPLICATE KEY UPDATE max_km = VALUES(max_km), limit_amount = VALUES(limit_amount)')
                    ->execute([':d' => $effectiveFrom, ':min' => $minKm, ':max' => $maxKm === '' ? null : $maxKm, ':l' => $limit]);
                set_flash('success', '通勤手当の非課税限度（' . $effectiveFrom . ' 適用、' . $minKm . 'km以上）を登録しました。');
                settings_redirect('commute');
            } elseif ($action === 'commute_delete') {
                $pdo->prepare('DELETE FROM pay_commute_limits WHERE id = :id')->execute([':id' => (int) ($_POST['id'] ?? 0)]);
                set_flash('success', '通勤手当の非課税限度の行を削除しました。');
                settings_redirect('commute');
            } elseif ($action === 'parking_add') {
                $effectiveFrom = (string) ($_POST['effective_from'] ?? '');
                $cap = parse_non_negative_int($_POST['cap_amount'] ?? '');
                $minDistance = trim((string) ($_POST['min_distance_km'] ?? ''));
                pay_assert_date($effectiveFrom, '適用開始日');
                if ($cap === null || !preg_match('/^\d{1,3}(\.\d)?$/', $minDistance)) {
                    throw new InvalidArgumentException('上限額と距離条件を正しく入力してください。');
                }
                $pdo->prepare('INSERT INTO pay_parking_rules (effective_from, cap_amount, min_distance_km) VALUES (:d, :c, :m)
                               ON DUPLICATE KEY UPDATE cap_amount = VALUES(cap_amount), min_distance_km = VALUES(min_distance_km)')
                    ->execute([':d' => $effectiveFrom, ':c' => $cap, ':m' => $minDistance]);
                set_flash('success', '駐車場等の料金の加算ルール（' . $effectiveFrom . ' 適用）を登録しました。');
                settings_redirect('parking');
            } elseif ($action === 'parking_delete') {
                $pdo->prepare('DELETE FROM pay_parking_rules WHERE id = :id')->execute([':id' => (int) ($_POST['id'] ?? 0)]);
                set_flash('success', '駐車場等の料金の加算ルールを削除しました。');
                settings_redirect('parking');
            }
        } catch (InvalidArgumentException | RuntimeException $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();
$settings = pay_settings($pdo);
$preview = $_SESSION['pay_wh_preview'] ?? null;

$tableYears = $pdo->query('SELECT table_year, COUNT(*) AS n, MAX(max_amount) AS max_amount FROM pay_withholding_table GROUP BY table_year ORDER BY table_year')->fetchAll();
$rates = $pdo->query('SELECT * FROM pay_emp_insurance_rates ORDER BY effective_from DESC')->fetchAll();
$minWages = $pdo->query('SELECT * FROM pay_min_wages ORDER BY prefecture, effective_from DESC')->fetchAll();
$commuteLimits = $pdo->query('SELECT * FROM pay_commute_limits ORDER BY effective_from DESC, min_km')->fetchAll();
$parkingRules = $pdo->query('SELECT * FROM pay_parking_rules ORDER BY effective_from DESC')->fetchAll();

// 税額の確認（GET。DBには書かない）
$checkResult = null;
if (isset($_GET['check_amount'])) {
    $checkErrors = [];
    $checkYear = (int) ($_GET['check_year'] ?? 0);
    $checkAmount = (int) ($_GET['check_amount'] ?? 0);
    $checkColumn = ($_GET['check_column'] ?? 'kou') === 'otsu' ? 'otsu' : 'kou';
    $checkDependents = max(0, (int) ($_GET['check_dependents'] ?? 0));
    $checkTax = pay_withholding_tax($pdo, $checkAmount, $checkColumn, $checkDependents, $checkYear, $checkErrors);
    $checkResult = [
        'label' => $checkYear . '年分 ' . ($checkColumn === 'kou' ? '甲欄 扶養' . $checkDependents . '人' : '乙欄') . ' 課税対象額 ' . pay_yen($checkAmount),
        'tax' => $checkTax,
        'errors' => $checkErrors,
    ];
}

pay_render_header($admin, '給与設定・税額表', 'settings');
pay_render_messages($flash, $errorMessage);
?>

<section id="basic">
    <h2>基本設定</h2>
    <form method="post" action="/admin/payroll_settings.php">
        <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>">
        <input type="hidden" name="action" value="save_settings">
        <fieldset>
            <div class="form-row"><label class="caption">会社名（明細・賃金台帳）</label>
                <input type="text" name="company_name" maxlength="100" size="40" value="<?= pay_h($settings['company_name']) ?>">
                <?php if ($settings['company_name'] === ''): ?><span class="small" style="color:#b3261e;">未入力です（雇用主の正式名称を入力してください）</span><?php endif; ?>
            </div>
            <div class="form-row"><label class="caption">締日</label>
                <input type="number" name="closing_day" min="1" max="31" value="<?= (int) $settings['closing_day'] ?>"> 日（31=末日）</div>
            <div class="form-row"><label class="caption">支給月</label>
                <select name="pay_month_offset">
                    <option value="0" <?= (int) $settings['pay_month_offset'] === 0 ? 'selected' : '' ?>>当月払い</option>
                    <option value="1" <?= (int) $settings['pay_month_offset'] === 1 ? 'selected' : '' ?>>翌月払い</option>
                </select></div>
            <div class="form-row"><label class="caption">支給日</label>
                <input type="number" name="pay_day" min="1" max="31" value="<?= (int) $settings['pay_day'] ?>"> 日（31=末日。土日祝は直前の平日に前倒し）</div>
            <div class="form-row"><label class="caption">週40時間の起算曜日</label>
                <select name="week_start_dow">
                    <?php foreach (PAY_WEEKDAY_LABELS as $dow => $label): ?>
                        <option value="<?= $dow ?>" <?= (int) $settings['week_start_dow'] === $dow ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-row"><label class="caption">時間外の支給倍率</label>
                <input type="text" name="overtime_rate" size="6" value="<?= pay_h($settings['overtime_rate']) ?>"> 倍（1日8時間・週40時間超）</div>
            <div class="form-row"><label class="caption">深夜の加算率</label>
                <input type="text" name="night_rate" size="6" value="<?= pay_h($settings['night_rate']) ?>">（22時〜5時の加算分）</div>
            <div class="form-row"><label class="caption">通勤手当 非課税限度（公共交通機関）</label>
                <input type="number" name="public_transit_nontax_limit" min="0" value="<?= (int) $settings['public_transit_nontax_limit'] ?>"> 円／月（併用・駐車場加算の合計上限にも使用）</div>
            <div class="form-row"><label class="caption">事業年度の開始月</label>
                <select name="fiscal_year_start_month">
                    <option value="">未設定</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $settings['fiscal_year_start_month'] !== null && (int) $settings['fiscal_year_start_month'] === $m ? 'selected' : '' ?>><?= $m ?>月</option>
                    <?php endfor; ?>
                </select>
                <span class="small">役員報酬の改定時期のチェック（定期同額給与：事業年度開始から3か月以内の改定）に使います</span>
                <?php if ($settings['fiscal_year_start_month'] === null): ?><span class="small" style="color:#b3261e;">未設定です</span><?php endif; ?></div>
            <p class="small">時間外・深夜の割増率と週の起算曜日は、賃金確認（wages.php）・シフト表の見込み額にも使われます。</p>
            <button type="submit">保存</button>
        </fieldset>
    </form>
</section>

<section id="withholding">
    <h2>源泉徴収税額表（月額表）</h2>
    <table class="grid">
        <thead><tr><th>年分</th><th class="num">行数</th><th class="num">表の上限</th></tr></thead>
        <tbody>
        <?php if (empty($tableYears)): ?>
            <tr><td colspan="3">未登録</td></tr>
        <?php endif; ?>
        <?php foreach ($tableYears as $ty): ?>
            <tr><td><?= (int) $ty['table_year'] ?>年分</td><td class="num"><?= (int) $ty['n'] ?></td><td class="num"><?= pay_yen((int) $ty['max_amount']) ?>未満</td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($preview !== null): ?>
        <?php
        $existingCount = 0;
        foreach ($tableYears as $ty) {
            if ((int) $ty['table_year'] === (int) $preview['year']) {
                $existingCount = (int) $ty['n'];
            }
        }
        $previewRows = $preview['rows'];
        $show = array_merge(array_slice($previewRows, 0, 5), [null], array_slice($previewRows, -5));
        ?>
        <fieldset>
            <legend>取込プレビュー：<?= (int) $preview['year'] ?>年分（<?= pay_h($preview['file_name']) ?>）全<?= count($previewRows) ?>行</legend>
            <div class="scroll">
            <table class="grid">
                <thead><tr><th class="num">以上</th><th class="num">未満</th><?php for ($k = 0; $k <= 7; $k++): ?><th class="num">甲<?= $k ?>人</th><?php endfor; ?><th class="num">乙欄</th></tr></thead>
                <tbody>
                <?php foreach ($show as $row): ?>
                    <?php if ($row === null): ?>
                        <tr><td colspan="11" class="small">…（中略）…</td></tr>
                        <?php continue; ?>
                    <?php endif; ?>
                    <tr>
                        <td class="num"><?= number_format($row['min_amount']) ?></td>
                        <td class="num"><?= number_format($row['max_amount']) ?></td>
                        <?php foreach ($row['kou'] as $value): ?><td class="num"><?= number_format($value) ?></td><?php endforeach; ?>
                        <td class="num"><?= $row['otsu'] !== null ? number_format($row['otsu']) : pay_h($row['otsu_rate']) . '%' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <form method="post" action="/admin/payroll_settings.php" class="inline-form"
                  onsubmit="return <?= $existingCount > 0 ? "confirm('" . (int) $preview['year'] . "年分は既に" . $existingCount . "行登録されています。削除して入れ替えますか？')" : 'true' ?>;">
                <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>">
                <input type="hidden" name="action" value="wh_confirm">
                <input type="hidden" name="preview_token" value="<?= pay_h($preview['token']) ?>">
                <button type="submit"><?= $existingCount > 0 ? '既存の行を削除して入れ替える' : 'この内容で登録する' ?></button>
            </form>
            <form method="post" action="/admin/payroll_settings.php" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>">
                <input type="hidden" name="action" value="wh_cancel">
                <button type="submit">取りやめる</button>
            </form>
        </fieldset>
    <?php endif; ?>

    <form method="post" action="/admin/payroll_settings.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>">
        <input type="hidden" name="action" value="wh_upload">
        <fieldset>
            <legend>Excelを取り込む</legend>
            <p class="small">国税庁「源泉徴収税額表」ページの「給与所得の源泉徴収税額表（月額表）」のExcel（.xls / .xlsx）をそのままアップロードしてください。740,000円未満の行を取り込みます（740,000円以上は計算式のため、給与計算では手計算のエラーになります）。</p>
            <div class="form-row"><label class="caption">年分（西暦）</label><input type="number" name="table_year" min="2020" max="2100" value="<?= (int) (new DateTime())->format('Y') + 1 ?>" required></div>
            <div class="form-row"><label class="caption">ファイル</label><input type="file" name="excel" accept=".xls,.xlsx" required></div>
            <button type="submit">読み込んでプレビュー</button>
        </fieldset>
    </form>

    <form method="get" action="/admin/payroll_settings.php#withholding">
        <fieldset>
            <legend>税額の確認（検算用）</legend>
            <input type="number" name="check_year" value="<?= pay_h($_GET['check_year'] ?? (new DateTime())->format('Y')) ?>" style="width:70px;">年分
            課税対象額 <input type="number" name="check_amount" min="0" value="<?= pay_h($_GET['check_amount'] ?? '') ?>" required>円
            <select name="check_column">
                <option value="kou" <?= ($_GET['check_column'] ?? '') !== 'otsu' ? 'selected' : '' ?>>甲欄</option>
                <option value="otsu" <?= ($_GET['check_column'] ?? '') === 'otsu' ? 'selected' : '' ?>>乙欄</option>
            </select>
            扶養 <input type="number" name="check_dependents" min="0" max="7" value="<?= pay_h($_GET['check_dependents'] ?? '0') ?>" style="width:50px;">人
            <button type="submit">確認</button>
            <?php if ($checkResult !== null): ?>
                <p><?= pay_h($checkResult['label']) ?> → <strong><?= $checkResult['tax'] !== null ? pay_yen($checkResult['tax']) : '計算できません' ?></strong>
                <?php foreach ($checkResult['errors'] as $e): ?><br><span style="color:#b3261e;"><?= pay_h($e) ?></span><?php endforeach; ?></p>
            <?php endif; ?>
        </fieldset>
    </form>
</section>

<section id="rates">
    <h2>雇用保険料率（労働者負担）</h2>
    <p class="small">勤務月の末日（賃金締切日）時点で有効な率を使います。一般の事業の料率を厚生労働省の「雇用保険料率のご案内」で確認して登録してください。</p>
    <table class="grid">
        <thead><tr><th>適用開始日</th><th class="num">労働者負担率</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rates as $rate): ?>
            <tr><td><?= pay_h($rate['effective_from']) ?></td><td class="num"><?= pay_h($rate['employee_rate']) ?></td>
                <td><form method="post" action="/admin/payroll_settings.php" class="inline-form" onsubmit="return confirm('この料率を削除しますか？');">
                    <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="rate_delete"><input type="hidden" name="id" value="<?= (int) $rate['id'] ?>">
                    <button type="submit" class="danger">削除</button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" action="/admin/payroll_settings.php">
        <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="rate_add">
        適用開始日 <input type="date" name="effective_from" required>
        労働者負担率 <input type="text" name="employee_rate" size="9" placeholder="0.00500" required>
        <button type="submit">追加・更新</button>
    </form>
</section>

<section id="minwage">
    <h2>最低賃金</h2>
    <table class="grid">
        <thead><tr><th>都道府県</th><th>発効日</th><th class="num">時間額</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($minWages as $mw): ?>
            <tr><td><?= pay_h($mw['prefecture']) ?></td><td><?= pay_h($mw['effective_from']) ?></td><td class="num"><?= pay_yen((int) $mw['hourly']) ?></td>
                <td><form method="post" action="/admin/payroll_settings.php" class="inline-form" onsubmit="return confirm('この最低賃金を削除しますか？');">
                    <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="minwage_delete"><input type="hidden" name="id" value="<?= (int) $mw['id'] ?>">
                    <button type="submit" class="danger">削除</button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" action="/admin/payroll_settings.php">
        <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="minwage_add">
        都道府県 <input type="text" name="prefecture" size="8" value="<?= pay_h(PAY_DEFAULT_WORK_PREFECTURE) ?>" required>
        発効日 <input type="date" name="effective_from" required>
        時間額 <input type="number" name="hourly" min="1" required>円
        <button type="submit">追加・更新</button>
    </form>
</section>

<section id="commute">
    <h2>通勤手当の非課税限度（自動車等の距離区分）</h2>
    <p class="small">支給日（支払われるべき日）時点で有効な適用開始日の区分表を使います。片道距離が「以上」〜「未満」に入る行の金額が限度額です。</p>
    <table class="grid">
        <thead><tr><th>適用開始日</th><th class="num">以上</th><th class="num">未満</th><th class="num">限度額（月）</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($commuteLimits as $cl): ?>
            <tr><td><?= pay_h($cl['effective_from']) ?></td><td class="num"><?= pay_h($cl['min_km']) ?>km</td>
                <td class="num"><?= $cl['max_km'] !== null ? pay_h($cl['max_km']) . 'km' : '—' ?></td><td class="num"><?= pay_yen((int) $cl['limit_amount']) ?></td>
                <td><form method="post" action="/admin/payroll_settings.php" class="inline-form" onsubmit="return confirm('この行を削除しますか？');">
                    <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="commute_delete"><input type="hidden" name="id" value="<?= (int) $cl['id'] ?>">
                    <button type="submit" class="danger">削除</button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" action="/admin/payroll_settings.php">
        <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="commute_add">
        適用開始日 <input type="date" name="effective_from" required>
        <input type="text" name="min_km" size="5" required>km以上
        <input type="text" name="max_km" size="5">km未満（空欄=上限なし）
        限度額 <input type="number" name="limit_amount" min="0" required>円
        <button type="submit">追加・更新</button>
    </form>
</section>

<section id="parking">
    <h2>駐車場等の料金の加算ルール</h2>
    <p class="small">自動車等・併用で「一定の要件を満たす駐車場等」を利用する人は、非課税限度額に1か月当たりの駐車場等の料金相当額（上限あり）を加算します（国税庁 通勤手当の非課税限度額の改正 Q&A Q3-1〜Q3-4）。</p>
    <table class="grid">
        <thead><tr><th>適用開始日</th><th class="num">加算の上限（月）</th><th class="num">距離条件</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($parkingRules as $pr): ?>
            <tr><td><?= pay_h($pr['effective_from']) ?></td><td class="num"><?= pay_yen((int) $pr['cap_amount']) ?></td><td class="num"><?= pay_h($pr['min_distance_km']) ?>km以上</td>
                <td><form method="post" action="/admin/payroll_settings.php" class="inline-form" onsubmit="return confirm('このルールを削除しますか？');">
                    <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="parking_delete"><input type="hidden" name="id" value="<?= (int) $pr['id'] ?>">
                    <button type="submit" class="danger">削除</button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" action="/admin/payroll_settings.php">
        <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="parking_add">
        適用開始日 <input type="date" name="effective_from" required>
        上限 <input type="number" name="cap_amount" min="0" required>円
        距離条件 <input type="text" name="min_distance_km" size="5" value="2.0" required>km以上
        <button type="submit">追加・更新</button>
    </form>
</section>
</body>
</html>
