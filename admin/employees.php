<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

function generate_invite_code(): string
{
    return bin2hex(random_bytes(8));
}

function generate_temp_password(): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $pw = '';
    for ($i = 0; $i < 12; $i++) {
        $pw .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pw;
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $hourlyWageWeekday = (int) ($_POST['hourly_wage_weekday'] ?? -1);
            $hourlyWageHoliday = (int) ($_POST['hourly_wage_holiday'] ?? -1);
            $commuteAllowanceType = (string) ($_POST['commute_allowance_type'] ?? 'daily');
            $commuteAllowanceAmount = (int) ($_POST['commute_allowance_amount'] ?? -1);

            if ($name === '') {
                $errorMessage = '氏名を入力してください。';
            } elseif ($hourlyWageWeekday < 0 || $hourlyWageHoliday < 0) {
                $errorMessage = '時給は0以上で入力してください。';
            } elseif (!in_array($commuteAllowanceType, ['daily', 'monthly'], true) || $commuteAllowanceAmount < 0) {
                $errorMessage = '交通費の区分・金額を正しく入力してください。';
            } else {
                $inviteCode = generate_invite_code();
                $expiresAt = (new DateTime('+7 days'))->format('Y-m-d H:i:s');

                $stmt = $pdo->prepare(
                    "INSERT INTO employees (name, role, hourly_wage_weekday, hourly_wage_holiday, commute_allowance_type, commute_allowance_amount, status, invite_code, invite_code_expires_at)
                     VALUES (:name, 'staff', :hourly_wage_weekday, :hourly_wage_holiday, :commute_allowance_type, :commute_allowance_amount, 'invited', :invite_code, :expires_at)"
                );
                $stmt->execute([
                    ':name' => $name,
                    ':hourly_wage_weekday' => $hourlyWageWeekday,
                    ':hourly_wage_holiday' => $hourlyWageHoliday,
                    ':commute_allowance_type' => $commuteAllowanceType,
                    ':commute_allowance_amount' => $commuteAllowanceAmount,
                    ':invite_code' => $inviteCode,
                    ':expires_at' => $expiresAt,
                ]);

                set_flash('success', htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . 'さんを登録し、招待コードを発行しました。');
                header('Location: /admin/employees.php');
                exit;
            }
        } elseif ($action === 'update_wage') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $hourlyWageWeekday = (int) ($_POST['hourly_wage_weekday'] ?? -1);
            $hourlyWageHoliday = (int) ($_POST['hourly_wage_holiday'] ?? -1);
            $commuteAllowanceType = (string) ($_POST['commute_allowance_type'] ?? 'daily');
            $commuteAllowanceAmount = (int) ($_POST['commute_allowance_amount'] ?? -1);

            if ($hourlyWageWeekday < 0 || $hourlyWageHoliday < 0) {
                $errorMessage = '時給は0以上で入力してください。';
            } elseif (!in_array($commuteAllowanceType, ['daily', 'monthly'], true) || $commuteAllowanceAmount < 0) {
                $errorMessage = '交通費の区分・金額を正しく入力してください。';
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE employees SET hourly_wage_weekday = :hourly_wage_weekday, hourly_wage_holiday = :hourly_wage_holiday,
                     commute_allowance_type = :commute_allowance_type, commute_allowance_amount = :commute_allowance_amount
                     WHERE id = :id AND role = 'staff'"
                );
                $stmt->execute([
                    ':hourly_wage_weekday' => $hourlyWageWeekday,
                    ':hourly_wage_holiday' => $hourlyWageHoliday,
                    ':commute_allowance_type' => $commuteAllowanceType,
                    ':commute_allowance_amount' => $commuteAllowanceAmount,
                    ':id' => $employeeId,
                ]);
                set_flash('success', '時給・交通費を更新しました。');
                header('Location: /admin/employees.php');
                exit;
            }
        } elseif ($action === 'add_allowance') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $allowanceName = trim((string) ($_POST['allowance_name'] ?? ''));
            $allowanceAmount = (int) ($_POST['allowance_amount'] ?? -1);

            $empCheckStmt = $pdo->prepare("SELECT id FROM employees WHERE id = :id AND role = 'staff'");
            $empCheckStmt->execute([':id' => $employeeId]);

            if ($allowanceName === '') {
                $errorMessage = '手当名を入力してください。';
            } elseif ($allowanceAmount < 0) {
                $errorMessage = '手当の月額は0以上で入力してください。';
            } elseif ($empCheckStmt->fetch() === false) {
                $errorMessage = '対象の従業員が見つかりません。';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO employee_allowances (employee_id, name, monthly_amount) VALUES (:employee_id, :name, :monthly_amount)'
                );
                $stmt->execute([
                    ':employee_id' => $employeeId,
                    ':name' => $allowanceName,
                    ':monthly_amount' => $allowanceAmount,
                ]);
                set_flash('success', '手当を追加しました。');
                header('Location: /admin/employees.php');
                exit;
            }
        } elseif ($action === 'delete_allowance') {
            $allowanceId = (int) ($_POST['allowance_id'] ?? 0);
            $stmt = $pdo->prepare(
                'DELETE ea FROM employee_allowances ea INNER JOIN employees e ON e.id = ea.employee_id
                 WHERE ea.id = :id AND e.role = \'staff\''
            );
            $stmt->execute([':id' => $allowanceId]);
            set_flash('success', '手当を削除しました。');
            header('Location: /admin/employees.php');
            exit;
        } elseif ($action === 'disable') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE employees SET status = 'disabled' WHERE id = :id AND role = 'staff'");
            $stmt->execute([':id' => $employeeId]);
            set_flash('success', '従業員を無効化しました。');
            header('Location: /admin/employees.php');
            exit;
        } elseif ($action === 'enable') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $stmt = $pdo->prepare(
                "UPDATE employees
                 SET status = CASE WHEN login_id IS NULL THEN 'invited' ELSE 'active' END
                 WHERE id = :id AND role = 'staff' AND status = 'disabled'"
            );
            $stmt->execute([':id' => $employeeId]);
            set_flash('success', '従業員を有効化しました。');
            header('Location: /admin/employees.php');
            exit;
        } elseif ($action === 'reset_password') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $stmt = $pdo->prepare(
                "SELECT id, name, login_id, status FROM employees WHERE id = :id AND role = 'staff'"
            );
            $stmt->execute([':id' => $employeeId]);
            $emp = $stmt->fetch();

            if (!$emp || $emp['status'] !== 'active' || $emp['login_id'] === null) {
                $errorMessage = '対象の従業員が見つからないか、パスワードリセットできない状態です。';
            } else {
                $pw = generate_temp_password();
                $hash = password_hash($pw, PASSWORD_DEFAULT);

                $pdo->prepare("UPDATE employees SET password_hash = :hash WHERE id = :id")
                    ->execute([':hash' => $hash, ':id' => $employeeId]);

                $pdo->prepare(
                    "INSERT INTO admin_password_reset_logs (employee_id, admin_id) VALUES (:emp_id, :admin_id)"
                )->execute([':emp_id' => $employeeId, ':admin_id' => (int) $admin['id']]);

                start_session_once();
                $_SESSION['temp_pw_display'] = [
                    'employee_name' => $emp['name'],
                    'login_id' => $emp['login_id'],
                    'password' => $pw,
                ];

                set_flash('success', htmlspecialchars($emp['name'], ENT_QUOTES, 'UTF-8') . 'さんの仮パスワードを発行しました。');
                header('Location: /admin/employees.php');
                exit;
            }
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();

start_session_once();
$tempPwDisplay = null;
if (isset($_SESSION['temp_pw_display'])) {
    $tempPwDisplay = $_SESSION['temp_pw_display'];
    unset($_SESSION['temp_pw_display']);
}

$employeesStmt = $pdo->query(
    "SELECT id, name, login_id, hourly_wage_weekday, hourly_wage_holiday, commute_allowance_type, commute_allowance_amount, status, invite_code, invite_code_expires_at
     FROM employees
     WHERE role = 'staff'
     ORDER BY FIELD(status, 'invited', 'active', 'disabled'), name"
);
$employees = $employeesStmt->fetchAll();

$allowancesByEmployee = [];
foreach ($employees as $employee) {
    $allowancesByEmployee[(int) $employee['id']] = get_employee_allowances($pdo, (int) $employee['id']);
}

$statusLabels = [
    'invited' => '招待中',
    'active' => '有効',
    'disabled' => '無効',
];

$nowStr = (new DateTime())->format('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>従業員管理 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        .temp-pw-box { background: #fff3cd; border: 1px solid #e0a000; border-radius: 6px; padding: 14px 16px; margin-bottom: 16px; }
        .temp-pw-box h3 { margin: 0 0 8px; font-size: 1em; color: #856404; }
        .temp-pw-box .pw-value { font-family: monospace; font-size: 1.3em; letter-spacing: 0.1em; background: #fff; border: 1px solid #ccc; padding: 6px 12px; border-radius: 4px; display: inline-block; margin: 4px 0; }
        .temp-pw-box .pw-meta { font-size: 0.85em; color: #666; margin-top: 6px; }
        section { margin-bottom: 24px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; }
        .form-row { margin-bottom: 8px; }
        .form-row label { display: inline-block; width: 100px; }
        table.employees { border-collapse: collapse; width: 100%; }
        table.employees th, table.employees td { border: 1px solid #ccc; padding: 8px; text-align: left; vertical-align: top; }
        table.employees th { background: #f5f5f5; }
        .status-badge { display: inline-block; font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
        .status-invited { background: #fff3cd; color: #856404; }
        .status-active { background: #e6f4ea; color: #1e7e34; }
        .status-disabled { background: #eee; color: #777; }
        .invite-box { margin-top: 6px; }
        .invite-box input { width: 320px; max-width: 100%; font-size: 0.85em; }
        .invite-box button { font-size: 0.85em; }
        .expired-note { color: #b3261e; font-size: 0.85em; }
        .wage-form, .status-form { display: inline-block; margin-right: 8px; margin-top: 4px; }
        .wage-form input[type="number"] { width: 80px; }
        .inline-form { display: inline; }
        .login-id { font-family: monospace; font-size: 0.9em; color: #444; }
        .reset-pw-btn { background: #e67e22; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 0.85em; margin-top: 4px; }
        .reset-pw-btn:hover { background: #c0392b; }
    </style>
</head>
<body>
<header>
    <h1>従業員管理</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($tempPwDisplay !== null): ?>
    <div class="temp-pw-box">
        <h3>仮パスワード発行完了（この画面のみ表示）</h3>
        <div>
            対象: <?= htmlspecialchars($tempPwDisplay['employee_name'], ENT_QUOTES, 'UTF-8') ?>
            （ログインID: <span class="login-id"><?= htmlspecialchars($tempPwDisplay['login_id'], ENT_QUOTES, 'UTF-8') ?></span>）
        </div>
        <div class="pw-value"><?= htmlspecialchars($tempPwDisplay['password'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="pw-meta">本人に口頭または対面でお伝えください。このページを離れると再表示できません。</div>
    </div>
<?php endif; ?>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="new-employee-form">
    <h2>新規従業員追加</h2>
    <fieldset>
        <form method="post" action="/admin/employees.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-row">
                <label for="name">氏名</label>
                <input type="text" id="name" name="name" maxlength="100" required>
            </div>

            <div class="form-row">
                <label for="hourly_wage_weekday">平日時給（円）</label>
                <input type="number" id="hourly_wage_weekday" name="hourly_wage_weekday" min="0" step="1" required>
            </div>

            <div class="form-row">
                <label for="hourly_wage_holiday">土日祝時給（円）</label>
                <input type="number" id="hourly_wage_holiday" name="hourly_wage_holiday" min="0" step="1" required>
            </div>

            <div class="form-row">
                <label for="commute_allowance_type">交通費区分</label>
                <select id="commute_allowance_type" name="commute_allowance_type">
                    <option value="daily">日額（1出勤あたり）</option>
                    <option value="monthly">月額（固定）</option>
                </select>
            </div>

            <div class="form-row">
                <label for="commute_allowance_amount">交通費金額（円）</label>
                <input type="number" id="commute_allowance_amount" name="commute_allowance_amount" min="0" step="1" value="0" required>
            </div>

            <button type="submit">登録して招待コードを発行</button>
        </form>
    </fieldset>
</section>

<section class="employee-list">
    <h2>従業員一覧</h2>

    <?php if (empty($employees)): ?>
        <p class="notice">従業員が登録されていません。上のフォームから追加してください。</p>
    <?php else: ?>
        <table class="employees">
            <thead>
                <tr>
                    <th>氏名</th>
                    <th>ログインID</th>
                    <th>平日時給</th>
                    <th>土日祝時給</th>
                    <th>交通費</th>
                    <th>手当</th>
                    <th>状態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $employee): ?>
                    <?php $employeeAllowances = $allowancesByEmployee[(int) $employee['id']] ?? []; ?>
                    <tr>
                        <td><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($employee['login_id'] !== null): ?>
                                <span class="login-id"><?= htmlspecialchars($employee['login_id'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span style="color:#aaa;">未設定</span>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format((int) $employee['hourly_wage_weekday']) ?>円</td>
                        <td><?= number_format((int) $employee['hourly_wage_holiday']) ?>円</td>
                        <td>
                            <?= $employee['commute_allowance_type'] === 'monthly' ? '月額' : '日額' ?>
                            <?= number_format((int) $employee['commute_allowance_amount']) ?>円
                        </td>
                        <td>
                            <?php if (empty($employeeAllowances)): ?>
                                <span style="color:#aaa;">なし</span>
                            <?php else: ?>
                                <ul style="margin:0; padding-left:1.1em;">
                                <?php foreach ($employeeAllowances as $allowance): ?>
                                    <li>
                                        <?= htmlspecialchars($allowance['name'], ENT_QUOTES, 'UTF-8') ?>
                                        <?= number_format((int) $allowance['monthly_amount']) ?>円/月
                                        <form method="post" action="/admin/employees.php" class="inline-form" onsubmit="return confirm('この手当を削除しますか？');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="delete_allowance">
                                            <input type="hidden" name="allowance_id" value="<?= (int) $allowance['id'] ?>">
                                            <button type="submit" style="font-size:0.8em;">削除</button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <form method="post" action="/admin/employees.php" class="inline-form" style="margin-top:4px;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="add_allowance">
                                <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                <input type="text" name="allowance_name" placeholder="手当名" maxlength="100" style="width:90px;" required>
                                <input type="number" name="allowance_amount" placeholder="月額" min="0" step="1" style="width:70px;" required>円
                                <button type="submit" style="font-size:0.85em;">+ 追加</button>
                            </form>
                        </td>
                        <td>
                            <span class="status-badge status-<?= htmlspecialchars($employee['status'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($statusLabels[$employee['status']] ?? $employee['status'], ENT_QUOTES, 'UTF-8') ?>
                            </span>

                            <?php if ($employee['status'] === 'invited'): ?>
                                <?php $isExpired = $employee['invite_code_expires_at'] === null || $employee['invite_code_expires_at'] < $nowStr; ?>
                                <?php if ($isExpired): ?>
                                    <div class="expired-note">招待コードの有効期限が切れています。</div>
                                <?php else: ?>
                                    <div class="invite-box">
                                        招待URL:<br>
                                        <input type="text" readonly onclick="this.select()" value="https://shift.carewash.net/staff/register.php?code=<?= htmlspecialchars($employee['invite_code'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="button" onclick="copyInviteUrl(this)">コピー</button>
                                        <div class="expired-note" style="color:#856404;">有効期限: <?= htmlspecialchars($employee['invite_code_expires_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="/admin/employees.php" class="wage-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="update_wage">
                                <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                平日<input type="number" name="hourly_wage_weekday" min="0" step="1" value="<?= (int) $employee['hourly_wage_weekday'] ?>">円
                                土日祝<input type="number" name="hourly_wage_holiday" min="0" step="1" value="<?= (int) $employee['hourly_wage_holiday'] ?>">円
                                <br>
                                交通費
                                <select name="commute_allowance_type">
                                    <option value="daily" <?= $employee['commute_allowance_type'] === 'daily' ? 'selected' : '' ?>>日額</option>
                                    <option value="monthly" <?= $employee['commute_allowance_type'] === 'monthly' ? 'selected' : '' ?>>月額</option>
                                </select>
                                <input type="number" name="commute_allowance_amount" min="0" step="1" value="<?= (int) $employee['commute_allowance_amount'] ?>">円
                                <button type="submit">時給・交通費変更</button>
                            </form>

                            <?php if ($employee['status'] === 'active' && $employee['login_id'] !== null): ?>
                                <form method="post" action="/admin/employees.php" class="status-form inline-form"
                                      onsubmit="return confirm('<?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?>さんの仮パスワードを発行します。よろしいですか？');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                    <button type="submit" class="reset-pw-btn">仮PW発行</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($employee['status'] === 'disabled'): ?>
                                <form method="post" action="/admin/employees.php" class="status-form inline-form" onsubmit="return confirm('この従業員を有効化しますか？');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="enable">
                                    <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                    <button type="submit">有効化</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/admin/employees.php" class="status-form inline-form" onsubmit="return confirm('この従業員を無効化しますか？');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="disable">
                                    <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                    <button type="submit">無効化</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<script>
function copyInviteUrl(button) {
    var input = button.previousElementSibling;
    input.select();
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(function () {
            var original = button.textContent;
            button.textContent = 'コピーしました';
            setTimeout(function () { button.textContent = original; }, 2000);
        });
    }
}
</script>
</body>
</html>
