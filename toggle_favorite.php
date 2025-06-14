<?php
// toggle_favorite.php
// เพิ่มหรือลบสูตรโปรดของผู้ใช้ (ต้องล็อกอินก่อน)

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
session_start();

// 1) ตรวจสอบ login
$userId = getLoggedInUserId();
if (! $userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'ต้องล็อกอินก่อน']);
    exit;
}

// 2) รับค่า POST
$recipeId = isset($_POST['recipe_id']) ? intval($_POST['recipe_id']) : 0;
$favorite = isset($_POST['favorite']) && $_POST['favorite'] === '1';

if ($recipeId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'recipe_id ไม่ถูกต้อง']);
    exit;
}

try {
    // 3) เพิ่มหรือลบจาก favorites
    if ($favorite) {
        // เพิ่มเป็นสูตรโปรด (IGNORE ซ้ำ)
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO favorites(user_id, recipe_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$userId, $recipeId]);
    } else {
        // ลบออกจากสูตรโปรด
        $stmt = $pdo->prepare("
            DELETE FROM favorites
            WHERE user_id = ? AND recipe_id = ?
        ");
        $stmt->execute([$userId, $recipeId]);
    }

    // 4) สำเร็จ
    echo json_encode(['success' => true, 'message' => 'สำเร็จ']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
