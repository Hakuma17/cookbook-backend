<?php
/**
 * post_comment.php — สร้างหรือแก้ไขรีวิว (rating + comment) ต่อสูตร (ผู้ใช้ 1 คน 1 รีวิวต่อ 1 recipe)
 * =====================================================================================
 * METHOD: POST
 * PARAMS:
 *   - recipe_id (int > 0)
 *   - rating   (float 1..5)
 *   - comment  (string อนุญาตว่าง)
 * LOGIC:
 *   1) ตรวจว่าผู้ใช้เคยรีวิวหรือยัง (SELECT review)
 *   2) ถ้าเคยและ rating/comment ไม่เปลี่ยน → คืน success + unchanged=true (idempotent)
 *   3) ถ้าเปลี่ยนจริง: Flood guard (>= 5s จาก created_at เดิม) → ป้องกัน spam
 *   4) UPDATE หรือ INSERT ตามสถานะ
 *   5) Recalculate aggregate (AVG / COUNT) แล้วอัปเดตตาราง recipe
 *   6) คืนรีวิวล่าสุด (รวม avatar)
 * FLOOD GUARD: ใช้ timestamp created_at (อัปเดตเมื่อแก้) → หากแก้ภายใน 5 วินาที block (429)
 * SECURITY: requireLogin, prepared statements, sanitizing comment
 * PERFORMANCE: 3-4 query ต่อรอบ (select existing, write, aggregate, fetch latest) รับได้เพราะไม่ถี่
 * TODO:
 *   - พิจารณาเก็บ updated_at แยกจาก created_at เพื่อแสดงเวลาเริ่มโพสต์จริง
 *   - เพิ่ม soft delete / history version ถ้าต้อง audit
 * =====================================================================================
 */

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
$comment  = sanitize($_POST['comment'] ?? '');      // สามารถเป็นค่าว่าง: ใช้เฉพาะ rating
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
    ", [$recipeId, $uid]); // รีวิวเดิม (ถ้ามี)
    

    if ($review) {
        // เทียบว่า "มีการเปลี่ยนจริง" หรือไม่
        $sameRating  = (float)$review['rating'] === (float)$rating;
        $sameComment = trim((string)$review['comment']) === $comment;
    $unchanged   = $sameRating && $sameComment; // ทั้งคะแนนและข้อความไม่เปลี่ยน

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
            ", [$recipeId, $uid]) ?: (object)[]; // ดึงข้อมูลเต็มสำหรับ client sync
    
            jsonOutput(['success' => true, 'data' => $c, 'unchanged' => true]);
        }

        /* ──────────── Flood Guard (≤ 5 วินาที เฉพาะกรณีมีการแก้จริง) ──────────── */
    if (strtotime($review['created_at']) >= time() - 5) { // ยังไม่ถึง 5s → block
            jsonOutput(['success' => false, 'message' => 'รอสักครู่ก่อนแก้ไขรีวิวอีกครั้ง'], 429);
        }

    // UPDATE เฉพาะเมื่อมีการเปลี่ยน (ผ่าน flood guard แล้ว)
        dbExec("
            UPDATE review
               SET rating = ?, comment = ?, created_at = NOW()
             WHERE recipe_id = ? AND user_id = ?
        ", [$rating, $comment, $recipeId, $uid]);

    } else {
    // INSERT เมื่อไม่เคยมีรีวิว
        dbExec("
            INSERT INTO review (recipe_id, user_id, rating, comment, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ", [$recipeId, $uid, $rating, $comment]);
    }

    /* ──────────── Recalculate Recipe Stats ──────────── */
    $row = dbOne("
        SELECT AVG(rating) AS avg, COUNT(*) AS cnt
          FROM review WHERE recipe_id = ?
    ", [$recipeId]); // aggregate หลังเขียน
    

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
