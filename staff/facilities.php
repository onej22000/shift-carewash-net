<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'update_note') {
            $facilityId = (int) ($_POST['facility_id'] ?? 0);
            $noteRaw = trim((string) ($_POST['note'] ?? ''));
            $note = $noteRaw === '' ? null : $noteRaw;

            $existsStmt = $pdo->prepare('SELECT 1 FROM facilities WHERE id = :id');
            $existsStmt->execute([':id' => $facilityId]);

            if ($existsStmt->fetchColumn() === false) {
                $errorMessage = '対象の施設が見つかりません。';
            } else {
                $updateStmt = $pdo->prepare('UPDATE facilities SET note = :note WHERE id = :id');
                $updateStmt->execute([':note' => $note, ':id' => $facilityId]);
                set_flash('success', '備考を更新しました。');
                header('Location: /staff/facilities.php');
                exit;
            }
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();

$facilitiesStmt = $pdo->query(
    'SELECT id, name, facility_type, room_count, onboarding_start_date, pickup_schedule, address, phone_number, note,
            issued_linen_bag_orange, issued_linen_bag_yellow, issued_laundry_net_count, is_active
     FROM facilities ORDER BY is_active DESC, name'
);
$facilities = $facilitiesStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/staff/mobile-ui.css?v=20260807-1">
    <title>施設一覧 | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        table.facilities { border-collapse: collapse; width: 100%; }
        table.facilities th, table.facilities td { border: 1px solid #ccc; padding: 8px; text-align: left; font-size: 0.9em; }
        table.facilities th { background: #f5f5f5; }
        .status-badge { display: inline-block; font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
        .status-active { background: #e6f4ea; color: #1e7e34; }
        .status-disabled { background: #eee; color: #777; }
        .note-cell { max-width: 220px; }
        .note-cell textarea { width: 100%; box-sizing: border-box; height: 50px; font-size: 0.9em; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
    </style>
</head>
<body>
<header>
    <h1>施設一覧</h1>
    <nav><a href="/staff/dashboard.php">ダッシュボードに戻る</a> | <a href="/staff/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if (empty($facilities)): ?>
    <p class="notice">施設が登録されていません。</p>
<?php else: ?>
    <table class="facilities">
        <thead>
            <tr>
                <th>施設名</th>
                <th>施設種別</th>
                <th>居室数</th>
                <th>受託開始日</th>
                <th>集荷曜日</th>
                <th>住所</th>
                <th>電話番号</th>
                <th>備考</th>
                <th>交付リネン袋数（オレンジ）</th>
                <th>交付リネン袋数（黄）</th>
                <th>交付洗濯ネット数</th>
                <th>状態</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($facilities as $facility): ?>
                <tr>
                    <td><a href="/staff/facility_detail.php?id=<?= (int) $facility['id'] ?>"><?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?></a></td>
                    <td><?= htmlspecialchars($facility['facility_type'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= $facility['room_count'] !== null ? (int) $facility['room_count'] . '室' : '-' ?></td>
                    <td><?= $facility['onboarding_start_date'] !== null ? htmlspecialchars($facility['onboarding_start_date'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                    <td><?= $facility['pickup_schedule'] !== null ? htmlspecialchars($facility['pickup_schedule'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                    <td><?= $facility['address'] !== null ? htmlspecialchars($facility['address'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                    <td><?= $facility['phone_number'] !== null ? htmlspecialchars($facility['phone_number'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                    <td class="note-cell">
                        <form method="post" action="/staff/facilities.php">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="update_note">
                            <input type="hidden" name="facility_id" value="<?= (int) $facility['id'] ?>">
                            <textarea name="note"><?= htmlspecialchars($facility['note'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            <button type="submit">保存</button>
                        </form>
                    </td>
                    <td><?= $facility['issued_linen_bag_orange'] !== null ? (int) $facility['issued_linen_bag_orange'] . '枚' : '-' ?></td>
                    <td><?= $facility['issued_linen_bag_yellow'] !== null ? (int) $facility['issued_linen_bag_yellow'] . '枚' : '-' ?></td>
                    <td><?= $facility['issued_laundry_net_count'] !== null ? (int) $facility['issued_laundry_net_count'] . '枚' : '-' ?></td>
                    <td>
                        <?php if ((int) $facility['is_active'] === 1): ?>
                            <span class="status-badge status-active">有効</span>
                        <?php else: ?>
                            <span class="status-badge status-disabled">無効</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>
