<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/shifts_pdf_render.php';

$admin = require_login('admin');
$pdo = getPdo();

render_monthly_shift_pdf($pdo, (string) ($_GET['month'] ?? ''), ($_GET['layout'] ?? '') === 'split');
