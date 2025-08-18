<?php
// map_ingredients_to_groups.php — รับรายชื่อ “วัตถุดิบเดี่ยว” แล้วแม็ปเป็น “ชื่อกลุ่มวัตถุดิบ” (ingredients.newcatagory)
// - อินพุต: names[] (POST/GET ได้), รองรับส่งมาทีละหลายชื่อ
// - การจับคู่: ชื่อวัตถุดิบจะถูกค้นใน i.name / i.display_name / i.searchable_keywords (LIKE + ESCAPE '\')
// - ส่งคืน: { success:true, groups:[<group_name>...], count, unmatched:[ชื่อที่หาไม่เจอ] }

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

/* ─── helper ───────────────────────────────────────────────────────── */
if (!function_exists('likePatternParam')) {
    // ทำ pattern สำหรับ LIKE โดย escape \ % _
    function likePatternParam(string $s): string {
        $s = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $s);
        return '%' . $s . '%'; // contains
    }
}

try {
    // รองรับทั้ง POST/GET และทั้งรูปแบบ names[]=a&names[]=b หรือ names=a,b
    $src = array_merge($_GET, $_POST);

    $raw = [];
    if (isset($src['names'])) {
        if (is_array($src['names'])) {
            $raw = $src['names'];
        } else {
            $raw = explode(',', (string)$src['names']);
        }
    } elseif (isset($src['name'])) {
        // เผื่อเรียกมาผิดคีย์
        $raw = is_array($src['name']) ? $src['name'] : explode(',', (string)$src['name']);
    }

    // ทำความสะอาด input
    $names = array_values(array_unique(array_filter(array_map(function ($v) {
        $s = trim((string)$v);
        // ตัด space ซ้ำ/ normalize แบบง่าย
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }, $raw), function ($s) {
        return $s !== '';
    })));

    if (empty($names)) {
        jsonOutput(['success' => true, 'groups' => [], 'count' => 0, 'unmatched' => []]);
    }

    // จำกัดจำนวนชื่อที่ยอมรับต่อครั้ง
    $maxNames = 30;
    if (count($names) > $maxNames) {
        $names = array_slice($names, 0, $maxNames);
    }

    // ประกอบ WHERE (OR block ต่อชื่อ)
    $orBlocks = [];
    $params   = [];

    foreach ($names as $n) {
        $pat = likePatternParam($n);
        $orBlocks[] = "(i.name LIKE ? ESCAPE '\\\\' OR i.display_name LIKE ? ESCAPE '\\\\' OR i.searchable_keywords LIKE ? ESCAPE '\\\\')";
        $params[] = $pat;
        $params[] = $pat;
        $params[] = $pat;
    }

    // limit กลุ่มสูงสุดที่คืน (กันผลลัพธ์ใหญ่เกิน)
    $limit = isset($src['limit']) ? (int)$src['limit'] : 50;
    $limit = max(1, min(100, $limit));

    $sql = "
        SELECT DISTINCT TRIM(i.newcatagory) AS group_name
        FROM ingredients i
        WHERE i.newcatagory IS NOT NULL
          AND TRIM(i.newcatagory) <> ''
          AND (" . implode(' OR ', $orBlocks) . ")
        ORDER BY group_name ASC
        LIMIT ?
    ";
    $params[] = $limit;

    $rows = dbAll($sql, $params);

    $groups = array_values(array_filter(array_map(function ($r) {
        return (string)($r['group_name'] ?? '');
    }, $rows), function ($g) {
        return $g !== '';
    }));

    // สร้างรายการ unmatched แบบหยาบ ๆ (ไม่มีผลต่อ FE ถ้าไม่ใช้)
    // หมายเหตุ: การคำนวณ unmatched อย่างแท้จริงต้องยิง query แยกต่อชื่อ
    // ที่นี่เราจะรายงานเฉพาะกรณี “ไม่มีผลลัพธ์เลย”
    $unmatched = empty($groups) ? $names : [];

    jsonOutput([
        'success'   => true,
        'groups'    => $groups,        // ← FE ใช้อันนี้
        'count'     => count($groups),
        'unmatched' => $unmatched,     // ← เผื่อ debug/อนาคต
    ]);

} catch (Throwable $e) {
    error_log('[map_ingredients_to_groups] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
