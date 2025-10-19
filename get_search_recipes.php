<?php
/**
 * get_search_recipes.php (LEGACY / SIMPLE SEARCH)
 * =====================================================================
 * เวอร์ชันดั้งเดิม / เบากว่า ของ unified search:
 *   - JOIN recipe_ingredient ต่อ token (INNER JOIN * n) → กรองให้ “ต้องมีทุก token”
 *   - มีตัวกรอง include/exclude ingredient IDs + groups (newcatagory)
 *   - มี name_rank อย่างง่าย (เทียบตรง / ตัดช่องว่าง / LIKE prefix)
 *   - เพิ่มข้อมูลแพ้ (has_allergy, allergy_groups, allergy_names) เช่นเดียวกับ unified
 *   - มี fallback 2 ชั้นท้ายไฟล์ (ค้นชื่อแบบ AND tokens, และค้นจาก ingredient id)
 * แตกต่างจากไฟล์ unified:
 *   - ไม่มีการคำนวณ ing_match_cnt / ing_rank
 *   - ไม่มีการประกอบชุดเงื่อนไขชื่อแบบ OR + AND ของ token (ใช้ JOIN บังคับ AND โดยโครงสร้าง)
 *   - SELECT DISTINCT + INNER JOIN หลายครั้ง อาจช้าถ้าจำนวน token มาก (จำกัดเองตอนใช้งาน)
 * ข้อควรทราบ:
 *   - ใช้ placeholders “?” ทั้งหมด ป้องกัน SQL injection
 *   - LIKE ทุกจุด escape wildcard (%/_) + backslash
 *   - limit capped 50, มี total (filtered count) + total_recipes (global)
 * =====================================================================
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/json.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

if (!function_exists('likePatternParam')) {
    function likePatternParam(string $s): string {
        $s = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $s);
        return '%' . $s . '%';
    }
}

try {
  // 1) ดึงพารามิเตอร์พื้นฐาน
    $p        = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
    $q        = sanitize($p['q'] ?? '');
    $qNoSpc   = preg_replace('/\s+/u', '', $q);
  // Default sort for Search should be name_asc
  $sort     = strtolower(trim($p['sort'] ?? 'name_asc'));
    $catId    = isset($p['cat_id']) && $p['cat_id'] !== '' ? (int)$p['cat_id'] : null;
    $page     = max(1, (int)($p['page']  ?? 1));
    $limit    = max(1, min(50, (int)($p['limit'] ?? 26)));
    $offset   = ($page - 1) * $limit;

    $incRaw     = $p['include_ids'] ?? [];
    $excRaw     = $p['exclude_ids'] ?? [];
    if (!is_array($incRaw)) $incRaw = [$incRaw];
    if (!is_array($excRaw)) $excRaw = [$excRaw];
    $includeIds = array_filter(array_map('intval', $incRaw));
    $excludeIds = array_filter(array_map('intval', $excRaw));

  $tokens = []; // tokens วัตถุดิบ (คั่นด้วย , หรือ space หรือ ;) แบบง่าย
    if (!empty($p['ingredients'])) {
        $tokens = preg_split('/[,\s;]+/u', $p['ingredients'], -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_map('trim', $tokens);
    }

    /* ★★★ NEW: ตัวกรอง “กลุ่มวัตถุดิบ” (newcatagory) */
    $group = isset($p['group']) ? trim((string)$p['group']) : '';

    $includeGroups = [];
    if (!empty($p['include_groups'])) {
        $includeGroups = is_array($p['include_groups'])
            ? array_values(array_filter(array_map('trim', $p['include_groups'])))
            : array_values(array_filter(array_map('trim', explode(',', (string)$p['include_groups']))));
    }

    $excludeGroups = [];
    if (!empty($p['exclude_groups'])) {
        $excludeGroups = is_array($p['exclude_groups'])
            ? array_values(array_filter(array_map('trim', $p['exclude_groups'])))
            : array_values(array_filter(array_map('trim', explode(',', (string)$p['exclude_groups']))));
    }

    $qLen = mb_strlen($q);
    if ($qLen > 100)              jsonError('คำค้นหายาวเกินไป', 400);
    if ($qLen > 0 && $qLen < 2)   jsonError('กรุณาใส่คำค้นอย่างน้อย 2 ตัวอักษร', 400);

    $userId = getLoggedInUserId();

  /* ───────────────────────── SELECT หลัก + allergy joins ───────────────────────── */
    $select = "
      SELECT DISTINCT
        r.recipe_id AS recipe_id,
        r.recipe_id AS id,
        r.name,
        r.image_path,
        r.prep_time,
        r.average_rating,
        (SELECT COUNT(*) FROM favorites f WHERE f.recipe_id = r.recipe_id) AS favorite_count,
        (SELECT COUNT(*) FROM review    v WHERE v.recipe_id = r.recipe_id) AS review_count,
        (SELECT GROUP_CONCAT(DISTINCT
                CASE
                  WHEN ri.descrip IS NOT NULL AND ri.descrip <> '' THEN ri.descrip
                  ELSE i.display_name
                END
                SEPARATOR ', ')
           FROM recipe_ingredient ri
           JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
          WHERE ri.recipe_id = r.recipe_id) AS short_ingredients,
        (SELECT GROUP_CONCAT(DISTINCT ri.ingredient_id)
           FROM recipe_ingredient ri
          WHERE ri.recipe_id = r.recipe_id) AS ingredient_ids";

    $paramsSelect = [];

  if ($userId) { // ถ้ามีผู้ใช้ → คำนวณข้อมูลแพ้ตามกลุ่ม
        $select .= ",
        /* [NEW] has_allergy: เทียบ newcatagory ระหว่างส่วนผสมกับสิ่งที่ผู้ใช้แพ้ */
        EXISTS (
          SELECT 1
            FROM recipe_ingredient ri_all
            JOIN ingredients i_all ON i_all.ingredient_id = ri_all.ingredient_id
           WHERE ri_all.recipe_id = r.recipe_id
             AND EXISTS (
               SELECT 1
                 FROM allergyinfo a
                 JOIN ingredients ia ON ia.ingredient_id = a.ingredient_id
                WHERE a.user_id = ?
                  AND ia.newcatagory = i_all.newcatagory
             )
        ) AS has_allergy,

        /* ★★★ [NEW] กลุ่มที่ชนกับสิ่งที่ผู้ใช้แพ้ (ส่งเป็น CSV) */
        (SELECT GROUP_CONCAT(DISTINCT TRIM(i_all.newcatagory) SEPARATOR ',')
           FROM recipe_ingredient ri_all
           JOIN ingredients i_all ON i_all.ingredient_id = ri_all.ingredient_id
          WHERE ri_all.recipe_id = r.recipe_id
            AND EXISTS (
              SELECT 1
                FROM allergyinfo a
                JOIN ingredients ia ON ia.ingredient_id = a.ingredient_id
               WHERE a.user_id = ?
                 AND ia.newcatagory = i_all.newcatagory
            )
        ) AS allergy_groups,

        /* ★★★ [NEW] รายชื่อสำหรับโชว์ชิป: ใช้ชื่อจากรายการแพ้ของผู้ใช้ (representative) */
        (SELECT GROUP_CONCAT(DISTINCT COALESCE(ia2.display_name, ia2.name) SEPARATOR ',')
           FROM allergyinfo a2
           JOIN ingredients ia2 ON ia2.ingredient_id = a2.ingredient_id
          WHERE a2.user_id = ?
            AND TRIM(ia2.newcatagory) IN (
                SELECT TRIM(i_all2.newcatagory)
                  FROM recipe_ingredient ri_all2
                  JOIN ingredients i_all2 ON i_all2.ingredient_id = ri_all2.ingredient_id
                 WHERE ri_all2.recipe_id = r.recipe_id
            )
        ) AS allergy_names";
        $paramsSelect[] = $userId;  // for has_allergy
        $paramsSelect[] = $userId;  // for allergy_groups
        $paramsSelect[] = $userId;  // for allergy_names
    } else {
        $select .= ",
        0 AS has_allergy,
        NULL AS allergy_groups,     /* ★★★ [NEW] */
        NULL AS allergy_names       /* ★★★ [NEW] */";
    }

  // name_rank เมื่อมีคำค้น (เวอร์ชันย่อ 3 ระดับ)
    $selectNameRank = '';
    $paramsNameRank = [];
    if ($qLen) {
        $selectNameRank = ",
        (CASE
          WHEN r.name = ?                                THEN 3
          WHEN REPLACE(r.name,' ','') = ?                THEN 2
          WHEN r.name LIKE ? ESCAPE '\\\\'               THEN 1
          ELSE 0
         END) AS name_rank";
        $paramsNameRank = [$q, $qNoSpc, likePatternParam($q)];
    }

    $sql    = $select . $selectNameRank . "\nFROM recipe r";
    $paramsWhere = [];

  /* ───────────── tokens → JOIN (AND semantics ผ่าน INNER JOIN n ครั้ง) ───────────── */
    if ($tokens) {
        $i = 0;
        foreach ($tokens as $t) {
            $aliasRI  = 't' . (++$i);
            $aliasIng = 'ing' . $i;
            $sql .= "
              INNER JOIN recipe_ingredient AS $aliasRI
                         ON $aliasRI.recipe_id = r.recipe_id
              INNER JOIN ingredients AS $aliasIng
                         ON $aliasIng.ingredient_id = $aliasRI.ingredient_id
                        AND (
                              $aliasRI.descrip              LIKE ? ESCAPE '\\\\'
                           OR $aliasIng.name                LIKE ? ESCAPE '\\\\'
                           OR $aliasIng.display_name        LIKE ? ESCAPE '\\\\'
                           OR $aliasIng.searchable_keywords LIKE ? ESCAPE '\\\\'
                        )";
            $like = likePatternParam($t);
            array_push($paramsWhere, $like, $like, $like, $like);
        }
    }

  /* ───────── include / exclude ingredient IDs ───────── */
    if ($includeIds) {
        $marks = implode(',', array_fill(0, count($includeIds), '?'));
        $sql .= "
          INNER JOIN recipe_ingredient inc
                     ON inc.recipe_id = r.recipe_id
                    AND inc.ingredient_id IN ($marks)";
        $paramsWhere = array_merge($paramsWhere, $includeIds);
    }

    $sql .= $excludeIds
        ? " WHERE r.recipe_id NOT IN (
              SELECT recipe_id FROM recipe_ingredient WHERE ingredient_id IN (" . implode(',', array_fill(0, count($excludeIds), '?')) . ")
            )"
        : ' WHERE 1';
    $paramsWhere = array_merge($paramsWhere, $excludeIds);

  /* ───────── ตัวกรองชื่อ (ตรง/ตัดช่องว่าง/prefix LIKE และ compressed LIKE) ───────── */
    if ($qLen) {
        $sql .= "
          AND (
                r.name = ?
             OR REPLACE(r.name,' ','') = ?
             OR r.name LIKE ? ESCAPE '\\\\'
             OR REPLACE(r.name,' ','') LIKE ? ESCAPE '\\\\'
          )";
        array_push(
            $paramsWhere,
            $q,
            $qNoSpc,
            likePatternParam($q),
            likePatternParam($qNoSpc)
        );
    }

  /* ───────── หมวดหมู่ (category) ───────── */
    if ($catId !== null) {
        $sql .= "
          AND EXISTS (
            SELECT 1
              FROM category_recipe cr
             WHERE cr.recipe_id = r.recipe_id
               AND cr.category_id = ?
          )";
        $paramsWhere[] = $catId;
    }

  /* ───────── กรองกลุ่ม (group / include_groups / exclude_groups) ───────── */
    if ($group !== '') {
        $sql .= "
          AND EXISTS (
            SELECT 1
              FROM recipe_ingredient ri_g
              JOIN ingredients i_g ON i_g.ingredient_id = ri_g.ingredient_id
             WHERE ri_g.recipe_id = r.recipe_id
               AND TRIM(i_g.newcatagory) = TRIM(?)
          )";
        $paramsWhere[] = $group;
    }

    foreach ($includeGroups as $g) {
        $sql .= "
          AND EXISTS (
            SELECT 1
              FROM recipe_ingredient ri_gi
              JOIN ingredients i_gi ON i_gi.ingredient_id = ri_gi.ingredient_id
             WHERE ri_gi.recipe_id = r.recipe_id
               AND TRIM(i_gi.newcatagory) = TRIM(?)
          )";
        $paramsWhere[] = $g;
    }

    foreach ($excludeGroups as $g) {
        $sql .= "
          AND NOT EXISTS (
            SELECT 1
              FROM recipe_ingredient ri_ge
              JOIN ingredients i_ge ON i_ge.ingredient_id = ri_ge.ingredient_id
             WHERE ri_ge.recipe_id = r.recipe_id
               AND TRIM(i_ge.newcatagory) = TRIM(?)
          )";
        $paramsWhere[] = $g;
    }

  /* ───────── ORDER + LIMIT (ตามสเปก) ───────── */
  $orderTrail   = match ($sort) {
    // latest: created_at DESC, recipe_id DESC
    'latest'      => 'r.created_at DESC, r.recipe_id DESC',
    // name_asc (default): name ASC, recipe_id ASC
    'name_asc'    => 'r.name ASC, r.recipe_id ASC',
    // popular: average_rating DESC, review_count DESC, favorite_count DESC, created_at DESC, recipe_id DESC
    'popular'     => 'r.average_rating DESC, review_count DESC, favorite_count DESC, r.created_at DESC, r.recipe_id DESC',
    // keep legacy options if used elsewhere, add tie-breakers to be deterministic
    'trending'    => 'r.created_at DESC, favorite_count DESC, r.recipe_id DESC',
    'recommended' => 'r.average_rating DESC, review_count DESC, r.created_at DESC, r.recipe_id DESC',
    default       => 'r.name ASC, r.recipe_id ASC',
  };

    // Do not let name_rank affect the defined sorts (latest/name_asc/popular)
    $useNameRank = $qLen && !in_array($sort, ['latest','name_asc','popular'], true);
    $orderPrefix = $useNameRank ? 'name_rank DESC, ' : '';

    $sqlNoPaging = $sql . "
      ORDER BY {$orderPrefix}{$orderTrail}";

    $sql .= "
      ORDER BY {$orderPrefix}{$orderTrail}
      LIMIT ? OFFSET ?";

  $paramsNoPaging = array_merge($paramsSelect, $paramsNameRank, $paramsWhere);
  $paramsFinal    = array_merge($paramsNoPaging, [$limit, $offset]);

  /* ───────── Execute + Map Row ───────── */
  $total  = (int)dbVal("SELECT COUNT(*) FROM ( $sqlNoPaging ) AS _t", $paramsNoPaging);
  $rows   = dbAll($sql, $paramsFinal);

    $base   = getBaseUrl() . '/uploads/recipes';
    $mapRow = function($r) use ($base) {
        return [
            'recipe_id'         => (int)$r['recipe_id'],
            'id'                => (int)$r['id'],
            'name'              => $r['name'],
            'image_url'         => $r['image_path']
                                       ? $base . '/' . basename($r['image_path'])
                                       : $base . '/default_recipe.png',
            'favorite_count'    => (int)$r['favorite_count'],
            'average_rating'    => (float)$r['average_rating'],
            'review_count'      => (int)$r['review_count'],
            'prep_time'         => $r['prep_time'] !== null ? (int)$r['prep_time'] : null,
            'short_ingredients' => $r['short_ingredients'],
            'ingredient_ids'    => array_filter(array_map('intval',
                                       explode(',', $r['ingredient_ids'] ?? ''))),
            'has_allergy'       => !empty($r['has_allergy']),
            /* ★★★ [NEW] ส่งชื่อกลุ่ม/ชื่อที่ใช้ขึ้นชิป */
            'allergy_groups'    => array_values(array_filter(array_map('trim',
                                       explode(',', (string)($r['allergy_groups'] ?? ''))))),
            'allergy_names'     => array_values(array_filter(array_map('trim',
                                       explode(',', (string)($r['allergy_names'] ?? ''))))),
        ];
    };
    $data = array_map($mapRow, $rows);

  /* ───────── Fallbacks ชั้นที่ 1: ลอง AND LIKE ต่อคำ ───────── */
  if (empty($data) && $qLen > 0) { // Fallback ชั้นที่ 2: หา ingredient id จากชื่อ/ชื่อแสดง แล้ว reverse lookup สูตร
        $qWords = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        $qWords = array_filter($qWords, fn($s) => mb_strlen($s) >= 2);
        if ($qWords) {
            $likeConds = implode(' AND ', array_fill(0, count($qWords), 'r.name LIKE ? ESCAPE \'\\\\\''));
            $paramsFb = array_map('likePatternParam', $qWords);

            $sqlFb = "
              {$select}
                FROM recipe r
               WHERE {$likeConds}
               ORDER BY r.created_at DESC, r.recipe_id DESC
               LIMIT ? OFFSET ?";

            $paramsFbFinal = array_merge($paramsSelect, $paramsFb, [$limit, $offset]);

            $rowsFb = dbAll($sqlFb, $paramsFbFinal);
            $data   = array_map($mapRow, $rowsFb);
        }
    }

    if (empty($data) && $qLen > 0) {
        $stmtFb = pdo()->prepare("
            SELECT ingredient_id
              FROM ingredients
             WHERE REPLACE(name,' ','')         LIKE ? ESCAPE '\\\\'
                OR REPLACE(display_name,' ','') LIKE ? ESCAPE '\\\\'
        ");
        $qPat = likePatternParam($qNoSpc);
        $stmtFb->execute([$qPat, $qPat]);
        $ingFbIds = array_map('intval', $stmtFb->fetchAll(PDO::FETCH_COLUMN));

        if ($ingFbIds) {
            $marksFb   = implode(',', array_fill(0, count($ingFbIds), '?'));
            $sqlFb     = "
              {$select}
                FROM recipe r
                JOIN recipe_ingredient ri ON ri.recipe_id = r.recipe_id
               WHERE ri.ingredient_id IN ($marksFb)
               ORDER BY r.created_at DESC, r.recipe_id DESC
               LIMIT ? OFFSET ?";

            $paramsFbFinal = array_merge($paramsSelect, $ingFbIds, [$limit, $offset]);

            $rowsFb   = dbAll($sqlFb, $paramsFbFinal);
            $data     = array_map($mapRow, $rowsFb);
        }
    }

  $totalRecipes = (int)dbVal('SELECT COUNT(*) FROM recipe');
  jsonOutput([
        'success' => true,
        'page'    => $page,
    'limit'   => $limit,
    'has_next'=> ($page * $limit < $total),
    'count'   => count($data),
    'total'   => $total,
    'total_recipes' => $totalRecipes,
        'data'    => $data,
    ]);

} catch (Throwable $e) {
    error_log('[search_recipes] ' . $e->getMessage());
    jsonError('Server Error', 500);
}
