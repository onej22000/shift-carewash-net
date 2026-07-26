<?php
require_once __DIR__ . '/../includes/auth.php';

$staff = require_login('staff');
$pdo = getPdo();

$flash = pop_flash();
$csrfToken = csrf_token();
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $currentPw = (string) ($_POST['current_password'] ?? '');
        $newPw = (string) ($_POST['new_password'] ?? '');
        $confirmPw = (string) ($_POST['confirm_password'] ?? '');

        $row = $pdo->prepare('SELECT password_hash FROM employees WHERE id = :id');
        $row->execute([':id' => $staff['id']]);
        $emp = $row->fetch();

        if ($emp === false || $emp['password_hash'] === null || !password_verify($currentPw, $emp['password_hash'])) {
            $errorMessage = '現在のパスワードが正しくありません。';
        } elseif (strlen($newPw) < 8) {
            $errorMessage = '新しいパスワードは8文字以上で入力してください。';
        } elseif ($newPw !== $confirmPw) {
            $errorMessage = '新しいパスワードと確認用パスワードが一致しません。';
        } elseif ($currentPw === $newPw) {
            $errorMessage = '新しいパスワードは現在のパスワードと異なるものにしてください。';
        } else {
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE employees SET password_hash = :hash WHERE id = :id')
                ->execute([':hash' => $hash, ':id' => $staff['id']]);

            set_flash('success', 'パスワードを変更しました。');
            header('Location: /staff/change_password.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワード変更</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; max-width: 480px; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .form-row { margin-bottom: 12px; }
        .form-row label { display: block; font-size: 0.9em; margin-bottom: 4px; }
        .form-row input { width: 100%; box-sizing: border-box; padding: 8px; font-size: 1em; border: 1px solid #ccc; border-radius: 4px; }
        .hint { font-size: 0.8em; color: #666; margin-top: 2px; }
        button[type="submit"] { padding: 10px 24px; font-size: 1em; background: #0b5ed7; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        button[type="submit"]:hover { background: #084fbe; }
        nav a { font-size: 0.9em; }
    </style>
</head>
<body>
<header>
    <h1>パスワード変更</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<p>ログイン中: <?= htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8') ?>さん（ログインID: <?= htmlspecialchars($staff['login_id'], ENT_QUOTES, 'UTF-8') ?>）</p>

<form method="post" action="/staff/change_password.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-row">
        <label for="current_password">現在のパスワード</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
    </div>

    <div class="form-row">
        <label for="new_password">新しいパスワード</label>
        <input type="password" id="new_password" name="new_password" required autocomplete="new-password" minlength="8">
        <div class="hint">8文字以上</div>
    </div>

    <div class="form-row">
        <label for="confirm_password">新しいパスワード（確認）</label>
        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" minlength="8">
    </div>

    <button type="submit">パスワードを変更する</button>
</form>
</body>
</html>
