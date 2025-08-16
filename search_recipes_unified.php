<?php
/**
 * search_recipes_unified.php — R3-safe-final-merge-pythainlp+fallback-v4 (2025-08-10c)
 * - เหมือนเวอร์ชันที่คุณส่งมา แต่เพิ่ม Fallback helper (reqBool, defaultSearchTokenize,
 *   parseSearchTerms, likePattern) ไว้ในไฟล์นี้ เพื่อกันกรณี inc/functions.php ยังไม่มี
 * - ไม่ตัดโค้ดเดิมทิ้ง
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php'; // ถ้ามี helper ใหม่อยู่แล้ว ก็จะใช้ของเดิม

header('Content-Type: application/json; charset=UTF-8');

/* ───────────────────────────── Fallback Helpers ─────────────────────────────
   ถ้าโปรเจ็กต์ของคุณมีฟังก์ชันพวกนี้อยู่แล้วใน inc/functions.php จะไม่ใช้บล็อกนี้
   แต่ถ้าไม่มี (undefined function) บล็อกนี้จะช่วยให้ไฟล์ทำงานได้ทันที
-----------------------------------------------------------------------------*/
if (!function_exists('reqBool')) {
    function reqBool(string $key, bool $default = false): bool {
        $src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
        if (!array_key_exists($key, $src)) return $default;
        $v = $src[$key];
        if (is_bool($v)) return $v;
        $v = strtolower(trim((string)$v));
        return in_array($v, ['1','true','yes','on'], true);
    }
}
if (!function_exists('defaultSearchTokenize')) {
    // เปิด/ปิดตัดคำเริ่มต้น (ปิดไว้ปลอดภัย ถ้าอยากเปิดถาวรเปลี่ยนเป็น true)
    function defaultSearchTokenize(): bool { return false; }
}
if (!function_exists('likePattern')) {
    // ทำ pattern สำหรับ LIKE โดย escape \ % _
    function likePattern(string $s): string {
        $s = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $s);
        return '%' . $s . '%';
    }
}
if (!function_exists('parseSearchTerms')) {
    /**
     * ตัดคำค้น: ถ้าไม่มี PyThaiNLP/ตัวช่วยอื่น ให้ fallback เป็นแยกด้วยช่องว่าง/จุลภาค
     * คืน array ไม่ซ้ำ ไม่ว่าง ยาวสุดประมาณ 5 คำ (ตัดในผู้เรียกอีกชั้น)
     */
    function parseSearchTerms(string $raw, bool $tokenize): array {
        $raw = trim($raw);
        if ($raw === '') return [];
        // ถ้าอนาคตคุณมีตัวตัดคำไทย เรียกที่นี่แล้วค่อย fallback ต่อไปนี้
        $terms = preg_split('/[,\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_values(array_unique(array_map('trim', $terms)));
        return $terms;
    }
}
if (!function_exists('sanitize')) {
    // กัน null / trim คร่าว ๆ (กัน fatal ถ้าโปรเจ็กต์เก่ายังไม่มี)
    function sanitize(?string $s): string { return trim((string)$s); }
}
if (!function_exists('jsonOutput')) {
    function jsonOutput(array $obj, int $code = 200): void {
        http_response_code($code);
        echo json_encode($obj, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('jsonError')) {
    function jsonError(string $msg, int $code = 400): void {
        jsonOutput(['success'=>false, 'message'=>$msg], $code);
    }
}

/* ────────────────────────────────────────────────────────────────────────── */

try {
    /* 1) INPUT */
    $p        = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $rawQ     = sanitize(str_replace(',', ' ', (string)($p['q'] ?? '')));
    $qNoSpace = preg_replace('/\s+/u', '', $rawQ);

    $catId  = isset($p['cat_id']) && $p['cat_id'] !== '' ? (int)$p['cat_id'] : null;
    $sort   = strtolower(trim($p['sort'] ?? 'latest'));
    $page   = max(1, (int)($p['page'] ?? 1));
    $limit  = max(1, min(50, (int)($p['limit'] ?? 26)));
    $offset = ($page - 1) * $limit;

    $userId = getLoggedInUserId();  // ถ้า guest จะเป็น null

    /* include / exclude by ID */
    $includeIds = array_filter(array_map('intval', (array)($p['include_ids'] ?? [])));
    $excludeIds = array_filter(array_map('intval', (array)($p['exclude_ids'] ?? [])));

    /* include / exclude by “ชื่อวัตถุดิบ” */
    $includeNames = [];
    if (!empty($p['include'])) {
        $includeNames = array_filter(array_map(static function ($s) {
            return sanitize(trim($s));
        }, explode(',', $p['include'])));
    }
    $excludeNames = [];
    if (!empty($p['exclude'])) {
        $excludeNames = array_filter(array_map(static function ($s) {
            return sanitize(trim($s));
        }, explode(',', $p['exclude'])));
    }

    /* ★ กลุ่มวัตถุดิบ */
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

    /* 2) TOKENISE */
    $tokenize = reqBool('tokenize', defaultSearchTokenize());
    $tokens = $rawQ !== '' ? parseSearchTerms($rawQ, $tokenize) : [];

    // fallback เดาคำวัตถุดิบ (อย่างที่เคยทำ)
    if (count($tokens) <= 1 && $rawQ !== '') {
        $cands = dbAll(
            "SELECT name FROM ingredients WHERE ? LIKE CONCAT('%', name, '%')
             ORDER BY LENGTH(name) DESC LIMIT 10", [$rawQ]
        );
        foreach ($cands as $row) {
            $n = trim($row['name']);
            if ($n !== '' && !in_array($n, $tokens, true)) $tokens[] = $n;
        }
    }
    $tokens = array_slice($tokens, 0, 5);

    /* เก็บ params */
    $params = [];
    $push = static function (array &$params, $val, int $n = 1): void {
        for ($i = 0; $i < $n; $i++) $params[] = $val;
    };

    /* 3) SELECT FIELDS */
    $ingSelect = '0 AS ing_match_cnt, 0 AS ing_rank';
    if ($tokens) {
        $cases = [];
        foreach ($tokens as $tok) {
            $exists = "(EXISTS (
                SELECT 1
                  FROM recipe_ingredient ri
                  JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
                 WHERE ri.recipe_id = r.recipe_id
                   AND (
                       ri.descrip LIKE ? ESCAPE '\\\\'
                    OR i.name LIKE ? ESCAPE '\\\\'
                    OR i.display_name LIKE ? ESCAPE '\\\\'
                    OR i.searchable_keywords LIKE ? ESCAPE '\\\\'
                   )
            ))";
            $cases[] = "CASE WHEN $exists THEN 1 ELSE 0 END";
            $like = likePattern($tok);
            // $cnt จะถูกใส่ซ้ำ 2 ครั้งใน SELECT → 4 placeholders × 2 = 8
            $push($params, $like, 8);
        }
        $cnt = implode(' + ', $cases);
        $ingSelect = "$cnt AS ing_match_cnt,
                      CASE WHEN $cnt = " . count($tokens) . " THEN 2 ELSE 1 END AS ing_rank";
    }

    $recipeFields = <<<SQL
        r.recipe_id,
        r.name,
        r.image_path,
        r.prep_time,
        r.average_rating,

        (SELECT COUNT(*) FROM favorites f WHERE f.recipe_id = r.recipe_id) AS favorite_count,
        (SELECT COUNT(*) FROM review v WHERE v.recipe_id = r.recipe_id)    AS review_count,

        (SELECT GROUP_CONCAT(
                  DISTINCT CASE WHEN ri.descrip <> '' THEN ri.descrip ELSE i.display_name END
                  SEPARATOR ', ')
           FROM recipe_ingredient ri
           JOIN ingredients i USING(ingredient_id)
          WHERE ri.recipe_id = r.recipe_id) AS short_ingredients,

        (SELECT GROUP_CONCAT(DISTINCT ri.ingredient_id)
           FROM recipe_ingredient ri
          WHERE ri.recipe_id = r.recipe_id) AS ingredient_ids,

        $ingSelect,

        -- has_allergy แบบ “ขยายทั้งกลุ่ม”
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

        -- name_rank
        (CASE
           WHEN r.name = ?                                                   THEN 100
           WHEN REPLACE(REPLACE(REPLACE(r.name,CHAR(13),''),CHAR(10),''),' ','') = ? THEN  90
           WHEN r.name LIKE ?                                                THEN  80
           WHEN r.name LIKE ?                                                THEN  70
           WHEN REPLACE(r.name,' ','') LIKE ?                                THEN  60
           ELSE 0
         END) AS name_rank
    SQL;

    $push($params, $userId);
    $push($params, $rawQ);
    $push($params, $qNoSpace);
    $push($params, "{$rawQ}%");
    $push($params, "%{$qNoSpace}%");
    $push($params, "%{$qNoSpace}%");

    /* 4) SQL + WHERE */
    $sql = "SELECT\n  $recipeFields\nFROM recipe r\nWHERE 1=1\n";

    /* 5) เงื่อนไขชื่อ/วัตถุดิบ */
    if ($rawQ !== '') {
        $nameConds = [
            'r.name = ?',
            "REPLACE(REPLACE(REPLACE(r.name,CHAR(13),''),CHAR(10),''),' ','') = ?",
            'r.name LIKE ? ESCAPE \'\\\\\'',
            'r.name LIKE ? ESCAPE \'\\\\\'',
            "REPLACE(r.name,' ','') LIKE ? ESCAPE '\\\\'",
            'r.name LIKE ? ESCAPE \'\\\\\'',
        ];
        $push($params, $rawQ);
        $push($params, $qNoSpace);
        $push($params, "{$rawQ}%");
        $push($params, "%{$qNoSpace}%");
        $push($params, "%{$qNoSpace}%");
        $push($params, "%{$rawQ}%");

        if ($tokens) {
            $sub = [];
            foreach ($tokens as $tok) {
                $sub[] = 'r.name LIKE ? ESCAPE \'\\\\\'';
                $push($params, likePattern($tok));
            }
            $nameConds[] = '(' . implode(' AND ', $sub) . ')';
        }

        $ingConds = [];
        foreach ($tokens as $tok) {
            $exists = "EXISTS (
              SELECT 1
                FROM recipe_ingredient ri
                JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
               WHERE ri.recipe_id = r.recipe_id
                 AND (
                     ri.descrip LIKE ? ESCAPE '\\\\'
                  OR i.name LIKE ? ESCAPE '\\\\'
                  OR i.display_name LIKE ? ESCAPE '\\\\'
                  OR i.searchable_keywords LIKE ? ESCAPE '\\\\'
                 )
            )";
            $ingConds[] = $exists;
            $like = likePattern($tok);
            $push($params, $like, 4);
        }

        $sql .= "  AND (\n"
              . "    (" . implode(" OR\n     ", $nameConds) . ")\n";
        if ($ingConds) {
            $sql .= "    OR (" . implode(" AND ", $ingConds) . ")\n";
        }
        $sql .= "  )\n";
    }

    /* 5.3 กลุ่มวัตถุดิบ */
    if ($group !== '') {
        $sql .= "  AND EXISTS (
          SELECT 1
            FROM recipe_ingredient ri_g
            JOIN ingredients i_g ON i_g.ingredient_id = ri_g.ingredient_id
           WHERE ri_g.recipe_id = r.recipe_id
             AND TRIM(i_g.newcatagory) = TRIM(?)
        )\n";
        $push($params, $group);
    }
    foreach ($includeGroups as $g) {
        $sql .= "  AND EXISTS (
          SELECT 1
            FROM recipe_ingredient ri_gi
            JOIN ingredients i_gi ON i_gi.ingredient_id = ri_gi.ingredient_id
           WHERE ri_gi.recipe_id = r.recipe_id
             AND TRIM(i_gi.newcatagory) = TRIM(?)
        )\n";
        $push($params, $g);
    }
    foreach ($excludeGroups as $g) {
        $sql .= "  AND NOT EXISTS (
          SELECT 1
            FROM recipe_ingredient ri_ge
            JOIN ingredients i_ge ON i_ge.ingredient_id = ri_ge.ingredient_id
           WHERE ri_ge.recipe_id = r.recipe_id
             AND TRIM(i_ge.newcatagory) = TRIM(?)
        )\n";
        $push($params, $g);
    }

    /* 6) include / exclude ชื่อ/ID เดิม */
    if ($includeIds) {
        $ph = implode(',', array_fill(0, count($includeIds), '?'));
        $sql .= "  AND EXISTS (
          SELECT 1 FROM recipe_ingredient ri_inc
          WHERE ri_inc.recipe_id = r.recipe_id
            AND ri_inc.ingredient_id IN ($ph)
        )\n";
        foreach ($includeIds as $id) $push($params, $id);
    }
    if ($includeNames) {
        $subs = [];
        foreach ($includeNames as $nm) {
            $subs[] = 'EXISTS (
              SELECT 1
                FROM recipe_ingredient ri_n
                JOIN ingredients i_n USING(ingredient_id)
               WHERE ri_n.recipe_id = r.recipe_id
                 AND (
                     ri_n.descrip LIKE ? ESCAPE \'\\\\\'
                  OR i_n.name LIKE ? ESCAPE \'\\\\\'
                  OR i_n.display_name LIKE ? ESCAPE \'\\\\\'
                  OR i_n.searchable_keywords LIKE ? ESCAPE \'\\\\\'
                 )
            )';
            $like = likePattern($nm);
            $push($params, $like, 4);
        }
        $sql .= "  AND (" . implode(' AND ', $subs) . ")\n";
    }

    if ($excludeIds) {
        $ph = implode(',', array_fill(0, count($excludeIds), '?'));
        $sql .= "  AND NOT EXISTS (
          SELECT 1 FROM recipe_ingredient ri_exc
          WHERE ri_exc.recipe_id = r.recipe_id
            AND ri_exc.ingredient_id IN ($ph)
        )\n";
        foreach ($excludeIds as $id) $push($params, $id);
    }
    if ($excludeNames) {
        $subs = [];
        foreach ($excludeNames as $nm) {
            $subs[] = 'NOT EXISTS (
              SELECT 1
                FROM recipe_ingredient ri_x
                JOIN ingredients i_x USING(ingredient_id)
               WHERE ri_x.recipe_id = r.recipe_id
                 AND (
                     ri_x.descrip LIKE ? ESCAPE \'\\\\\'
                  OR i_x.name LIKE ? ESCAPE \'\\\\\'
                  OR i_x.display_name LIKE ? ESCAPE \'\\\\\'
                  OR i_x.searchable_keywords LIKE ? ESCAPE \'\\\\\'
                 )
            )';
            $like = likePattern($nm);
            $push($params, $like, 4);
        }
        $sql .= "  AND (" . implode(' AND ', $subs) . ")\n";
    }

    /* 7) หมวดหมู่ */
    if ($catId !== null) {
        $sql .= "  AND EXISTS (
          SELECT 1 FROM category_recipe cr
          WHERE cr.recipe_id = r.recipe_id
            AND cr.category_id = ?
        )\n";
        $push($params, $catId);
    }

    /* 8) SORT + PAGING */
    $orderBy = match ($sort) {
        'popular'     => 'favorite_count DESC',
        'trending'    => 'r.created_at DESC, favorite_count DESC',
        'recommended' => 'r.average_rating DESC, review_count DESC',
        default       => 'r.created_at DESC',
    };
    $sql .= "ORDER BY
        name_rank       DESC,
        $orderBy,
        ing_rank        DESC,
        ing_match_cnt   DESC,
        r.recipe_id     DESC
      LIMIT $limit OFFSET $offset";

    /* 9) ตรวจจำนวน placeholder */
    $phCnt = substr_count($sql, '?');
    if ($phCnt !== count($params)) {
        error_log("[search_recipes_unified] Placeholder=$phCnt Params=".count($params));
        throw new RuntimeException('Parameter count mismatch (internal)');
    }

    /* 10) EXECUTE + JSON OUTPUT */
    $rows = dbAll($sql, $params);
    $base = getBaseUrl() . '/uploads/recipes';

    $data = array_map(static function ($r) use ($base) {
        return [
            'recipe_id'         => (int)$r['recipe_id'],
            'name'              => $r['name'],
            'image_url'         => $r['image_path']
                                   ? $base . '/' . basename($r['image_path'])
                                   : $base . '/default_recipe.png',
            'prep_time'         => $r['prep_time'] !== null ? (int)$r['prep_time'] : null,
            'favorite_count'    => (int)$r['favorite_count'],
            'average_rating'    => (float)$r['average_rating'],
            'review_count'      => (int)$r['review_count'],
            'short_ingredients' => $r['short_ingredients'] ?? '',
            'ingredient_ids'    => array_filter(array_map('intval', explode(',', $r['ingredient_ids'] ?? ''))),
            'has_allergy'       => (bool)$r['has_allergy'],
        ];
    }, $rows);

    jsonOutput([
        'success' => true,
        'page'    => $page,
        'tokens'  => $tokens,
        'data'    => $data,
    ]);
}
catch (Throwable $e) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    error_log('[search_recipes_unified] ' . $e->getMessage());
    jsonError('เกิดข้อผิดพลาดภายในเซิร์ฟเวอร์', 500);
}
