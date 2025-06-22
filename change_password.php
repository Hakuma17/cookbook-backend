<?php
// change_password.php — เปลี่ยนรหัสผ่าน

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$userId      = requireLogin();
$oldPassword = $_POST['old_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';

if ($oldPassword === '' || $newPassword === '') {
    jsonOutput(['success' => false, 'message' => 'กรุณาระบุรหัสผ่านเก่าและใหม่'], 400);
}

try {
    /* ──────────────────── 1) ตรวจสอบรหัสผ่านเดิม ──────────────────── */
    $hash = dbVal("SELECT password FROM user WHERE user_id = ?", [$userId]);

    if (!$hash || !password_verify($oldPassword, $hash)) {
        jsonOutput(['success' => false, 'message' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง'], 401);
    }

    /* ──────────────────── 2) อัปเดตรหัสผ่านใหม่ ──────────────────── */
    $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);

    dbExec("UPDATE user SET password = ? WHERE user_id = ?", [$newHash, $userId]);

    jsonOutput(['success' => true, 'data' => ['message' => 'เปลี่ยนรหัสผ่านสำเร็จ']]);

} catch (Throwable $e) {
    error_log('[change_password] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
