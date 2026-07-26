<?php
require_once __DIR__ . '/../includes/auth.php';

start_session_once();

$loggedInEmployee = current_employee();
if ($loggedInEmployee !== null && $loggedInEmployee['role'] === 'admin') {
    header('Location: /admin/dashboard.php');
    exit;
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $loginId = trim((string) ($_POST['login_id'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $employee = attempt_login($loginId, $password, 'admin');
        if ($employee !== null) {
            login_employee($employee);
            header('Location: /admin/dashboard.php');
            exit;
        }

        $errorMessage = 'ログインIDまたはパスワードが正しくありません。';
    }
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理者ログイン | シフト管理</title>
</head>
<body>
    <h1>管理者ログイン</h1>

    <?php if ($errorMessage !== ''): ?>
        <p style="color:red;"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/login.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div>
            <label for="login_id">ログインID</label><br>
            <input type="text" id="login_id" name="login_id" required autofocus>
        </div>

        <div>
            <label for="password">パスワード</label><br>
            <input type="password" id="password" name="password" required>
        </div>

        <button type="submit">ログイン</button>
    </form>
</body>
</html>
