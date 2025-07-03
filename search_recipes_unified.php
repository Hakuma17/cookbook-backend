<?php
/**
 * search_recipes_unified.php — R3-safe-final-fix-v5
 *
 * 1) ชื่อเมนูตรง 100 % จะมาก่อนสุด
 * 2) ถ้าไม่ตรงชื่อ → สูตรที่มีวัตถุดิบตรงครบทุกคำค้น
 * 3) มีบางวัตถุดิบ (≥ 1 คำ) จะตามมาถัดไป
 * 4) แยกคำด้วยเว้นวรรค , หรือ ;  รองรับ include / exclude / allergy / category / sort / pagination
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
    $rawQ     = str_replace(',', ' ', $rawQ);          // คอมมา → เว้นวรรค
    $qNoSpace = preg_replace('/\s+/u', '', $rawQ);     // ตัดเว้นวรรคทั้งหมด

    $catId  = isset($p['cat_id']) && $p['cat_id'] !== '' ? (int)$p['cat_id'] : null;
    $sort   = strtolower(trim($p['sort'] ?? 'latest'));
    $page   = max(1, (int)($p['page'] ?? 1));
    $limit  = max(1, min(50, (int)($p['limit'] ?? 26)));
    $offset = ($page - 1) * $limit;

    $userId = getLoggedInUserId();

    /* include / exclude */
    $includeIds = array_filter(array_map('intval', (array)($p['include_ids'] ?? [])));
    $excludeIds = array_filter(array_map('intval', (array)($p['exclude_ids'] ?? [])));

    /* ─────────────────── 2) TOKENISE ───────────────── */
    $tokens = $rawQ !== '' ? preg_split('/\s+/u', $rawQ, -1, PREG_SPLIT_NO_EMPTY) : [];

    /* fallback: เดาคำวัตถุดิบ หากค้นแค่คำเดียว */
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
            $name = trim($row['name']);
            if ($name !== '' && !in_array($name, $tokens, true)) {
                $tokens[] = $name;
            }
        }
    }
    $tokens = array_slice($tokens, 0, 5);   // จำกัดไม่เกิน 5 คำ

    /* helper: เติมค่าเข้าพารามิเตอร์ */
    $params = [];
    $push   = static function (&$params, $val, $n = 1) {
        for ($i = 0; $i < $n; $i++) {
            $params[] = $val;
        }
    };

    /* ─────────────── 3) SELECT FIELDS ─────────────── */
    /* 3.1 ingredient-match counter */
    $ingSelect = '0 AS ing_match_cnt, 0 AS ing_rank';
    if ($tokens) {
        $pieces = [];
        foreach ($tokens as $tok) {
            $exists = "(EXISTS (SELECT 1
                                  FROM recipe_ingredient ri
                                  JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
                                 WHERE ri.recipe_id = r.recipe_id
                                   AND (ri.descrip LIKE ?
                                        OR i.name LIKE ?
                                        OR i.display_name LIKE ?
                                        OR i.searchable_keywords LIKE ?)))";
            $pieces[] = "CASE WHEN $exists THEN 1 ELSE 0 END";
            $like = "%{$tok}%";
            $push($params, $like, 8);   // ★ 4 placeholder × 2 การใช้ $cnt
        }
        $cnt       = implode(' + ', $pieces);
        $ingSelect = "$cnt AS ing_match_cnt,
                      CASE WHEN $cnt = " . count($tokens) . " THEN 2 ELSE 1 END AS ing_rank";
    }

    /* 3.2 recipe fields + name_rank */
    $recipeFields = <<<SQL
        r.recipe_id,
        r.name,
        r.image_path,
        r.prep_time,
        r.average_rating,
        (SELECT COUNT(*) FROM favorites f WHERE f.recipe_id = r.recipe_id) AS favorite_count,
        (SELECT COUNT(*) FROM review v WHERE v.recipe_id = r.recipe_id)    AS review_count,
        (SELECT GROUP_CONCAT(DISTINCT
                CASE WHEN ri.descrip <> '' THEN ri.descrip ELSE i.display_name END
                SEPARATOR ', ')
           FROM recipe_ingredient ri
           JOIN ingredients i USING(ingredient_id)
          WHERE ri.recipe_id = r.recipe_id) AS short_ingredients,
        (SELECT GROUP_CONCAT(DISTINCT ri.ingredient_id)
           FROM recipe_ingredient ri
          WHERE ri.recipe_id = r.recipe_id) AS ingredient_ids,
        $ingSelect,
        (CASE
           WHEN r.name = ?                                                   THEN 100
           WHEN REPLACE(REPLACE(REPLACE(r.name,CHAR(13),''),CHAR(10),''),' ','') = ? THEN 90
           WHEN r.name LIKE ?                                                THEN 80
           WHEN r.name LIKE ?                                                THEN 70
           WHEN REPLACE(r.name, ' ', '') LIKE ?                              THEN 60
           ELSE 0
         END) AS name_rank
    SQL;

    /* เติมพารามิเตอร์สำหรับ name_rank */
    $push($params, $rawQ);
    $push($params, $qNoSpace);
    $push($params, "{$rawQ}%");
    $push($params, "%{$qNoSpace}%");
    $push($params, "%{$qNoSpace}%");

    /* ───────────── 4) SQL + WHERE (พื้นฐาน) ─────────── */
    $sql = "SELECT\n  $recipeFields\nFROM recipe r\nWHERE 1=1\n";

    /* ────── 5) เงื่อนไขชื่อเมนู / วัตถุดิบ (ถ้ามี q) ───── */
    if ($rawQ !== '') {
        /* 5.1 ชื่อเมนู */
        $nameConds = [
            'r.name = ?',
            "REPLACE(REPLACE(REPLACE(r.name,CHAR(13),''),CHAR(10),''),' ','') = ?",
            'r.name LIKE ?',
            'r.name LIKE ?',
            "REPLACE(r.name, ' ', '') LIKE ?",
            'r.name LIKE ?'
        ];
        $push($params, $rawQ);
        $push($params, $qNoSpace);
        $push($params, "{$rawQ}%");
        $push($params, "%{$qNoSpace}%");
        $push($params, "%{$qNoSpace}%");
        $push($params, "%{$rawQ}%");

        /* AND ชื่อเมนูต้องมีทุก token */
        if ($tokens) {
            $sub = [];
            foreach ($tokens as $tok) {
                $sub[] = 'r.name LIKE ?';
                $push($params, "%{$tok}%");
            }
            $nameConds[] = '(' . implode(' AND ', $sub) . ')';
        }

        /* 5.2 ingredient EXISTS */
        $ingConds = [];
        foreach ($tokens as $tok) {
            $exists = "EXISTS (SELECT 1
                                 FROM recipe_ingredient ri
                                 JOIN ingredients i ON i.ingredient_id = ri.ingredient_id
                                WHERE ri.recipe_id = r.recipe_id
                                  AND (ri.descrip LIKE ?
                                       OR i.name LIKE ?
                                       OR i.display_name LIKE ?
                                       OR i.searchable_keywords LIKE ?))";
            $ingConds[] = $exists;
            $like = "%{$tok}%";
            $push($params, $like, 4);
        }

        $sql .= "  AND (\n    (" . implode(" OR\n     ", $nameConds) . ")\n";
        // if ($ingConds) {
        //     // ≥ 1 คำก็ผ่าน
        //     $sql .= "    OR (" . implode(' OR ', $ingConds) . ")\n";   // ≥ 1 คำก็ผ่าน
        // }
     if ($ingConds) {
         //ต้องตรงทุก token
         $sql .= "    OR (" . implode(' AND ', $ingConds) . ")\n";
     }
        $sql .= "  )\n";
    }

    /* ─────────── 6) include / exclude ─────────── */
    if ($includeIds) {
        $ph   = implode(',', array_fill(0, count($includeIds), '?'));
        $sql .= "  AND EXISTS (SELECT 1 FROM recipe_ingredient ri_inc
                               WHERE ri_inc.recipe_id = r.recipe_id
                                 AND ri_inc.ingredient_id IN ($ph))\n";
        foreach ($includeIds as $id) {
            $push($params, $id);
        }
    }
    if ($excludeIds) {
        $ph   = implode(',', array_fill(0, count($excludeIds), '?'));
        $sql .= "  AND NOT EXISTS (SELECT 1 FROM recipe_ingredient ri_exc
                                   WHERE ri_exc.recipe_id = r.recipe_id
                                     AND ri_exc.ingredient_id IN ($ph))\n";
        foreach ($excludeIds as $id) {
            $push($params, $id);
        }
    }

    /* ─────────── 7) allergy ─────────── */
    if ($userId) {
        $sql .= "  AND NOT EXISTS (SELECT 1
                                     FROM recipe_ingredient ri_all
                                     JOIN allergyinfo a USING(ingredient_id)
                                    WHERE ri_all.recipe_id = r.recipe_id
                                      AND a.user_id = ?)\n";
        $push($params, $userId);
    }

    /* ─────────── 8) category ─────────── */
    if ($catId !== null) {
        $sql .= "  AND EXISTS (SELECT 1
                                 FROM category_recipe cr
                                WHERE cr.recipe_id  = r.recipe_id
                                  AND cr.category_id = ?)\n";
        $push($params, $catId);
    }

    /* ─────────── 9) SORT / PAGING ─────────── */
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

    /* ─────────── 10) ASSERT PLACEHOLDER ─────────── */
    $phCnt = substr_count($sql, '?');
    if ($phCnt !== count($params)) {
        error_log("Placeholder=$phCnt Params=" . count($params));
        throw new RuntimeException('Parameter count mismatch (internal)');
    }

    /* ─────────── 11) EXECUTE & OUTPUT ─────────── */
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
        ];
    }, $rows);

    jsonOutput([
        'success' => true,
        'page'    => $page,
        'data'    => $data,
    ]);
} catch (Throwable $e) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    error_log('[search_recipes_unified] ' . $e->getMessage());
    jsonError('เกิดข้อผิดพลาดภายในเซิร์ฟเวอร์', 500);
}
