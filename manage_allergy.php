<?php
// manage_allergy.php — เพิ่ม / ลบ / ดึงวัตถุดิบที่ผู้ใช้แพ้
// ★ รองรับ ingredient_ids[] และขยายเป็นกลุ่มไอดีที่ชื่อเหมือนกัน

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $uid    = requireLogin();  // ถ้าไม่ล็อกอิน จะจบด้วย 401

    // 1) ดึงรายการแพ้
    if ($action === 'list') {
      if ($method !== 'GET') {
        jsonOutput(['success'=>false,'message'=>'GET only'],405);
      }
      $rows = dbAll(
        "SELECT i.ingredient_id, i.name, i.display_name, i.image_url, i.category
           FROM allergyinfo a
           JOIN ingredients i ON i.ingredient_id = a.ingredient_id
          WHERE a.user_id = ?",
        [$uid]
      );
      jsonOutput(['success'=>true,'data'=>$rows]);
    }

    // 2) เตรียม id ต้นทาง
    $raw = $_POST['ingredient_ids']
         ?? ($_POST['ingredient_id'] ? [$_POST['ingredient_id']] : []);
    $baseIds = array_values(array_filter(
      array_map('intval', (array)$raw),
      fn($v) => $v>0
    ));
    if (empty($baseIds)) {
      jsonOutput(
        ['success'=>false,'message'=>'กรุณาระบุ ingredient_ids'],
        400
      );
    }

    // 3) ขยายเป็นกลุ่มไอดีทั้งหมดที่ชื่อเหมือนกัน
    //    ถ้ามี id ต้นทาง N เราดึงชื่อของ N แล้ว SELECT ทุก ingredient_id
    //    ที่ name/display_name หรือ searchable_keywords LIKE คำนั้น
    $allIds = [];
    foreach ($baseIds as $id) {
      // 3-A) เอาชื่อจริงของไอดีนี้
      $row = dbOne(
        "SELECT name FROM ingredients WHERE ingredient_id = ? LIMIT 1",
        [$id]
      );
      if (!$row) continue;
      $kw = "%{$row['name']}%";

      // 3-B) หาทุกไอดีที่มีคำนี้
      $matches = dbAll(
        "SELECT ingredient_id
           FROM ingredients
          WHERE name               LIKE ?
             OR display_name       LIKE ?
             OR searchable_keywords LIKE ?",
        [$kw, $kw, $kw]
      );
      foreach ($matches as $m) {
        $allIds[] = (int)$m['ingredient_id'];
      }
    }
    // รวมกับไอดีต้นทาง แล้วลบซ้ำ
    $allIds = array_unique(array_merge($baseIds, $allIds));
    if (empty($allIds)) {
      jsonOutput(
        ['success'=>false,'message'=>'ไม่พบไอดีวัตถุดิบที่จับคู่ได้'],
        404
      );
    }

    // 4) ADD
    if ($action === 'add') {
      if ($method !== 'POST') {
        jsonOutput(['success'=>false,'message'=>'POST only'],405);
      }
      foreach ($allIds as $iid) {
        dbExec(
          "INSERT IGNORE INTO allergyinfo (user_id, ingredient_id)
             VALUES (?,?)",
          [$uid, $iid]
        );
      }
      jsonOutput(['success'=>true,'message'=>'เพิ่มรายการแพ้สำเร็จ']);
    }

    // 5) REMOVE
    if ($action === 'remove') {
      if ($method !== 'POST') {
        jsonOutput(['success'=>false,'message'=>'POST only'],405);
      }
      foreach ($allIds as $iid) {
        dbExec(
          "DELETE FROM allergyinfo
            WHERE user_id=? AND ingredient_id=?",
          [$uid, $iid]
        );
      }
      jsonOutput(['success'=>true,'message'=>'ลบรายการแพ้สำเร็จ']);
    }

    // action ผิด
    jsonOutput(
      ['success'=>false,'message'=>'action ต้องเป็น list / add / remove'],
      400
    );

} catch (Throwable $e) {
    error_log("[manage_allergy] ".$e->getMessage());
    jsonOutput(['success'=>false,'message'=>'Server error'],500);
}
