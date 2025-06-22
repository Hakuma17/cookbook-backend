<?php
/**
 * get_search_recipes.php
 * -------------------------------------------------------------
 * รับพารามิเตอร์:
 *   q          : คำค้น (optional)
 *   sort       : popular | trending | latest | recommended
 *   cat_id     : หมวดอาหารจริง (ตาราง category)  (optional)
 *   include_ids: array|int  ingredient_id ที่ “ต้องมี”   (optional)
 *   exclude_ids: array|int  ingredient_id ที่ “ต้องไม่มี” (optional)
 *   ingredients: ชื่อวัตถุดิบ (คั่น ,) จะ map เป็น include_ids อัตโนมัติ
 *   limit,page : paging
 * -------------------------------------------------------------
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/json.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

header('Content-Type: application/json; charset=UTF-8');

try {
    /* ────────────────────────────────────────────────
     * 1) รับและ sanitize พารามิเตอร์
     * ───────────────────────────────────────────── */
    $method = $_SERVER['REQUEST_METHOD'];
    $p      = $method === 'POST' ? $_POST : $_GET;

    $q        = sanitize(trim($p['q'] ?? ''));
    $sort     = strtolower(trim($p['sort'] ?? 'latest'));
    $catId    = isset($p['cat_id']) && $p['cat_id'] !== '' ? (int) $p['cat_id'] : null;

    $page     = max(1, (int) ($p['page']  ?? 1));
    $limit    = min(50, max(1, (int) ($p['limit'] ?? 20)));
    $offset   = ($page - 1) * $limit;

    $incRaw   = $p['include_ids'] ?? [];
    $excRaw   = $p['exclude_ids'] ?? [];

    if (!is_array($incRaw)) $incRaw = [$incRaw];
    if (!is_array($excRaw)) $excRaw = [$excRaw];

    $includeIds = array_map('intval', $incRaw);
    $excludeIds = array_map('intval', $excRaw);

    /* 1.1 แปลงชื่อ ingredients → id */
    if (!empty($p['ingredients'])) {
        $names = array_filter(array_map('trim', explode(',', $p['ingredients'])));
        if ($names) {
            $ph   = implode(',', array_fill(0, count($names), '?'));
            $rows = dbAll("SELECT ingredient_id FROM ingredients WHERE name IN ($ph)", $names);
            $ids  = array_column($rows, 'ingredient_id');
            $includeIds = array_merge($includeIds, $ids);
        }
    }

    /* ────────────────────────────────────────────────
     * 2) validation
     * ───────────────────────────────────────────── */
    if (mb_strlen($q) > 100)  jsonError('คำค้นหายาวเกินไป', 400);
    if ($q !== '' && mb_strlen($q) < 2) jsonError('กรุณาใส่อย่างน้อย 2 ตัวอักษร', 400);

    if ($q === '' && !$includeIds && $catId === null) {
        jsonOutput(['success' => true, 'page' => $page, 'data' => []]);
    }

    /* ────────────────────────────────────────────────
     * 3) ค้นหา
     * ───────────────────────────────────────────── */
    $userId  = getLoggedInUserId();
    $data    = search_recipes(
        query:                $q,
        includeIngredientIds: $includeIds,
        excludeIngredientIds: $excludeIds,
        categoryId:           $catId,
        offset:               $offset,
        limit:                $limit,
        userId:               $userId,
        sortKey:              $sort,
    );

    jsonOutput(['success' => true, 'page' => $page, 'data' => $data]);

} catch (Throwable $e) {
    error_log('[get_search_recipes] ' . $e->getMessage());
    jsonError('Server Error: ' . $e->getMessage(), 500);
}
