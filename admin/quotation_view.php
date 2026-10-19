<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/quotation_common.php';

$admin = require_login('admin');
$pdo = getPdo();

$quotation = quotation_fetch($pdo, (int) ($_GET['id'] ?? 0));
if ($quotation === null) {
    set_flash('error', '見積書が見つかりません。');
    header('Location: /admin/quotations.php');
    exit;
}

if (($_GET['format'] ?? '') === 'pdf') {
    quotation_output_pdf($quotation);
}

$flash = pop_flash();
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>見積書 <?= quotation_esc($quotation['quote_no']) ?> | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 14px; }
        .actions a.button, .actions button { display: inline-block; padding: 7px 14px; border: 1px solid #888; border-radius: 4px; background: #f5f5f5; color: #222; text-decoration: none; font-size: 0.95em; cursor: pointer; }
        .actions a.button.primary { background: #0b5ed7; border-color: #0b5ed7; color: #fff; }
        .actions button.danger { border-color: #b3261e; color: #b3261e; background: #fff; }
        .paper { max-width: 760px; border: 1px solid #ccc; box-shadow: 0 2px 8px rgba(0,0,0,.08); padding: 28px 30px; background: #fff; overflow-x: auto; }
        .meta { font-size: 0.85em; color: #666; margin-top: 10px; }
    </style>
</head>
<body>
<header>
    <h1>見積書 <?= quotation_esc($quotation['quote_no']) ?></h1>
    <nav>ログイン中: <?= quotation_esc($admin['name']) ?>さん（管理者） | <a href="/admin/quotations.php">見積書一覧</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= quotation_esc($flash['type']) ?>"><?= quotation_esc($flash['message']) ?></p>
<?php endif; ?>

<div class="actions">
    <a class="button primary" href="/admin/quotation_view.php?id=<?= (int) $quotation['id'] ?>&amp;format=pdf">PDFをダウンロード</a>
    <a class="button" href="/admin/quotation_edit.php?copy=<?= (int) $quotation['id'] ?>">この内容で新規作成</a>
    <form method="post" action="/admin/quotations.php" onsubmit="return confirm('見積書 <?= quotation_esc($quotation['quote_no']) ?> を削除します。元に戻せません。よろしいですか？');">
        <input type="hidden" name="csrf_token" value="<?= quotation_esc($csrfToken) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int) $quotation['id'] ?>">
        <button type="submit" class="danger">削除</button>
    </form>
</div>

<div class="paper">
    <?= quotation_document_html($quotation) ?>
</div>
<p class="meta">作成者：<?= quotation_esc($quotation['created_by_name'] ?? '') ?>　作成日時：<?= quotation_esc($quotation['created_at']) ?></p>
</body>
</html>
