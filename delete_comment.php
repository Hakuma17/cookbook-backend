<?php
// delete_comment.php — ผู้ใช้ลบรีวิวของตนเอง

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$userId   = requireLogin();
$recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
if ($recipeId <= 0) {
    jsonOutput(['success' => false, 'message' => 'recipe_id ไม่ถูกต้อง'], 400);
}

try {
    /* 1) ลบรีวิว */
    dbExec('DELETE FROM review WHERE recipe_id = ? AND user_id = ?', [$recipeId, $userId]);

    /* 2) คำนวณ rating ใหม่ */
    $row = dbOne('SELECT AVG(rating) AS avg, COUNT(*) AS cnt FROM review WHERE recipe_id = ?', [$recipeId]);

    $avg   = round((float)($row['avg'] ?? 0), 2);
    $count = (int)   ($row['cnt'] ?? 0);

    dbExec('UPDATE recipe SET average_rating = ?, nReviewer = ? WHERE recipe_id = ?', [$avg, $count, $recipeId]);

    jsonOutput(['success' => true, 'data' => [
        'average_rating' => $avg,
        'review_count'   => $count
    ]]);

} catch (Throwable $e) {
    error_log('[delete_comment] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
