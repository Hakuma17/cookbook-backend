<?php
// get_ingredients.php — รายชื่อวัตถุดิบทั้งหมด
// - ?grouped=1  → คืน “กลุ่มวัตถุดิบ” พร้อมรูปตัวแทน + recipe_count
// - โหมดปกติ   → คืนวัตถุดิบรายตัว
// จุดเด่น: ทำ absolute URL, เลือก default ตามโหมด, รองรับ http/https, เช็คไฟล์จริง

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // helper PDO wrapper

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

/**
 * ทำ URL รูปภาพให้เป็น absolute + fallback ถ้าไฟล์ไม่มีจริง
 * - ปล่อยผ่านกรณีเป็น http/https อยู่แล้ว
 * - ถ้าไม่พบไฟล์ และชื่อขึ้นต้น "ingredient_" จะลองสลับเป็น "ingredients_" ให้อัตโนมัติ
 * - เลือกไฟล์ default ตามโหมดด้วย $defaultFile
 */
function normalizeImageUrl(?string $raw, string $defaultFile = 'default_ingredients.png'): string {
    $baseUrl  = rtrim(getBaseUrl(), '/');
    $baseWeb  = $baseUrl . '/uploads/ingredients';
    $basePath = __DIR__ . '/uploads/ingredients';

    $raw = trim((string)$raw);

    // 1) ไม่มีค่า → default
    if ($raw === '') {
        return $baseWeb . '/' . $defaultFile;
    }

    // 2) เป็น URL ภายนอก → ปล่อยผ่าน
    if (preg_match('~^https?://~i', $raw)) {
        return $raw;
    }

    // 3) เป็นพาธภายใน → ใช้เฉพาะชื่อไฟล์ กัน path แปลก ๆ
    $filename = basename(str_replace('\\', '/', $raw));

    // 4) ถ้าไฟล์มีจริง → ใช้เลย
    $abs = $basePath . '/' . $filename;
    if (is_file($abs)) {
        return $baseWeb . '/' . $filename;
    }

    // 5) แก้เคส prefix พิมพ์ตก "ingredient_" → "ingredients_"
    if (strpos($filename, 'ingredient_') === 0) {
        $alt = 'ingredients_' . substr($filename, strlen('ingredient_'));
        if (is_file($basePath . '/' . $alt)) {
            return $baseWeb . '/' . $alt;
        }
    }

    // 6) ไม่เจออะไรเลย → default ตามโหมด
    return $baseWeb . '/' . $defaultFile;
}

try {
    $grouped = (isset($_GET['grouped']) && $_GET['grouped'] === '1');

    if ($grouped) {
        /*
         * โครงสร้างผลลัพธ์:
         * { success, groups: [{ group_name, representative_ingredient_id, representative_name,
         *                       image_url, item_count, recipe_count, catagorynew }] }
         */
        $rows = dbAll("
            SELECT 
                g.group_name,
                rep.ingredient_id             AS representative_ingredient_id,
                rep.name                      AS representative_name,
                COALESCE(rep.image_url, '')   AS image_url,
                g.ingredient_count            AS item_count,
                COALESCE(rc.recipe_count, 0)  AS recipe_count,
                g.group_name                  AS catagorynew
            FROM (
                SELECT TRIM(newcatagory) AS group_name,
                       COUNT(*)          AS ingredient_count,
                       /* เลือกตัวแทนที่ 'มีรูป' ก่อน ถ้าไม่มีค่อย fallback เป็น min id */
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
                SELECT TRIM(i.newcatagory) AS group_name,
                       COUNT(DISTINCT ri.recipe_id) AS recipe_count
                FROM ingredients i
                JOIN recipe_ingredient ri ON ri.ingredient_id = i.ingredient_id
                WHERE i.newcatagory IS NOT NULL AND TRIM(i.newcatagory) <> ''
                GROUP BY TRIM(i.newcatagory)
            ) rc ON rc.group_name = g.group_name
            ORDER BY g.group_name
        ");

        // ทำ absolute URL + default_group.png สำหรับโหมดกลุ่ม
        foreach ($rows as &$r) {
            $r['image_url'] = normalizeImageUrl($r['image_url'], 'default_group.png');
        }
        unset($r);

        jsonOutput(['success' => true, 'groups' => $rows]);
        exit;
    }

    // ───────────────────────────────────────────────────────────────
    // โหมดรายตัว
    $rows = dbAll("
        SELECT
            ingredient_id           AS id,
            name,
            COALESCE(image_url, '') AS image_url,
            category
        FROM ingredients
        ORDER BY name ASC
    ");

    foreach ($rows as &$r) {
        $r['image_url'] = normalizeImageUrl($r['image_url'], 'default_ingredients.png');
    }
    unset($r);

    jsonOutput(['success' => true, 'data' => $rows]);

} catch (Throwable $e) {
    error_log('[get_ingredients] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
