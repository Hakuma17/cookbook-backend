<?php

declare(strict_types=1);
require_once __DIR__ . '/inc/db.php';

header('Content-Type: application/json; charset=UTF-8');

/*── helper ────────────*/
function out(bool $ok, array $data = [], string $err = ''): never
{
    echo json_encode(
        ['success' => $ok, 'data' => $data] + ($ok ? [] : ['error' => $err]),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

try {

    /* ★★★ NEW: โหมดแนะนำ “กลุ่มวัตถุดิบ” ผ่าน ?type=group&q=... */
    $type = strtolower(trim($_GET['type'] ?? 'item'));
    if ($type === 'group') {
        // รองรับทั้ง q (ใหม่) และ term (เดิม) เพื่อความเข้ากันได้
        $q = trim($_GET['q'] ?? ($_GET['term'] ?? ''));
        if ($q === '') out(true); // ไม่มีคำค้น → คืน success เปล่าเหมือนเดิม

        $like = '%' . $q . '%';

        // พยายามจำกัด pool ด้วย LIKE ก่อน (ไวขึ้น)
        $rows = dbAll("
            SELECT DISTINCT TRIM(newcatagory) AS g
            FROM ingredients
            WHERE newcatagory IS NOT NULL
              AND TRIM(newcatagory) <> ''
              AND TRIM(newcatagory) COLLATE utf8mb4_general_ci LIKE :kw
            LIMIT 200
        ", [':kw' => $like], PDO::FETCH_COLUMN);

        // ถ้าไม่เจออะไรเลย ให้ fallback เป็น “กลุ่มทั้งหมด”
        if (!$rows) {
            $rows = dbAll("
                SELECT DISTINCT TRIM(newcatagory) AS g
                FROM ingredients
                WHERE newcatagory IS NOT NULL
                  AND TRIM(newcatagory) <> ''
            ", [], PDO::FETCH_COLUMN);
        }

        // จัดอันดับความใกล้เคียงแบบเดียวกับลอจิกเดิม (ใช้ levenshtein)
        $qLower = mb_strtolower($q);
        $ranked = array_map(
            fn($n) => ['name' => $n, 'score' => levenshtein($qLower, mb_strtolower($n))],
            $rows
        );
        usort($ranked, fn($a, $b) => $a['score'] <=> $b['score']);

        // ส่งกลับ 10 อันดับแรก
        $top = array_column(array_slice($ranked, 0, 10), 'name');
        out(true, $top);
    }

    /* ▼▼▼ [OLD/KEEP] โหมดเดิม: แนะนำจาก recipe_ingredient.descrip ▼▼▼ */

    /* 1)  รับ term */
    $term = trim($_GET['term'] ?? '');
    if ($term === '') out(true);

    $like = '%' . $term . '%';

    /* 2)  query descrip */
    $sql = "
        SELECT DISTINCT descrip
        FROM recipe_ingredient
        WHERE descrip <> ''
          AND descrip COLLATE utf8mb4_general_ci LIKE :kw
        LIMIT 100
    ";
    $rows = dbAll($sql, [':kw' => $like], PDO::FETCH_COLUMN);
    if (!$rows) out(true);

    /* 3)  คำนวณคะแนน levenshtein */
    $q = mb_strtolower($term);
    $ranked = array_map(
        fn($n) => ['name' => $n, 'score' => levenshtein($q, mb_strtolower($n))],
        $rows
    );
    usort($ranked, fn($a, $b) => $a['score'] <=> $b['score']);

    /* 4)  ส่งกลับ 10 อันดับแรก */
    $top = array_column(array_slice($ranked, 0, 10), 'name');
    out(true, $top);

} catch (Throwable $e) {
    error_log('[ingredient_suggest] ' . $e->getMessage());
    out(false, [], 'Server error');
}
