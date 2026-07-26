<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

function parse_vehicle_input(array $post): array
{
    $plateNumber = trim((string) ($post['plate_number'] ?? ''));

    $vehicleNameRaw = trim((string) ($post['vehicle_name'] ?? ''));
    $vehicleName = $vehicleNameRaw === '' ? null : $vehicleNameRaw;

    return ['plate_number' => $plateNumber, 'vehicle_name' => $vehicleName];
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create' || $action === 'update') {
            $values = parse_vehicle_input($_POST);

            if ($values['plate_number'] === '') {
                $errorMessage = 'ナンバープレートを入力してください。';
            } elseif ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO vehicles (plate_number, vehicle_name, is_active) VALUES (:plate_number, :vehicle_name, 1)'
                );
                $stmt->execute([
                    ':plate_number' => $values['plate_number'],
                    ':vehicle_name' => $values['vehicle_name'],
                ]);
                set_flash('success', htmlspecialchars($values['plate_number'], ENT_QUOTES, 'UTF-8') . 'を登録しました。');
                header('Location: /admin/vehicles.php');
                exit;
            } else {
                $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
                $stmt = $pdo->prepare(
                    'UPDATE vehicles SET plate_number = :plate_number, vehicle_name = :vehicle_name WHERE id = :id'
                );
                $stmt->execute([
                    ':plate_number' => $values['plate_number'],
                    ':vehicle_name' => $values['vehicle_name'],
                    ':id' => $vehicleId,
                ]);
                set_flash('success', htmlspecialchars($values['plate_number'], ENT_QUOTES, 'UTF-8') . 'を更新しました。');
                header('Location: /admin/vehicles.php');
                exit;
            }
        } elseif ($action === 'disable') {
            $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
            $pdo->prepare('UPDATE vehicles SET is_active = 0 WHERE id = :id')->execute([':id' => $vehicleId]);
            set_flash('success', '車両を無効化しました。');
            header('Location: /admin/vehicles.php');
            exit;
        } elseif ($action === 'enable') {
            $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
            $pdo->prepare('UPDATE vehicles SET is_active = 1 WHERE id = :id')->execute([':id' => $vehicleId]);
            set_flash('success', '車両を有効化しました。');
            header('Location: /admin/vehicles.php');
            exit;
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();

$vehiclesStmt = $pdo->query('SELECT id, plate_number, vehicle_name, is_active FROM vehicles ORDER BY is_active DESC, plate_number');
$vehicles = $vehiclesStmt->fetchAll();

$editingVehicle = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    foreach ($vehicles as $vehicle) {
        if ((int) $vehicle['id'] === $editId) {
            $editingVehicle = $vehicle;
            break;
        }
    }
}

$formAction = 'create';
$formVehicleId = null;
$formPlateNumber = '';
$formVehicleName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
    $formAction = (string) ($_POST['action'] ?? 'create');
    $formVehicleId = $formAction === 'update' ? (int) ($_POST['vehicle_id'] ?? 0) : null;
    $formPlateNumber = (string) ($_POST['plate_number'] ?? '');
    $formVehicleName = (string) ($_POST['vehicle_name'] ?? '');
} elseif ($editingVehicle !== null) {
    $formAction = 'update';
    $formVehicleId = (int) $editingVehicle['id'];
    $formPlateNumber = $editingVehicle['plate_number'];
    $formVehicleName = (string) ($editingVehicle['vehicle_name'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>車両マスタ管理 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; max-width: 360px; }
        .form-row { margin-bottom: 8px; }
        .form-row label { display: inline-block; width: 110px; vertical-align: top; }
        .form-row input[type="text"] { width: 220px; }
        table.vehicles { border-collapse: collapse; width: 100%; }
        table.vehicles th, table.vehicles td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        table.vehicles th { background: #f5f5f5; }
        .status-badge { display: inline-block; font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
        .status-active { background: #e6f4ea; color: #1e7e34; }
        .status-disabled { background: #eee; color: #777; }
        .inline-form { display: inline; }
    </style>
</head>
<body>
<header>
    <h1>車両マスタ管理</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/vehicle_maintenance_list.php">車両管理記録</a> | <a href="/admin/vehicle_check_list.php">集荷前車両等チェック記録</a> | <a href="/admin/vehicle_alert_settings.php">アラート設定</a> | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="vehicle-form">
    <h2><?= $formAction === 'update' ? '車両情報の編集' : '新規車両登録' ?></h2>
    <fieldset>
        <form method="post" action="/admin/vehicles.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= $formAction === 'update' ? 'update' : 'create' ?>">
            <?php if ($formAction === 'update'): ?>
                <input type="hidden" name="vehicle_id" value="<?= (int) $formVehicleId ?>">
            <?php endif; ?>

            <div class="form-row">
                <label for="plate_number">ナンバープレート</label>
                <input type="text" id="plate_number" name="plate_number" maxlength="20" value="<?= htmlspecialchars($formPlateNumber, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label for="vehicle_name">号車名</label>
                <input type="text" id="vehicle_name" name="vehicle_name" maxlength="100" value="<?= htmlspecialchars($formVehicleName, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <button type="submit"><?= $formAction === 'update' ? '更新する' : '登録する' ?></button>
            <?php if ($formAction === 'update'): ?>
                <a href="/admin/vehicles.php">キャンセル</a>
            <?php endif; ?>
        </form>
    </fieldset>
</section>

<section class="vehicle-list">
    <h2>車両一覧</h2>
    <?php if (empty($vehicles)): ?>
        <p class="notice">車両が登録されていません。上のフォームから追加してください。</p>
    <?php else: ?>
        <table class="vehicles">
            <thead>
                <tr>
                    <th>ナンバープレート</th>
                    <th>号車名</th>
                    <th>状態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vehicles as $vehicle): ?>
                    <tr>
                        <td><?= htmlspecialchars($vehicle['plate_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $vehicle['vehicle_name'] !== null ? htmlspecialchars($vehicle['vehicle_name'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td>
                            <?php if ((int) $vehicle['is_active'] === 1): ?>
                                <span class="status-badge status-active">有効</span>
                            <?php else: ?>
                                <span class="status-badge status-disabled">無効</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/admin/vehicles.php?edit=<?= (int) $vehicle['id'] ?>">編集</a>
                            <?php if ((int) $vehicle['is_active'] === 1): ?>
                                <form method="post" action="/admin/vehicles.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="disable">
                                    <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['id'] ?>">
                                    <button type="submit">無効化</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/admin/vehicles.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="enable">
                                    <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['id'] ?>">
                                    <button type="submit">有効化</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
</body>
</html>
