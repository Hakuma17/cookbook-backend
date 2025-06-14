<?php
// post_rating.php
// บันทึกหรืออัปเดตคะแนนดาว แล้วคำนวณ average ใหม่

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
session_start();

$userId = getLoggedInUserId();
if (! $userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'ต้องล็อกอินก่อน'], JSON_UNESCAPED_UNICODE);
    exit;
}

$recipeId = isset($_POST['recipe_id']) ? intval($_POST['recipe_id']) : 0;
$rating   = isset($_POST['rating'])    ? floatval($_POST['rating']) : 0;
if ($recipeId <= 0 || $rating <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบ'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // อัปเดตหรือเพิ่มคะแนน
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM review WHERE recipe_id = ? AND user_id = ?");
    $stmt->execute([$recipeId, $userId]);
    if ($stmt->fetchColumn() > 0) {
        $upd = $pdo->prepare("UPDATE review SET rating = ? WHERE recipe_id = ? AND user_id = ?");
        $upd->execute([$rating, $recipeId, $userId]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO review (recipe_id, user_id, rating, comment, created_at)
            VALUES (?, ?, ?, '', NOW())
        ");
        $ins->execute([$recipeId, $userId, $rating]);
    }

    // คำนวณ average_rating และ review_count ใหม่
    $sqlAvg = "SELECT AVG(rating) AS avg_rating, COUNT(*) AS count_rating FROM review WHERE recipe_id = ?";
    $stmtAvg = $pdo->prepare($sqlAvg);
    $stmtAvg->execute([$recipeId]);
    $avgRow = $stmtAvg->fetch(PDO::FETCH_ASSOC);
    $avg   = floatval($avgRow['avg_rating']   ?? 0);
    $count = intval($avgRow['count_rating']  ?? 0);

    // ปรับลงในตาราง recipe
    $updRec = $pdo->prepare("UPDATE recipe SET average_rating = ?, nReviewer = ? WHERE recipe_id = ?");
    $updRec->execute([round($avg, 2), $count, $recipeId]);

    echo json_encode([
        'success'        => true,
        'average_rating' => round($avg, 2),
        'review_count'   => $count,
        'user_rating'    => $rating,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
