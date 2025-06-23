<?php
// get_comments.php — ดึงคอมเมนต์ทั้งหมด + is_mine

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$recipeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($recipeId <= 0) {
    jsonOutput(['success' => false, 'message' => 'ต้องระบุ recipe_id'], 400);
}

try {
    /* 1) ดึงคอมเมนต์ */
    $rows = dbAll("
        SELECT r.user_id,
               u.profile_name    AS user_name,
               u.path_imgProfile AS avatar_url,
               r.rating,
               r.comment,
               r.created_at
        FROM review r
        JOIN user u ON u.user_id = r.user_id
        WHERE r.recipe_id = ?
        ORDER BY r.created_at DESC
    ", [$recipeId]);

    /* 2) ติด flag is_mine + ทำ URL รูปโปรไฟล์ให้เต็ม */
    $me = getLoggedInUserId();
    $baseUrl = getBaseUrl() . '/uploads/users';

    foreach ($rows as &$r) {
        $file = trim($r['avatar_url'] ?? '');
        $r['avatar_url'] = $file !== ''
            ? $baseUrl . '/' . basename($file)
            : $baseUrl . '/default_avatar.png';
        $r['is_mine'] = ($me && $r['user_id'] == $me) ? 1 : 0;
    }
    unset($r);

    jsonOutput(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    error_log('[get_comments] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
