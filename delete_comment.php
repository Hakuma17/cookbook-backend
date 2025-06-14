<?php
// delete_comment.php — ลบรีวิวของผู้ใช้ต่อสูตรที่ระบุ
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
session_start();

$userId   = getLoggedInUserId();
$recipeId = intval($_POST['recipe_id'] ?? 0);

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'ต้องล็อกอินก่อน'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($recipeId <= 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'recipe_id ไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ลบแถวที่ตรงกับ user_id + recipe_id
$stmt = $pdo->prepare("DELETE FROM review WHERE recipe_id = ? AND user_id = ?");
$stmt->execute([$recipeId, $userId]);

// ——————————————
// รีคอล์ค average_rating และ nReviewer ในตาราง recipe
$avgStmt = $pdo->prepare("
    SELECT
      AVG(rating)    AS avg_rating,
      COUNT(*)       AS count_rating
    FROM review
    WHERE recipe_id = ?
");
$avgStmt->execute([$recipeId]);
$avgRow = $avgStmt->fetch(PDO::FETCH_ASSOC);
$avg   = floatval($avgRow['avg_rating']   ?? 0);
$count = intval($avgRow['count_rating']  ?? 0);

$updRec = $pdo->prepare("
    UPDATE recipe
       SET average_rating = ?, nReviewer = ?
     WHERE recipe_id = ?
");
$updRec->execute([round($avg, 2), $count, $recipeId]);
// ——————————————

echo json_encode([
    'success'        => true,
    'average_rating' => round($avg, 2),
    'review_count'   => $count
], JSON_UNESCAPED_UNICODE);
