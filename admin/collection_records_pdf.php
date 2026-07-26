<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/collection_records_pdf_render.php';

$admin = require_login('admin');
$pdo = getPdo();

render_collection_records_pdf($pdo, (int) ($_GET['facility_id'] ?? 0), (string) ($_GET['month'] ?? ''));
