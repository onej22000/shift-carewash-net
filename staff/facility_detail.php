<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$staff = require_login('staff');
$pdo = getPdo();

$facilityId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT id, name, room_count, onboarding_start_date, pickup_schedule, address, phone_number, note,
            issued_linen_bag_orange, issued_linen_bag_yellow, issued_laundry_net_count, is_active
     FROM facilities WHERE id = :id'
);
$stmt->execute([':id' => $facilityId]);
$facility = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $facility !== false ? htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') : '施設情報' ?> | シフト管理</title>
    <style>
        body { font-family: sans-serif; margin: 0; padding: 16px; color: #222; font-size: 1.05em; }
        header { margin-bottom: 16px; }
        .back-link { display: inline-block; margin-bottom: 12px; color: #0b5ed7; text-decoration: none; }
        h1 { font-size: 1.4em; margin: 0 0 4px; }
        .status-badge { display: inline-block; font-size: 0.75em; padding: 2px 10px; border-radius: 10px; margin-bottom: 12px; }
        .status-active { background: #e6f4ea; color: #1e7e34; }
        .status-disabled { background: #eee; color: #777; }
        .notice { padding: 10px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        .info-card { border: 1px solid #ddd; border-radius: 10px; overflow: hidden; }
        .info-row { padding: 12px 14px; border-bottom: 1px solid #eee; }
        .info-row:last-child { border-bottom: none; }
        .info-row .label { font-size: 0.8em; color: #777; margin-bottom: 2px; }
        .info-row .value { font-size: 1.15em; word-break: break-word; }
        .info-row .value a { color: #0b5ed7; text-decoration: none; }
        .info-row .value.note { white-space: pre-wrap; font-size: 1em; }
        .call-button { display: inline-block; margin-top: 6px; padding: 10px 18px; background: #1e7e34; color: #fff; border-radius: 8px; text-decoration: none; font-size: 1.05em; }
    </style>
</head>
<body>
<header>
    <a class="back-link" href="/staff/facilities.php">← 施設一覧に戻る</a>
</header>

<?php if ($facility === false): ?>
    <p class="notice">対象の施設が見つかりません。</p>
<?php else: ?>
    <h1><?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ((int) $facility['is_active'] === 1): ?>
        <span class="status-badge status-active">有効</span>
    <?php else: ?>
        <span class="status-badge status-disabled">無効</span>
    <?php endif; ?>

    <div class="info-card">
        <div class="info-row">
            <div class="label">住所</div>
            <div class="value">
                <?php if ($facility['address'] !== null): ?>
                    <?= htmlspecialchars($facility['address'], ENT_QUOTES, 'UTF-8') ?>
                    <br>
                    <a href="https://maps.google.com/?q=<?= urlencode($facility['address']) ?>" target="_blank" rel="noopener">地図で見る →</a>
                <?php else: ?>
                    -
                <?php endif; ?>
            </div>
        </div>
        <div class="info-row">
            <div class="label">電話番号</div>
            <div class="value">
                <?php if ($facility['phone_number'] !== null): ?>
                    <?= htmlspecialchars($facility['phone_number'], ENT_QUOTES, 'UTF-8') ?>
                    <br>
                    <a class="call-button" href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $facility['phone_number']), ENT_QUOTES, 'UTF-8') ?>">電話をかける</a>
                <?php else: ?>
                    -
                <?php endif; ?>
            </div>
        </div>
        <div class="info-row">
            <div class="label">居室数</div>
            <div class="value"><?= $facility['room_count'] !== null ? (int) $facility['room_count'] . '室' : '-' ?></div>
        </div>
        <div class="info-row">
            <div class="label">集荷曜日</div>
            <div class="value"><?= $facility['pickup_schedule'] !== null ? htmlspecialchars($facility['pickup_schedule'], ENT_QUOTES, 'UTF-8') : '-' ?></div>
        </div>
        <div class="info-row">
            <div class="label">受託開始日</div>
            <div class="value"><?= $facility['onboarding_start_date'] !== null ? htmlspecialchars($facility['onboarding_start_date'], ENT_QUOTES, 'UTF-8') : '-' ?></div>
        </div>
        <div class="info-row">
            <div class="label">交付リネン袋数（オレンジ）</div>
            <div class="value"><?= $facility['issued_linen_bag_orange'] !== null ? (int) $facility['issued_linen_bag_orange'] . '枚' : '-' ?></div>
        </div>
        <div class="info-row">
            <div class="label">交付リネン袋数（黄）</div>
            <div class="value"><?= $facility['issued_linen_bag_yellow'] !== null ? (int) $facility['issued_linen_bag_yellow'] . '枚' : '-' ?></div>
        </div>
        <div class="info-row">
            <div class="label">交付洗濯ネット数</div>
            <div class="value"><?= $facility['issued_laundry_net_count'] !== null ? (int) $facility['issued_laundry_net_count'] . '枚' : '-' ?></div>
        </div>
        <div class="info-row">
            <div class="label">備考</div>
            <div class="value note"><?= $facility['note'] !== null ? nl2br(htmlspecialchars($facility['note'], ENT_QUOTES, 'UTF-8')) : '-' ?></div>
        </div>
    </div>
<?php endif; ?>
</body>
</html>
