<?php
// post_rating.php
// บันทึกหรืออัปเดตคะแนนดาว แล้วคำนวณ average ใหม่

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

$userId = getLoggedInUserId();
if (! $userId) {
    http_response_code(401);
    echo json_encode(['error' => 'ต้องล็อกอินก่อน']);
    exit;
}

$recipeId = isset($_POST['recipe_id']) ? intval($_POST['recipe_id']) : 0;
$rating   = isset($_POST['rating'])    ? floatval($_POST['rating']) : 0;
if ($recipeId <= 0 || $rating <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ข้อมูลไม่ครบ']); 
    exit;
}

// อัปเดตหรือเพิ่มคะแนน
$stmt = $pdo->prepare("SELECT COUNT(*) FROM review WHERE recipe_id = ? AND user_id = ?");
$stmt->execute([$recipeId, $userId]);
if ($stmt->fetchColumn() > 0) {
    $upd = $pdo->prepare("UPDATE review SET rating = ? WHERE recipe_id = ? AND user_id = ?");
    $upd->execute([$rating, $recipeId, $userId]);
} else {
    $ins = $pdo->prepare("INSERT INTO review (recipe_id, user_id, rating, comment, created_at)
                          VALUES (?, ?, ?, '', NOW())");
    $ins->execute([$recipeId, $userId, $rating]);
}

// คำนวณ average_rating และ review_count ใหม่
$sqlAvg = "SELECT AVG(rating), COUNT(*) FROM review WHERE recipe_id = ?";
$stmtAvg = $pdo->prepare($sqlAvg);
$stmtAvg->execute([$recipeId]);
list($avg, $count) = $stmtAvg->fetch(PDO::FETCH_NUM);

// ปรับลงในตาราง recipe
$updRec = $pdo->prepare("UPDATE recipe SET average_rating = ?, nReviewer = ? WHERE recipe_id = ?");
$updRec->execute([$avg, $count, $recipeId]);

echo json_encode([
    'success'        => true,
    'average_rating' => round($avg, 2),
    'review_count'   => intval($count),
    'user_rating'    => $rating,
], JSON_UNESCAPED_UNICODE);
