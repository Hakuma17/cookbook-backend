<?php
// post_rating.php — เก็บ/แก้ไขคะแนนดาวเฉย ๆ (ไม่บังคับ comment)

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$uid      = requireLogin();
$recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
$rating   = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_FLOAT) ?: 0;

if ($recipeId <= 0 || $rating < 1 || $rating > 5) {
    jsonOutput(['success' => false, 'message' => 'ข้อมูลไม่ครบหรือคะแนนผิด'], 400);
}

try {
    $exists = dbVal("SELECT 1 FROM review WHERE recipe_id = ? AND user_id = ?", [$recipeId, $uid]);

    if ($exists) {
        dbExec("
            UPDATE review
               SET rating = ?, created_at = NOW()
             WHERE recipe_id = ? AND user_id = ?
        ", [$rating, $recipeId, $uid]);
    } else {
        dbExec("
            INSERT INTO review (recipe_id, user_id, rating, comment, created_at)
            VALUES (?, ?, ?, '', NOW())
        ", [$recipeId, $uid, $rating]);
    }

    $row = dbOne("
        SELECT AVG(rating) AS avg, COUNT(*) AS cnt
        FROM review WHERE recipe_id = ?
    ", [$recipeId]);

    dbExec("
        UPDATE recipe SET average_rating = ?, nReviewer = ? WHERE recipe_id = ?
    ", [round($row['avg'] ?? 0, 2), (int)($row['cnt'] ?? 0), $recipeId]);

    jsonOutput([
        'success'        => true,
        'average_rating' => round($row['avg'] ?? 0, 2),
        'review_count'   => (int)($row['cnt'] ?? 0),
        'user_rating'    => $rating,
    ]);

} catch (Throwable $e) {
    error_log('[post_rating] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
