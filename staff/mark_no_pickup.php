<?php
require_once __DIR__ . '/../includes/auth.php';

$staff = require_login('staff');
$pdo = getPdo();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /staff/dashboard.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    set_flash('error', '不正なリクエストです。再度お試しください。');
    header('Location: /staff/dashboard.php');
    exit;
}

$facilityId = (int) ($_POST['facility_id'] ?? 0);
$pickupDate = (string) ($_POST['pickup_date'] ?? '');

if ($facilityId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pickupDate)) {
    set_flash('error', '不正なリクエストです。再度お試しください。');
    header('Location: /staff/dashboard.php');
    exit;
}

$facilityStmt = $pdo->prepare(
    "SELECT id, name FROM facilities WHERE id = :id AND facility_type = '介護施設'"
);
$facilityStmt->execute([':id' => $facilityId]);
$facility = $facilityStmt->fetch();

if ($facility === false) {
    set_flash('error', '対象の施設が見つかりません。');
    header('Location: /staff/dashboard.php');
    exit;
}

// UNIQUE KEY (facility_id, pickup_date) との重複はエラーにせず成功として扱う（冪等性、二重送信対策）。
$insertStmt = $pdo->prepare(
    'INSERT IGNORE INTO no_pickup_confirmations (facility_id, pickup_date, staff_id)
     VALUES (:facility_id, :pickup_date, :staff_id)'
);
$insertStmt->execute([
    ':facility_id' => $facilityId,
    ':pickup_date' => $pickupDate,
    ':staff_id' => $staff['id'],
]);

set_flash('success', $facility['name'] . '（' . $pickupDate . '）を集荷なしとして確認しました。');
header('Location: /staff/dashboard.php');
exit;
