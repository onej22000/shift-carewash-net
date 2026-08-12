<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_login('admin');
$pdo = getPdo();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    set_flash('error', '不正なリクエストです。再度お試しください。');
    header('Location: /admin/boards.php');
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$boardType = (string) ($_POST['board_type'] ?? '');

if (!array_key_exists($boardType, BOARD_TYPES)) {
    set_flash('error', '掲示板の指定が正しくありません。');
    header('Location: /admin/boards.php');
    exit;
}

if ($action === 'create' || $action === 'update') {
    $content = trim((string) ($_POST['content'] ?? ''));
    if ($content === '') {
        set_flash('error', '投稿内容を入力してください。');
    } elseif ($action === 'create') {
        $stmt = $pdo->prepare(
            'INSERT INTO board_posts (board_type, content, created_by) VALUES (:board_type, :content, :created_by)'
        );
        $stmt->execute([
            ':board_type' => $boardType,
            ':content' => $content,
            ':created_by' => $admin['id'],
        ]);
        set_flash('success', BOARD_TYPES[$boardType] . 'に投稿しました。');
    } else {
        $postId = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare(
            'UPDATE board_posts SET content = :content, updated_by = :updated_by
             WHERE id = :id AND board_type = :board_type AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':content' => $content,
            ':updated_by' => $admin['id'],
            ':id' => $postId,
            ':board_type' => $boardType,
        ]);
        set_flash('success', '投稿を更新しました。');
    }
} elseif ($action === 'delete') {
    $postId = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare(
        'UPDATE board_posts SET deleted_at = NOW(), updated_by = :updated_by
         WHERE id = :id AND board_type = :board_type AND deleted_at IS NULL'
    );
    $stmt->execute([
        ':updated_by' => $admin['id'],
        ':id' => $postId,
        ':board_type' => $boardType,
    ]);
    set_flash('success', '投稿を削除しました。');
}

header('Location: /admin/boards.php');
exit;