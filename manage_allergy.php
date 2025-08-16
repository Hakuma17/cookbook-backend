<?php
// manage_allergy.php — เพิ่ม / ลบ / ดึงวัตถุดิบที่ผู้ใช้แพ้
// ★ รองรับ ingredient_ids[] และขยายเป็นกลุ่มไอดีที่ชื่อเหมือนกัน
// ★★★ (2025-08-09) เพิ่มโหมด mode=group: ขยายด้วย ingredients.newcatagory

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $uid    = requireLogin();  // ถ้าไม่ล็อกอิน จะจบด้วย 401

    // ใช้โหมด: single (ดีฟอลต์ = พฤติกรรมเดิม) หรือ group (ใหม่)
    $mode = strtolower($_POST['mode'] ?? $_GET['mode'] ?? 'single'); // 'single' | 'group'

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
    // [FIX] ใช้ isset ป้องกัน Notice: Undefined index: ingredient_id
    $raw = $_POST['ingredient_ids']
         ?? (isset($_POST['ingredient_id']) && $_POST['ingredient_id'] !== '' ? [$_POST['ingredient_id']] : []);
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

    // 3) ขยายชุดไอดี
    $allIds = [];

    /* ──────────────────────────────────────────────────────────────
     * [OLD] โหมดเดิม (single): ขยายด้วยการจับชื่อ/keywords (คงไว้เป็นคอมเมนต์)
     * ────────────────────────────────────────────────────────────── */

    if ($mode === 'group') {
        // ★★★ [NEW] ขยายด้วย newcatagory: เอาทุก ingredient_id ที่อยู่ใน “กลุ่มเดียวกัน”
        $groupNames = [];           // เก็บชื่อกลุ่ม (TRIM) ที่พบ
        foreach ($baseIds as $id) {
            $row = dbOne("SELECT TRIM(newcatagory) AS g FROM ingredients WHERE ingredient_id = ? LIMIT 1", [$id]);
            if ($row && $row['g'] !== '' && $row['g'] !== null) {
                $groupNames[$row['g']] = true;
            } else {
                // ถ้าไม่มีกลุ่มให้พ่วง id นั้นไว้ตรง ๆ (กัน edge case)
                $allIds[] = (int)$id;
            }
        }

        if ($groupNames) {
            // ดึงสมาชิกทุกตัวของแต่ละกลุ่ม
            foreach (array_keys($groupNames) as $g) {
                // [FIX] อย่าส่งอาร์กิวเมนต์ตัวที่ 3 ให้ dbAll (กัน signature mismatch)
                $members = dbAll(
                    "SELECT ingredient_id FROM ingredients WHERE TRIM(newcatagory) = ?",
                    [$g]
                );
                foreach ($members as $m) {
                    $allIds[] = (int)$m['ingredient_id']; // [FIX] อ่านแบบ associative
                }
            }
        }

        $allIds = array_values(array_unique($allIds));
        if (empty($allIds)) {
            jsonOutput(['success'=>false,'message'=>'ไม่พบสมาชิกในกลุ่มของวัตถุดิบที่ระบุ'],404);
        }
    } else {
        // ── ใช้ลอจิกเดิม (single): ขยายด้วยชื่อ/keywords
        foreach ($baseIds as $id) {
            $row = dbOne("SELECT name FROM ingredients WHERE ingredient_id = ? LIMIT 1", [$id]);
            if (!$row) continue;
            $kw = "%{$row['name']}%";
            $matches = dbAll(
                "SELECT ingredient_id
                   FROM ingredients
                  WHERE name                LIKE ?
                     OR display_name        LIKE ?
                     OR searchable_keywords LIKE ?",
                [$kw, $kw, $kw]
            );
            foreach ($matches as $m) $allIds[] = (int)$m['ingredient_id'];
        }
        $allIds = array_values(array_unique(array_merge($baseIds, $allIds)));
        if (empty($allIds)) {
            jsonOutput(['success'=>false,'message'=>'ไม่พบไอดีวัตถุดิบที่จับคู่ได้'],404);
        }
    }

    // 4) ADD
    if ($action === 'add') {
      if ($method !== 'POST') {
        jsonOutput(['success'=>false,'message'=>'POST only'],405);
      }

      // แทรกแบบ INSERT IGNORE (กันซ้ำ)
      foreach ($allIds as $iid) {
        dbExec(
          "INSERT IGNORE INTO allergyinfo (user_id, ingredient_id)
             VALUES (?,?)",
          [$uid, $iid]
        );
      }

      // ข้อความตอบกลับ
      $msg = ($mode === 'group')
          ? 'เพิ่มรายการแพ้แบบกลุ่มสำเร็จ'
          : 'เพิ่มรายการแพ้สำเร็จ';
      jsonOutput(['success'=>true,'message'=>$msg]);
    }

    // 5) REMOVE
    if ($action === 'remove') {
      if ($method !== 'POST') {
        jsonOutput(['success'=>false,'message'=>'POST only'],405);
      }

      if ($mode === 'group') {
        // ★★★ [NEW] ลบแบบกลุ่ม: ลบสมาชิกทั้งหมดของ “ทุกกลุ่มที่อ้างถึง”
        // หา group name จาก baseIds แล้วลบด้วยการ JOIN เพื่อความชัวร์
        $groupNames = [];
        foreach ($baseIds as $id) {
            $row = dbOne("SELECT TRIM(newcatagory) AS g FROM ingredients WHERE ingredient_id = ? LIMIT 1", [$id]);
            if ($row && $row['g'] !== '' && $row['g'] !== null) {
                $groupNames[$row['g']] = true;
            } else {
                // ถ้าไม่มีชื่อกลุ่ม ให้ลบเฉพาะ id นั้นตรง ๆ
                dbExec("DELETE FROM allergyinfo WHERE user_id = ? AND ingredient_id = ?", [$uid, $id]);
            }
        }
        foreach (array_keys($groupNames) as $g) {
            dbExec("
              DELETE a FROM allergyinfo a
              JOIN ingredients i ON i.ingredient_id = a.ingredient_id
              WHERE a.user_id = ? AND TRIM(i.newcatagory) = ?
            ", [$uid, $g]);
        }
        $msg = 'ลบรายการแพ้แบบกลุ่มสำเร็จ';
        jsonOutput(['success'=>true,'message'=>$msg]);

      } else {
        // ── [OLD] ลบแบบเดิม (รายตัว/ขยายจากชื่อ)
        foreach ($allIds as $iid) {
          dbExec(
            "DELETE FROM allergyinfo
              WHERE user_id=? AND ingredient_id=?",
            [$uid, $iid]
          );
        }
        jsonOutput(['success'=>true,'message'=>'ลบรายการแพ้สำเร็จ']);
      }
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
