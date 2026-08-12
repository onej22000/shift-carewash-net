<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

const FACILITY_EDITABLE_FIELDS = ['name', 'facility_type', 'room_count', 'onboarding_start_date', 'pickup_schedule', 'address', 'phone_number', 'note', 'issued_linen_bag_orange', 'issued_linen_bag_yellow', 'issued_linen_bag_blue', 'issued_laundry_net_count'];

const FACILITY_TYPES = ['介護施設', '自社', 'クリーニング所'];

function parse_facility_input(array $post): array
{
    $name = trim((string) ($post['name'] ?? ''));

    $facilityType = trim((string) ($post['facility_type'] ?? ''));
    $facilityType = in_array($facilityType, FACILITY_TYPES, true) ? $facilityType : null;

    $roomCountRaw = trim((string) ($post['room_count'] ?? ''));
    $roomCount = $roomCountRaw === '' ? null : (int) $roomCountRaw;

    $onboardingStartDateRaw = trim((string) ($post['onboarding_start_date'] ?? ''));
    $onboardingStartDate = null;
    if ($onboardingStartDateRaw !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $onboardingStartDateRaw);
        $onboardingStartDate = $dt !== false ? $dt->format('Y-m-d') : false;
    }

    $pickupSchedule = trim((string) ($post['pickup_schedule'] ?? ''));
    $pickupSchedule = in_array($pickupSchedule, FACILITY_PICKUP_SCHEDULES, true) ? $pickupSchedule : null;

    $address = trim((string) ($post['address'] ?? ''));
    $address = $address === '' ? null : $address;

    $phoneNumber = trim((string) ($post['phone_number'] ?? ''));
    $phoneNumber = $phoneNumber === '' ? null : $phoneNumber;

    $note = trim((string) ($post['note'] ?? ''));
    $note = $note === '' ? null : $note;

    $issuedLinenBagOrangeRaw = trim((string) ($post['issued_linen_bag_orange'] ?? ''));
    $issuedLinenBagOrange = $issuedLinenBagOrangeRaw === '' ? null : (int) $issuedLinenBagOrangeRaw;

    $issuedLinenBagYellowRaw = trim((string) ($post['issued_linen_bag_yellow'] ?? ''));
    $issuedLinenBagYellow = $issuedLinenBagYellowRaw === '' ? null : (int) $issuedLinenBagYellowRaw;

    $issuedLinenBagBlueRaw = trim((string) ($post['issued_linen_bag_blue'] ?? ''));
    $issuedLinenBagBlue = $issuedLinenBagBlueRaw === '' ? null : (int) $issuedLinenBagBlueRaw;

    $issuedLaundryNetCountRaw = trim((string) ($post['issued_laundry_net_count'] ?? ''));
    $issuedLaundryNetCount = $issuedLaundryNetCountRaw === '' ? null : (int) $issuedLaundryNetCountRaw;

    return [
        'name' => $name,
        'facility_type' => $facilityType,
        'room_count' => $roomCount,
        'onboarding_start_date' => $onboardingStartDate,
        'pickup_schedule' => $pickupSchedule,
        'address' => $address,
        'phone_number' => $phoneNumber,
        'note' => $note,
        'issued_linen_bag_orange' => $issuedLinenBagOrange,
        'issued_linen_bag_yellow' => $issuedLinenBagYellow,
        'issued_linen_bag_blue' => $issuedLinenBagBlue,
        'issued_laundry_net_count' => $issuedLaundryNetCount,
    ];
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = '不正なリクエストです。再度お試しください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create' || $action === 'update') {
            $values = parse_facility_input($_POST);

            if ($values['name'] === '') {
                $errorMessage = '施設名を入力してください。';
            } elseif ($values['facility_type'] === null) {
                $errorMessage = '施設種別を選択してください。';
            } elseif ($values['onboarding_start_date'] === false) {
                $errorMessage = '受託開始日の形式が正しくありません。';
            } elseif ($values['room_count'] !== null && $values['room_count'] < 0) {
                $errorMessage = '居室数は0以上の数値を入力してください。';
            } elseif ($values['issued_linen_bag_orange'] !== null && $values['issued_linen_bag_orange'] < 0) {
                $errorMessage = '交付リネン袋数（オレンジ）は0以上の数値を入力してください。';
            } elseif ($values['issued_linen_bag_yellow'] !== null && $values['issued_linen_bag_yellow'] < 0) {
                $errorMessage = '交付リネン袋数（黄）は0以上の数値を入力してください。';
            } elseif ($values['issued_linen_bag_blue'] !== null && $values['issued_linen_bag_blue'] < 0) {
                $errorMessage = '交付リネン袋数（青）は0以上の数値を入力してください。';
            } elseif ($values['issued_laundry_net_count'] !== null && $values['issued_laundry_net_count'] < 0) {
                $errorMessage = '交付洗濯ネット数は0以上の数値を入力してください。';
            } elseif ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO facilities (name, facility_type, room_count, onboarding_start_date, pickup_schedule, address, phone_number, note, issued_linen_bag_orange, issued_linen_bag_yellow, issued_linen_bag_blue, issued_laundry_net_count, is_active)
                     VALUES (:name, :facility_type, :room_count, :onboarding_start_date, :pickup_schedule, :address, :phone_number, :note, :issued_linen_bag_orange, :issued_linen_bag_yellow, :issued_linen_bag_blue, :issued_laundry_net_count, 1)'
                );
                $stmt->execute([
                    ':name' => $values['name'],
                    ':facility_type' => $values['facility_type'],
                    ':room_count' => $values['room_count'],
                    ':onboarding_start_date' => $values['onboarding_start_date'],
                    ':pickup_schedule' => $values['pickup_schedule'],
                    ':address' => $values['address'],
                    ':phone_number' => $values['phone_number'],
                    ':note' => $values['note'],
                    ':issued_linen_bag_orange' => $values['issued_linen_bag_orange'],
                    ':issued_linen_bag_yellow' => $values['issued_linen_bag_yellow'],
                    ':issued_linen_bag_blue' => $values['issued_linen_bag_blue'],
                    ':issued_laundry_net_count' => $values['issued_laundry_net_count'],
                ]);
                $newFacilityId = (int) $pdo->lastInsertId();
                record_facility_issuance_stock_adjustment($pdo, null, $values, $newFacilityId, $values['name'], $admin['id']);
                set_flash('success', htmlspecialchars($values['name'], ENT_QUOTES, 'UTF-8') . 'を登録しました。');
                header('Location: /admin/facilities.php');
                exit;
            } else {
                $facilityId = (int) ($_POST['facility_id'] ?? 0);
                $beforeStmt = $pdo->prepare(
                    'SELECT issued_linen_bag_orange, issued_linen_bag_yellow, issued_linen_bag_blue, issued_laundry_net_count FROM facilities WHERE id = :id'
                );
                $beforeStmt->execute([':id' => $facilityId]);
                $beforeValues = $beforeStmt->fetch() ?: null;

                $stmt = $pdo->prepare(
                    'UPDATE facilities
                     SET name = :name, facility_type = :facility_type, room_count = :room_count, onboarding_start_date = :onboarding_start_date,
                         pickup_schedule = :pickup_schedule, address = :address, phone_number = :phone_number, note = :note,
                         issued_linen_bag_orange = :issued_linen_bag_orange, issued_linen_bag_yellow = :issued_linen_bag_yellow,
                         issued_linen_bag_blue = :issued_linen_bag_blue,
                         issued_laundry_net_count = :issued_laundry_net_count
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':name' => $values['name'],
                    ':facility_type' => $values['facility_type'],
                    ':room_count' => $values['room_count'],
                    ':onboarding_start_date' => $values['onboarding_start_date'],
                    ':pickup_schedule' => $values['pickup_schedule'],
                    ':address' => $values['address'],
                    ':phone_number' => $values['phone_number'],
                    ':note' => $values['note'],
                    ':issued_linen_bag_orange' => $values['issued_linen_bag_orange'],
                    ':issued_linen_bag_yellow' => $values['issued_linen_bag_yellow'],
                    ':issued_linen_bag_blue' => $values['issued_linen_bag_blue'],
                    ':issued_laundry_net_count' => $values['issued_laundry_net_count'],
                    ':id' => $facilityId,
                ]);
                record_facility_issuance_stock_adjustment($pdo, $beforeValues, $values, $facilityId, $values['name'], $admin['id']);
                set_flash('success', htmlspecialchars($values['name'], ENT_QUOTES, 'UTF-8') . 'を更新しました。');
                header('Location: /admin/facilities.php');
                exit;
            }
        } elseif ($action === 'disable') {
            $facilityId = (int) ($_POST['facility_id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE facilities SET is_active = 0 WHERE id = :id');
            $stmt->execute([':id' => $facilityId]);
            set_flash('success', '施設を無効化しました。');
            header('Location: /admin/facilities.php');
            exit;
        } elseif ($action === 'enable') {
            $facilityId = (int) ($_POST['facility_id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE facilities SET is_active = 1 WHERE id = :id');
            $stmt->execute([':id' => $facilityId]);
            set_flash('success', '施設を有効化しました。');
            header('Location: /admin/facilities.php');
            exit;
        }
    }
}

$flash = pop_flash();
$csrfToken = csrf_token();

$facilitiesStmt = $pdo->query(
    'SELECT id, name, facility_type, room_count, onboarding_start_date, pickup_schedule, address, phone_number, note,
            issued_linen_bag_orange, issued_linen_bag_yellow, issued_linen_bag_blue, issued_laundry_net_count, is_active
     FROM facilities ORDER BY is_active DESC, name'
);
$facilities = $facilitiesStmt->fetchAll();

// ---- 編集対象の読み込み ----
$editingFacility = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    foreach ($facilities as $facility) {
        if ((int) $facility['id'] === $editId) {
            $editingFacility = $facility;
            break;
        }
    }
}

// ---- フォームの初期値決定 ----
$formAction = 'create';
$formFacilityId = null;
$formName = '';
$formFacilityType = '介護施設';
$formRoomCount = '';
$formOnboardingStartDate = '';
$formPickupSchedule = '';
$formAddress = '';
$formPhoneNumber = '';
$formNote = '';
$formIssuedLinenBagOrange = '';
$formIssuedLinenBagYellow = '';
$formIssuedLinenBagBlue = '';
$formIssuedLaundryNetCount = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== '') {
    $formAction = (string) ($_POST['action'] ?? 'create');
    $formFacilityId = $formAction === 'update' ? (int) ($_POST['facility_id'] ?? 0) : null;
    $formName = (string) ($_POST['name'] ?? '');
    $formFacilityType = (string) ($_POST['facility_type'] ?? '');
    $formRoomCount = (string) ($_POST['room_count'] ?? '');
    $formOnboardingStartDate = (string) ($_POST['onboarding_start_date'] ?? '');
    $formPickupSchedule = (string) ($_POST['pickup_schedule'] ?? '');
    $formAddress = (string) ($_POST['address'] ?? '');
    $formPhoneNumber = (string) ($_POST['phone_number'] ?? '');
    $formNote = (string) ($_POST['note'] ?? '');
    $formIssuedLinenBagOrange = (string) ($_POST['issued_linen_bag_orange'] ?? '');
    $formIssuedLinenBagYellow = (string) ($_POST['issued_linen_bag_yellow'] ?? '');
    $formIssuedLinenBagBlue = (string) ($_POST['issued_linen_bag_blue'] ?? '');
    $formIssuedLaundryNetCount = (string) ($_POST['issued_laundry_net_count'] ?? '');
} elseif ($editingFacility !== null) {
    $formAction = 'update';
    $formFacilityId = (int) $editingFacility['id'];
    $formName = $editingFacility['name'];
    $formFacilityType = $editingFacility['facility_type'];
    $formRoomCount = $editingFacility['room_count'] !== null ? (string) $editingFacility['room_count'] : '';
    $formOnboardingStartDate = (string) ($editingFacility['onboarding_start_date'] ?? '');
    $formPickupSchedule = (string) ($editingFacility['pickup_schedule'] ?? '');
    $formAddress = (string) ($editingFacility['address'] ?? '');
    $formPhoneNumber = (string) ($editingFacility['phone_number'] ?? '');
    $formNote = (string) ($editingFacility['note'] ?? '');
    $formIssuedLinenBagOrange = $editingFacility['issued_linen_bag_orange'] !== null ? (string) $editingFacility['issued_linen_bag_orange'] : '';
    $formIssuedLinenBagYellow = $editingFacility['issued_linen_bag_yellow'] !== null ? (string) $editingFacility['issued_linen_bag_yellow'] : '';
    $formIssuedLinenBagBlue = $editingFacility['issued_linen_bag_blue'] !== null ? (string) $editingFacility['issued_linen_bag_blue'] : '';
    $formIssuedLaundryNetCount = $editingFacility['issued_laundry_net_count'] !== null ? (string) $editingFacility['issued_laundry_net_count'] : '';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>施設管理 | 管理者</title>
    <style>
        body { font-family: sans-serif; margin: 16px; color: #222; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        h1 { font-size: 1.3em; margin: 0; }
        .message { padding: 8px 12px; border-radius: 4px; margin-bottom: 12px; }
        .message.success { background: #e6f4ea; color: #1e7e34; }
        .message.error { background: #fdecea; color: #b3261e; }
        .notice { padding: 8px 12px; background: #fff3cd; color: #856404; border-radius: 4px; }
        section { margin-bottom: 24px; }
        fieldset { border: 1px solid #ccc; border-radius: 4px; padding: 12px; }
        .form-row { margin-bottom: 8px; }
        .form-row label { display: inline-block; width: 170px; vertical-align: top; }
        .form-row input[type="text"], .form-row input[type="date"], .form-row input[type="number"], .form-row select { width: 260px; }
        .form-row textarea { width: 260px; height: 60px; vertical-align: top; }
        table.facilities { border-collapse: collapse; width: 100%; }
        table.facilities th, table.facilities td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        table.facilities th { background: #f5f5f5; }
        .status-badge { display: inline-block; font-size: 0.8em; padding: 2px 8px; border-radius: 10px; }
        .status-active { background: #e6f4ea; color: #1e7e34; }
        .status-disabled { background: #eee; color: #777; }
        .inline-form { display: inline; }
        .note-cell { max-width: 200px; white-space: pre-wrap; word-break: break-all; }
    </style>
</head>
<body>
<header>
    <h1>施設管理</h1>
    <nav>ログイン中: <?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>さん（管理者） | <a href="/admin/dashboard.php">ダッシュボード</a> | <a href="/admin/logout.php">ログアウト</a></nav>
</header>

<?php if ($flash !== null): ?>
    <p class="message <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
    <p class="message error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="facility-form">
    <h2><?= $formAction === 'update' ? '施設情報の編集' : '新規施設登録' ?></h2>
    <fieldset>
        <form method="post" action="/admin/facilities.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="<?= $formAction === 'update' ? 'update' : 'create' ?>">
            <?php if ($formAction === 'update'): ?>
                <input type="hidden" name="facility_id" value="<?= (int) $formFacilityId ?>">
            <?php endif; ?>

            <div class="form-row">
                <label for="name">施設名</label>
                <input type="text" id="name" name="name" maxlength="100" value="<?= htmlspecialchars($formName, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="form-row">
                <label>施設種別</label>
                <?php foreach (FACILITY_TYPES as $type): ?>
                    <label style="width:auto; margin-right:12px; display:inline-block;">
                        <input type="radio" name="facility_type" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= $formFacilityType === $type ? 'checked' : '' ?> required>
                        <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="form-row">
                <label for="room_count">居室数</label>
                <input type="number" id="room_count" name="room_count" min="0" step="1" value="<?= htmlspecialchars($formRoomCount, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="onboarding_start_date">受託開始日</label>
                <input type="date" id="onboarding_start_date" name="onboarding_start_date" value="<?= htmlspecialchars($formOnboardingStartDate, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="pickup_schedule">集荷曜日</label>
                <select id="pickup_schedule" name="pickup_schedule">
                    <option value="">選択してください</option>
                    <?php foreach (FACILITY_PICKUP_SCHEDULES as $schedule): ?>
                        <option value="<?= htmlspecialchars($schedule, ENT_QUOTES, 'UTF-8') ?>" <?= $formPickupSchedule === $schedule ? 'selected' : '' ?>>
                            <?= htmlspecialchars($schedule, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <label for="address">施設住所</label>
                <input type="text" id="address" name="address" maxlength="255" value="<?= htmlspecialchars($formAddress, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="phone_number">電話番号</label>
                <input type="text" id="phone_number" name="phone_number" maxlength="20" value="<?= htmlspecialchars($formPhoneNumber, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="note">備考</label>
                <textarea id="note" name="note"><?= htmlspecialchars($formNote, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="form-row">
                <label for="issued_linen_bag_orange">交付リネン袋数（オレンジ）</label>
                <input type="number" id="issued_linen_bag_orange" name="issued_linen_bag_orange" min="0" step="1" value="<?= htmlspecialchars($formIssuedLinenBagOrange, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="issued_linen_bag_yellow">交付リネン袋数（黄）</label>
                <input type="number" id="issued_linen_bag_yellow" name="issued_linen_bag_yellow" min="0" step="1" value="<?= htmlspecialchars($formIssuedLinenBagYellow, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="issued_laundry_net_count">交付洗濯ネット数</label>
                <input type="number" id="issued_laundry_net_count" name="issued_laundry_net_count" min="0" step="1" value="<?= htmlspecialchars($formIssuedLaundryNetCount, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-row">
                <label for="issued_linen_bag_blue">交付リネン袋数（青）</label>
                <input type="number" id="issued_linen_bag_blue" name="issued_linen_bag_blue" min="0" step="1" value="<?= htmlspecialchars($formIssuedLinenBagBlue, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <button type="submit"><?= $formAction === 'update' ? '更新する' : '登録する' ?></button>
            <?php if ($formAction === 'update'): ?>
                <a href="/admin/facilities.php">キャンセル</a>
            <?php endif; ?>
        </form>
    </fieldset>
</section>

<section class="facility-list">
    <h2>施設一覧</h2>
    <?php if (empty($facilities)): ?>
        <p class="notice">施設が登録されていません。上のフォームから追加してください。</p>
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
                    <th>交付リネン袋数（青）</th>
                    <th>交付洗濯ネット数</th>
                    <th>状態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facilities as $facility): ?>
                    <tr>
                        <td><a href="/admin/facility_detail.php?id=<?= (int) $facility['id'] ?>"><?= htmlspecialchars($facility['name'], ENT_QUOTES, 'UTF-8') ?></a></td>
                        <td><?= htmlspecialchars($facility['facility_type'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $facility['room_count'] !== null ? (int) $facility['room_count'] . '室' : '-' ?></td>
                        <td><?= $facility['onboarding_start_date'] !== null ? htmlspecialchars($facility['onboarding_start_date'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= $facility['pickup_schedule'] !== null ? htmlspecialchars($facility['pickup_schedule'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= $facility['address'] !== null ? htmlspecialchars($facility['address'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td><?= $facility['phone_number'] !== null ? htmlspecialchars($facility['phone_number'], ENT_QUOTES, 'UTF-8') : '-' ?></td>
                        <td class="note-cell"><?= $facility['note'] !== null ? nl2br(htmlspecialchars($facility['note'], ENT_QUOTES, 'UTF-8')) : '-' ?></td>
                        <td><?= $facility['issued_linen_bag_orange'] !== null ? (int) $facility['issued_linen_bag_orange'] . '枚' : '-' ?></td>
                        <td><?= $facility['issued_linen_bag_yellow'] !== null ? (int) $facility['issued_linen_bag_yellow'] . '枚' : '-' ?></td>
                        <td><?= $facility['issued_linen_bag_blue'] !== null ? (int) $facility['issued_linen_bag_blue'] . '枚' : '-' ?></td>
                        <td><?= $facility['issued_laundry_net_count'] !== null ? (int) $facility['issued_laundry_net_count'] . '枚' : '-' ?></td>
                        <td>
                            <?php if ((int) $facility['is_active'] === 1): ?>
                                <span class="status-badge status-active">有効</span>
                            <?php else: ?>
                                <span class="status-badge status-disabled">無効</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/admin/facilities.php?edit=<?= (int) $facility['id'] ?>">編集</a>
                            <?php if ((int) $facility['is_active'] === 1): ?>
                                <form method="post" action="/admin/facilities.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="disable">
                                    <input type="hidden" name="facility_id" value="<?= (int) $facility['id'] ?>">
                                    <button type="submit">無効化</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/admin/facilities.php" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="enable">
                                    <input type="hidden" name="facility_id" value="<?= (int) $facility['id'] ?>">
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
