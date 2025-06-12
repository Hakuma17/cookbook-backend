<?php
// post_comment.php
// บันทึกความคิดเห็นใหม่ และคืนข้อมูลคอมเมนต์นั้น

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
$comment  = isset($_POST['comment'])   ? trim($_POST['comment']) : '';
$rating   = isset($_POST['rating'])    ? floatval($_POST['rating']) : 0;
if ($recipeId <= 0 || $comment === '') {
    http_response_code(400);
    echo json_encode(['error' => 'ข้อมูลไม่ครบ']); 
    exit;
}

// แทรกคอมเมนต์ใหม่
$ins = $pdo->prepare("INSERT INTO review (recipe_id, user_id, rating, comment, created_at)
                      VALUES (?, ?, ?, ?, NOW())");
$ins->execute([$recipeId, $userId, $rating, $comment]);

// ดึงกลับมา 1 รายการล่าสุด
$sql = "
    SELECT
      r.user_id,
      u.profile_name AS user_name,
      u.path_imgProfile AS avatar_url,
      r.rating,
      r.comment,
      r.created_at
    FROM review r
    JOIN user u ON u.user_id = r.user_id
    WHERE r.recipe_id = ? AND r.user_id = ?
    ORDER BY r.created_at DESC
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$recipeId, $userId]);
$newComment = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
    'success' => true,
    'comment' => $newComment,
], JSON_UNESCAPED_UNICODE);
