<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();
$flash = pop_flash();
$csrfToken = csrf_token();
$editPostId = isset($_GET['edit_post']) ? (int) $_GET['edit_post'] : null;
$editBoardType = isset($_GET['board_type']) && array_key_exists((string) $_GET['board_type'], BOARD_TYPES)
    ? (string) $_GET['board_type']
    : null;

$boardPosts = [];
$lastSeenPostId = 0;
foreach (BOARD_TYPES as $boardType => $boardLabel) {
    $boardPosts[$boardType] = fetch_board_posts($pdo, $boardType);
    foreach ($boardPosts[$boardType] as $post) {
        $lastSeenPostId = max($lastSeenPostId, (int) $post['id']);
    }
}

$readStmt = $pdo->prepare(
    'INSERT INTO board_read_status (employee_id, last_seen_post_id)
     VALUES (:employee_id, :last_seen_post_id)
     ON DUPLICATE KEY UPDATE last_seen_post_id = GREATEST(last_seen_post_id, VALUES(last_seen_post_id))'
);
$readStmt->execute([':employee_id' => $staff['id'], ':last_seen_post_id' => $lastSeenPostId]);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title>掲示板 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .board-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
        .board-card { border: 2px solid #0b5ed7; border-radius: 8px; padding: 14px 16px; background: #f4f8ff; }
        .board-card h2 { margin: 0 0 10px; font-size: 1.05em; color: #0b5ed7; }
        .board-post-list { list-style: none; margin: 0 0 12px; padding: 0; max-height: 420px; overflow-y: auto; }
        .board-post { background: #fff; border: 1px solid #ccc; border-radius: 6px; padding: 8px 10px; margin-bottom: 8px; }
        .board-post-content { white-space: pre-wrap; word-break: break-word; margin: 0 0 6px; }
        .board-post-meta { font-size: 0.8em; color: #666; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px; align-items: baseline; }
        .board-post-actions a, .board-post-actions button { font-size: 0.85em; margin-left: 8px; }
        .board-empty { font-size: 0.9em; color: #777; margin: 0 0 12px; }
        .board-form textarea { width: 100%; box-sizing: border-box; min-height: 72px; font-family: inherit; font-size: 0.95em; padding: 6px; }
        .board-form { margin-top: 8px; }
        .board-form-actions { margin-top: 6px; }
        .inline-form { display: inline; }
        .link-danger { background: none; border: none; padding: 0; color: #b3261e; text-decoration: underline; cursor: pointer; }
        @media (max-width: 900px) {
            .board-grid {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                width: 100%;
                gap: 22px;
            }
            .board-card {
                box-sizing: border-box;
                width: 100%;
                min-width: 0;
                padding: 22px;
            }
            .board-post-list { max-height: none; }
        }
    </style>
</head>
<body>
<header>
    <h1>掲示板</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="board-grid">
<?php foreach (BOARD_TYPES as $boardType => $boardLabel): ?>
    <div class="board-card" id="board-<?= htmlspecialchars($boardType, ENT_QUOTES, 'UTF-8') ?>">
        <h2><?= htmlspecialchars($boardLabel, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php if (empty($boardPosts[$boardType])): ?>
            <p class="board-empty">投稿はまだありません。</p>
        <?php else: ?>
            <ul class="board-post-list">
            <?php foreach ($boardPosts[$boardType] as $post): ?>
                <li class="board-post">
                <?php if ($editBoardType === $boardType && $editPostId === (int) $post['id']): ?>
                    <form method="post" action="/staff/board_action.php" class="board-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="board_type" value="<?= htmlspecialchars($boardType, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
                        <textarea name="content" maxlength="1000" required><?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        <div class="board-form-actions"><button type="submit">更新する</button> <a href="/staff/boards.php#board-<?= htmlspecialchars($boardType, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a></div>
                    </form>
                <?php else: ?>
                    <p class="board-post-content"><?= nl2br(htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8')) ?></p>
                    <div class="board-post-meta">
                        <span><?= htmlspecialchars($post['created_by_name'], ENT_QUOTES, 'UTF-8') ?>さん（<?= htmlspecialchars((new DateTime($post['created_at']))->format('n/j H:i'), ENT_QUOTES, 'UTF-8') ?>）<?php if ($post['updated_by_name'] !== null): ?> ／編集: <?= htmlspecialchars($post['updated_by_name'], ENT_QUOTES, 'UTF-8') ?>さん（<?= htmlspecialchars((new DateTime($post['updated_at']))->format('n/j H:i'), ENT_QUOTES, 'UTF-8') ?>）<?php endif; ?></span>
                        <span class="board-post-actions">
                            <a href="/staff/boards.php?edit_post=<?= (int) $post['id'] ?>&board_type=<?= htmlspecialchars($boardType, ENT_QUOTES, 'UTF-8') ?>#board-<?= htmlspecialchars($boardType, ENT_QUOTES, 'UTF-8') ?>">編集</a>
                            <form method="post" action="/staff/board_action.php" class="inline-form" onsubmit="return confirm('この投稿を削除します。よろしいですか？');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="board_type" value="<?= htmlspecialchars($boardType, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
                                <button type="submit" class="link-danger">削除</button>
                            </form>
                        </span>
                    </div>
                <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="post" action="/staff/board_action.php" class="board-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="board_type" value="<?= htmlspecialchars($boardType, ENT_QUOTES, 'UTF-8') ?>">
            <textarea name="content" maxlength="1000" placeholder="注意事項を入力してください" required></textarea>
            <div class="board-form-actions"><button type="submit">投稿する</button></div>
        </form>
    </div>
<?php endforeach; ?>
</section>
</body>
</html>
