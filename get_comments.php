<?php
/**
 * get_comments.php — ดึงรายการรีวิว (rating + comment) ทั้งหมดของ recipe หนึ่ง
 * =====================================================================================
 * METHOD: GET
 * QUERY PARAMS:
 *   id (int recipe_id > 0)
 * RESPONSE: { success, data: [ { user_id, user_name, avatar_url, rating, comment, created_at, is_mine } ] }
 * LOGIC STEPS:
 *   1) Validate & รับ recipe_id
 *   2) Query review + user profile (JOIN) เรียงล่าสุดก่อน
 *   3) Normalize avatar_url (absolute / internal path / empty)
 *   4) Flag is_mine (1 = ของ user ที่ล็อกอิน, 0 = คนอื่น)
 * SECURITY:
 *   - Public read (ไม่บังคับล็อกอิน) แต่ใช้ session หากมีเพื่อตั้ง is_mine
 *   - Prepared statements ป้องกัน SQL injection
 * PERFORMANCE:
 *   - Single SELECT + post-processing loop
 *   - TODO: pagination หากรีวิวจำนวนมาก
 * EDGE CASES:
 *   - ไม่มีรีวิว → data = []
 *   - ผู้ใช้ไม่มีรูป → default_avatar.png
 * TODO:
 *   - เพิ่ม pagination & total_count
 *   - รองรับ ordering ทางเลือก (เช่น rating สูงสุดก่อน)
 * =====================================================================================
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { // จำกัดเฉพาะ GET
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$recipeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0; // รับ recipe_id จาก query string
if ($recipeId <= 0) { // Validate ขั้นพื้นฐาน
    jsonOutput(['success' => false, 'message' => 'ต้องระบุ recipe_id'], 400);
}

try {
    // (1) ดึงคอมเมนต์ + ข้อมูลผู้ใช้: JOIN เพื่อลดจำนวน query
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

    // (2) Normalize avatar_url + flag is_mine
    $me            = getLoggedInUserId();            // ล็อกอินหรือไม่ (null ถ้าไม่)
    $uploadsPrefix = 'uploads/users/';               // โฟลเดอร์รูปโปรไฟล์
    $baseUploads   = rtrim(getBaseUrl(), '/') . '/'; // ให้แน่ใจว่ามี / ปิดท้าย

    foreach ($rows as &$r) {
        $file = trim((string)($r['avatar_url'] ?? ''));
        if ($file !== '' && preg_match('/^https?:\/\//i', $file)) { // absolute URL → ใช้ตรงๆ
            $r['avatar_url'] = $file;
        } elseif ($file !== '') { // internal path / filename
            $file = ltrim($file, '/');
            if (stripos($file, $uploadsPrefix) === 0) { // ป้องกัน prefix ซ้ำ
                $file = substr($file, strlen($uploadsPrefix));
            }
            $r['avatar_url'] = $baseUploads . $uploadsPrefix . $file;
        } else { // ไม่มีข้อมูลรูป → default
            $r['avatar_url'] = $baseUploads . $uploadsPrefix . 'default_avatar.png';
        }
        $r['is_mine'] = ($me && (int)$r['user_id'] === (int)$me) ? 1 : 0; // ใช้ได้แม้ guest (me = null)
    }
    unset($r);

    jsonOutput(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    error_log('[get_comments] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
