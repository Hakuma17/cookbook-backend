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
