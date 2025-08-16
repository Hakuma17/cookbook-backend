<?php
// get_allergy_list.php — รายการวัตถุดิบที่ผู้ใช้แพ้ (+ groups summary)

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

header('Content-Type: application/json; charset=UTF-8'); // [NEW]

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $userId = requireLogin();

    // [OLD] เวอร์ชันเดิม: ส่งเฉพาะรายการรายตัว
    /*
    $rows = dbAll("
        SELECT i.ingredient_id, i.name, i.image_url
        FROM allergyinfo a
        JOIN ingredients i ON a.ingredient_id = i.ingredient_id
        WHERE a.user_id = ?
    ", [$userId]);

    jsonOutput(['success' => true, 'data' => $rows]);
    */

    // [KEEP] ยังคงคืน data รายตัวเหมือนเดิม
    $rows = dbAll("
        SELECT i.ingredient_id, i.name, i.image_url
        FROM allergyinfo a
        JOIN ingredients i ON a.ingredient_id = i.ingredient_id
        WHERE a.user_id = ?
    ", [$userId]);

    // ★★★ [NEW] groups: สรุปชื่อกลุ่มที่แพ้ + representative_ingredient_id (id น้อยสุดของกลุ่ม)
    // ใช้ TRIM(newcatagory) กันช่องว่างหัวท้าย และกรองกลุ่มว่าง/NULL ทิ้ง
    $groups = dbAll("
        SELECT
            TRIM(i.newcatagory)     AS group_name,
            MIN(i.ingredient_id)    AS representative_ingredient_id
        FROM allergyinfo a
        JOIN ingredients i ON a.ingredient_id = i.ingredient_id
        WHERE a.user_id = ?
          AND i.newcatagory IS NOT NULL
          AND TRIM(i.newcatagory) <> ''
        GROUP BY TRIM(i.newcatagory)
        ORDER BY group_name
    ", [$userId]);

    // [NEW] ทำ URL รูปให้เป็น absolute + ใส่ fallback
    $baseIng = rtrim(getBaseUrl(), '/').'/uploads/ingredients';

    // [NEW] แปลง/ทำความสะอาดฝั่ง data (รายตัว)
    foreach ($rows as &$r) {
        $r['ingredient_id'] = (int)$r['ingredient_id'];   // cast ให้ชัด
        // alias เผื่อ FE map ตาม model อื่น ๆ
        $r['id']            = $r['ingredient_id'];        // [NEW] alias
        // รูป: absolute + fallback
        $r['image_url'] = !empty($r['image_url'])
            ? $baseIng . '/' . basename($r['image_url'])
            : $baseIng . '/default_ingredients.png';
    }
    unset($r);

    // [NEW] ทำดิกชันนารีไว้ช่วยเติมรูป/ชื่อให้ groups
    $nameById  = [];
    $imageById = [];
    foreach ($rows as $x) {
        $nameById[$x['ingredient_id']]  = $x['name'] ?? '';
        $imageById[$x['ingredient_id']] = $x['image_url'] ?? '';
    }

    // [NEW] แปลง/เติมข้อมูลฝั่ง groups
    foreach ($groups as &$g) {
        $g['representative_ingredient_id'] = (int)$g['representative_ingredient_id'];
        // เผื่อใช้โชว์ชิปสวย ๆ (optional แต่มีไว้ดี)
        $repId = $g['representative_ingredient_id'];
        $g['representative_name'] = $nameById[$repId]  ?? ($g['group_name'] ?? '');
        $g['image_url']           = $imageById[$repId] ?? ($baseIng . '/default_group.png');
    }
    unset($g);

    jsonOutput([
        'success' => true,
        'data'    => $rows,
        'groups'  => $groups
    ]);

} catch (Throwable $e) {
    error_log('[allergy_list] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
