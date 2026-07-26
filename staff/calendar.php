<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', '不正なリクエストです。再度お試しください。');
        header('Location: /staff/calendar.php');
        exit;
    }

    if (($_POST['action'] ?? '') === 'regenerate') {
        regenerate_calendar_token($pdo, (int) $staff['id']);
        set_flash('success', 'トークンを再発行しました。古いURLは無効になりました。カレンダーアプリ側のURLを新しいものに登録し直してください。');
    }

    header('Location: /staff/calendar.php');
    exit;
}

$flash = pop_flash();
$token = ensure_calendar_token($pdo, (int) $staff['id']);
$icsUrl = calendar_ics_url($token);
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>カレンダー連携 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 4px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; border: 1px solid #ccc; border-radius: 8px; padding: 16px; }
        .url-box { display: flex; gap: 8px; flex-wrap: wrap; margin: 8px 0; }
        .url-box input { flex: 1; min-width: 240px; padding: 8px; font-size: 0.95em; }
        .url-box button { padding: 8px 16px; }
        .danger-button { background: #fdecea; color: #b3261e; border: 1px solid #b3261e; border-radius: 4px; padding: 8px 16px; cursor: pointer; }
        .back-link { margin-top: 16px; }
    </style>
</head>
<body>
<header>
    <h1>カレンダー連携</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードへ戻る</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section>
    <p>このURLをGoogleカレンダーやiPhoneカレンダーの「URLで購読」機能に登録すると、今後のシフトが自動反映されます。<br>
       数時間ごとに更新されます。シフトを変更してもすぐには反映されません。</p>

    <div class="url-box">
        <input type="text" id="ics-url" readonly onclick="this.select()" value="<?= htmlspecialchars($icsUrl, ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" onclick="copyIcsUrl(this)">コピー</button>
    </div>

    <p class="notice">このURLを知っている人は誰でもあなたのシフトを閲覧できます。他人に共有しないでください。URLが漏れた場合は下の「再発行」を行ってください。</p>
</section>

<section>
    <h2>トークンを再発行する</h2>
    <p>再発行すると、上記の古いURLは使えなくなります（購読していたカレンダーアプリには再度新しいURLを登録し直す必要があります）。</p>
    <form method="post" action="/staff/calendar.php" onsubmit="return confirm('トークンを再発行します。現在のURLは使えなくなりますが、よろしいですか？');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="regenerate">
        <button type="submit" class="danger-button">トークンを再発行する</button>
    </form>
</section>

<p class="back-link"><a href="/staff/dashboard.php">← ダッシュボードへ戻る</a></p>

<script>
function copyIcsUrl(button) {
    var input = document.getElementById('ics-url');
    input.select();
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(function () {
            var original = button.textContent;
            button.textContent = 'コピーしました';
            setTimeout(function () { button.textContent = original; }, 2000);
        });
    } else {
        document.execCommand('copy');
    }
}
</script>
</body>
</html>
