<?php
// get_profile.php — ดึงข้อมูลโปรไฟล์ของผู้ใช้ที่ล็อกอิน

require_once __DIR__ . '/inc/config.php';
// ★ แก้ลำดับ: include functions ก่อน เพื่อให้ pdo()/jsonOutput()/requireLogin() พร้อมใช้
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

// ★ ลบฟังก์ชัน dbRow() เฉพาะกิจเดิมที่ใช้ global $pdo ออก
//   ตอนนี้ใช้ dbRow() ที่ประกาศไว้ใน inc/db.php ซึ่งใช้ pdo() ถูกต้องแล้ว

/**
 * Executes a prepared statement and returns a single row as an associative array.
 *
 * @param string $sql The SQL query to execute.
 * @param array $params The parameters to bind to the query.
 * @return array|null The row as an associative array, or null if no row is found or an error occurs.
 */
// (คอมเมนต์อธิบายไว้เพื่ออ้างอิง — การ implement ย้ายไปอยู่ inc/db.php แล้ว)

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$uid = requireLogin(); // ต้องมีเซสชัน

try {
    // ดึงข้อมูลจากตาราง user
    $u = dbRow("
        SELECT user_id, email, profile_name, profile_info, path_imgProfile, google_id, created_at
          FROM user
         WHERE user_id = ?
        LIMIT 1
    ", [$uid]);

    if (!$u) {
        jsonOutput(['success' => false, 'message' => 'ไม่พบผู้ใช้'], 404);
    }

    // เตรียม path/URL รูป
    $path = trim((string)($u['path_imgProfile'] ?? ''));
    if (($qPos = strpos($path, '?')) !== false) {
        $path = substr($path, 0, $qPos);
    }
    // ★ กัน backslash
    $path = str_replace('\\', '/', $path);

    if ($path === '') {
        $path = 'uploads/users/default_avatar.png';
    }

    $base = rtrim(getBaseUrl(), '/'); // เช่น http://10.0.2.2/cookbookapp
    $url  = $base . '/' . ltrim($path, '/');

    $data = [
        'user_id'         => (int)$u['user_id'],
        'email'           => (string)$u['email'],
        'profile_name'    => (string)($u['profile_name'] ?? ''),
        'profile_info'    => (string)($u['profile_info'] ?? ''),
        'path_imgProfile' => $path,    // เก็บ path ฝั่งเซิร์ฟเวอร์
        'image_url'       => $url,     // URL เต็มไว้แสดงผล
        'google_id'       => (string)($u['google_id'] ?? ''),
        'created_at'      => (string)($u['created_at'] ?? ''),
    ];

    jsonOutput(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    error_log('[get_profile] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
