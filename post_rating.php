<?php
/**
 * post_rating.php — ให้/แก้ไขคะแนนดาว (ไม่บังคับ comment) ต่อ recipe
 * =====================================================================================
 * METHOD: POST
 * PARAMS:
 *   recipe_id (int > 0)
 *   rating   (float 1..5)
 * DIFFERENCE vs post_comment.php:
 *   - Endpoint เบากว่า: ไม่รับ comment → ใช้สำหรับ UI ที่ให้กดดาวอย่างรวดเร็ว
 *   - ถ้าจะเพิ่มข้อความ แนะนำให้เรียก post_comment.php
 * LOGIC:
 *   1) ตรวจสอบอินพุต + requireLogin
 *   2) มีรีวิวเดิมหรือยัง? (SELECT EXISTS)
 *   3) UPDATE rating + refresh created_at หรือ INSERT ใหม่
 *   4) Aggregate (AVG, COUNT) แล้วอัปเดต recipe
 *   5) ส่งค่า aggregate + user_rating กลับ
 * SECURITY: prepared statements, session based user
 * PERFORMANCE: สูงสุด 3 queries → ใช้ได้
 * EDGE CASES: คะแนนเดิมซ้ำ → ยังอัปเดตเวลา (อาจใช้เป็น last active) ถ้าไม่ต้องการให้เปลี่ยนเวลา สามารถเพิ่มเงื่อนไขเปรียบเทียบได้
 * TODO:
 *   - กัน spam ด้วย flood guard เหมือน post_comment (ถ้าจำเป็น)
 * =====================================================================================
 */

require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { // จำกัดเฉพาะ POST
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$uid      = requireLogin(); // ผู้ใช้ต้องล็อกอิน
$recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
$rating   = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_FLOAT) ?: 0;

// ตรวจสอบช่วงคะแนน + recipeId
if ($recipeId <= 0 || $rating < 1 || $rating > 5) {
    jsonOutput(['success' => false, 'message' => 'ข้อมูลไม่ครบหรือคะแนนผิด'], 400);
}

try {
    // (1) มีอยู่เดิมหรือไม่? ใช้ EXISTS เชิงตรรกะ (dbVal คืน 1 หรือ null)
    $exists = dbVal("SELECT 1 FROM review WHERE recipe_id = ? AND user_id = ?", [$recipeId, $uid]);

    if ($exists) {
        // (2a) UPDATE เฉพาะ rating (comment เว้นเป็นค่าว่างเดิม) + ปรับ created_at เพื่อสื่อว่า "เพิ่งให้คะแนน"
        dbExec("
            UPDATE review
               SET rating = ?, created_at = NOW()
             WHERE recipe_id = ? AND user_id = ?
        ", [$rating, $recipeId, $uid]);
    } else {
        // (2b) INSERT ใหม่ (comment ว่างเสมอใน endpoint นี้)
        dbExec("
            INSERT INTO review (recipe_id, user_id, rating, comment, created_at)
            VALUES (?, ?, ?, '', NOW())
        ", [$recipeId, $uid, $rating]);
    }

    // (3) Aggregate ค่าเฉลี่ย + จำนวนผู้รีวิว
    $row = dbOne("
        SELECT AVG(rating) AS avg, COUNT(*) AS cnt
        FROM review WHERE recipe_id = ?
    ", [$recipeId]);

    // (4) อัปเดต cache ในตาราง recipe เพื่อลดการคำนวณซ้ำใน endpoint อื่น
    dbExec("
        UPDATE recipe SET average_rating = ?, nReviewer = ? WHERE recipe_id = ?
    ", [round($row['avg'] ?? 0, 2), (int)($row['cnt'] ?? 0), $recipeId]);

    // (5) ตอบกลับ FE
    jsonOutput([
        'success'        => true,
        'average_rating' => round($row['avg'] ?? 0, 2),
        'review_count'   => (int)($row['cnt'] ?? 0),
        'user_rating'    => $rating,
    ]);

} catch (Throwable $e) {
    error_log('[post_rating] ' . $e->getMessage()); // logging ภายใน
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
