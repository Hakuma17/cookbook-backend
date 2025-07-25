<?php
// get_comments.php — ดึงคอมเมนต์ทั้งหมด + is_mine

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$recipeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($recipeId <= 0) {
    jsonOutput(['success' => false, 'message' => 'ต้องระบุ recipe_id'], 400);
}

try {
    /* ───────── 1) ดึงคอมเมนต์ ───────── */
    $rows = dbAll("
        SELECT r.user_id,
               COALESCE(u.profile_name, '')    AS user_name,
               COALESCE(u.path_imgProfile, '') AS avatar_url,
               r.rating,
               r.comment,
               r.created_at
        FROM review r
        JOIN user u ON u.user_id = r.user_id
        WHERE r.recipe_id = ?
        ORDER BY r.created_at DESC
    ", [$recipeId]);

    /* ───────── 2) ติด flag is_mine + เตรียม URL รูป ───────── */
    $me = getLoggedInUserId();                       // user_id ที่ล็อกอิน (หรือ null)
    $baseUploads = getBaseUrl() . '/uploads/users';  // ไว้ต่อกับไฟล์ local

    foreach ($rows as &$r) {
        $file = trim($r['avatar_url']);

        // ถ้าเป็น URL เต็ม (ขึ้นต้นด้วย http/https) → ใช้เลย
        if ($file !== '' && preg_match('/^https?:\/\//i', $file)) {
            $r['avatar_url'] = $file;
        }
        // ถ้าเป็น path local → ต่อ BASE_URL
        elseif ($file !== '') {
            $r['avatar_url'] = $baseUploads . '/' . ltrim($file, '/');
        }
        // ไม่ได้ตั้งรูป → ใช้ default
        else {
            $r['avatar_url'] = $baseUploads . '/default_avatar.png';
        }

        // is_mine: 1 = ของฉัน, 0 = คนอื่น
        $r['is_mine'] = ($me && $r['user_id'] == $me) ? 1 : 0;
    }
    unset($r); // break reference

    jsonOutput(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    error_log('[get_comments] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
