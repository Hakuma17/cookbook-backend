<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $term = trim($_GET['term'] ?? '');
    if ($term === '') {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $like = "%{$term}%";

    // ─── 1) ดึงคำเบื้องต้นจาก 3 แหล่ง ───
    $sql1 = "
        SELECT DISTINCT display_name AS name FROM ingredients
        WHERE display_name LIKE :t1 OR searchable_keywords LIKE :t2
    ";
    $names1 = dbAll($sql1, [':t1' => $like, ':t2' => $like], PDO::FETCH_COLUMN);

    $sql2 = "
        SELECT DISTINCT descrip AS name FROM recipe_ingredient
        WHERE descrip IS NOT NULL AND descrip <> '' AND descrip LIKE :t3
    ";
    $names2 = dbAll($sql2, [':t3' => $like], PDO::FETCH_COLUMN);

    $sql3 = "
        SELECT DISTINCT descrip AS name FROM ingredients
        WHERE descrip IS NOT NULL AND descrip <> '' AND descrip LIKE :t4
    ";
    $names3 = dbAll($sql3, [':t4' => $like], PDO::FETCH_COLUMN);

    // ─── 2) รวมทั้งหมด ───
    $allRaw = array_unique(array_merge($names1, $names2, $names3));

    // ─── 3) จัดอันดับความคล้ายด้วย levenshtein ───
    $ranked = [];
    foreach ($allRaw as $name) {
        $score = levenshtein(mb_strtolower($term), mb_strtolower($name));
        $ranked[] = ['name' => $name, 'score' => $score];
    }

    // ─── 4) เรียงจากใกล้ที่สุด → ไกลที่สุด ───
    usort($ranked, fn($a, $b) => $a['score'] <=> $b['score']);

    // ─── 5) ตัดเหลือ 10 อันดับแรก ───
    $topNames = array_column(array_slice($ranked, 0, 10), 'name');

    echo json_encode(['success' => true, 'data' => $topNames]);
} catch (Throwable $e) {
    error_log("suggestions error: " . $e->getMessage());
    echo json_encode(['success' => false, 'data' => [], 'error' => 'Server error']);
}
