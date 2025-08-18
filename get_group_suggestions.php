<?php
// get_group_suggestions.php — แนะนำชื่อ “กลุ่มวัตถุดิบ” จากคอลัมน์ ingredients.newcatagory
// - ส่งกลับเป็น JSON: { success, data: [<group_name>...], count, items:[{group_name,recipe_count}] }
// - ปลอดภัย: ใช้ placeholder ทุกจุด + LIKE ESCAPE '\\' + limit ควบคุมได้
// - ใช้ได้ทั้งโหมด prefix (default) และ contains (?contains=1)

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

/* ─── helper เฉพาะไฟล์ ──────────────────────────────────────────────── */
if (!function_exists('likePatternParam')) {
    // ทำ pattern สำหรับ LIKE โดย escape \ % _
    function likePatternParam(string $s, bool $contains = false): string {
        $s = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $s);
        return $contains ? ('%' . $s . '%') : ($s . '%'); // default = prefix
    }
}

try {
    // ชื่อพารามิเตอร์คำค้น: รองรับ q / term / query (เอาตัวแรกที่เจอ)
    $src = $_GET;
    $rawQ = '';
    foreach (['q', 'term', 'query'] as $k) {
        if (isset($src[$k]) && trim((string)$src[$k]) !== '') {
            $rawQ = sanitize((string)$src[$k]);
            break;
        }
    }

    // mode: contains=1 จะค้นแบบ “มีคำนี้อยู่ที่ไหนก็ได้”, ปกติ = prefix
    $contains = isset($src['contains']) && in_array(strtolower((string)$src['contains']), ['1','true','yes','on'], true);

    // จำกัดจำนวนผลลัพธ์ (กันหลุดมือ)
    $limit = isset($src['limit']) ? (int)$src['limit'] : 12;
    $limit = max(1, min(30, $limit));

    // แกนข้อมูล: ดึง group_name จาก ingredients.newcatagory ที่ไม่ว่าง
    // พร้อมนับ recipe_count (จำนวนสูตรที่มีส่วนผสมในกลุ่มนั้น)
    $params = [];
    if ($rawQ !== '') {
        $sql = "
            SELECT
                TRIM(i.newcatagory) AS group_name,
                COUNT(DISTINCT r.recipe_id) AS recipe_count
            FROM ingredients i
            LEFT JOIN recipe_ingredient ri ON ri.ingredient_id = i.ingredient_id
            LEFT JOIN recipe r            ON r.recipe_id = ri.recipe_id
            WHERE i.newcatagory IS NOT NULL
              AND TRIM(i.newcatagory) <> ''
              AND TRIM(i.newcatagory) LIKE ? ESCAPE '\\\\'
            GROUP BY TRIM(i.newcatagory)
            ORDER BY recipe_count DESC, group_name ASC
            LIMIT ?
        ";
        $params[] = likePatternParam($rawQ, $contains);
        $params[] = $limit;
    } else {
        // ไม่ใส่คำค้น → เสนอ “Top groups” ตามจำนวนสูตรก่อน
        $sql = "
            SELECT
                TRIM(i.newcatagory) AS group_name,
                COUNT(DISTINCT r.recipe_id) AS recipe_count
            FROM ingredients i
            LEFT JOIN recipe_ingredient ri ON ri.ingredient_id = i.ingredient_id
            LEFT JOIN recipe r            ON r.recipe_id = ri.recipe_id
            WHERE i.newcatagory IS NOT NULL
              AND TRIM(i.newcatagory) <> ''
            GROUP BY TRIM(i.newcatagory)
            ORDER BY recipe_count DESC, group_name ASC
            LIMIT ?
        ";
        $params[] = $limit;
    }

    $rows = dbAll($sql, $params);

    // รูปแบบผลลัพธ์ที่ TypeAhead ฝั่งแอปต้องการ: array ของ string
    $names = array_map(static function ($r) {
        return (string)$r['group_name'];
    }, $rows);

    jsonOutput([
        'success' => true,
        'data'    => $names,            // ← ใช้ตรง ๆ เป็น Future<List<String>>
        'count'   => count($names),
        'items'   => array_map(static function ($r) {
            return [
                'group_name'   => (string)$r['group_name'],
                'recipe_count' => (int)$r['recipe_count'],
            ];
        }, $rows),                       // ← เผื่ออนาคตอยากโชว์จำนวนบน dropdown
    ]);
} catch (Throwable $e) {
    error_log('[get_group_suggestions] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
