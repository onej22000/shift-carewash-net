<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

// 共用アカウント（複数人が1つのログインを使い回す）は「本人」という単一の状態を持たないため、
// 出勤済みかどうかの事前チェック・区分の初期提案は行わず、代わりに出勤する従業員を都度選ばせる。
$isSharedAccount = (int) ($staff['is_shared_account'] ?? 0) === 1;

$suggestedCategory = null;
$employees = [];
$openEmployeeIds = [];

if ($isSharedAccount) {
    $employeesStmt = $pdo->query(
        "SELECT id, name FROM employees WHERE role = 'staff' AND is_shared_account = 0 AND status = 'active' ORDER BY name"
    );
    $employees = $employeesStmt->fetchAll();
    $openEmployeeIds = array_map('intval', array_column(find_open_attendance_today($pdo), 'employee_id'));
} else {
    $openStmt = $pdo->prepare(
        "SELECT id
         FROM attendance
         WHERE employee_id = :employee_id AND status = 'working' AND DATE(clock_in_at) = CURDATE()
           AND deleted_at IS NULL
         ORDER BY clock_in_at DESC
         LIMIT 1"
    );
    $openStmt->execute([':employee_id' => $staff['id']]);
    $openRecord = $openStmt->fetch();

    if ($openRecord !== false) {
        // 既に出勤中の場合、退勤はclock_out.php（作業実績入力を伴う）でのみ受け付ける
        header('Location: /staff/clock_out.php');
        exit;
    }

    // ---- 本日のシフトから区分の初期値を提案する（複数シフトがあれば区分をまとめてSHIFT_CATEGORIES優先順で解決） ----
    $today = (new DateTime())->format('Y-m-d');
    $todayShiftsStmt = $pdo->prepare('SELECT categories FROM shifts WHERE employee_id = :employee_id AND work_date = :work_date');
    $todayShiftsStmt->execute([':employee_id' => $staff['id'], ':work_date' => $today]);

    $todayCategories = [];
    foreach ($todayShiftsStmt->fetchAll() as $shift) {
        foreach (categories_from_value($shift['categories']) as $category) {
            if (!in_array($category, $todayCategories, true)) {
                $todayCategories[] = $category;
            }
        }
    }
    $suggestedCategory = resolve_shift_category($todayCategories);
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $category = (string) ($_POST['category'] ?? '');
        $targetEmployeeId = (int) $staff['id'];

        if ($isSharedAccount) {
            $targetEmployeeId = (int) ($_POST['employee_id'] ?? 0);
            $validEmployeeIds = array_map('intval', array_column($employees, 'id'));
            if (!in_array($targetEmployeeId, $validEmployeeIds, true)) {
                $errorMessage = '従業員を選択してください。';
            } elseif (in_array($targetEmployeeId, $openEmployeeIds, true)) {
                $errorMessage = 'その従業員は既に出勤中です。';
            }
        }

        if ($errorMessage === '' && !in_array($category, SHIFT_CATEGORIES, true)) {
            $errorMessage = '区分を選択してください。';
        }

        if ($errorMessage === '') {
            $lat = (isset($_POST['lat']) && $_POST['lat'] !== '') ? (float) $_POST['lat'] : null;
            $lng = (isset($_POST['lng']) && $_POST['lng'] !== '') ? (float) $_POST['lng'] : null;

            // 集荷区分の場合は出勤打刻を即確定させず、集荷前車両等チェックを挟む
            // （チェック完了時にvehicle_check.php側でattendanceをINSERTする）。
            if ($category === '集荷') {
                $_SESSION['pending_clock_in'] = [
                    'employee_id' => $targetEmployeeId,
                    'category' => $category,
                    'lat' => $lat,
                    'lng' => $lng,
                    'requested_at' => (new DateTime())->format('Y-m-d H:i:s'),
                ];
                header('Location: /staff/vehicle_check.php');
                exit;
            }

            $insertStmt = $pdo->prepare(
                "INSERT INTO attendance (employee_id, category, clock_in_at, clock_in_lat, clock_in_lng, status)
                 VALUES (:employee_id, :category, :clock_in_at, :lat, :lng, 'working')"
            );
            $insertStmt->execute([
                ':employee_id' => $targetEmployeeId,
                ':category' => $category,
                ':clock_in_at' => (new DateTime())->format('Y-m-d H:i:s'),
                ':lat' => $lat,
                ':lng' => $lng,
            ]);

            set_flash('success', '出勤を記録しました。');
            header('Location: /staff/dashboard.php');
            exit;
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();
$formCategory = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['category'] ?? '') : (string) ($suggestedCategory ?? '');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title>出勤 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.error { background: #fdecea; color: #b3261e; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        fieldset { border: 1px solid #ccc; border-radius: 6px; padding: 16px; max-width: 360px; }
        .form-row { margin-bottom: 12px; }
        .form-row label { display: block; margin-bottom: 4px; font-weight: bold; }
        .form-row select { width: 100%; font-size: 1em; padding: 6px; }
        #submit-button { font-size: 1.1em; padding: 12px 32px; border-radius: 6px; border: none; color: #fff; background: #0b5ed7; cursor: pointer; }
    </style>
</head>
<body>
<header>
    <h1>出勤</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($isSharedAccount): ?>
    <p class="notice">出勤する従業員を選択してください。</p>
<?php elseif ($suggestedCategory !== null): ?>
    <p class="notice">本日のシフトから区分「<?= htmlspecialchars($suggestedCategory, ENT_QUOTES, 'UTF-8') ?>」を初期選択しています。違う場合は変更してください。</p>
<?php else: ?>
    <p class="notice">本日のシフトに区分が設定されていません。区分を選択してください。</p>
<?php endif; ?>

<form id="clock-form" method="post" action="/staff/clock.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="lat" id="clock-lat" value="">
    <input type="hidden" name="lng" id="clock-lng" value="">

    <fieldset>
        <?php if ($isSharedAccount): ?>
        <div class="form-row">
            <label for="employee_id">従業員</label>
            <select id="employee_id" name="employee_id" required>
                <option value="">選択してください</option>
                <?php foreach ($employees as $employee): ?>
                    <?php if (in_array((int) $employee['id'], $openEmployeeIds, true)): ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <option value="<?= (int) $employee['id'] ?>" <?= ($_SERVER['REQUEST_METHOD'] === 'POST' && (int) ($_POST['employee_id'] ?? 0) === (int) $employee['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-row">
            <label for="category">区分</label>
            <select id="category" name="category" required>
                <option value="">選択してください</option>
                <?php foreach (SHIFT_CATEGORIES as $category): ?>
                    <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= $formCategory === $category ? 'selected' : '' ?>>
                        <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" id="submit-button">出勤する</button>
    </fieldset>
</form>

<script>
document.getElementById('clock-form').addEventListener('submit', function (e) {
    var form = this;
    var button = document.getElementById('submit-button');

    if (button.dataset.located === '1') {
        return;
    }

    e.preventDefault();
    button.disabled = true;
    button.textContent = '処理中...';

    function submitForm() {
        button.dataset.located = '1';
        form.submit();
    }

    if (!navigator.geolocation) {
        submitForm();
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function (position) {
            document.getElementById('clock-lat').value = position.coords.latitude;
            document.getElementById('clock-lng').value = position.coords.longitude;
            submitForm();
        },
        function () {
            submitForm();
        },
        { timeout: 5000 }
    );
});
</script>
</body>
</html>
