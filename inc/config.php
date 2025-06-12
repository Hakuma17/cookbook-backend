<?php
// inc/config.php

// — Database config —
define('DB_HOST', 'localhost');
define('DB_NAME', 'cookbook_db');
define('DB_USER', 'root');
define('DB_PASS', '');
// ปิดโชว์ error บนเว็บ
ini_set('display_errors', 0);
error_reporting(0);

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    // บันทึกลง log แล้วโยน exception ขึ้นไปให้ไฟล์เรียกจับ
    error_log('DB Connection failed: ' . $e->getMessage());
    throw new Exception('Database unavailable');
}

// — Google OAuth config —
define('GOOGLE_CLIENT_ID',
  '84901598956-dui13r3k1qmvo0t0kpj6h5mhjrjbvoln.apps.googleusercontent.com'
);
