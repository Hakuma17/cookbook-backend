<?php
// get_comments.php
// ดึงความคิดเห็นทั้งหมดของสูตรนั้น พร้อม flag is_mine สำหรับผู้ใช้ปัจจุบัน

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
session_start();

$recipeId = intval($_GET['id'] ?? 0);
if ($recipeId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'ต้องระบุ recipe_id'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $sql = "
      SELECT
        r.user_id AS user_id, 
        u.profile_name    AS user_name,
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
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $currentUser = $_SESSION['user_id'] ?? null;
    foreach ($rows as &$r) {
        $r['is_mine'] = ($currentUser && $r['user_id'] == $currentUser) ? 1 : 0;
    }

    echo json_encode([
        'success'  => true,
        'data'     => $rows
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
