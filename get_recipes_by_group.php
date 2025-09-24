<?php
/**
 * get_recipes_by_group.php — ดึงรายการสูตรที่มีส่วนผสมอยู่ใน “กลุ่มวัตถุดิบ (newcatagory)” ที่กำหนด
 * =====================================================================================
 * QUERY PARAMS:
 *   group (string, required)   : ชื่อกลุ่ม (TRIM แล้วเทียบตรงๆ, ควรคำนึงเรื่องตัวพิมพ์)
 *   sort  (string, optional)   : latest | popular | recommended | name_asc (default: latest)
 *   page  (int, optional)      : เริ่มที่ 1
 *   limit (int, optional)      : 1..50 (default 26)
 *
 * SORT MODES:
 *   latest       → r.created_at DESC
 *   popular      → favorite_count DESC, average_rating DESC
 *   recommended  → average_rating DESC, review_count DESC
 *   name_asc     → r.name ASC
 *
 * RESPONSE (success=true):
 * {
 *   success, group, page, limit,
 *   total (จำนวนสูตรในกลุ่มนี้ทั้งหมด),
 *   total_recipes (จำนวนสูตรทุกกลุ่มรวม ใช้อ้างอิงสัดส่วน),
 *   has_next (bool), count (จำนวนที่ส่งกลับในหน้านี้),
 *   data: [ { recipe_id, name, image_url, prep_time, favorite_count,
 *            average_rating, review_count, short_ingredients, ingredient_ids[], has_allergy } ... ]
 * }
 *
 * ALLERGY:
 *   - has_allergy: EXISTS ส่วนผสมในสูตรที่ newcatagory ไปชนกับรายการแพ้ของผู้ใช้
 *
 * PERFORMANCE / DB NOTES:
 *   - ใช้ subqueries ต่อ recipe (favorite_count, avg rating, review_count, short ingredients)
 *   - ป้องกัน row duplication โดยไม่ join ตรง review/favorites เป็นหลัก ใช้ correlated subquery แทน
 *   - แนะนำดัชนี: ingredients(newcatagory), recipe_ingredient(recipe_id,ingredient_id), favorites(recipe_id), review(recipe_id)
 *   - GROUP_CONCAT short_ingredients: หากต้องการเพิ่มความยาว → SET SESSION group_concat_max_len
 *
 * PAGINATION:
 *   - total = COUNT(*) สูตรทั้งหมดในกลุ่ม (query COUNT แยก)
 *   - has_next = (page * limit < total)
 *
 * TODO / EXTENSIONS:
 *   - เพิ่ม allergy_groups / allergy_names เช่น endpoints อื่น
 *   - เพิ่ม filter อื่น (min_rating, has_allergy=0, time<=X ฯลฯ)
 *   - ควร normalise group case-insensitive (LOWER) ถ้าคอนซิสต์
 * =====================================================================================
 */

require_once __DIR__ . '/inc/config.php';   // โหลดคอนฟิกพื้นฐาน (timezone, error mode, autoload composer)
require_once __DIR__ . '/inc/functions.php'; // ฟังก์ชันอรรถประโยชน์กลาง (session, sanitize, getBaseUrl, ฯลฯ)
require_once __DIR__ . '/inc/db.php';        // wrapper PDO (dbAll, dbVal, dbOne) ช่วยลดซ้ำ และ ensure prepared

header('Content-Type: application/json; charset=UTF-8'); // บังคับ charset ป้องกันปัญหา UTF-8 แตก

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {               // ป้องกัน method อื่น (POST/PUT/DELETE) มาเรียกผิดที่
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$group  = isset($_GET['group']) ? (string)$_GET['group'] : ''; // กลุ่ม (newcatagory) เป้าหมาย
$group  = trim($group);                                        // ตัดช่องว่างหัวท้าย (สำคัญก่อนเทียบตรง)
$sort   = strtolower(trim($_GET['sort'] ?? 'latest'));         // โหมดเรียง
$page   = max(1, (int)($_GET['page'] ?? 1));                   // page >= 1 เสมอ
$limit  = max(1, min(50, (int)($_GET['limit'] ?? 26)));        // ควบคุม upper bound กัน DOS ดึงทีละเยอะ
$offset = ($page - 1) * $limit;                                // offset สำหรับ LIMIT

// ดึง user id จาก session (ถ้าไม่ล็อกอินจะได้ null) — ใช้สำหรับ has_allergy
$uid = getLoggedInUserId();
// เผื่อรองรับ use-case บาง client ส่ง user_id เพิ่ม (ไม่แนะนำ แต่รักษาความเข้ากันได้)
if (!$uid && isset($_GET['user_id'])) {
    $tmp = (int)$_GET['user_id'];
    if ($tmp > 0) $uid = $tmp; // ยอมรับเฉพาะค่าบวก
}

if ($group === '') { // ต้องมี group เสมอ จึงจะค้นหาได้
    jsonOutput(['success' => false, 'message' => 'Missing group'], 400);
}

try {
    // เพิ่มเพดานความยาว GROUP_CONCAT (เผื่อกลุ่มที่มีส่วนผสมยาวมาก)
    try { dbAll("SET SESSION group_concat_max_len = 4096"); } catch (Throwable $e) { /* เงียบได้ ไม่ critical */ }

    // ★ RECOMMEND INDEXES (บันทึกเพื่อทีม DBA):
    //   ingredients(newcatagory)
    //   recipe_ingredient(recipe_id), recipe_ingredient(ingredient_id)
    //   favorites(recipe_id,user_id)
    //   review(recipe_id,user_id)

    // แปลงพารามิเตอร์ sort เป็น ORDER BY ที่ปลอดภัย (จำกัด whitelist ผ่าน match)
    $orderBy = match ($sort) {
        'name_asc'    => 'r.name ASC',                                  // เรียงตามชื่อ A→Z
        'popular'     => 'favorite_count DESC, average_rating DESC',    // เมนูที่โดน favorite มากที่สุด มาก่อน
        'recommended' => 'average_rating DESC, review_count DESC',      // เน้นคะแนนเฉลี่ยสูงตามด้วยจำนวนรีวิว
        default       => 'r.created_at DESC',                           // ล่าสุด (fallback)
    };

    // รวมจำนวนสูตรทั้งหมดในกลุ่ม (ใช้ EXISTS เพื่อยืนยันว่ามีอย่างน้อยหนึ่ง ingredient ในกลุ่ม)
    $countSql = "
        SELECT COUNT(*) AS cnt
        FROM recipe r
        WHERE EXISTS (
            SELECT 1
            FROM recipe_ingredient ri_g
            JOIN ingredients i_g ON i_g.ingredient_id = ri_g.ingredient_id
            WHERE ri_g.recipe_id = r.recipe_id
              AND i_g.newcatagory = :group
        )
    ";
    $totalRow = dbAll($countSql, [':group' => $group]);              // คืน array แถวเดียว
    $total    = isset($totalRow[0]['cnt']) ? (int)$totalRow[0]['cnt'] : 0; // ปลอดภัยถ้า null → 0

    // ★ Query หลัก: เลี่ยง CROSS JOIN/ROW MULTIPLY โดยใช้ correlated subquery ซ้อน
    //   แต่ละ meta (favorite_count, average_rating, review_count, short_ingredients) คำนวณแยก
    //   trade-off: อ่านง่าย + deterministic แต่มีหลาย subquery → อาจช้าถ้า table ใหญ่มาก (พิจารณา cache / summary table)
    $sql = "
        SELECT 
            r.recipe_id,
            r.name,
            r.image_path,
            r.prep_time,

            /* meta */
                (SELECT COUNT(*)                  -- จำนวนคน favorite เมนูนี้
               FROM favorites f
              WHERE f.recipe_id = r.recipe_id) AS favorite_count,

                (SELECT COALESCE(AVG(rv.rating),0) -- คะแนนเฉลี่ย (NULL → 0)
               FROM review rv
              WHERE rv.recipe_id = r.recipe_id) AS average_rating,

                (SELECT COUNT(*)                  -- จำนวนรีวิวทั้งหมด
               FROM review rv2
              WHERE rv2.recipe_id = r.recipe_id) AS review_count,

            /* short ingredients + ids */
            (SELECT GROUP_CONCAT(              -- สร้างข้อความย่อรายชื่อวัตถุดิบ (ไม่ซ้ำ)
                        DISTINCT CASE
                            WHEN ri.descrip <> '' THEN ri.descrip   -- ถ้ามี descrip (รายละเอียด) ใช้อันนั้น
                            ELSE i.display_name                     -- มิฉะนั้น fallback เป็น display_name
                        END
                        SEPARATOR ', ')
               FROM recipe_ingredient ri
               JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
              WHERE ri.recipe_id = r.recipe_id) AS short_ingredients,

                (SELECT GROUP_CONCAT(DISTINCT ri2.ingredient_id) -- รายการ ingredient_id สำหรับ client ใช้ detail ต่อ
               FROM recipe_ingredient ri2
              WHERE ri2.recipe_id = r.recipe_id) AS ingredient_ids,

                        /* has_allergy แบบกลุ่ม (เทียบ newcatagory) */
            " . ($uid ? "EXISTS (
                SELECT 1
                FROM recipe_ingredient ri_all
                JOIN ingredients i_all ON i_all.ingredient_id = ri_all.ingredient_id
               WHERE ri_all.recipe_id = r.recipe_id
                 AND EXISTS (
                        SELECT 1
                        FROM allergyinfo a
                        JOIN ingredients ia ON ia.ingredient_id = a.ingredient_id
                       WHERE a.user_id = :uid
                         AND TRIM(ia.newcatagory) = TRIM(i_all.newcatagory)
                 )
            )" : "0") . " AS has_allergy

        FROM recipe r
                /* เงื่อนไขกลุ่ม: ต้องมีส่วนผสม (ingredient) อย่างน้อยหนึ่งตัวที่ newcatagory = :group */
        WHERE EXISTS (
            SELECT 1
            FROM recipe_ingredient ri_g
            JOIN ingredients i_g ON i_g.ingredient_id = ri_g.ingredient_id
            WHERE ri_g.recipe_id = r.recipe_id
              AND i_g.newcatagory = :group
        )
                ORDER BY $orderBy, r.recipe_id DESC       -- เติม recipe_id DESC เพื่อให้ผลลัพธ์นิ่ง หากค่า sort หลักเท่ากัน
        LIMIT :limit OFFSET :offset
    ";

    $params = [             // ผูก placeholder → ป้องกัน SQL injection
        ':group'  => $group,
        ':limit'  => $limit,
        ':offset' => $offset,
    ];
    if ($uid) $params[':uid'] = $uid; // เฉพาะเมื่อมี user ใช้สำหรับ has_allergy EXISTS

    $rows = dbAll($sql, $params); // คืน array ของแถว (แต่ละแถวหนึ่ง recipe)

    // base URL สำหรับภาพ (คงรูปแบบเดียวกับ endpoint อื่น)
    $base = rtrim(getBaseUrl(), '/').'/uploads/recipes';

    $data = array_map(function($r) use ($base) {
        // 1) เลือกรูป: ถ้ามี path ใช้ basename ตัด directory traversal, ไม่มี → default
        $img = !empty($r['image_path']) ? $base . '/' . basename($r['image_path'])
                                         : $base . '/default_recipe.png';

        // 2) ingredient_ids: แปลงเป็น array<int>; filter ตัดช่องว่าง/ค่าว่าง
        $ids = array_filter(array_map('intval', explode(',', $r['ingredient_ids'] ?? '')));

        // 3) คืนโครงสร้างข้อมูลที่ FE ต้องการ (typing ชัดเจน)
        return [
            'recipe_id'         => (int)$r['recipe_id'],
            'name'              => (string)$r['name'],
            'image_url'         => $img,
            'prep_time'         => $r['prep_time'] !== null ? (int)$r['prep_time'] : null,
            'favorite_count'    => (int)$r['favorite_count'],
            'average_rating'    => round((float)$r['average_rating'], 2),
            'review_count'      => (int)$r['review_count'],
            'short_ingredients' => $r['short_ingredients'] ?? '',
            'ingredient_ids'    => $ids,
            'has_allergy'       => (bool)$r['has_allergy'],
        ];
    }, $rows); // END map rows → data array

    $totalRecipes = (int)dbVal('SELECT COUNT(*) FROM recipe'); // นับสูตรทั้งหมดในระบบ (global reference)
    jsonOutput([
        'success'       => true,
        'group'         => $group,               // กลุ่มที่ค้นหา
        'page'          => $page,                // หน้าปัจจุบัน
        'limit'         => $limit,               // จำนวนต่อหน้า
        'total'         => $total,               // จำนวนสูตรทั้งหมดในกลุ่มนี้
        'total_recipes' => $totalRecipes,        // จำนวนสูตรทั้งหมดในระบบ (ใช้โชว์ progress bar สัดส่วนได้)
        'has_next'      => ($page * $limit < $total), // ยังมีหน้าถัดไปหรือไม่
        'data'          => $data,                // รายการข้อมูล
        'count'         => count($data),         // จำนวนที่ส่งกลับจริงในหน้านี้
    ]); // END response

} catch (Throwable $e) {
    // Log ข้อผิดพลาด (ไม่ส่งรายละเอียดภายในให้ผู้ใช้เพื่อความปลอดภัย)
    error_log('[get_recipes_by_group] '.$e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
