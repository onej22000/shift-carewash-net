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

$openStmt = $pdo->prepare(
    "SELECT id, break_start_at, break_end_at, total_break_minutes
     FROM attendance
     WHERE employee_id = :employee_id AND status = 'working' AND DATE(clock_in_at) = CURDATE()
       AND deleted_at IS NULL
     ORDER BY clock_in_at DESC
     LIMIT 1"
);
$openStmt->execute([':employee_id' => $staff['id']]);
$openRecord = $openStmt->fetch();

if ($openRecord === false) {
    set_flash('error', '出勤中の記録が見つかりません。');
    header('Location: /staff/dashboard.php');
    exit;
}

$isOnBreak = $openRecord['break_start_at'] !== null && $openRecord['break_end_at'] === null;
$action = (string) ($_POST['action'] ?? '');

if ($action === 'start') {
    if ($isOnBreak) {
        set_flash('error', '既に休憩中です。');
        header('Location: /staff/dashboard.php');
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE attendance SET break_start_at = :now, break_end_at = NULL WHERE id = :id"
    );
    $stmt->execute([':now' => (new DateTime())->format('Y-m-d H:i:s'), ':id' => $openRecord['id']]);

    set_flash('success', '休憩に入りました。');
    header('Location: /staff/dashboard.php');
    exit;
}

if ($action === 'end') {
    if (!$isOnBreak) {
        set_flash('error', '休憩中ではありません。');
        header('Location: /staff/dashboard.php');
        exit;
    }

    $breakStart = new DateTime($openRecord['break_start_at']);
    $now = new DateTime();
    $breakMinutes = max(0, (int) round(($now->getTimestamp() - $breakStart->getTimestamp()) / 60));
    $totalBreakMinutes = (int) ($openRecord['total_break_minutes'] ?? 0) + $breakMinutes;

    $stmt = $pdo->prepare(
        "UPDATE attendance SET break_end_at = :now, total_break_minutes = :total WHERE id = :id"
    );
    $stmt->execute([
        ':now' => $now->format('Y-m-d H:i:s'),
        ':total' => $totalBreakMinutes,
        ':id' => $openRecord['id'],
    ]);

    set_flash('success', '休憩から戻りました。');
    header('Location: /staff/dashboard.php');
    exit;
}

header('Location: /staff/dashboard.php');
exit;
