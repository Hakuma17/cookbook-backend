<?php
// get_recipes_by_group.php — ดึงสูตรตาม “กลุ่มวัตถุดิบ (newcatagory)”

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$group  = isset($_GET['group']) ? (string)$_GET['group'] : '';
$group  = trim($group); // ใช้ trim แค่ฝั่งพารามิเตอร์
$sort   = strtolower(trim($_GET['sort'] ?? 'latest')); // latest | popular | recommended
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(1, min(50, (int)($_GET['limit'] ?? 26)));
$offset = ($page - 1) * $limit;

// user จาก session เป็นหลัก; อนุญาตส่งมากับพารามถ้าต้องการ
$uid = getLoggedInUserId();
if (!$uid && isset($_GET['user_id'])) {
    $tmp = (int)$_GET['user_id'];
    if ($tmp > 0) $uid = $tmp;
}

if ($group === '') {
    jsonOutput(['success' => false, 'message' => 'Missing group'], 400);
}

try {
    // กัน GROUP_CONCAT สั้นไปสำหรับ short_ingredients
    try { dbAll("SET SESSION group_concat_max_len = 4096"); } catch (Throwable $e) {}

    // ★ RECOMMEND INDEXES:
    // ALTER TABLE ingredients         ADD INDEX idx_newcatagory (newcatagory);
    // ALTER TABLE recipe_ingredient   ADD INDEX idx_ri_rec (recipe_id), ADD INDEX idx_ri_ing (ingredient_id);
    // ALTER TABLE favorites           ADD INDEX idx_fav_rec (recipe_id);
    // ALTER TABLE review              ADD INDEX idx_rev_rec (recipe_id);

    // ★ ORDER BY ตามโหมด
    $orderBy = match ($sort) {
        'popular'     => 'favorite_count DESC, average_rating DESC',
        'recommended' => 'average_rating DESC, review_count DESC',
        default       => 'r.created_at DESC',
    };

    // รวมจำนวนทั้งหมดสำหรับ pagination
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
    $totalRow = dbAll($countSql, [':group' => $group]);
    $total    = isset($totalRow[0]['cnt']) ? (int)$totalRow[0]['cnt'] : 0;

    // ★ ใช้ subquery เลี่ยงคูณแถว + คงเสถียรภาพของผลลัพธ์
    $sql = "
        SELECT 
            r.recipe_id,
            r.name,
            r.image_path,
            r.prep_time,

            /* meta */
            (SELECT COUNT(*)
               FROM favorites f
              WHERE f.recipe_id = r.recipe_id) AS favorite_count,

            (SELECT COALESCE(AVG(rv.rating),0)
               FROM review rv
              WHERE rv.recipe_id = r.recipe_id) AS average_rating,

            (SELECT COUNT(*)
               FROM review rv2
              WHERE rv2.recipe_id = r.recipe_id) AS review_count,

            /* short ingredients + ids */
            (SELECT GROUP_CONCAT(
                        DISTINCT CASE
                            WHEN ri.descrip <> '' THEN ri.descrip
                            ELSE i.display_name
                        END
                        SEPARATOR ', ')
               FROM recipe_ingredient ri
               JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
              WHERE ri.recipe_id = r.recipe_id) AS short_ingredients,

            (SELECT GROUP_CONCAT(DISTINCT ri2.ingredient_id)
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
        /* เงื่อนไขกลุ่ม: สูตรต้องมีส่วนผสมที่อยู่ในกลุ่มนี้อย่างน้อย 1 ตัว */
        WHERE EXISTS (
            SELECT 1
            FROM recipe_ingredient ri_g
            JOIN ingredients i_g ON i_g.ingredient_id = ri_g.ingredient_id
            WHERE ri_g.recipe_id = r.recipe_id
              AND i_g.newcatagory = :group
        )
        ORDER BY $orderBy, r.recipe_id DESC
        LIMIT :limit OFFSET :offset
    ";

    $params = [
        ':group'  => $group,
        ':limit'  => $limit,
        ':offset' => $offset,
    ];
    if ($uid) $params[':uid'] = $uid;

    $rows = dbAll($sql, $params);

    // ทำ URL รูปให้เป็น URL เต็ม + fallback รูปค่าเริ่มต้น
    $base = rtrim(getBaseUrl(), '/').'/uploads/recipes';

    $data = array_map(function($r) use ($base) {
        $img = !empty($r['image_path']) ? $base . '/' . basename($r['image_path'])
                                         : $base . '/default_recipe.png';

        // แปลง ingredient_ids ให้เป็นอาเรย์ตัวเลข
        $ids = array_filter(array_map('intval', explode(',', $r['ingredient_ids'] ?? '')));

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
    }, $rows);

    jsonOutput([
        'success'  => true,
        'group'    => $group,
        'page'     => $page,
        'limit'    => $limit,
        'total'    => $total,
        'has_next' => ($page * $limit < $total),
        'data'     => $data,
        'count'    => count($data),
    ]);

} catch (Throwable $e) {
    error_log('[get_recipes_by_group] '.$e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
