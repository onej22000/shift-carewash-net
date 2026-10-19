<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/accounting_ledger.php';

$admin = require_login('admin');
$pdo = getPdo();

/** 証憑ファイル（領収書・請求書の画像／PDF）の配信。管理者のログインが必要。 */

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM acc_attachments WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
$path = $row ? acc_attach_dir() . '/' . basename((string) $row['stored_name']) : '';
if (!$row || !is_file($path)) {
    http_response_code(404);
    echo 'ファイルが見つかりません。';
    exit;
}
header('Content-Type: ' . $row['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode((string) $row['original_name']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
