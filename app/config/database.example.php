<?php
// Copy to database.custom.php and configure using the values from your hosting panel.
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'betternotebook');
define('DB_USER', 'betternotebook');
define('DB_PASS', '');

// Generate independent 32-byte hex values with: php scripts/database.php secret
define('BETTERNOTEBOOK_ENCRYPTION_KEY', '');
define('BETTERNOTEBOOK_SETUP_TOKEN', ''); // Fresh installs only. Remove after setup.
