<?php
/**
 * get_user_favorites.php — รายการสูตรโปรดของผู้ใช้
 * =====================================================================
 * โหมด:
 *   - ปกติ: คืนรายละเอียด recipe เต็ม (ชื่อ, รูป, คะแนน, review_count, favorite_count, has_allergy)
 *   - ?only_ids=1: คืนเฉพาะอาร์เรย์ [id,...] (เบา เหมาะ sync เร็ว / badge)
 * คุณลักษณะ:
 *   - กรองโดย user_id (ต้องล็อกอิน)
 *   - has_allergy: ตรวจแบบ “กลุ่ม” (เทียบ newcatagory) ลด false‑negative
 *   - คืนทั้ง id และ recipe_id เพื่อความเข้ากันได้หลายเวอร์ชันของแอป
 * ประสิทธิภาพ:
 *   - มี subquery count review / favorites ต่อ recipe; ถ้าตารางใหญ่ควรพิจารณา materialized fields หรือ join แยก
 * ความปลอดภัย:
 *   - requireLogin() → ห้ามเข้าถึงของผู้อื่น
 * =====================================================================
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // ← helper PDO (dbAll, dbVal, ...)

// NOTE: ไม่ลบของเดิม ใส่เป็นคอมเมนต์ไว้ในส่วนที่เปลี่ยน

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { // อนุญาตเฉพาะ GET
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    /* 1) ต้องล็อกอิน */
    $uid = requireLogin();

    /* 2) โหมดเบา: ?only_ids=1 → ส่งกลับอาร์เรย์ id ล้วน */
    $onlyIds = (isset($_GET['only_ids']) && $_GET['only_ids'] === '1'); // โหมดเบา
    if ($onlyIds) {
        // ใช้คอลัมน์เดียวให้เบาที่สุด
    $rows = dbAll("SELECT recipe_id AS id FROM favorites WHERE user_id = ?", [$uid]); // ดึงเฉพาะ id
        $ids  = [];
        if (is_array($rows)) {
            // map เป็น int ปลอดภัย
            foreach ($rows as $r) {
                $v = isset($r['id']) ? (int)$r['id'] : null;
                if ($v && $v > 0) $ids[] = $v;
            }
        }
        jsonOutput(['success' => true, 'data' => $ids]);
        exit;
    }

    /* 3) โหมดเต็ม: คืนรายละเอียดเมนูโปรด */
    $baseUploads = rtrim(getBaseUrl(), '/').'/uploads/recipes';

    // ดึงข้อมูลหลัก + นับรีวิว/หัวใจปัจจุบัน
    $rows = dbAll("
        SELECT
            r.recipe_id,
            r.name,
            r.average_rating,
            -- นับจำนวนรีวิว
            (SELECT COUNT(*) FROM review  c WHERE c.recipe_id = r.recipe_id) AS review_count,
            -- นับจำนวนคนกดถูกใจทั้งหมดของเมนูนี้
            (SELECT COUNT(*) FROM favorites f2 WHERE f2.recipe_id = r.recipe_id) AS favorite_count
        FROM favorites  f
        JOIN recipe     r ON r.recipe_id = f.recipe_id
        WHERE f.user_id = ?
        ORDER BY r.name ASC
    ", [$uid]);

    $data = [];
    foreach ($rows as $r) {
        // [OLD] วิธีเดิม: เช็คแพ้แบบเทียบ ingredient_id ตรง ๆ (คงไว้เป็นคอมเมนต์)
        /*
        $hasAllergy = dbVal("
            SELECT COUNT(*)
            FROM recipe_ingredient ri
            WHERE ri.recipe_id = ?
              AND ri.ingredient_id IN (
                    SELECT ingredient_id FROM allergyinfo WHERE user_id = ?
              )
        ", [$r['recipe_id'], $uid]) > 0;
        */

        // [NEW] วิธีใหม่: เช็คแพ้แบบ “กลุ่ม” เทียบ newcatagory
                    // [NEW] วิธีใหม่: เช็คแพ้แบบ “กลุ่ม” เทียบ newcatagory (ใช้ EXISTS บน newcatagory เพื่อลดซ้ำ)
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
                    ", [$r['recipe_id'], $uid]) > 0; // เช็คแพ้แบบ “กลุ่ม”

        // สร้าง URL รูป (fallback ถ้าไม่มี)
        $img = !empty($r['image_path'])
            ? ($baseUploads.'/'.basename($r['image_path']))
            : ($baseUploads.'/default_recipe.png');

        $avgRating = isset($r['average_rating']) ? round((float)$r['average_rating'], 1) : 0.0;
        $reviewCnt = isset($r['review_count'])   ? (int)$r['review_count']   : 0;
        $favCnt    = isset($r['favorite_count']) ? (int)$r['favorite_count'] : 0;

        // คืนทั้ง id และ recipe_id เพื่อความเข้ากันได้กับแอปหลายเวอร์ชัน
    $item = [ // โครงสร้างข้อมูลที่ FE คาดหวัง
            'id'              => (int)$r['recipe_id'],
            'recipe_id'       => (int)$r['recipe_id'],
            'name'            => (string)$r['name'],
            'prep_time'       => ($r['prep_time'] !== null ? (int)$r['prep_time'] : null),
            'average_rating'  => $avgRating,
            'review_count'    => $reviewCnt,
            'favorite_count'  => $favCnt,
            'is_favorited'    => true,              // เพราะเป็นเมนูโปรดของผู้ใช้รายนี้
            'image_url'       => $img,
            'has_allergy'     => $hasAllergy,       // ← คิดแบบ “กลุ่ม”
        ];

        $data[] = $item;
    }

    jsonOutput(['success' => true, 'data' => $data]);

} catch (Throwable $e) {
    error_log('[get_user_favorites] '.$e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
