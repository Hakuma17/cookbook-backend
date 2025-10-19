<?php
/**
 * get_user_favorites.php — รายการสูตรโปรดของผู้ใช้
 * =====================================================================
 * โหมด:
 *   - ปกติ: คืนรายละเอียด recipe เต็ม
 *   - ?only_ids=1: คืนเฉพาะ [id,...] (โหมดเบา)
 * คุณลักษณะ:
 *   - กรองตาม user_id (ต้องล็อกอิน)
 *   - has_allergy: ตรวจแบบ “กลุ่ม” (เทียบ newcatagory)
 *   - คืนทั้ง id และ recipe_id เพื่อความเข้ากันได้หลายเวอร์ชันของแอป
 * =====================================================================
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    /* 1) ต้องล็อกอิน */
    $uid = requireLogin();

    /* 2) โหมดเบา: ?only_ids=1 → ส่งกลับอาร์เรย์ id ล้วน */
    $onlyIds = (isset($_GET['only_ids']) && $_GET['only_ids'] === '1');
    if ($onlyIds) {
        $rows = dbAll("SELECT recipe_id AS id FROM favorites WHERE user_id = ?", [$uid]);
        $ids  = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $v = isset($r['id']) ? (int)$r['id'] : null;
                if ($v && $v > 0) {
                    $ids[] = $v;
                }
            }
        }
        jsonOutput(['success' => true, 'data' => $ids]);
        exit;
    }

    /* 3) โหมดเต็ม */
    $baseUploads = rtrim(getBaseUrl(), '/') . '/uploads/recipes';

    $rows = dbAll("
        SELECT
            r.recipe_id,
            r.name,
            r.image_path,
            r.prep_time,
            r.average_rating,
            (SELECT COUNT(*) FROM review     c  WHERE c.recipe_id = r.recipe_id) AS review_count,
            (SELECT COUNT(*) FROM favorites f2 WHERE f2.recipe_id = r.recipe_id) AS favorite_count
        FROM favorites  f
        JOIN recipe     r ON r.recipe_id = f.recipe_id
        WHERE f.user_id = ?
        ORDER BY r.name ASC
    ", [$uid]);

    $data = [];
    foreach ($rows as $r) {
        $hasAllergy = dbVal("
            SELECT COUNT(*)
            FROM recipe_ingredient ri
            JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
            WHERE ri.recipe_id = ?
              AND EXISTS (
                SELECT 1
                FROM allergyinfo a
                JOIN ingredients ia ON ia.ingredient_id = a.ingredient_id
                WHERE a.user_id = ?
                  AND TRIM(ia.newcatagory) = TRIM(i.newcatagory)
              )
        ", [$r['recipe_id'], $uid]) > 0;

        // ประกอบ URL รูปแบบเดียวกับ get_cart_items.php
        $rawPath = trim((string)($r['image_path'] ?? ''));
        if ($rawPath === '') {
            $imageUrl = $baseUploads . '/default_recipe.png';
        } elseif (preg_match('~^(?:https?:)?//~i', $rawPath)) {
            // เป็น URL เต็มอยู่แล้ว
            $imageUrl = $rawPath;
        } else {
            // ใช้ basename เพื่อกัน path แปลก ๆ แล้วต่อกับ base ตามที่เก็บไฟล์จริง
            $imageUrl = $baseUploads . '/' . basename($rawPath);
        }

        $avgRating = isset($r['average_rating']) ? round((float)$r['average_rating'], 1) : 0.0;
        $reviewCnt = isset($r['review_count'])   ? (int)$r['review_count']   : 0;
        $favCnt    = isset($r['favorite_count']) ? (int)$r['favorite_count'] : 0;

        $data[] = [
            'id'             => (int)$r['recipe_id'],
            'recipe_id'      => (int)$r['recipe_id'],
            'name'           => (string)$r['name'],
            'prep_time'      => isset($r['prep_time']) ? (int)$r['prep_time'] : null,
            'average_rating' => $avgRating,
            'review_count'   => $reviewCnt,
            'favorite_count' => $favCnt,
            'is_favorited'   => true,
            'image_path'     => $rawPath,
            'image_url'      => $imageUrl,
            'has_allergy'    => $hasAllergy,
        ];
    }

    jsonOutput(['success' => true, 'data' => $data]);

} catch (Throwable $e) {
    error_log('[get_user_favorites] ' . $e->getMessage() . ' on line ' . $e->getLine());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
