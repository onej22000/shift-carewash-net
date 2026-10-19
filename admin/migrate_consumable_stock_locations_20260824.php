<?php
require_once __DIR__ . '/../includes/auth.php';
require_login('admin');
http_response_code(410);
?>
<!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8"><title>DB更新完了</title></head>
<body><p>このDB更新ページは実行済みのため無効化されています。</p></body></html>