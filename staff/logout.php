<?php
require_once __DIR__ . '/../includes/auth.php';

logout_employee();
header('Location: /staff/login.php');
exit;
