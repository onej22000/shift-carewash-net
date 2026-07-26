<?php
require_once __DIR__ . '/../includes/auth.php';

logout_employee();
header('Location: /admin/login.php');
exit;
