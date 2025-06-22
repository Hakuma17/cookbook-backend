<?php
// post_comment.php — ผู้ใช้ 1 คนรีวิวได้ 1 ครั้งต่อเมนู (UPDATE แทน INSERT ถ้ามีแล้ว)

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$uid      = requireLogin();
$recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
$comment  = sanitize($_POST['comment'] ?? '');
$rating   = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_FLOAT) ?: 0;

if ($recipeId <= 0 || $comment === '') {
    jsonOutput(['success' => false, 'message' => 'ข้อมูลไม่ครบ'], 400);
}
if ($rating < 1 || $rating > 5) {
    jsonOutput(['success' => false, 'message' => 'ให้คะแนน 1-5 ดาวเท่านั้น'], 400);
}

/* flood-guard ≤ 5 วินาที */
$createdAt = dbVal("
    SELECT created_at FROM review
    WHERE recipe_id = ? AND user_id = ?
    ORDER BY created_at DESC LIMIT 1
", [$recipeId, $uid]);

if ($createdAt && strtotime($createdAt) >= time() - 5) {
    jsonOutput(['success' => false, 'message' => 'รอสักครู่ก่อนคอมเมนต์อีกครั้ง'], 429);
}

try {
    /* upsert รีวิว */
    $exists = dbVal("SELECT 1 FROM review WHERE recipe_id = ? AND user_id = ?", [$recipeId, $uid]);

    if ($exists) {
        dbExec("
            UPDATE review
               SET rating = ?, comment = ?, created_at = NOW()
             WHERE recipe_id = ? AND user_id = ?
        ", [$rating, $comment, $recipeId, $uid]);
    } else {
        dbExec("
            INSERT INTO review (recipe_id, user_id, rating, comment, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ", [$recipeId, $uid, $rating, $comment]);
    }

    /* คำนวณค่าเฉลี่ย & reviewer */
    $row = dbOne("
        SELECT AVG(rating) AS avg, COUNT(*) AS cnt
        FROM review WHERE recipe_id = ?
    ", [$recipeId]);

    dbExec("
        UPDATE recipe SET average_rating = ?, nReviewer = ? WHERE recipe_id = ?
    ", [round($row['avg'] ?? 0, 2), (int)($row['cnt'] ?? 0), $recipeId]);

    /* รีวิวล่าสุดของผู้ใช้ */
    $c = dbOne("
        SELECT  r.user_id, u.profile_name AS user_name, u.path_imgProfile AS avatar_url,
                r.rating, r.comment, r.created_at
        FROM    review r JOIN user u ON u.user_id = r.user_id
        WHERE   r.recipe_id = ? AND r.user_id = ? LIMIT 1
    ", [$recipeId, $uid]) ?: (object)[];

    jsonOutput(['success' => true, 'data' => $c]);

} catch (Throwable $e) {
    error_log('[post_comment] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
