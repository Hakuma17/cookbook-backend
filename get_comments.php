<?php
// get_comments.php
// ดึงความคิดเห็นทั้งหมดของสูตรนั้น

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

$recipeId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($recipeId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ต้องระบุ recipe_id']);
    exit;
}

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
    WHERE r.recipe_id = ?
    ORDER BY r.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$recipeId]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($comments, JSON_UNESCAPED_UNICODE);
