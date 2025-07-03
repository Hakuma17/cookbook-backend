<?php
/* inc/functions.php */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/json.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ─────────  PDO ───────── */
$__pdo = null;
function pdo(): PDO
{
    global $__pdo;
    if ($__pdo === null) {
        $__pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,     // ✨ เพิ่ม
            ]
        );
    }
    return $__pdo;
}

/* ───────── Helpers ───────── */
function sanitize(string $v): string
{
    return trim($v);  // ✨ ไม่ escape HTML เพื่อให้ LIKE ตรงเป๊ะ
}

function getBaseUrl(): string
{
    // สร้าง scheme พร้อม "://"
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // dirname(...) คืน path ของสคริปต์ปัจจุบัน (เช่น /cookbookapp)
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

    // กลับมาเป็น URL เต็ม ไม่ลด "://"
    return rtrim($scheme . $host . $dir, '/');
}

function getLoggedInUserId(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

function requireLogin(): int
{
    $uid = getLoggedInUserId();
    if (!$uid) {
        jsonOutput(['success' => false, 'message' => 'ต้องล็อกอินก่อน'], 401);
    }
    return $uid;
}

function respond(bool $ok, array $data = [], int $code = 200): void
{
    jsonOutput([
        'success' => $ok,
        'message' => $ok ? 'ดำเนินการสำเร็จ' : ($data['message'] ?? 'เกิดข้อผิดพลาด'),
        'data'    => $ok ? $data : (object)[],
    ], $code);
}
