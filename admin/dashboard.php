<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

$vehicleAlerts = calc_vehicle_alerts($pdo, (new DateTime())->format('Y-m-d'));

$flash = pop_flash();
$csrfToken = csrf_token();

$editPostId = isset($_GET['edit_post']) ? (int) $_GET['edit_post'] : null;
$editBoardType = isset($_GET['board_type']) && array_key_exists((string) $_GET['board_type'], BOARD_TYPES)
    ? (string) $_GET['board_type']
    : null;

$boardPosts = [];
foreach (BOARD_TYPES as $boardType => $boardLabel) {
    $boardPosts[$boardType] = fetch_board_posts($pdo, $boardType);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理者ダッシュボード | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .greeting { font-size: 1.1em; margin-bottom: 24px; }
        .nav-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .nav-card { display: block; border: 1px solid #ccc; border-radius: 8px; padding: 16px; text-decoration: none; color: #222; background: #fff; transition: box-shadow 0.15s, border-color 0.15s; }
        .nav-card:hover { border-color: #0b5ed7; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .nav-card h2 { font-size: 1.05em; margin: 0 0 8px; color: #0b5ed7; }
        .nav-card p { margin: 0; font-size: 0.9em; color: #555; }
        .badge { display: inline-block; font-size: 0.75em; padding: 2px 6px; border-radius: 4px; background: #fff3cd; color: #856404; margin-left: 6px; }
        .vehicle-alert-banner { padding: 12px 16px; background: #fdecea; border: 2px solid #b3261e; border-radius: 6px; color: #7a1913; margin-bottom: 16px; }
        .vehicle-alert-banner h2 { margin: 0 0 8px; font-size: 1.05em; color: #b3261e; }
        .vehicle-alert-banner ul { margin: 0; padding-left: 20px; }
        .vehicle-alert-banner li { margin-bottom: 4px; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .inline-form { display: inline; }
        .link-danger { background: none; border: none; padding: 0; color: #b3261e; text-decoration: underline; cursor: pointer; font-size: 1em; }
        .board-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .board-card { border: 2px solid #0b5ed7; border-radius: 8px; padding: 14px 16px; background: #f4f8ff; }
        .board-card h2 { margin: 0 0 10px; font-size: 1.05em; color: #0b5ed7; }
        .board-post-list { list-style: none; margin: 0 0 12px; padding: 0; max-height: 320px; overflow-y: auto; }
        .board-post { background: #fff; border: 1px solid #ccc; border-radius: 6px; padding: 8px 10px; margin-bottom: 8px; }
        .board-post-content { white-space: pre-wrap; word-break: break-word; margin: 0 0 6px; }
        .board-post-meta { font-size: 0.8em; color: #666; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px; align-items: baseline; }
        .board-post-actions a, .board-post-actions button { font-size: 0.85em; margin-left: 8px; }
        .board-post-actions button.link-danger { background: none; border: none; padding: 0; color: #b3261e; text-decoration: underline; cursor: pointer; font-size: 0.85em; }
        .board-empty { font-size: 0.9em; color: #777; margin: 0 0 12px; }
        .board-form textarea { width: 100%; box-sizing: border-box; min-height: 60px; font-family: inherit; font-size: 0.95em; padding: 6px; }
        .board-form { margin-top: 8px; }
        .board-form .board-form-actions { margin-top: 6px; }
    </style>
</head>
<body>
<header>
    <h1>管理者ダッシュボード</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/logout.php">ログアウト</a></nav>
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
                                <form method="post" action="/admin/board_action.php" class="board-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="board_type" value="<?= htmlspecialchars($boardType, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
                                    <textarea name="content" maxlength="1000" required><?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <div class="board-form-actions">
                                        <button type="submit">更新する</button>
                                        <a href="/admin/dashboard.php#board-<?= htmlspecialchars($boardType, ENT_QUOTES, 'UTF-8') ?>">キャンセル</a>
                                    </div>
                                </form>
                            <?php else: ?>
                                <p class="board-post-content"><?= nl2br(htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8')) ?></p>
                                <div class="board-post-meta">
                                    <span>
                                        <?= htmlspecialchars($post['created_by_name'], ENT_QUOTES, 'UTF-8') ?>さん
                                        （<?= htmlspecialchars((new DateTime($post['created_at']))->format('n/j H:i'), ENT_QUOTES, 'UTF-8') ?>）
                                        <?php if ($post['updated_by_name'] !== null): ?>
                                            ／編集: <?= htmlspecialchars($post['updated_by_name'], ENT_QUOTES, 'UTF-8') ?>さん
                                            （<?= htmlspecialchars((new DateTime($post['updated_at']))->format('n/j H:i'), ENT_QUOTES, 'UTF-8') ?>）
                                        <?php endif; ?>
                                    </span>
                                    <span class="board-post-actions">
                                        <a href="/admin/dashboard.php?edit_post=<?= (int) $post['id'] ?>&board_type=<?= htmlspecialchars($boardType, ENT_QUOTES, 'UTF-8') ?>#board-<?= htmlspecialchars($boardType, ENT_QUOTES, 'UTF-8') ?>">編集</a>
                                        <form method="post" action="/admin/board_action.php" class="inline-form" style="display:inline"
                                              onsubmit="return confirm('この投稿を削除します。よろしいですか？');">
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
            <form method="post" action="/admin/board_action.php" class="board-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="board_type" value="<?= htmlspecialchars($boardType, ENT_QUOTES, 'UTF-8') ?>">
                <textarea name="content" maxlength="1000" placeholder="注意事項を入力してください" required></textarea>
                <div class="board-form-actions">
                    <button type="submit">投稿する</button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
</section>

<?php if (!empty($vehicleAlerts)): ?>
    <div class="vehicle-alert-banner">
        <h2>⚠ 車両の期限・交換時期に関する警告</h2>
        <ul>
            <?php foreach ($vehicleAlerts as $alert): ?>
                <li><?= htmlspecialchars($alert['vehicle_label'], ENT_QUOTES, 'UTF-8') ?>：<?= htmlspecialchars($alert['label'], ENT_QUOTES, 'UTF-8') ?>（<?= htmlspecialchars($alert['detail'], ENT_QUOTES, 'UTF-8') ?>）</li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<p class="greeting">こんにちは、<?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん</p>

<section class="nav-cards">
    <a class="nav-card" href="/admin/shifts.php">
        <h2>シフト表作成</h2>
        <p>週・月単位のカレンダーでシフトを登録・編集します。</p>
    </a>
    <a class="nav-card" href="/admin/employees.php">
        <h2>従業員管理</h2>
        <p>従業員の登録・時給設定・招待コード発行を行います。</p>
    </a>
    <a class="nav-card" href="/admin/wages.php">
        <h2>賃金確認</h2>
        <p>日次・月中の集計確認と、月次の確定処理を行います。</p>
    </a>
    <a class="nav-card" href="/admin/facilities.php">
        <h2>施設管理</h2>
        <p>取引先施設の登録・無効化を行います。</p>
    </a>
    <a class="nav-card" href="/admin/work_status.php">
        <h2>作業状況・残数確認</h2>
        <p>施設ごとの工程別累計・滞留・残数を確認します。</p>
    </a>
    <a class="nav-card" href="/admin/work_speed.php">
        <h2>作業速度分析</h2>
        <p>洗濯代行の1人あたり所要時間を従業員別・施設別に確認します。</p>
    </a>
    <a class="nav-card" href="/admin/attendance_edit_logs.php">
        <h2>打刻修正履歴</h2>
        <p>従業員自身による出退勤・休憩打刻の修正履歴を確認します。</p>
    </a>
    <a class="nav-card" href="/admin/shift_edit_logs.php">
        <h2>シフト編集履歴</h2>
        <p>従業員自身によるシフトの新規登録・変更・削除の履歴を確認します。</p>
    </a>
    <a class="nav-card" href="/admin/attendance_monthly.php">
        <h2>月間打刻実績</h2>
        <p>従業員×日付のグリッドで、シフト予定と実際の打刻実績を並べて確認します。</p>
    </a>
    <a class="nav-card" href="/admin/work_stage_records.php">
        <h2>作業実績の管理</h2>
        <p>集荷・洗濯代行の個別記録を一覧・登録・修正・削除します。</p>
    </a>
    <a class="nav-card" href="/admin/work_stage_record_edit_logs.php">
        <h2>作業実績修正履歴</h2>
        <p>管理者による作業実績の追加・修正・削除の履歴を確認します。</p>
    </a>
    <a class="nav-card" href="/admin/collection_records.php">
        <h2>集荷・配送記録簿</h2>
        <p>施設・対象月ごとの集荷〜到着〜発送〜返却の記録を一覧・登録・修正・削除・PDF出力します。</p>
    </a>
    <a class="nav-card" href="/admin/collection_cycle_edit_logs.php">
        <h2>集荷・配送記録修正履歴</h2>
        <p>従業員・管理者による集荷・配送記録の追加・修正・削除履歴を確認します。</p>
    </a>
    <a class="nav-card" href="/admin/travel_time.php">
        <h2>施設間移動時間</h2>
        <p>集荷・配送記録の日時・担当者・場所から、施設間の移動時間を休憩控除の上で算出します。</p>
    </a>
    <a class="nav-card" href="/admin/collection_headcount.php">
        <h2>到着リネン袋の確認・返却リネン袋数の登録</h2>
        <p>到着済みリネン袋の中身（人数）と、発送済みリネン袋の返却袋数を確認・登録し、既存の記録を修正・削除します。</p>
    </a>
    <a class="nav-card" href="/admin/consumable_stock.php">
        <h2>消耗品在庫管理</h2>
        <p>リネン袋（オレンジ・黄・青）・洗濯ネットの在庫を登録・修正・取り消しします。</p>
    </a>
    <a class="nav-card" href="/admin/vehicles.php">
        <h2>車両マスタ管理</h2>
        <p>ナンバープレート・号車名の登録・編集・無効化を行います。</p>
    </a>
    <a class="nav-card" href="/admin/vehicle_check_list.php">
        <h2>集荷前車両等チェック記録</h2>
        <p>全従業員分の集荷前車両点検記録を一覧・登録・修正・削除します。</p>
    </a>
    <a class="nav-card" href="/admin/vehicle_maintenance_list.php">
        <h2>車両管理記録</h2>
        <p>車検・保険・オイル・タイヤ交換の記録を車両ごとに登録・修正・削除します。</p>
    </a>
    <a class="nav-card" href="/admin/vehicle_alert_settings.php">
        <h2>車両アラート設定</h2>
        <p>車検・保険期限、オイル・タイヤ交換の警告を出す日数を設定します。</p>
    </a>
</section>
</body>
</html>
