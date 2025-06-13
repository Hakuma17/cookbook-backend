<?php
// update_cart.php
// เพิ่มหรืออัปเดตจำนวนเสิร์ฟของสูตรอาหารในตะกร้า

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
session_start();

try {
    // 1) ตรวจสอบการล็อกอิน
    $userId = getLoggedInUserId();
    if (! $userId) {
        echo json_encode([
            'success' => false,
            'message' => 'กรุณาเข้าสู่ระบบก่อน'
        ]);
        exit;
    }

    // 2) รับค่า POST
    $recipeId = isset($_POST['recipe_id']) ? intval($_POST['recipe_id']) : 0;
    $count    = isset($_POST['count'])     ? floatval($_POST['count']) : 0.0;

    if ($recipeId <= 0 || $count <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'ข้อมูลไม่ถูกต้อง'
        ]);
        exit;
    }

    // 3) ตรวจสอบว่ามีอยู่แล้วในตะกร้าไหม
    $checkSql  = "SELECT 1 FROM cart WHERE user_id = ? AND recipe_id = ? LIMIT 1";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([$userId, $recipeId]);
    $exists    = $checkStmt->fetchColumn();

    if ($exists) {
        // 4) ถ้ามี → อัปเดต
        $updateSql  = "UPDATE cart SET nServings = ? WHERE user_id = ? AND recipe_id = ?";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([$count, $userId, $recipeId]);

        echo json_encode([
            'success' => true,
            'message' => 'อัปเดตตะกร้าเรียบร้อย'
        ]);
    } else {
        // 5) ยังไม่มี → แทรกใหม่
        $insertSql  = "INSERT INTO cart (user_id, recipe_id, nServings) VALUES (?, ?, ?)";
        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute([$userId, $recipeId, $count]);

        echo json_encode([
            'success' => true,
            'message' => 'เพิ่มสินค้าในตะกร้าเรียบร้อย'
        ]);
    }

} catch (Exception $e) {
    // แม้เกิดข้อผิดพลาด ก็คืน JSON และให้ Dart อ่านได้เสมอ
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
    exit;
}
