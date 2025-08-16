<?php
require_once __DIR__.'/inc/config.php';
require_once __DIR__.'/inc/db.php';
require_once __DIR__.'/inc/functions.php'; // สำหรับ getLoggedInUserId
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$withMeta = (isset($_GET['with_meta']) && $_GET['with_meta'] === '1');
$uid = getLoggedInUserId();

/* [OLD] โหมดเดิม: คืนชื่อเมนูอย่างเดียว (คงไว้เป็นคอมเมนต์)
$q = trim($_GET['q'] ?? '');
$list = [];
if ($q !== '') {
    $list = dbAll(
        "SELECT name FROM recipe
          WHERE name LIKE ? ORDER BY name LIMIT 10",
        ["%$q%"]
    );
}
echo json_encode(array_column($list, 'name'), JSON_UNESCAPED_UNICODE);
exit;
*/

if ($q === '') {
    // ไม่มีคำค้น → คืน array ว่างตามสเปคเดิม
    echo json_encode($withMeta ? [] : [], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($withMeta) {
    // ★★★ [NEW] โหมด meta: ส่งกลับ { recipe_id, name, has_allergy }
    // has_allergy คิดแบบ “กลุ่ม” เทียบ newcatagory
    $sql = "
        SELECT 
            r.recipe_id,
            r.name,
            " . ($uid ? "
            EXISTS (
              SELECT 1
                FROM recipe_ingredient ri
                JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
               WHERE ri.recipe_id = r.recipe_id
                 AND EXISTS (
                   SELECT 1
                     FROM allergyinfo a
                     JOIN ingredients ia ON ia.ingredient_id = a.ingredient_id
                    WHERE a.user_id = :uid
                      AND TRIM(ia.newcatagory) = TRIM(i.newcatagory)
                 )
            )" : "0") . " AS has_allergy
        FROM recipe r
        WHERE r.name LIKE :kw
        ORDER BY r.name
        LIMIT 10
    ";
    $params = [':kw' => "%$q%"];
    if ($uid) $params[':uid'] = $uid;

    $rows = dbAll($sql, $params);
    $out = array_map(function($r) {
        return [
            'recipe_id'   => (int)$r['recipe_id'],
            'name'        => (string)$r['name'],
            'has_allergy' => (bool)$r['has_allergy'],
        ];
    }, $rows);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── โหมดเดิม (รายชื่ออย่างเดียว) ────────────────────────────────
$list = dbAll(
    "SELECT name FROM recipe
      WHERE name LIKE ? ORDER BY name LIMIT 10",
    ["%$q%"]
);
echo json_encode(array_column($list, 'name'), JSON_UNESCAPED_UNICODE);
