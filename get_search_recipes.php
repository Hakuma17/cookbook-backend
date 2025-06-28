<?php
/**
 * get_search_recipes.php
 * ------------------------------------------------------------------
 *  พารามิเตอร์ (GET / POST)
 *    q              : keyword (optional)
 *    sort           : popular | trending | latest | recommended
 *    cat_id         : category id     (optional)
 *    include_ids[]  : ingredient ids “ต้องมี”   (optional)
 *    exclude_ids[]  : ingredient ids “ต้องไม่มี” (optional)
 *    ingredients    : ชื่อวัตถุดิบคั่น "," หรือเว้นวรรค  →  ต้อง “มีครบทุกคำ”
 *    page / limit   : paging ( default limit = 26 )
 * ------------------------------------------------------------------
 *  • เพิ่มคอลัมน์  favorite_count  เสมอในการ SELECT
 *  • ถ้า param  ingredients  ถูกส่งมา (เช่น  “กุ้ง,กระเทียม” หรือ “กุ้ง กระเทียม”)
 *    → จะแยกเป็น token และค้นหาเฉพาะสูตรที่มีวัตถุดิบครบทุก token
 */
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/json.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    /* ───────────── 1) รับและตรวจพารามิเตอร์ ───────────── */
    $p = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;

    $q      = sanitize($p['q'] ?? '');
    $sort   = strtolower(trim($p['sort'] ?? 'latest'));
    $catId  = isset($p['cat_id']) && $p['cat_id'] !== '' ? (int)$p['cat_id'] : null;

    $page   = max(1, (int)($p['page']  ?? 1));
    $limit  = max(1, min(50, (int)($p['limit'] ?? 26)));
    $offset = ($page - 1) * $limit;

    /* include / exclude ids */
    $incRaw = $p['include_ids'] ?? [];
    $excRaw = $p['exclude_ids'] ?? [];
    if (!is_array($incRaw)) $incRaw = [$incRaw];
    if (!is_array($excRaw)) $excRaw = [$excRaw];
    $includeIds = array_filter(array_map('intval', $incRaw));
    $excludeIds = array_filter(array_map('intval', $excRaw));

    /* extra ingredient-names filter (ต้อง “มีครบทุกคำ”) */
    $tokens = [];
    if (!empty($p['ingredients'])) {
        // แยกด้วย , / ; / เว้นวรรคหลาย ๆ ช่อง
        $tokens = preg_split('/[,\s;]+/u', $p['ingredients'], -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_map('trim', $tokens);
    }

    /* validation */
    $qLen = mb_strlen($q);
    if ($qLen > 100) jsonError('คำค้นหายาวเกินไป', 400);
    if ($qLen > 0 && $qLen < 2) jsonError('กรุณาใส่คำค้นอย่างน้อย 2 ตัวอักษร', 400);

    if (
        $qLen === 0 &&
        $catId === null &&
        !$includeIds && !$tokens   // ไม่มี filter ใดเลย
    ) {
        jsonOutput(['success' => true, 'page' => $page, 'data' => []]);
    }

    /* ───────────── 2) user id (optional) ───────────── */
    $userId = getLoggedInUserId();

    /* ───────────── 3) สร้าง SQL ───────────── */
    $sql  = "SELECT r.*, IFNULL(r.favorite_count,0) AS favorite_count
               FROM recipe r";

    /* (3.1) JOIN ตรวจ ingredient tokens (ต้องมีครบ) */
    $params = [];
    if ($tokens) {
        $i = 0;
        foreach ($tokens as $t) {
            // sub-query เช็กว่ามี ingredient ที่ชื่อแบบ loose-match
            $alias = 't' . (++$i);
            $sql  .= " INNER JOIN recipe_ingredients AS $alias
                          ON $alias.recipe_id = r.id
                         AND $alias.ingredient_name LIKE ?";
            $params[] = '%' . $t . '%';
        }
    }

    /* (3.2) include / exclude ids */
    if ($includeIds) {
        $inMarks = implode(',', array_fill(0, count($includeIds), '?'));
        $sql .= " INNER JOIN recipe_ingredients inc
                    ON inc.recipe_id = r.id
                   AND inc.ingredient_id IN ($inMarks)";
        $params = array_merge($params, $includeIds);
    }
    if ($excludeIds) {
        $notMarks = implode(',', array_fill(0, count($excludeIds), '?'));
        $sql .= " WHERE r.id NOT IN (
                     SELECT recipe_id
                       FROM recipe_ingredients
                      WHERE ingredient_id IN ($notMarks)
                  )";
        $params = array_merge($params, $excludeIds);
    } else {
        $sql .= $excludeIds ? '' : ' WHERE 1';   // ถ้ายังไม่มี WHERE ให้ตั้งต้น
    }

    /* (3.3) keyword / category */
    if ($qLen) {
        $sql .= " AND r.name LIKE ?";
        $params[] = '%' . $q . '%';
    }
    if ($catId !== null) {
        $sql .= " AND r.category_id = ?";
        $params[] = $catId;
    }

    /* (3.4) sorting */
    switch ($sort) {
        case 'popular':
            $sql .= " ORDER BY r.favorite_count DESC";
            break;
        case 'trending':
            $sql .= " ORDER BY r.created_at DESC, r.favorite_count DESC";
            break;
        case 'recommended':
            $sql .= " ORDER BY r.average_rating DESC, r.review_count DESC";
            break;
        default: /* latest */
            $sql .= " ORDER BY r.created_at DESC";
    }

    $sql .= " LIMIT $limit OFFSET $offset";

    /* ───────────── 4) query DB ───────────── */
    $rows = dbAll($sql, $params);

    /* แปลงเป็น array-friendly (int/float ให้ถูกชนิด) */
    $data = array_map(function ($r) {
        return [
            'id'              => (int)$r['id'],
            'name'            => $r['name'],
            'image_url'       => $r['image_url'],
            'favorite_count'  => (int)$r['favorite_count'],   // ★ ส่งกลับ!
            'average_rating'  => (float)$r['average_rating'],
            'review_count'    => (int)$r['review_count'],
            'prep_time'       => (int)$r['prep_time'],
        ];
    }, $rows);

    /* ───────────── 5) response ───────────── */
    jsonOutput([
        'success' => true,
        'page'    => $page,
        'data'    => $data,
        // 'debug' => ['sql' => $sql, 'params' => $params]  // เปิดได้ตอน dev
    ]);

} catch (Throwable $e) {
    error_log('[get_search_recipes] ' . $e->getMessage());
    jsonError('Server Error', 500);
}
