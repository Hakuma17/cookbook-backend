<?php

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/json.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $p        = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
    $q        = sanitize($p['q'] ?? '');
    $qNoSpc   = preg_replace('/\s+/u', '', $q);
    $sort     = strtolower(trim($p['sort'] ?? 'latest'));
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

    $tokens = [];
    if (!empty($p['ingredients'])) {
        $tokens = preg_split('/[,\s;]+/u', $p['ingredients'], -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_map('trim', $tokens);
    }

    $qLen = mb_strlen($q);
    if ($qLen > 100)              jsonError('คำค้นหายาวเกินไป', 400);
    if ($qLen > 0 && $qLen < 2)   jsonError('กรุณาใส่คำค้นอย่างน้อย 2 ตัวอักษร', 400);

    $userId = getLoggedInUserId();

    // SELECT clause หลักสำหรับใช้ทั้งใน query ปกติและ fallback
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

    $selectNameRank  = '';
    $paramsNameRank  = [];
    if ($qLen) {
        $selectNameRank = ",
        (CASE
          WHEN r.name = :q_exact                   THEN 3
          WHEN REPLACE(r.name,' ','') = :q_exact_nospace   THEN 2
          WHEN r.name LIKE :q_prefix               THEN 1
          ELSE 0
         END) AS name_rank";
        $paramsNameRank = [
            ':q_exact'         => $q,
            ':q_exact_nospace' => $qNoSpc,
            ':q_prefix'        => $q . '%',
        ];
    }

    $sql    = $select . $selectNameRank . "\nFROM recipe r";
    $params = [];

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
                              $aliasRI.descrip           LIKE ?
                           OR $aliasIng.name              LIKE ?
                           OR $aliasIng.display_name      LIKE ?
                           OR $aliasIng.searchable_keywords LIKE ?
                        )";
            $like = '%' . $t . '%';
            array_push($params, $like, $like, $like, $like);
        }
    }

    if ($includeIds) {
        $marks = implode(',', array_fill(0, count($includeIds), '?'));
        $sql .= "
          INNER JOIN recipe_ingredient inc
                     ON inc.recipe_id = r.recipe_id
                    AND inc.ingredient_id IN ($marks)";
        $params = array_merge($params, $includeIds);
    }

    $sql .= $excludeIds
        ? " WHERE r.recipe_id NOT IN (
              SELECT recipe_id FROM recipe_ingredient WHERE ingredient_id IN (" . implode(',', array_fill(0, count($excludeIds), '?')) . ")
            )"
        : ' WHERE 1';
    $params = array_merge($params, $excludeIds);

    if ($qLen) {
        $sql .= "
          AND (
                r.name = ?
             OR REPLACE(r.name,' ','') = ?
             OR r.name LIKE ?
             OR REPLACE(r.name,' ','') LIKE ?
          )";
        array_push($params, $q, $qNoSpc, "%{$q}%", "%{$qNoSpc}%");
    }

    if ($catId !== null) {
        $sql .= "
          AND EXISTS (
            SELECT 1
              FROM category_recipe cr
             WHERE cr.recipe_id = r.recipe_id
               AND cr.category_id = ?
          )";
        $params[] = $catId;
    }

    $orderTrail   = match ($sort) {
        'popular'     => 'favorite_count DESC',
        'trending'    => 'r.created_at DESC, favorite_count DESC',
        'recommended' => 'r.average_rating DESC, review_count DESC',
        default       => 'r.created_at DESC',
    };
    $orderNameRank = $qLen ? 'name_rank DESC,' : '';

    $sql .= "
      ORDER BY {$orderNameRank} {$orderTrail}
      LIMIT {$limit} OFFSET {$offset}";

    $params = array_merge($paramsNameRank, $params);
    $rows   = dbAll($sql, $params);

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
        ];
    };
    $data = array_map($mapRow, $rows);

    // ===== Fallback 1: แยกคำค้นหาแล้วลองหาในชื่อสูตร
    if (empty($data) && $qLen > 0) {
        $qWords = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        $qWords = array_filter($qWords, fn($s) => mb_strlen($s) >= 2);
        if ($qWords) {
            $likeConds = implode(' AND ', array_fill(0, count($qWords), 'r.name LIKE ?'));
            $paramsFb = array_map(fn($w) => '%' . $w . '%', $qWords);
            // [FIXED] ใช้ $select เพื่อให้ได้ column ครบเหมือน query หลัก
            $sqlFb = "
              {$select}
                FROM recipe r
               WHERE {$likeConds}
               ORDER BY r.created_at DESC
               LIMIT {$limit} OFFSET {$offset}";
            $rowsFb = dbAll($sqlFb, $paramsFb);
            $data = array_map($mapRow, $rowsFb);
        }
    }

    // ===== Fallback 2: ถ้ายังว่างอีก → หาในวัตถุดิบ
    if (empty($data) && $qLen > 0) {
        // [FIXED] แก้ไขเงื่อนไข LIKE ให้ถูกต้อง
        $stmtFb = pdo()->prepare("
            SELECT ingredient_id
              FROM ingredients
             WHERE REPLACE(name,' ','') LIKE :qPattern
                OR REPLACE(display_name,' ','') LIKE :qPattern
        ");
        $stmtFb->execute([':qPattern' => '%' . $qNoSpc . '%']);
        $ingFbIds = array_map('intval', $stmtFb->fetchAll(PDO::FETCH_COLUMN));
        
        if ($ingFbIds) {
            $marksFb   = implode(',', array_fill(0, count($ingFbIds), '?'));
            // [FIXED] ใช้ $select เพื่อให้ได้ column ครบเหมือน query หลัก
            $sqlFb     = "
              {$select}
                FROM recipe r
                JOIN recipe_ingredient ri ON ri.recipe_id = r.recipe_id
               WHERE ri.ingredient_id IN ($marksFb)
               ORDER BY r.created_at DESC
               LIMIT ? OFFSET ?";
            $paramsFb = array_merge($ingFbIds, [$limit, $offset]);
            $rowsFb   = dbAll($sqlFb, $paramsFb);
            $data     = array_map($mapRow, $rowsFb);
        }
    }

    jsonOutput([
        'success' => true,
        'page'    => $page,
        'data'    => $data,
    ]);

} catch (Throwable $e) {
    error_log('[search_recipes] ' . $e->getMessage());
    jsonError('Server Error', 500);
}