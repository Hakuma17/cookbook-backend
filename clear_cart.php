<?php
// clear_cart.php
// ลบสูตรทั้งหมดในตะกร้าของผู้ใช้

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

try {
    // 1) ตรวจสอบล็อกอินและดึง user_id
    $userId = requireLogin();

    // 2) ลบข้อมูลในตาราง cart ของผู้ใช้
    $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $userId]);

    // 3) ตอบกลับ JSON
    jsonOutput([
        'success' => true,
        'message' => 'ล้างตะกร้าเรียบร้อยแล้ว'
    ]);
} catch (Exception $e) {
    jsonOutput([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ], 500);
}
