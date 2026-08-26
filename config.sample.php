<?php
// Example configuration for CareWash Shift & Operations.
//
// Copy this file to config.php and replace the placeholder values.
// config.php is excluded from Git and must never contain credentials that
// are committed to a public repository.

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'change_this_password');

// Optional business location.
// Replace these values if location-aware attendance or distance features
// are enabled in your deployment.
define('BUSINESS_LAT', 0.0);
define('BUSINESS_LNG', 0.0);

// Display names used by PDFs and other screens.
define('COMPANY_NAME', 'Your Company');
define('STORE_NAME', 'Main Office');
