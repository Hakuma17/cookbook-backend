<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

// Block in production
if (strtolower((string)(getenv('APP_ENV') ?: 'production')) === 'production') {
	http_response_code(403);
	echo 'Forbidden';
	exit;
}

echo 'เวลาปัจจุบันของ PHP: ' . date('c');
echo '<br>';
$now = dbVal('SELECT NOW()');
echo 'เวลาปัจจุบันของ MySQL: ' . date('c', strtotime((string)$now));