<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

const VEHICLE_ALERT_TYPE_LABELS = [
    'shaken' => '車検期限',
    'jibaiseki' => '自賠責保険期限',
    'ninni' => '任意保険期限',
    'oil' => 'オイル交換',
    'tire' => 'タイヤ交換',
    'battery' => 'バッテリー交換',
];

const VEHICLE_ALERT_TYPE_DESCRIPTIONS = [
    'shaken' => '次回車検期限の何日前から警告するか',
    'jibaiseki' => '自賠責保険契約終了日の何日前から警告するか',
    'ninni' => '任意保険契約終了日の何日前から警告するか',
    'oil' => '前回オイル交換から何日経過したら警告するか',
    'tire' => '前回タイヤ交換（各輪）から何日経過したら警告するか',
    'battery' => '前回バッテリー交換から何日経過したら警告するか',
];

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $errors = [];
        $parsed = [];

        foreach (VEHICLE_ALERT_TYPE_LABELS as $alertType => $label) {
            $thresholdRaw = trim((string) ($_POST['threshold_days'][$alertType] ?? ''));
            $isActive = isset($_POST['is_active'][$alertType]) ? 1 : 0;

            if ($thresholdRaw === '' || !ctype_digit($thresholdRaw) || (int) $thresholdRaw < 1) {
                $errors[] = $label . 'の閾値は1以上の整数を入力してください。';
                continue;
            }

            $parsed[$alertType] = [
                'threshold_days' => (int) $thresholdRaw,
                'is_active' => $isActive,
            ];
        }

        if (!empty($errors)) {
            $errorMessage = implode(' ', $errors);
        } else {
            $updateStmt = $pdo->prepare(
                'UPDATE vehicle_alert_settings SET threshold_days = :threshold_days, is_active = :is_active WHERE alert_type = :alert_type'
            );
            foreach ($parsed as $alertType => $values) {
                $updateStmt->execute([
                    ':threshold_days' => $values['threshold_days'],
                    ':is_active' => $values['is_active'],
                    ':alert_type' => $alertType,
                ]);
            }
            set_flash('success', 'アラート設定を更新しました。');
            header('Location: /admin/vehicle_alert_settings.php');
            exit;
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();

$settingsStmt = $pdo->query('SELECT alert_type, threshold_days, is_active FROM vehicle_alert_settings');
$settingsByType = [];
foreach ($settingsStmt->fetchAll() as $row) {
    $settingsByType[$row['alert_type']] = $row;
}

$formValues = [];
foreach (VEHICLE_ALERT_TYPE_LABELS as $alertType => $label) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
        $formValues[$alertType] = [
            'threshold_days' => (string) ($_POST['threshold_days'][$alertType] ?? ''),
            'is_active' => isset($_POST['is_active'][$alertType]),
        ];
    } else {
        $formValues[$alertType] = [
            'threshold_days' => (string) ($settingsByType[$alertType]['threshold_days'] ?? ''),
            'is_active' => (int) ($settingsByType[$alertType]['is_active'] ?? 1) === 1,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>車両アラート設定 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; margin-bottom: 16px; }
        table.settings { border-collapse: collapse; width: 100%; max-width: 640px; }
        table.settings th, table.settings td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        table.settings th { background: #f5f5f5; }
        table.settings input[type="number"] { width: 80px; }
        .desc { font-size: 0.8em; color: #666; margin-top: 2px; }
    </style>
</head>
<body>
<header>
    <h1>車両アラート設定</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/vehicles.php">車両マスタ管理</a> | <a href="/admin/vehicle_check_list.php">集荷前車両等チェック記録</a> | <a href="/admin/vehicle_maintenance_list.php">車両管理記録</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<p class="notice">ここで設定した日数を基準に、ダッシュボードの車両警告（車検・保険期限、オイル・タイヤ交換）の表示タイミングが決まります。無効化した項目は警告対象から外れます。</p>

<form method="post" action="/admin/vehicle_alert_settings.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <table class="settings">
        <thead>
            <tr>
                <th>警告項目</th>
                <th>閾値（日数）</th>
                <th>有効</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (VEHICLE_ALERT_TYPE_LABELS as $alertType => $label): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        <div class="desc"><?= htmlspecialchars(VEHICLE_ALERT_TYPE_DESCRIPTIONS[$alertType], ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td>
                        <input type="number" name="threshold_days[<?= htmlspecialchars($alertType, ENT_QUOTES, 'UTF-8') ?>]" min="1" step="1" value="<?= htmlspecialchars($formValues[$alertType]['threshold_days'], ENT_QUOTES, 'UTF-8') ?>" required> 日
                    </td>
                    <td>
                        <input type="checkbox" name="is_active[<?= htmlspecialchars($alertType, ENT_QUOTES, 'UTF-8') ?>]" value="1" <?= $formValues[$alertType]['is_active'] ? 'checked' : '' ?>>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top:12px;">
        <button type="submit">更新する</button>
    </p>
</form>
</body>
</html>
