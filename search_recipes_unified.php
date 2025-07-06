<?php
/**
 * search_recipes_unified.php — R3-safe-final-merge-pythainlp+fallback-v4 (2025-07-05)
 *
 * 1) ชื่อเมนูตรง 100 % จะมาก่อนสุด
 * 2) ถ้าไม่ตรงชื่อ → สูตรที่มีวัตถุดิบตรงครบทุกคำค้น
 * 3) มีบางวัตถุดิบ (≥ 1 คำ) จะตามมาถัดไป (ตอนนี้เลือก “ครบทุกคำ”)
 * 4) รองรับ include / exclude / allergy (warning) / category / sort / pagination
 * 5) ใช้ PyThaiNLP newmm → ถ้า python ใช้ไม่ได้จะ fallback เป็น RegExp
 * 6) Fallback เดาคำวัตถุดิบ (เฉพาะกรณีมี ≤ 1 token) เหมือน v5
 * 7) include / exclude ด้วย “ชื่อวัตถุดิบ” ผ่านพารามิเตอร์  
 *    include=กุ้ง,หมูสับ | exclude=กระเทียม,นม
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/json.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    /* ─────────────────── 1) INPUT ─────────────────── */
    $p        = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $rawQ     = sanitize(trim($p['q'] ?? ''));
    $rawQ     = str_replace(',', ' ', $rawQ);
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

    /* ─────────────────── 2) TOKENISE ───────────────── */
    $tokens = $rawQ !== '' ? thaiTokens($rawQ) : [];
    if (!$tokens && $rawQ !== '') {
        $tokens = preg_split('/\s+/u', $rawQ, -1, PREG_SPLIT_NO_EMPTY);
    }
    // fallback หา token เพิ่ม (เดิม v5)
    if (count($tokens) <= 1 && $rawQ !== '') {
        $cands = dbAll(
            "SELECT name
               FROM ingredients
              WHERE ? LIKE CONCAT('%', name, '%')
           ORDER BY LENGTH(name) DESC
              LIMIT 10",
            [$rawQ]
        );
        foreach ($cands as $row) {
            $n = trim($row['name']);
            if ($n !== '' && !in_array($n, $tokens, true)) {
                $tokens[] = $n;
            }
        }
    }
    $tokens = array_slice($tokens, 0, 5);

    /* helper: เก็บ params ทีละตัวหรือทีละก้อน */
    $params = [];
    $push   = static function (array &$params, $val, int $n = 1): void {
        for ($i = 0; $i < $n; $i++) {
            $params[] = $val;
        }
    };

    /* ─────────────── 3) SELECT FIELDS ─────────────── */
    // 3.1 นับ match แต่ละ token
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
                       ri.descrip LIKE ?
                    OR i.name LIKE ?
                    OR i.display_name LIKE ?
                    OR i.searchable_keywords LIKE ?
                   )
            ))";
            $cases[] = "CASE WHEN $exists THEN 1 ELSE 0 END";
            $like = "%{$tok}%";
            // แต่ละ token ต้องเติม 4 placeholders × 2 (ใน CASE + ใน COUNT) = 8
            $push($params, $like, 8);
        }
        $cnt = implode(' + ', $cases);
        $ingSelect = "$cnt AS ing_match_cnt,
                      CASE WHEN $cnt = " . count($tokens) . " THEN 2 ELSE 1 END AS ing_rank";
    }

    // 3.2 เอา field มาตั้งแต่ recipe_id ไปจนถึง short_ingredients, ingredient_ids,
    //      ing_match_cnt, ing_rank, name_rank — และเพิ่ม has_allergy
    $recipeFields = <<<SQL
        r.recipe_id,
        r.name,
        r.image_path,
        r.prep_time,
        r.average_rating,

        -- ยอด favorite ตรงๆ จากตาราง favorites
        (SELECT COUNT(*) FROM favorites f WHERE f.recipe_id = r.recipe_id)
            AS favorite_count,

        -- จำนวนรีวิว
        (SELECT COUNT(*) FROM review v WHERE v.recipe_id = r.recipe_id)
            AS review_count,

        -- สรุปวัตถุดิบสั้นๆ
        (SELECT GROUP_CONCAT(
                  DISTINCT CASE WHEN ri.descrip <> '' THEN ri.descrip ELSE i.display_name END
                  SEPARATOR ', ')
           FROM recipe_ingredient ri
           JOIN ingredients i USING(ingredient_id)
          WHERE ri.recipe_id = r.recipe_id)
            AS short_ingredients,

        -- id วัตถุดิบทั้งหมด (ใช้ตอนพาร์สกลับเป็น array)
        (SELECT GROUP_CONCAT(DISTINCT ri.ingredient_id)
           FROM recipe_ingredient ri
          WHERE ri.recipe_id = r.recipe_id)
            AS ingredient_ids,

        -- 1) นับ match  2) จัด rank ว่าครบทุก token หรือไม่
        $ingSelect,

        -- has_allergy: เช็คว่ามีวัตถุดิบที่ user แพ้หรือไม่
        EXISTS (
          SELECT 1
            FROM recipe_ingredient ri_all
            JOIN allergyinfo a USING(ingredient_id)
           WHERE ri_all.recipe_id = r.recipe_id
             AND a.user_id = ?
        ) AS has_allergy,

        -- name_rank: จัดอันดับชื่อเมนูตรงต่างระดับ
        (CASE
           WHEN r.name = ?                                                   THEN 100
           WHEN REPLACE(REPLACE(REPLACE(r.name,CHAR(13),''),CHAR(10),''),' ','') = ? THEN  90
           WHEN r.name LIKE ?                                                THEN  80
           WHEN r.name LIKE ?                                                THEN  70
           WHEN REPLACE(r.name,' ','') LIKE ?                                THEN  60
           ELSE 0
         END) AS name_rank
    SQL;

    // เติม param สำหรับ has_allergy + name_rank placeholders (รวม 1 + 5 = 6 ตัว)
    $push($params, $userId);     // for has_allergy
    $push($params, $rawQ);       // name_rank = ?
    $push($params, $qNoSpace);   // name_rank without spaces
    $push($params, "{$rawQ}%");  
    $push($params, "%{$qNoSpace}%");
    $push($params, "%{$qNoSpace}%");

    /* ───────────── 4) SQL + WHERE ─────────── */
    $sql = "SELECT\n  $recipeFields\nFROM recipe r\nWHERE 1=1\n";

    /* ───────── 5) เงื่อนไขชื่อเมนู / วัตถุดิบ ───────── */
    if ($rawQ !== '') {
        // 5.1 name conditions
        $nameConds = [
            'r.name = ?',
            "REPLACE(REPLACE(REPLACE(r.name,CHAR(13),''),CHAR(10),''),' ','') = ?",
            'r.name LIKE ?',
            'r.name LIKE ?',
            "REPLACE(r.name,' ','') LIKE ?",
            'r.name LIKE ?',
        ];
        // เติมอีก 6 param ให้ตรงกับเงื่อนไข
        $push($params, $rawQ);
        $push($params, $qNoSpace);
        $push($params, "{$rawQ}%");
        $push($params, "%{$qNoSpace}%");
        $push($params, "%{$qNoSpace}%");
        $push($params, "%{$rawQ}%");

        // ถ้ามี tokens: name ต้อง match ทุก token
        if ($tokens) {
            $sub = [];
            foreach ($tokens as $tok) {
                $sub[] = 'r.name LIKE ?';
                $push($params, "%{$tok}%");
            }
            $nameConds[] = '(' . implode(' AND ', $sub) . ')';
        }

        // 5.2 ingredient EXISTS (match ทุก token)
        $ingConds = [];
        foreach ($tokens as $tok) {
            $exists = "EXISTS (
              SELECT 1
                FROM recipe_ingredient ri
                JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
               WHERE ri.recipe_id = r.recipe_id
                 AND (
                     ri.descrip LIKE ?
                  OR i.name LIKE ?
                  OR i.display_name LIKE ?
                  OR i.searchable_keywords LIKE ?
                 )
            )";
            $ingConds[] = $exists;
            $push($params, "%{$tok}%", 4);
        }

        $sql .= "  AND (\n"
              . "    (" . implode(" OR\n     ", $nameConds) . ")\n";
        if ($ingConds) {
            $sql .= "    OR (" . implode(" AND ", $ingConds) . ")\n";
        }
        $sql .= "  )\n";
    }

    /* ─────────── 6) include / exclude ─────────── */
    if ($includeIds) {
        $ph = implode(',', array_fill(0, count($includeIds), '?'));
        $sql .= "  AND EXISTS (
          SELECT 1 FROM recipe_ingredient ri_inc
          WHERE ri_inc.recipe_id = r.recipe_id
            AND ri_inc.ingredient_id IN ($ph)
        )\n";
        foreach ($includeIds as $id) {
            $push($params, $id);
        }
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
                     ri_n.descrip LIKE ?
                  OR i_n.display_name LIKE ?
                  OR i_n.searchable_keywords LIKE ?
                 )
            )';
            $push($params, "%{$nm}%", 3);
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
        foreach ($excludeIds as $id) {
            $push($params, $id);
        }
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
                     ri_x.descrip LIKE ?
                  OR i_x.display_name LIKE ?
                  OR i_x.searchable_keywords LIKE ?
                 )
            )';
            $push($params, "%{$nm}%", 3);
        }
        $sql .= "  AND (" . implode(' AND ', $subs) . ")\n";
    }

    /* ─────────── 7) หมวดหมู่ ─────────── */
    if ($catId !== null) {
        $sql .= "  AND EXISTS (
          SELECT 1 FROM category_recipe cr
          WHERE cr.recipe_id = r.recipe_id
            AND cr.category_id = ?
        )\n";
        $push($params, $catId);
    }

    /* ─────────── 8) SORT + PAGING ─────────── */
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

    /* ─────────── 9) ตรวจจำนวน placeholder ─────────── */
    $phCnt = substr_count($sql, '?');
    if ($phCnt !== count($params)) {
        error_log("Placeholder=$phCnt Params=" . count($params));
        throw new RuntimeException('Parameter count mismatch (internal)');
    }

    /* ─────────── 10) EXECUTE + JSON OUTPUT ─────────── */
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
            'has_allergy'       => (bool)$r['has_allergy'],  // เพิ่มใน output
        ];
    }, $rows);

    jsonOutput([
        'success' => true,
        'page'    => $page,
        'tokens'  => $tokens,  // ส่งกลับให้ Flutter ไฮไลท์
        'data'    => $data,
    ]);
}
catch (Throwable $e) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    error_log('[search_recipes_unified] ' . $e->getMessage());
    jsonError('เกิดข้อผิดพลาดภายในเซิร์ฟเวอร์', 500);
}
