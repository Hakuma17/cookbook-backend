<?php
// inc/config.php

// — Database config —
define('DB_HOST', 'localhost');
define('DB_NAME', 'cookbook_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// — Google OAuth config —
define('GOOGLE_CLIENT_ID',
  '84901598956-dui13r3k1qmvo0t0kpj6h5mhjrjbvoln.apps.googleusercontent.com'
);

// — เปิด error log ช่วง dev —
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
