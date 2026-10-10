<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * 会計機能のテーブルを作る（一度だけ使う画面）。管理者のみ。実行後はこのファイルをサーバーから削除してください。
 * acc_tables.sql → acc_ledger_tables.sql の順に実行する。何度実行しても既存のデータは消えない（CREATE TABLE IF NOT EXISTS など）。
 */
$admin = require_login('admin');
$pdo = getPdo();
$results = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $results[] = ['error', '不正なリクエストです。画面を再読み込みしてやり直してください。'];
    } else {
        $ran = true;
        foreach (['acc_tables.sql', 'acc_ledger_tables.sql'] as $file) {
            $path = __DIR__ . '/sql/' . $file;
            if (!is_file($path)) {
                $results[] = ['error', $file . ' が見つかりません（admin/sql/ にアップロードしてください）。'];
                break;
            }
            $lines = [];
            foreach (preg_split('/\R/u', (string) file_get_contents($path)) as $line) {
                if (!preg_match('/\A\s*--/u', $line)) {
                    $lines[] = $line;
                }
            }
            $statements = array_filter(array_map('trim', explode(";\n", implode("\n", $lines) . "\n")), static fn (string $s): bool => $s !== '' && $s !== ';');
            $ok = 0;
            foreach ($statements as $sql) {
                try {
                    $pdo->exec(rtrim($sql, ";\n "));
                    $ok++;
                } catch (PDOException $e) {
                    $results[] = ['error', $file . ' のエラー: ' . $e->getMessage() . '  [' . mb_substr(preg_replace('/\s+/u', ' ', $sql), 0, 80) . '…]'];
                }
            }
            $results[] = ['ok', $file . ': ' . $ok . ' 件の命令を実行しました。'];
        }
    }
}
$tables = [];
foreach (['acc_books', 'acc_manual', 'acc_book_settings', 'acc_chart', 'acc_opening', 'acc_attachments', 'acc_audit', 'acc_assets', 'acc_sub_info', 'acc_tax_year'] as $t) {
    try {
        $pdo->query('SELECT 1 FROM ' . $t . ' LIMIT 1');
        $tables[$t] = true;
    } catch (PDOException $e) {
        $tables[$t] = false;
    }
}
?>
<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><title>会計機能のセットアップ</title>
<style>body{font-family:sans-serif;margin:24px;max-width:760px}.ok{color:#0a6b2d}.error{color:#b00020}li{margin:4px 0}</style></head>
<body>
<h1>会計機能のセットアップ</h1>
<p>会計用のテーブルを作成します。既にあるテーブルやデータは消えません。</p>
<?php foreach ($results as [$kind, $msg]) : ?><p class="<?= $kind ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?>
<h3>テーブルの状態</h3>
<ul><?php foreach ($tables as $t => $exists) : ?><li class="<?= $exists ? 'ok' : 'error' ?>"><?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>: <?= $exists ? 'あり' : 'なし' ?></li><?php endforeach; ?></ul>
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit">テーブルを作成する</button>
</form>
<?php if (!in_array(false, $tables, true)) : ?><p class="ok"><strong>すべて揃いました。</strong>このファイル（admin/install_accounting.php）をサーバーから削除してください。<a href="/admin/accounting_input.php">会計画面へ</a></p><?php endif; ?>
</body></html>
