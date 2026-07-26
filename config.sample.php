<?php
// データベース接続設定（shift.carewash.net）
// 実運用では config.php をこのファイルからコピーして値を設定してください（config.php はgit管理対象外）
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// 事業所の位置情報（滋賀県草津市追分南4丁目付近。距離判定ロジックは将来実装）
define('BUSINESS_LAT', 34.997475);
define('BUSINESS_LNG', 135.963574);

// シフト表PDF等に表示する会社名・店舗名
define('COMPANY_NAME', 'フトン巻きのジロー');
define('STORE_NAME', '草津追分店');
