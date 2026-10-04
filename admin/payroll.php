<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payroll.php';

$admin = require_login('admin');
$pdo = getPdo();
sync_employee_wages_from_history($pdo);

function payroll_redirect(?int $runId = null, string $query = ''): void
{
    $url = '/admin/payroll.php';
    if ($runId !== null) {
        $url .= '?run_id=' . $runId . ($query !== '' ? '&' . $query : '');
    }
    header('Location: ' . $url);
    exit;
}

/** 手入力の金額（勤怠調整はマイナス可、それ以外は0以上） */
function parse_manual_amount($value, bool $allowNegative, string $label): int
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    if (!preg_match($allowNegative ? '/^-?\d{1,9}$/' : '/^\d{1,9}$/', $value)) {
        throw new InvalidArgumentException($label . 'は' . ($allowNegative ? '' : '0以上の') . '整数（円）で入力してください。');
    }
    return (int) $value;
}

function parse_manual_label($value, int $amount, string $label, int $maxLength): ?string
{
    $value = trim((string) $value);
    if ($amount !== 0 && $value === '') {
        throw new InvalidArgumentException($label . 'を入力してください。');
    }
    if (mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException($label . 'は' . $maxLength . '文字以内で入力してください。');
    }
    return $amount === 0 ? null : $value;
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $runId = (int) ($_POST['run_id'] ?? 0);
        try {
            if ($action === 'create_run') {
                $workMonth = (string) ($_POST['work_month'] ?? '');
                $payDate = trim((string) ($_POST['pay_date'] ?? ''));
                $newRunId = pay_create_run($pdo, $workMonth, $payDate === '' ? null : $payDate, (int) $admin['id']);
                set_flash('success', $workMonth . '分の下書きを作成し、計算しました。');
                payroll_redirect($newRunId);
            }

            $run = pay_fetch_run($pdo, $runId);
            if ($run === null) {
                throw new InvalidArgumentException('給与計算の回が見つかりません。');
            }

            if ($action === 'recalc') {
                $pdo->beginTransaction();
                pay_recalculate_run($pdo, $runId);
                $pdo->commit();
                set_flash('success', '最新の勤怠・設定で再計算しました。');
                payroll_redirect($runId);
            } elseif ($action === 'update_pay_date') {
                if ($run['status'] !== 'draft') {
                    throw new InvalidArgumentException('支給日は下書きの間だけ変更できます。');
                }
                $payDate = (string) ($_POST['pay_date'] ?? '');
                pay_assert_date($payDate, '支給日');
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE pay_runs SET pay_date = :pay_date, updated_at = NOW() WHERE id = :id')->execute([':pay_date' => $payDate, ':id' => $runId]);
                pay_recalculate_run($pdo, $runId);
                $pdo->commit();
                set_flash('success', '支給日を ' . $payDate . ' に変更し、再計算しました（税額表は' . substr($payDate, 0, 4) . '年分）。');
                payroll_redirect($runId);
            } elseif ($action === 'save_manual') {
                if ($run['status'] !== 'draft') {
                    throw new InvalidArgumentException('確定・取消済みの回は修正できません。');
                }
                $employeeId = (int) ($_POST['employee_id'] ?? 0);
                $adjust = parse_manual_amount($_POST['attendance_adjust'] ?? '', true, '勤怠調整額');
                $otherTaxable = parse_manual_amount($_POST['other_taxable'] ?? '', false, 'その他課税支給');
                $otherNontax = parse_manual_amount($_POST['other_nontax'] ?? '', false, 'その他非課税支給');
                $otherDeduction = parse_manual_amount($_POST['other_deduction'] ?? '', false, 'その他控除');
                $fields = [
                    'attendance_adjust' => $adjust,
                    'attendance_adjust_reason' => parse_manual_label($_POST['attendance_adjust_reason'] ?? '', $adjust, '勤怠調整の理由', 255),
                    'other_taxable' => $otherTaxable,
                    'other_taxable_label' => parse_manual_label($_POST['other_taxable_label'] ?? '', $otherTaxable, 'その他課税支給の項目名', 50),
                    'other_nontax' => $otherNontax,
                    'other_nontax_label' => parse_manual_label($_POST['other_nontax_label'] ?? '', $otherNontax, 'その他非課税支給の項目名', 50),
                    'other_deduction' => $otherDeduction,
                    'other_deduction_label' => parse_manual_label($_POST['other_deduction_label'] ?? '', $otherDeduction, 'その他控除の項目名', 50),
                ];
                $note = trim((string) ($_POST['note'] ?? ''));
                $fields['note'] = $note === '' ? null : mb_substr($note, 0, 1000);

                $pdo->beginTransaction();
                $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($fields)));
                $params = [':run_id' => $runId, ':employee_id' => $employeeId];
                foreach ($fields as $column => $value) {
                    $params[':' . $column] = $value;
                }
                $stmt = $pdo->prepare('UPDATE pay_slips SET ' . $sets . ' WHERE run_id = :run_id AND employee_id = :employee_id');
                $stmt->execute($params);
                pay_recalculate_run($pdo, $runId);
                $pdo->commit();
                set_flash('success', '明細の手入力項目を保存し、再計算しました。');
                payroll_redirect($runId);
            } elseif ($action === 'close') {
                if ($run['status'] !== 'draft') {
                    throw new InvalidArgumentException('下書きの回だけ確定できます。');
                }
                pay_close_run($pdo, $runId, (int) $admin['id']);
                set_flash('success', $run['work_month'] . '分を確定しました。以後は編集できません（修正は取消→新しい下書きで再計算）。');
                payroll_redirect($runId);
            } elseif ($action === 'void') {
                pay_void_run($pdo, $runId, (string) ($_POST['void_reason'] ?? ''), (int) $admin['id']);
                set_flash('success', $run['work_month'] . '分を取消しました。同じ勤務月の下書きを作り直せます。');
                payroll_redirect($runId);
            } elseif ($action === 'delete_draft') {
                pay_delete_draft_run($pdo, $runId);
                set_flash('success', $run['work_month'] . '分の下書きを削除しました。');
                payroll_redirect();
            }
        } catch (InvalidArgumentException | RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = $e->getMessage();
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();
$settings = pay_settings($pdo);
$selectedRun = isset($_GET['run_id']) ? pay_fetch_run($pdo, (int) $_GET['run_id']) : null;
$editEmployeeId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;

$runs = $pdo->query(
    'SELECT r.*, COUNT(s.id) AS slip_count, COALESCE(SUM(s.gross_total), 0) AS gross_sum, COALESCE(SUM(s.net_pay), 0) AS net_sum
     FROM pay_runs r LEFT JOIN pay_slips s ON s.run_id = r.id
     GROUP BY r.id ORDER BY r.work_month DESC, r.id DESC'
)->fetchAll();

$defaultWorkMonth = (new DateTime('first day of last month'))->format('Y-m');

pay_render_header($admin, '給与計算', 'payroll');
pay_render_messages($flash, $errorMessage);
?>

<?php if ($selectedRun === null): ?>
    <section>
        <h2>下書きを作成</h2>
        <form method="post" action="/admin/payroll.php">
            <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>">
            <input type="hidden" name="action" value="create_run">
            勤務月 <input type="month" name="work_month" value="<?= pay_h($defaultWorkMonth) ?>" required>
            支給日 <input type="date" name="pay_date"> <span class="small">空欄なら既定（<?= (int) $settings['pay_month_offset'] === 1 ? '翌月' : '当月' ?><?= (int) $settings['pay_day'] === 31 ? '末日' : (int) $settings['pay_day'] . '日' ?>、土日祝は直前の平日）。税額表の年分は支給日で決まります。</span>
            <button type="submit">作成して計算</button>
        </form>
        <p class="small">同じ勤務月の下書き・確定は1件までです。作り直すときは取消してください。勤怠の修正は打刻修正画面で行い、ここでは「再計算」で反映します。</p>
    </section>

    <section>
        <h2>給与計算の一覧</h2>
        <table class="grid">
            <thead><tr><th>勤務月</th><th>計算期間</th><th>支給日</th><th>状態</th><th class="num">人数</th><th class="num">総支給</th><th class="num">差引支給</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($runs)): ?><tr><td colspan="8">まだありません</td></tr><?php endif; ?>
            <?php foreach ($runs as $r): ?>
                <tr class="<?= $r['status'] === 'void' ? 'muted' : '' ?>">
                    <td><?= pay_h($r['work_month']) ?></td>
                    <td><?= pay_h($r['period_start']) ?>〜<?= pay_h($r['period_end']) ?></td>
                    <td><?= pay_h($r['pay_date']) ?></td>
                    <td><span class="badge badge-<?= pay_h($r['status']) ?>"><?= pay_h(PAY_STATUS_LABELS[$r['status']]) ?></span></td>
                    <td class="num"><?= (int) $r['slip_count'] ?></td>
                    <td class="num"><?= pay_yen((int) $r['gross_sum']) ?></td>
                    <td class="num"><?= pay_yen((int) $r['net_sum']) ?></td>
                    <td><a href="/admin/payroll.php?run_id=<?= (int) $r['id'] ?>">開く</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php else: ?>
    <?php
    $run = $selectedRun;
    $runId = (int) $run['id'];
    $slipsStmt = $pdo->prepare('SELECT s.*, e.name FROM pay_slips s JOIN employees e ON e.id = s.employee_id WHERE s.run_id = :run_id ORDER BY e.id');
    $slipsStmt->execute([':run_id' => $runId]);
    $slips = $slipsStmt->fetchAll();
    $errorCount = 0;
    $totals = array_fill_keys(['pay_officer', 'pay_laundry', 'pay_store', 'pay_pickup', 'pay_overtime', 'pay_night', 'allowance_total', 'commute_total', 'parking_total', 'manual_pay', 'gross_total', 'si_total', 'emp_insurance', 'withholding_tax', 'resident_tax', 'other_deduction', 'deduction_total', 'net_pay'], 0);
    foreach ($slips as $s) {
        if (!empty(json_decode((string) $s['errors'], true))) {
            $errorCount++;
        }
    }
    $isDraft = $run['status'] === 'draft';
    ?>
    <p><a href="/admin/payroll.php">← 一覧へ</a></p>
    <section>
        <h2><?= pay_h($run['work_month']) ?>分 <span class="badge badge-<?= pay_h($run['status']) ?>"><?= pay_h(PAY_STATUS_LABELS[$run['status']]) ?></span></h2>
        <p>計算期間 <?= pay_h($run['period_start']) ?>〜<?= pay_h($run['period_end']) ?> ／ 支給日 <strong><?= pay_h($run['pay_date']) ?></strong>（源泉徴収税額表は<?= (int) substr($run['pay_date'], 0, 4) ?>年分）</p>
        <?php if ($run['status'] === 'closed'): ?>
            <p class="small">確定日時 <?= pay_h($run['closed_at']) ?></p>
        <?php elseif ($run['status'] === 'void'): ?>
            <p class="notice">取消済み（<?= pay_h($run['voided_at']) ?>）理由: <?= pay_h($run['void_reason']) ?></p>
        <?php endif; ?>

        <?php if ($isDraft): ?>
            <?php if ($errorCount > 0): ?>
                <p class="message error"><?= $errorCount ?>名の明細に確定できないエラーがあります（表の「エラー・注意」を確認してください）。</p>
            <?php endif; ?>
            <form method="post" action="/admin/payroll.php" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="recalc"><input type="hidden" name="run_id" value="<?= $runId ?>">
                <button type="submit">再計算</button>
            </form>
            <form method="post" action="/admin/payroll.php" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="update_pay_date"><input type="hidden" name="run_id" value="<?= $runId ?>">
                支給日 <input type="date" name="pay_date" value="<?= pay_h($run['pay_date']) ?>" required><button type="submit">変更</button>
            </form>
            <form method="post" action="/admin/payroll.php" class="inline-form" onsubmit="return confirm('<?= pay_h($run['work_month']) ?>分を確定します。確定後は編集できません。よろしいですか？');">
                <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="close"><input type="hidden" name="run_id" value="<?= $runId ?>">
                <button type="submit" <?= $errorCount > 0 ? 'disabled title="エラーを解消すると確定できます"' : '' ?>>確定</button>
            </form>
            <form method="post" action="/admin/payroll.php" class="inline-form" onsubmit="return confirm('この下書きを削除しますか？');">
                <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="delete_draft"><input type="hidden" name="run_id" value="<?= $runId ?>">
                <button type="submit" class="danger">下書きを削除</button>
            </form>
        <?php endif; ?>
        <?php if ($run['status'] !== 'void'): ?>
            <form method="post" action="/admin/payroll.php" style="margin-top:8px;" onsubmit="return confirm('<?= pay_h($run['work_month']) ?>分を取消します。よろしいですか？');">
                <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>"><input type="hidden" name="action" value="void"><input type="hidden" name="run_id" value="<?= $runId ?>">
                取消の理由 <input type="text" name="void_reason" size="40" maxlength="255" required>
                <button type="submit" class="danger">取消</button>
            </form>
        <?php endif; ?>
        <p><a href="/admin/payroll_slip.php?run_id=<?= $runId ?>" target="_blank">全員の給与明細を表示・印刷</a></p>
    </section>

    <section>
        <div class="scroll">
        <table class="grid">
            <thead>
                <tr>
                    <th>氏名</th><th>区分</th><th class="num">出勤</th><th class="num">労働時間</th>
                    <th class="num">役員報酬</th><th class="num">洗濯代行</th><th class="num">店舗</th><th class="num">集荷</th><th class="num">時間外</th><th class="num">深夜</th>
                    <th class="num">手当</th><th class="num">交通費</th><th class="num">駐車場代</th><th class="num">調整・その他</th>
                    <th class="num">総支給</th><th class="num">社会保険</th><th class="num">雇用保険</th><th class="num">課税対象</th><th class="num">所得税</th><th class="num">住民税</th><th class="num">その他控除</th>
                    <th class="num">差引支給</th><th>エラー・注意</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($slips as $s): ?>
                <?php
                $errors = json_decode((string) $s['errors'], true) ?: [];
                $detail = json_decode((string) $s['calc_detail'], true) ?: [];
                $manualPay = (int) $s['attendance_adjust'] + (int) $s['other_taxable'] + (int) $s['other_nontax'];
                $isOfficer = $s['employment_type'] === 'officer';
                $row = [
                    'pay_officer' => (int) $s['pay_officer'],
                    'pay_laundry' => (int) $s['pay_laundry'], 'pay_store' => (int) $s['pay_store'], 'pay_pickup' => (int) $s['pay_pickup'],
                    'pay_overtime' => (int) $s['pay_overtime'], 'pay_night' => (int) $s['pay_night'], 'allowance_total' => (int) $s['allowance_total'],
                    'commute_total' => (int) $s['commute_total'], 'parking_total' => (int) $s['parking_total'], 'manual_pay' => $manualPay,
                    'gross_total' => (int) $s['gross_total'], 'si_total' => pay_si_total($s), 'emp_insurance' => (int) $s['emp_insurance'], 'withholding_tax' => (int) $s['withholding_tax'],
                    'resident_tax' => (int) $s['resident_tax'], 'other_deduction' => (int) $s['other_deduction'],
                    'deduction_total' => (int) $s['deduction_total'], 'net_pay' => (int) $s['net_pay'],
                ];
                foreach ($row as $key => $value) {
                    $totals[$key] += $value;
                }
                ?>
                <tr>
                    <td><?= pay_h($s['name']) ?></td>
                    <td><?= $isOfficer ? '<strong>役員</strong>' : '従業員' ?></td>
                    <td class="num"><?= $isOfficer ? '—' : (int) $s['work_days'] . '日' ?></td>
                    <td class="num"><?= $isOfficer ? '—' : pay_h(pay_minutes_label((int) $s['minutes_total'])) ?></td>
                    <td class="num"><?= number_format($row['pay_officer']) ?></td>
                    <td class="num"><?= number_format($row['pay_laundry']) ?></td>
                    <td class="num"><?= number_format($row['pay_store']) ?></td>
                    <td class="num"><?= number_format($row['pay_pickup']) ?></td>
                    <td class="num"><?= number_format($row['pay_overtime']) ?></td>
                    <td class="num"><?= number_format($row['pay_night']) ?></td>
                    <td class="num"><?= number_format($row['allowance_total']) ?></td>
                    <td class="num"><?= number_format($row['commute_total']) ?></td>
                    <td class="num"><?= number_format($row['parking_total']) ?></td>
                    <td class="num"><?= number_format($manualPay) ?></td>
                    <td class="num"><strong><?= number_format($row['gross_total']) ?></strong></td>
                    <td class="num"><?= number_format($row['si_total']) ?><?= $row['si_total'] > 0 ? '<div class="small">' . pay_h(substr((string) $s['si_month'], 5) . '月分') . '</div>' : '' ?></td>
                    <td class="num"><?= number_format($row['emp_insurance']) ?></td>
                    <td class="num"><?= number_format((int) $s['taxable_amount']) ?></td>
                    <td class="num"><?= number_format($row['withholding_tax']) ?></td>
                    <td class="num"><?= number_format($row['resident_tax']) ?></td>
                    <td class="num"><?= number_format($row['other_deduction']) ?></td>
                    <td class="num"><strong><?= number_format($row['net_pay']) ?></strong></td>
                    <td>
                        <?php if (!empty($errors)): ?><ul class="errors"><?php foreach ($errors as $e): ?><li><?= pay_h($e) ?></li><?php endforeach; ?></ul><?php endif; ?>
                        <?php if (!empty($detail['warnings'])): ?><ul class="warnings"><?php foreach ($detail['warnings'] as $w): ?><li><?= pay_h($w) ?></li><?php endforeach; ?></ul><?php endif; ?>
                        <?php if ((int) $s['commute_taxable'] > 0): ?><div class="small">通勤手当のうち課税 <?= pay_yen((int) $s['commute_taxable']) ?>（非課税限度 <?= pay_yen((int) $s['commute_nontax_limit']) ?>）</div><?php endif; ?>
                        <?php if (!empty($detail['no_payment'])): ?><div class="small">勤怠・支給なし</div><?php endif; ?>
                    </td>
                    <td>
                        <a href="/admin/payroll_slip.php?id=<?= (int) $s['id'] ?>" target="_blank">明細</a>
                        <?php if ($isDraft): ?> | <a href="/admin/payroll.php?run_id=<?= $runId ?>&edit=<?= (int) $s['employee_id'] ?>#edit">修正</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
                <tr class="total">
                    <td>合計（<?= count($slips) ?>名）</td><td></td><td></td><td></td>
                    <?php foreach (['pay_officer', 'pay_laundry', 'pay_store', 'pay_pickup', 'pay_overtime', 'pay_night', 'allowance_total', 'commute_total', 'parking_total', 'manual_pay', 'gross_total', 'si_total', 'emp_insurance'] as $key): ?>
                        <td class="num"><?= number_format($totals[$key]) ?></td>
                    <?php endforeach; ?>
                    <td></td>
                    <?php foreach (['withholding_tax', 'resident_tax', 'other_deduction', 'net_pay'] as $key): ?>
                        <td class="num"><?= number_format($totals[$key]) ?></td>
                    <?php endforeach; ?>
                    <td></td><td></td>
                </tr>
            </tbody>
        </table>
        </div>
    </section>

    <?php if ($isDraft && $editEmployeeId !== null): ?>
        <?php
        $editSlip = null;
        foreach ($slips as $s) {
            if ((int) $s['employee_id'] === $editEmployeeId) {
                $editSlip = $s;
            }
        }
        ?>
        <?php if ($editSlip !== null): ?>
        <section id="edit">
            <h2><?= pay_h($editSlip['name']) ?>さんの手入力項目</h2>
            <form method="post" action="/admin/payroll.php">
                <input type="hidden" name="csrf_token" value="<?= pay_h($csrfToken) ?>">
                <input type="hidden" name="action" value="save_manual">
                <input type="hidden" name="run_id" value="<?= $runId ?>">
                <input type="hidden" name="employee_id" value="<?= (int) $editSlip['employee_id'] ?>">
                <fieldset>
                    <?php if ($editSlip['employment_type'] === 'officer'): ?>
                    <p class="small">役員報酬（月額）は従業員の給与設定で登録します。ここでは手入力のその他支給・控除だけを入力します。保存すると再計算します。</p>
                    <?php else: ?>
                    <p class="small">勤怠の時間は打刻修正画面で直してください（修正履歴が残ります）。ここでは金額の調整だけを入力します。保存すると再計算します。</p>
                    <div class="form-row"><label class="caption">勤怠調整額（円、マイナス可）</label>
                        <input type="number" name="attendance_adjust" value="<?= (int) $editSlip['attendance_adjust'] ?>">
                        理由 <input type="text" name="attendance_adjust_reason" size="40" maxlength="255" value="<?= pay_h($editSlip['attendance_adjust_reason'] ?? '') ?>">
                        <span class="small">課税・雇用保険の対象。明細に「勤怠調整」と理由を表示</span></div>
                    <?php endif; ?>
                    <div class="form-row"><label class="caption">その他課税支給</label>
                        <input type="number" name="other_taxable" min="0" value="<?= (int) $editSlip['other_taxable'] ?>">
                        項目名 <input type="text" name="other_taxable_label" maxlength="50" value="<?= pay_h($editSlip['other_taxable_label'] ?? '') ?>"></div>
                    <div class="form-row"><label class="caption">その他非課税支給</label>
                        <input type="number" name="other_nontax" min="0" value="<?= (int) $editSlip['other_nontax'] ?>">
                        項目名 <input type="text" name="other_nontax_label" maxlength="50" value="<?= pay_h($editSlip['other_nontax_label'] ?? '') ?>"></div>
                    <div class="form-row"><label class="caption">その他控除</label>
                        <input type="number" name="other_deduction" min="0" value="<?= (int) $editSlip['other_deduction'] ?>">
                        項目名 <input type="text" name="other_deduction_label" maxlength="50" value="<?= pay_h($editSlip['other_deduction_label'] ?? '') ?>"></div>
                    <div class="form-row"><label class="caption">備考（明細に表示）</label>
                        <textarea name="note" rows="2" cols="60" maxlength="1000"><?= pay_h($editSlip['note'] ?? '') ?></textarea></div>
                    <button type="submit">保存して再計算</button>
                    <a href="/admin/payroll.php?run_id=<?= $runId ?>">閉じる</a>
                </fieldset>
            </form>
        </section>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
