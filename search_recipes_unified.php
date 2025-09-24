<?php // เปิดแท็ก PHP หลักของไฟล์
/**
 * search_recipes_unified.php — Unified Flexible Recipe Search
 * =====================================================================
 * จุดประสงค์:
 *   - รวม logic การค้นหา/กรอง/จัดอันดับ สูตรอาหาร ภายในไฟล์เดียวแบบ self‑contained
 *   - รองรับการค้นหาจาก “ชื่อ”, “วัตถุดิบ (descrip / name / display_name / keywords)”,
 *     “กลุ่มวัตถุดิบ (newcatagory)”, หมวดหมู่ (category) และ include/exclude เฉพาะรายชื่อ / ID
 *   - ให้คะแนนชื่อ (name_rank) + จำนวนวัตถุดิบที่แมตช์ (ing_match_cnt / ing_rank)
 *   - ส่งข้อมูลเกี่ยวกับสารก่อภูมิแพ้ (has_allergy + รายชื่อกลุ่ม/ชื่อวัตถุดิบที่ผู้ใช้แพ้)
 *   - คืน pagination + total (จำนวนผลลัพธ์ตรงเงื่อนไข) + total_recipes (จำนวนสูตรทั้งหมดในระบบ)
 *
 * โครงสร้าง (High-level Pipeline):
 *   1) อ่านอินพุต / พารามิเตอร์ (q, page, limit, sort, include/exclude, groups, category)
 *   2) สร้าง tokens (ตัดคำหรือ split ธรรมดา) + heuristic เติมวัตถุดิบที่ซ้อนอยู่ในสตริงค้นหา
 *   3) เตรียม placeholder & parameters (อาร์เรย์ $params)
 *   4) ประกอบ SELECT fields (รวม subquery ต่าง ๆ: favorites, reviews, ingredients, allergy)
 *   5) ประกอบ WHERE ตาม: keyword/name, ingredients exists, group include/exclude, ชื่อ/ID รวม-ยกเว้น, category
 *   6) (ต่อเนื่องกับ 5) ใส่เงื่อนไข include/exclude รายชื่อ/รหัสวัตถุดิบ
 *   7) Filter หมวดหมู่ (category)
 *   8) เลือก ORDER BY ตาม sort + ใส่ LIMIT/OFFSET + จัดลำดับ rank
 *   9) ตรวจสอบจำนวน placeholder ตรงกับจำนวนพารามิเตอร์ (safety check)
 *  10) รัน SQL: ดึง total (COUNT ทั้งเซ็ตที่กรอง) + ดึงรายการหน้า (LIMIT/OFFSET) + ส่ง JSON
 *
 * อธิบายคะแนน:
 *   - name_rank: ให้คะแนนสูงสุด 100 ถ้าชื่อเท่ากับคำค้นตรง ๆ, ไล่ลด 90,80,… ตาม pattern (เช่น เหมือนหลังตัดช่องว่าง, LIKE แบบ prefix, ฯลฯ)
 *   - ing_match_cnt: นับจำนวน token ที่แมตช์กับวัตถุดิบของสูตร (EXISTS ต่อ token)
 *   - ing_rank: ถ้า ing_match_cnt ครบทุก token → 2 มิฉะนั้น 1 (ใช้จัด PRIORITY ถัดจากชื่อ)
 *
 * หมายเหตุด้านประสิทธิภาพ & การปรับปรุงในอนาคต:
 *   - ปริมาณ subquery หลายอันใน SELECT อาจมีผลเมื่อข้อมูลโตมาก → พิจารณาเพิ่ม index
 *   - EXISTS + LIKE หลายอัน: ถ้า scale ใหญ่ อาจมองไปที่ Full‑Text Index หรือ Search Engine ภายนอก
 *   - ปัจจุบันใช้ dynamic SQL พร้อม prepared statements → ปลอดภัยจาก SQL injection (ค่าทั้งหมดผ่าน placeholders)
 * =====================================================================
 */

require_once __DIR__ . '/inc/config.php';     // โหลดการตั้งค่าพื้นฐาน (ENV / error mode)
require_once __DIR__ . '/inc/db.php';         // โหลดส่วนเชื่อมต่อฐานข้อมูล / ฟังก์ชัน PDO wrapper
require_once __DIR__ . '/inc/functions.php';  // รวมฟังก์ชันช่วยหลัก (ถ้ามีจะใช้ของโปรเจ็กต์)

header('Content-Type: application/json; charset=UTF-8'); // กำหนด header ให้ตอบ JSON UTF‑8

/* ───────────────────────────── Fallback Helpers ─────────────────────────────
   ถ้าโปรเจ็กต์ของคุณมีฟังก์ชันพวกนี้อยู่แล้วใน inc/functions.php จะไม่ใช้บล็อกนี้
   แต่ถ้าไม่มี (undefined function) บล็อกนี้จะช่วยให้ไฟล์ทำงานได้ทันที
-----------------------------------------------------------------------------*/
if (!function_exists('reqBool')) {                              // ถ้าโปรเจ็กต์หลักยังไม่มีฟังก์ชันนี้
    function reqBool(string $key, bool $default = false): bool { // อ่านพารามิเตอร์แบบ boolean
        $src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET; // เลือกแหล่งข้อมูลตามเมทอด
        if (!array_key_exists($key, $src)) return $default;      // ไม่มีคีย์ → คืนค่า default
        $v = $src[$key];                                        // ค่าดิบ
        if (is_bool($v)) return $v;                             // ถ้าเป็น boolean อยู่แล้วก็ส่งกลับ
        $v = strtolower(trim((string)$v));                      // แปลงเป็นสตริง + ตัดช่องว่าง + to lower
        return in_array($v, ['1','true','yes','on'], true);     // ตรวจรูปแบบที่ถือว่าเป็น true
    }
}
if (!function_exists('defaultSearchTokenize')) {                 // Fallback flag เริ่มต้นว่าต้องตัดคำไหม
    function defaultSearchTokenize(): bool { return false; }     // ค่าเริ่มต้นปิด (ลดโหลด + คุมพฤติกรรม)
}
if (!function_exists('likePattern')) {                           // ฟังก์ชันสร้าง pattern สำหรับ LIKE (มี % ครอบ)
    function likePattern(string $s): string {                    // พร้อม escape อักขระพิเศษ
        $s = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $s); // escape ตัว wildcard
        return '%' . $s . '%';                                   // ครอบด้วย % หน้า-หลัง
    }
}
if (!function_exists('parseSearchTerms')) {                      // แยกคำค้น (fallback แบบง่าย)
    function parseSearchTerms(string $raw, bool $tokenize): array { // $tokenize เผื่ออนาคตเปิดใช้ตัวตัดคำจริง
        $raw = trim($raw);                                       // ตัดช่องว่างหัวท้าย
        if ($raw === '') return [];                              // ว่าง → ไม่มีคำ
        // ถ้าอนาคตเสริม PyThaiNLP ให้แทรก logic ก่อนบรรทัดล่างนี้ได้
        $terms = preg_split('/[,\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY); // แยกด้วย คอมมา หรือ ช่องว่าง
        $terms = array_values(array_unique(array_map('trim', $terms)));   // ตัดซ้ำ + จัด index ใหม่
        return $terms;                                           // คืนอาร์เรย์คำ
    }
}
if (!function_exists('sanitize')) {                              // ฟังก์ชันกัน null + trim อย่างง่าย
    function sanitize(?string $s): string { return trim((string)$s); }
}
if (!function_exists('jsonOutput')) {                           // ส่ง JSON + เลขสถานะ แล้วจบการทำงาน
    function jsonOutput(array $obj, int $code = 200): void {
        http_response_code($code);                              // ตั้ง HTTP status code
        echo json_encode($obj, JSON_UNESCAPED_UNICODE);         // พิมพ์ JSON (ไม่ escape อักษรไทย)
        exit;                                                   // จบสคริปต์
    }
}
if (!function_exists('jsonError')) {                            // ส่ง JSON error มาตรฐาน
    function jsonError(string $msg, int $code = 400): void {
        jsonOutput(['success'=>false, 'message'=>$msg], $code);  // ห่อ message + success=false
    }
}

/* ────────────────────────────────────────────────────────────────────────── */

try {
    /* ───────────────────────── 1) INPUT PARAMETERS ─────────────────────────
       ดึงค่าจาก GET/POST (priority ตาม method) + เตรียม page/limit/sort
       หมายเหตุ: limit capped ที่ 50 เพื่อกัน query หนักเกินจำเป็น
    ------------------------------------------------------------------------*/
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

     /* ───────────────────────── 2) TOKENISE / BUILD TOKENS ─────────────────
         - เปิด/ปิดโหมดตัดคำผ่าน ?tokenize=1 (หรือใช้ default จากระบบ)
         - เติม tokens heuristics: ค้น ingredients ชื่อซ้อนในสตริง (fallback เมื่อมี token เดียว)
         - จำกัดจำนวนสูงสุด 5 token (ควบคุมความซับซ้อนของ SQL)
     ------------------------------------------------------------------------*/
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

     /* ───────────────────────── (Helper) PARAM PUSH ────────────────────────
         $params เป็นลิสต์สำหรับ binding ทุก ? ใน SQL (รักษาลำดับสำคัญมาก)
     ------------------------------------------------------------------------*/
    $params = [];
    $push = static function (array &$params, $val, int $n = 1): void {
        for ($i = 0; $i < $n; $i++) $params[] = $val;
    };

     /* ───────────────────────── 3) BUILD DYNAMIC SELECT FIELDS ─────────────
         ส่วนนี้จะสร้างนิพจน์ $ingSelect สำหรับนับการแมตช์วัตถุดิบต่อ token
         - $cases: รายการ CASE WHEN EXISTS( … ) ต่อ token
         - ing_match_cnt = ผลรวม CASE (ได้ 1 ต่อ token ที่พบ)
         - ing_rank = 2 ถ้าจำนวนที่พบ == จำนวน token ทั้งหมด (ครบทุกคำ) ไม่งั้น 1
         หมายเหตุ: ใช้ LIKE หลายคอลัมน์ (descrip, name, display_name, searchable_keywords)
     ------------------------------------------------------------------------*/
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
        ) AS allergy_names,

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

    // ⬇️ เดิมมีแค่ 1 ครั้งสำหรับ has_allergy → เพิ่มอีก 2 ครั้งให้ตรง allergy_groups / allergy_names
    $push($params, $userId); // has_allergy
    $push($params, $userId); // allergy_groups
    $push($params, $userId); // allergy_names

    $push($params, $rawQ);
    $push($params, $qNoSpace);
    $push($params, "{$rawQ}%");
    $push($params, "%{$qNoSpace}%");
    $push($params, "%{$qNoSpace}%");

    /* ───────────────────────── 4) BASE SQL & INITIAL WHERE ─────────────── */
    $sql = "SELECT\n  $recipeFields\nFROM recipe r\nWHERE 1=1\n";

     /* ───────────────────────── 5) NAME / INGREDIENT CONDITIONS ────────────
         - nameConds: OR ชุดใหญ่ (เทียบตรง, ตัดช่องว่าง, LIKE หลากหลายรูปแบบ, AND tokens)
         - ingConds:  AND EXISTS ต่อ token ครบทุก token (AND chain)
         การประกอบสุดท้าย: ( (nameConds combined with OR) OR (AND chain of ingConds) )
     ------------------------------------------------------------------------*/
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

    /* 5.3 กลุ่มวัตถุดิบ (group/include_groups/exclude_groups) */
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

     /* ───────────────────────── 6) INCLUDE / EXCLUDE BY ID / NAME ──────────
         - includeIds   : สูตรต้องมี ingredient ใดก็ได้ในชุด (ใช้ EXISTS + IN list)
         - includeNames : สร้าง EXISTS หลายก้อน (AND ทั้งหมด) เพื่อต้องแมตช์ทุกชื่อ
         - excludeIds   : NOT EXISTS (IN list) → ต้องไม่มีสักตัว
         - excludeNames : NOT EXISTS หลายก้อน (AND) → ต้องไม่แมตช์ทุกชื่อที่ระบุ
     ------------------------------------------------------------------------*/
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

    /* ───────────────────────── 7) CATEGORY FILTER ───────────────────────── */
    if ($catId !== null) {
        $sql .= "  AND EXISTS (
          SELECT 1 FROM category_recipe cr
          WHERE cr.recipe_id = r.recipe_id
            AND cr.category_id = ?
        )\n";
        $push($params, $catId);
    }

        /* ───────────────────────── 8) SORT + PAGING ───────────────────────────
             สร้าง $sqlNoPaging (ฉบับยังไม่ ORDER BY/LIMIT) → ใช้นับ total (ข้อ 10)
             ORDER PRIORITY:
                 1) name_rank (ให้ชื่อที่ตรงสุดมาก่อน)
                 2) ฐานตาม sort (เช่น created_at, favorite_count ฯลฯ)
                 3) ing_rank (สูตรที่ครอบคลุม token ทั้งหมดมาก่อน)
                 4) ing_match_cnt (สูตรที่แมตช์หลาย token กว่า)
                 5) recipe_id DESC (tie-breaker)
        ------------------------------------------------------------------------*/
    // เก็บ SQL ก่อนใส่ ORDER BY/LIMIT เอาไว้ใช้คำนวนจำนวนผลลัพธ์ทั้งหมดของเงื่อนไขปัจจุบัน
    $sqlNoPaging = $sql;
    $orderBy = match ($sort) {
        'name_asc'    => 'r.name ASC',
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

     /* ───────────────────────── 9) PLACEHOLDER COUNT SAFETY ────────────────
         ป้องกัน programmer error: ถ้า ? ใน SQL ไม่เท่ากับจำนวน $params → throw
     ------------------------------------------------------------------------*/
    $phCnt = substr_count($sql, '?');
    if ($phCnt !== count($params)) {
        error_log("[search_recipes_unified] Placeholder=$phCnt Params=".count($params));
        throw new RuntimeException('Parameter count mismatch (internal)');
    }

     /* ───────────────────────── 10) EXECUTE & BUILD JSON ──────────────────
         - total: COUNT ทั้งหมดของเซ็ตที่กรอง (ใช้ subquery ครอบ SQL เดิมก่อน paging)
         - rows : รายการหน้าปัจจุบัน (LIMIT/OFFSET)
         - total_recipes: จำนวนสูตรในระบบทั้งหมด (ไม่สนเงื่อนไขค้นหา) ให้ FE ทำสถิติ/เปรียบเทียบ
         has_next = (page * limit) < total
     ------------------------------------------------------------------------*/
    $total = (int)dbVal("SELECT COUNT(*) FROM ( $sqlNoPaging ) AS _t", $params);

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

            /* ★★★ [NEW] ส่งชื่อกลุ่ม/ชื่อที่ใช้ขึ้นชิป */
            'allergy_groups'    => array_values(array_filter(array_map('trim',
                                        explode(',', (string)($r['allergy_groups'] ?? ''))))),
            'allergy_names'     => array_values(array_filter(array_map('trim',
                                        explode(',', (string)($r['allergy_names'] ?? ''))))),
        ];
    }, $rows);

    // รวมจำนวนสูตรทั้งหมดในฐานข้อมูล (เพื่อให้ FE ทราบจำนวนรวมทั้งหมด)
    $totalRecipes = (int)dbVal('SELECT COUNT(*) FROM recipe');

    jsonOutput([
        'success' => true,
        'page'    => $page,
        'tokens'  => $tokens,
        'total'   => $total,
        'limit'   => $limit,
        'has_next'=> ($page * $limit < $total),
        'total_recipes' => $totalRecipes,
        'count'   => count($data),
        'data'    => $data,
    ]);
}
catch (Throwable $e) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    error_log('[search_recipes_unified] ' . $e->getMessage());
    jsonError('เกิดข้อผิดพลาดภายในเซิร์ฟเวอร์', 500);
}
