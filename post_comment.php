<?php
// post_comment.php — ผู้ใช้ 1 คนรีวิวได้ 1 ครั้งต่อเมนู (UPDATE แทน INSERT ถ้ามีแล้ว)
//
// 2025-08-16 – ★ Idempotent edit:
//   - ถ้ารีวิวเดิมมีอยู่และ rating/comment "ไม่เปลี่ยน" → คืน success ทันที (ไม่อัปเดต/ไม่โดน Flood Guard)
//   - Flood Guard 5s ใช้เฉพาะตอน "มีการเปลี่ยนจริง" เท่านั้น

/* ──────────── Dependencies ──────────── */
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

/* ──────────── Method Check ──────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

/* ──────────── Input Retrieval ──────────── */
$uid      = requireLogin();
$recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
$comment  = sanitize($_POST['comment'] ?? '');      // ขอ comment ได้เป็นค่าว่าง
$rating   = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_FLOAT) ?: 0;

/* ──────────── Validation ──────────── */
// ตรวจเฉพาะ recipe_id เท่านั้น (ไม่บังคับ comment)
if ($recipeId <= 0) {
    jsonOutput(['success' => false, 'message' => 'ต้องระบุ recipe_id'], 400);
}
// rating ต้องอยู่ระหว่าง 1–5
if ($rating < 1 || $rating > 5) {
    jsonOutput(['success' => false, 'message' => 'ให้คะแนน 1-5 ดาวเท่านั้น'], 400);
}

try {
    /* ──────────── Load existing review ──────────── */
    $review = dbOne("
        SELECT rating, comment, created_at
          FROM review
         WHERE recipe_id = ? AND user_id = ?
         LIMIT 1
    ", [$recipeId, $uid]);

    if ($review) {
        // เทียบว่า "มีการเปลี่ยนจริง" หรือไม่
        $sameRating  = (float)$review['rating'] === (float)$rating;
        $sameComment = trim((string)$review['comment']) === $comment;
        $unchanged   = $sameRating && $sameComment;

        if ($unchanged) {
            // ★ ไม่เปลี่ยนอะไรเลย → คืนข้อมูลเดิมเป็น success ทันที (idempotent)
            $c = dbOne("
                SELECT  r.user_id,
                        u.profile_name   AS user_name,
                        u.path_imgProfile AS avatar_url,
                        r.rating,
                        r.comment,
                        r.created_at
                  FROM review r
                  JOIN user u ON u.user_id = r.user_id
                 WHERE r.recipe_id = ? AND r.user_id = ?
                 LIMIT 1
            ", [$recipeId, $uid]) ?: (object)[];
            jsonOutput(['success' => true, 'data' => $c, 'unchanged' => true]);
        }

        /* ──────────── Flood Guard (≤ 5 วินาที เฉพาะกรณีมีการแก้จริง) ──────────── */
        if (strtotime($review['created_at']) >= time() - 5) {
            jsonOutput(['success' => false, 'message' => 'รอสักครู่ก่อนแก้ไขรีวิวอีกครั้ง'], 429);
        }

        // UPDATE เมื่อมีการเปลี่ยนจริง
        dbExec("
            UPDATE review
               SET rating = ?, comment = ?, created_at = NOW()
             WHERE recipe_id = ? AND user_id = ?
        ", [$rating, $comment, $recipeId, $uid]);

    } else {
        // INSERT ถ้าไม่มีรีวิวเดิม
        dbExec("
            INSERT INTO review (recipe_id, user_id, rating, comment, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ", [$recipeId, $uid, $rating, $comment]);
    }

    /* ──────────── Recalculate Recipe Stats ──────────── */
    $row = dbOne("
        SELECT AVG(rating) AS avg, COUNT(*) AS cnt
          FROM review WHERE recipe_id = ?
    ", [$recipeId]);

    dbExec("
        UPDATE recipe 
           SET average_rating = ?, nReviewer = ?
         WHERE recipe_id = ?
    ", [round($row['avg'] ?? 0, 2), (int)($row['cnt'] ?? 0), $recipeId]);

    /* ──────────── Fetch Latest Review ──────────── */
    $c = dbOne("
        SELECT  r.user_id,
                u.profile_name   AS user_name,
                u.path_imgProfile AS avatar_url,
                r.rating,
                r.comment,
                r.created_at
          FROM review r
          JOIN user u ON u.user_id = r.user_id
         WHERE r.recipe_id = ? AND r.user_id = ?
         LIMIT 1
    ", [$recipeId, $uid]) ?: (object)[];

    /* ──────────── Output JSON ──────────── */
    jsonOutput(['success' => true, 'data' => $c]);

} catch (Throwable $e) {
    error_log('[post_comment] ' . $e->getMessage());
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
