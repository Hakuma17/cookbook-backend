<?php
// get_ingredient_groups.php — ดึงการ์ด “กลุ่มวัตถุดิบ” สำหรับหน้า Home/All
// คืน: { success, groups:[{group_name, representative_ingredient_id, representative_name, image_url, recipe_count, item_count, catagorynew, display_name, api_group_value}], total_groups }

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    // เลือก “ตัวแทน” ของแต่ละกลุ่ม + นับจำนวนสูตรในกลุ่มนั้น
    $rows = dbAll("
        SELECT 
            g.group_name,
            rep.ingredient_id            AS representative_ingredient_id,
            rep.name                     AS representative_name,
            COALESCE(rep.image_url, '')  AS image_url,
            COALESCE(rc.recipe_count, 0) AS recipe_count,   -- จำนวนสูตรของกลุ่ม
            COALESCE(rc.recipe_count, 0) AS item_count,     -- alias เดิมเพื่อ compat FE
            g.group_name                 AS catagorynew
        FROM (
            SELECT TRIM(newcatagory) AS group_name,
                   MIN(ingredient_id) AS rep_id
            FROM ingredients
            WHERE newcatagory IS NOT NULL AND TRIM(newcatagory) <> ''
            GROUP BY TRIM(newcatagory)
        ) g
        JOIN ingredients rep ON rep.ingredient_id = g.rep_id
        LEFT JOIN (
            SELECT TRIM(i.newcatagory) AS group_name,
                   COUNT(DISTINCT ri.recipe_id) AS recipe_count
            FROM ingredients i
            JOIN recipe_ingredient ri ON ri.ingredient_id = i.ingredient_id
            WHERE i.newcatagory IS NOT NULL AND TRIM(i.newcatagory) <> ''
            GROUP BY TRIM(i.newcatagory)
        ) rc ON rc.group_name = g.group_name
        ORDER BY g.group_name
    ");

    // ทำ URL เต็ม + fallback รูป default_group.png
    $baseIng = rtrim(getBaseUrl(), '/').'/uploads/ingredients';
    foreach ($rows as &$r) {
        $r['image_url'] = !empty($r['image_url'])
            ? $baseIng . '/' . basename($r['image_url'])
            : $baseIng . '/default_group.png';

        // ---- จุดสำคัญ: ให้การ์ดโชว์ชื่อ “หมวด” ----
        $r['api_group_value'] = $r['group_name'];   // key ใช้ค้นหา/กรอง
        $r['display_name']    = $r['group_name'];   // ← เดิมเป็น representative_name

        // เก็บชื่อวัตถุดิบตัวแทนไว้เผื่อใช้ที่อื่น
        $r['representative_display_name'] = $r['representative_name'];

        // cast ชนิดข้อมูลให้ชัดเจน
        $r['representative_ingredient_id'] = (int)$r['representative_ingredient_id'];
        $r['recipe_count'] = (int)$r['recipe_count'];
        $r['item_count']   = (int)$r['item_count'];
        $r['total_recipes'] = (int)$r['recipe_count']; // ชื่อสำรองที่ FE บางจุดใช้
    }
    unset($r);

    jsonOutput([
        'success'      => true,
        'groups'       => $rows,
        'total_groups' => count($rows)
    ]);
} catch (Throwable $e) {
    error_log('[get_ingredient_groups] '.$e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
