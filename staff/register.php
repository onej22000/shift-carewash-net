<?php
require_once __DIR__ . '/../includes/auth.php';

start_session_once();

function find_invited_employee(PDO $pdo, string $code): array
{
    if ($code === '') {
        return [null, 'empty'];
    }

    $stmt = $pdo->prepare(
        "SELECT id, name, invite_code_expires_at
         FROM employees
         WHERE invite_code = :code AND status = 'invited'
         LIMIT 1"
    );
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();

    if ($row === false) {
        return [null, 'not_found'];
    }
    if ($row['invite_code_expires_at'] === null || $row['invite_code_expires_at'] < (new DateTime())->format('Y-m-d H:i:s')) {
        return [null, 'expired'];
    }

    return [$row, 'ok'];
}

$pdo = getPdo();
// GET/POSTを取り違えないよう、リクエストメソッドに応じて取得元を固定する
// （POST時にGETのcodeを優先してしまうと、フォームのhidden inputで送られた値より
//   クエリ文字列側を誤って優先しかねないため）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = (string) ($_POST['code'] ?? '');
} else {
    $code = (string) ($_GET['code'] ?? '');
}
[$employee, $codeStatus] = find_invited_employee($pdo, $code);
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $employee !== null) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $loginId = trim((string) ($_POST['login_id'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $loginId)) {
            $errorMessage = 'ログインIDは半角英数字とアンダースコアで3〜50文字で入力してください。';
        } elseif (strlen($password) < 8) {
            $errorMessage = 'パスワードは8文字以上で入力してください。';
        } elseif ($password !== $passwordConfirm) {
            $errorMessage = 'パスワードが一致しません。';
        } else {
            $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE login_id = :login_id');
            $dupStmt->execute([':login_id' => $loginId]);

            if ((int) $dupStmt->fetchColumn() > 0) {
                $errorMessage = 'このログインIDは既に使用されています。';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare(
                    "UPDATE employees
                     SET login_id = :login_id, password_hash = :password_hash, status = 'active',
                         invite_code = NULL, invite_code_expires_at = NULL
                     WHERE id = :id AND invite_code = :code AND status = 'invited'"
                );
                $updateStmt->execute([
                    ':login_id' => $loginId,
                    ':password_hash' => $passwordHash,
                    ':id' => $employee['id'],
                    ':code' => $code,
                ]);

                if ($updateStmt->rowCount() === 1) {
                    set_flash('success', '登録が完了しました。ログインしてください。');
                    header('Location: /staff/login.php');
                    exit;
                }

                $errorMessage = '登録に失敗しました。招待コードが既に使用済みの可能性があります。';
            }
        }
    }
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>従業員登録 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        h1 { font-size: 1.3em; }
        .message.error { padding: 8px 12px; border-radius: 4px; background: #fdecea; color: #b3261e; margin-bottom: 12px; }
        .form-row { margin-bottom: 10px; }
        .form-row label { display: block; margin-bottom: 4px; }
        .hint { font-size: 0.85em; color: #555; }
    </style>
</head>
<body>
    <h1>従業員登録</h1>

    <?php if ($employee === null): ?>
        <p class="message error">
            <?php if ($codeStatus === 'expired'): ?>
                この招待コードは有効期限が切れています。管理者に再発行を依頼してください。
            <?php else: ?>
                この招待コードは無効です。URLをご確認のうえ、管理者にお問い合わせください。
            <?php endif; ?>
        </p>
    <?php else: ?>
        <p><?= htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') ?>さん、こんにちは。ログインID・パスワードを設定してください。</p>

        <?php if ($errorMessage !== ''): ?>
            <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form method="post" action="/staff/register.php" id="register-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="code" value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-row">
                <label for="login_id">ログインID</label>
                <input type="text" id="login_id" name="login_id" required autofocus>
                <div class="hint">半角英数字とアンダースコアで3〜50文字</div>
            </div>

            <div class="form-row">
                <label for="password">パスワード</label>
                <input type="password" id="password" name="password" required>
                <div class="hint">8文字以上</div>
            </div>

            <div class="form-row">
                <label for="password_confirm">パスワード（確認）</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>

            <button type="submit" id="register-submit">登録する</button>
        </form>
        <script>
        document.getElementById('register-form').addEventListener('submit', function () {
            var button = document.getElementById('register-submit');
            if (button.dataset.submitted === '1') {
                return;
            }
            button.dataset.submitted = '1';
            button.disabled = true;
            button.textContent = '処理中...';
        });
        </script>
    <?php endif; ?>
</body>
</html>
