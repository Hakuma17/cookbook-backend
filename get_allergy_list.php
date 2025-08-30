<?php
// get_allergy_list.php — รายการวัตถุดิบที่ผู้ใช้แพ้ (+ groups summary)

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php'; // ★ เรียกใช้ฟังก์ชันกลางจากที่นี่
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

// ★ ลบ: ฟังก์ชัน normalizeImageUrl ถูกย้ายไปที่ inc/functions.php แล้ว

try {
    $userId = requireLogin();

    // ───────────────────────────────────────────────────────────────
    // 1) รายการ "วัตถุดิบรายตัว" ที่ผู้ใช้แพ้
    $rows = dbAll("
        SELECT i.ingredient_id, i.name, COALESCE(i.image_url, '') AS image_url
        FROM allergyinfo a
        JOIN ingredients i ON a.ingredient_id = i.ingredient_id
        WHERE a.user_id = ?
        ORDER BY i.name ASC
    ", [$userId]);

    foreach ($rows as &$r) {
        $r['ingredient_id'] = (int)$r['ingredient_id'];
        $r['id']            = $r['ingredient_id']; // alias ให้ FE reuse model เดิมได้
        // เรียกใช้ฟังก์ชันกลาง
        $r['image_url']     = normalizeImageUrl($r['image_url'], 'default_ingredients.png');
    }
    unset($r);

    // ★ แก้ไข: ทำดิกชันนารีช่วย lookup โดยใช้ array_column เพื่อความกระชับ
    $nameById  = array_column($rows, 'name', 'ingredient_id');
    $imageById = array_column($rows, 'image_url', 'ingredient_id');


    // ───────────────────────────────────────────────────────────────
    // 2) Summary เป็น “กลุ่มที่แพ้”
    // - เลือก representative_ingredient_id ที่ "มีรูป" ก่อน ถ้าไม่มีค่อย fallback เป็น id น้อยสุด
    $groups = dbAll("
        SELECT
            TRIM(i.newcatagory) AS group_name,
            COALESCE(
                MIN(CASE WHEN i.image_url IS NOT NULL AND TRIM(i.image_url) <> '' THEN i.ingredient_id END),
                MIN(i.ingredient_id)
            ) AS representative_ingredient_id
        FROM allergyinfo a
        JOIN ingredients i ON a.ingredient_id = i.ingredient_id
        WHERE a.user_id = ?
          AND i.newcatagory IS NOT NULL
          AND TRIM(i.newcatagory) <> ''
        GROUP BY TRIM(i.newcatagory)
        ORDER BY group_name
    ", [$userId]);

    foreach ($groups as &$g) {
        $repId = (int)$g['representative_ingredient_id'];
        $repName = $nameById[$repId] ?? ($g['group_name'] ?? '');
        $repImg  = $imageById[$repId] ?? ''; // อาจว่างถ้าไม่เจอใน rows (กันไว้)

        $g['representative_ingredient_id'] = $repId;
        $g['representative_name'] = $repName;
        $g['image_url']           = $repImg !== ''
            ? $repImg
            // เรียกใช้ฟังก์ชันกลาง
            : normalizeImageUrl('', 'default_group.png');

        // ฟิลด์เพิ่มเติมเผื่อ FE ใช้
        $g['api_group_value'] = $g['group_name'];
        $g['display_name']    = $g['group_name'];
        $g['catagorynew']     = $g['group_name'];
    }
    unset($g);

    jsonOutput([
        'success' => true,
        'data'    => $rows,   // รายตัวที่แพ้ (id, name, image_url absolute)
        'groups'  => $groups  // กลุ่มที่แพ้ (group_name, representative_ingredient_id, representative_name, image_url absolute)
    ]);

} catch (Throwable $e) {
    error_log('[get_allergy_list] ' . $e->getMessage() . ' on line ' . $e->getLine());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}