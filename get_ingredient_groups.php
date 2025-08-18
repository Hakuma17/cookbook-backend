<?php
// get_ingredient_groups.php — การ์ด “กลุ่มวัตถุดิบ” สำหรับหน้า Home/All
// คืน: { success, groups:[{group_name, representative_ingredient_id, representative_name, image_url,
//       recipe_count, item_count, catagorynew, display_name, api_group_value, representative_display_name}], total_groups }

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

/**
 * ทำ URL รูปภาพให้เป็น absolute + fallback ถ้าไฟล์ไม่มีจริง
 * - ปล่อยผ่านกรณีเป็น http/https
 * - ถ้าไม่พบไฟล์ และชื่อขึ้นต้น "ingredient_" จะลองสลับเป็น "ingredients_" ให้อัตโนมัติ
 * - โหมดกลุ่มใช้ default_group.png
 */
function normalizeImageUrl(?string $raw, string $defaultFile = 'default_group.png'): string {
    $baseUrl  = rtrim(getBaseUrl(), '/');
    $baseWeb  = $baseUrl . '/uploads/ingredients';
    $basePath = __DIR__ . '/uploads/ingredients';

    $raw = trim((string)$raw);
    if ($raw === '') return $baseWeb . '/' . $defaultFile;

    if (preg_match('~^https?://~i', $raw)) return $raw;

    $filename = basename(str_replace('\\', '/', $raw));
    $abs = $basePath . '/' . $filename;
    if (is_file($abs)) return $baseWeb . '/' . $filename;

    if (strpos($filename, 'ingredient_') === 0) {
        $alt = 'ingredients_' . substr($filename, strlen('ingredient_'));
        if (is_file($basePath . '/' . $alt)) return $baseWeb . '/' . $alt;
    }
    return $baseWeb . '/' . $defaultFile;
}

try {
    // เลือก “ตัวแทน” ที่มีรูปก่อน + นับจำนวนวัตถุดิบและจำนวนสูตรต่อกลุ่ม
    $rows = dbAll("
        SELECT 
            g.group_name,
            rep.ingredient_id             AS representative_ingredient_id,
            rep.name                      AS representative_name,
            COALESCE(rep.image_url, '')   AS image_url,
            COALESCE(rc.recipe_count, 0)  AS recipe_count,   -- จำนวนสูตรของกลุ่ม
            g.ingredient_count            AS item_count,     -- จำนวนวัตถุดิบในกลุ่ม
            g.group_name                  AS catagorynew
        FROM (
            SELECT TRIM(newcatagory) AS group_name,
                   COUNT(*)          AS ingredient_count,
                   /* เลือก id ตัวแทนที่ 'มีรูป' ก่อน ถ้าไม่มีค่อย fallback เป็น min id */
                   COALESCE(
                     MIN(CASE WHEN image_url IS NOT NULL AND TRIM(image_url) <> '' THEN ingredient_id END),
                     MIN(ingredient_id)
                   ) AS rep_id
            FROM ingredients
            WHERE newcatagory IS NOT NULL AND TRIM(newcatagory) <> ''
            GROUP BY TRIM(newcatagory)
        ) g
        JOIN ingredients rep ON rep.ingredient_id = g.rep_id
        LEFT JOIN (
            SELECT TRIM(i.newcatagory)        AS group_name,
                   COUNT(DISTINCT ri.recipe_id) AS recipe_count
            FROM ingredients i
            JOIN recipe_ingredient ri ON ri.ingredient_id = i.ingredient_id
            WHERE i.newcatagory IS NOT NULL AND TRIM(i.newcatagory) <> ''
            GROUP BY TRIM(i.newcatagory)
        ) rc ON rc.group_name = g.group_name
        ORDER BY g.group_name
    ");

    // ทำ absolute URL + เติมฟิลด์ที่ FE ใช้
    foreach ($rows as &$r) {
        $r['image_url'] = normalizeImageUrl($r['image_url'], 'default_group.png');

        // ให้การ์ดโชว์ชื่อ “หมวด” (กลุ่ม)
        $r['api_group_value'] = $r['group_name'];
        $r['display_name']    = $r['group_name'];

        // เก็บชื่อวัตถุดิบตัวแทนไว้เผื่อใช้
        $r['representative_display_name'] = $r['representative_name'];

        // cast ชนิดข้อมูลให้ชัดเจน
        $r['representative_ingredient_id'] = (int)$r['representative_ingredient_id'];
        $r['recipe_count'] = (int)$r['recipe_count'];
        $r['item_count']   = (int)$r['item_count'];
        $r['total_recipes'] = (int)$r['recipe_count']; // alias ที่บางจุดใน FE ใช้
    }
    unset($r);

    jsonOutput([
        'success'      => true,
        'groups'       => $rows,
        'total_groups' => count($rows),
    ]);
} catch (Throwable $e) {
    error_log('[get_ingredient_groups] '.$e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
