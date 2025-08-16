<?php
// get_comments.php — ดึงคอมเมนต์ทั้งหมด + is_mine (fixed avatar URL building)

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
        SELECT
            r.user_id,
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

    /* ───────── 2) ประกอบ URL รูป + ติด flag is_mine ───────── */
    $me            = getLoggedInUserId();                 // user_id ที่ล็อกอิน (หรือ null)
    $uploadsPrefix = 'uploads/users/';                    // โฟลเดอร์จริงของรูปโปรไฟล์
    $baseUploads   = rtrim(getBaseUrl(), '/') . '/';      // ex. https://example.com/

    foreach ($rows as &$r) {
        $file = trim((string)($r['avatar_url'] ?? ''));

        if ($file !== '' && preg_match('/^https?:\/\//i', $file)) {
            // เป็น URL เต็มอยู่แล้ว → ใช้เลย
            $r['avatar_url'] = $file;
        } elseif ($file !== '') {
            // เป็นพาธภายในหรือไฟล์เนม
            // - ตัดสแลชหัว
            // - ตัด prefix ซ้ำ 'uploads/users/' ถ้ามี เพื่อไม่ให้ประกบซ้ำ
            $file = ltrim($file, '/');
            if (stripos($file, $uploadsPrefix) === 0) {
                $file = substr($file, strlen($uploadsPrefix)); // เหลือแค่ชื่อไฟล์
            }
            $r['avatar_url'] = $baseUploads . $uploadsPrefix . $file;
        } else {
            // ไม่ได้ตั้งรูป → ชี้ไป default
            $r['avatar_url'] = $baseUploads . $uploadsPrefix . 'default_avatar.png';
        }

        // is_mine: 1 = ของฉัน, 0 = คนอื่น
        $r['is_mine'] = ($me && (int)$r['user_id'] === (int)$me) ? 1 : 0;
    }
    unset($r); // break reference

    jsonOutput(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    error_log('[get_comments] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
