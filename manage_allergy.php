<?php
// manage_allergy.php — เพิ่ม / ลบ / ดึงวัตถุดิบที่ผู้ใช้แพ้

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    $uid = requireLogin(); // ถ้าไม่ล็อกอิน → respond() จะจบการทำงานให้

    /* ─── 1) LIST ─────────────────────────────────── */
    if ($action === 'list') {
        if ($method !== 'GET') {
            jsonOutput(['success' => false, 'message' => 'GET only'], 405);
        }

        $rows = dbAll("
            SELECT  i.ingredient_id, i.name, i.display_name, i.image_url, i.category
            FROM    allergyinfo a
            JOIN    ingredients i ON i.ingredient_id = a.ingredient_id
            WHERE   a.user_id = ?
        ", [$uid]);

        jsonOutput(['success' => true, 'data' => $rows]);
    }

    /* ─── 2) เตรียม id ชุดที่จะ add/remove ─────────── */
    $raw = $_POST['ingredient_ids'] ?? ($_POST['ingredient_id'] ?? []);
    $ids = array_map('intval', (array)$raw);
    $ids = array_values(array_filter($ids, fn($v) => $v > 0));
    if (!$ids) {
        jsonOutput(['success' => false, 'message' => 'กรุณาระบุ ingredient_id'], 400);
    }

    /* ─── 3) ADD ───────────────────────────────────── */
    if ($action === 'add') {
        if ($method !== 'POST') {
            jsonOutput(['success' => false, 'message' => 'POST only'], 405);
        }

        foreach ($ids as $id) {
            dbExec("INSERT IGNORE INTO allergyinfo (user_id, ingredient_id) VALUES (?, ?)", [$uid, $id]);
        }

        jsonOutput(['success' => true, 'message' => 'เพิ่มรายการแพ้สำเร็จ']);
    }

    /* ─── 4) REMOVE ────────────────────────────────── */
    if ($action === 'remove') {
        if ($method !== 'POST') {
            jsonOutput(['success' => false, 'message' => 'POST only'], 405);
        }

        foreach ($ids as $id) {
            dbExec("DELETE FROM allergyinfo WHERE user_id = ? AND ingredient_id = ?", [$uid, $id]);
        }

        jsonOutput(['success' => true, 'message' => 'ลบรายการแพ้สำเร็จ']);
    }

    /* ─── 5) action ไม่ถูกต้อง ─────────────────────── */
    jsonOutput(['success' => false, 'message' => 'action ต้องเป็น list / add / remove'], 400);

} catch (Throwable $e) {
    error_log('[manage_allergy] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
