<?php
// clear_cart.php — ล้างตะกร้า

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    /* ──────────────────── 1) ตรวจล็อกอิน ──────────────────── */
    $userId = requireLogin();

    /* ──────────────────── 2) ล้างตะกร้าสูตรอาหาร ──────────────────── */
    dbExec("DELETE FROM cart WHERE user_id = ?", [$userId]);

    /* ──────────────────── 3) ส่งผลลัพธ์ ──────────────────── */
    jsonOutput(['success' => true, 'data' => ['message' => 'ล้างตะกร้าเรียบร้อยแล้ว']]);

} catch (Throwable $e) {
    error_log('[clear_cart] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
