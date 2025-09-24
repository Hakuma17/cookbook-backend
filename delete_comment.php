<?php
/**
 * delete_comment.php — ผู้ใช้ลบรีวิว (rating+comment) ของตนเองออกจาก recipe
 * =====================================================================================
 * METHOD: POST
 * PARAMS (POST):
 *   recipe_id (int > 0)
 * STEPS:
 *   1) requireLogin → ได้ user_id
 *   2) DELETE review (scoped ด้วย recipe_id + user_id)
 *   3) Recalculate aggregate (AVG, COUNT) ของ recipe ที่เหลือ
 *   4) UPDATE recipe.average_rating, recipe.nReviewer
 *   5) ส่งค่า aggregate ใหม่กลับให้ FE sync
 * SECURITY:
 *   - ผู้ใช้ลบได้เฉพาะของตนเอง (WHERE user_id = current)
 *   - ไม่เปิดเผยรายละเอียด DB error ต่อ client (log ภายใน)
 * PERFORMANCE: 2 write (DELETE + UPDATE) + 1 read aggregate — รับได้
 * EDGE CASES:
 *   - ไม่มีรีวิว (DELETE 0 rows) → aggregate จะเป็น 0,0 หลังคำนวณ
 *   - ลบอีกรอบซ้ำ → ผลเหมือนเดิม (idempotent-ish)
 * TODO:
 *   - Soft delete + เก็บ history หากต้อง audit
 * =====================================================================================
 */

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { // จำกัดเฉพาะ POST
    jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
}

$userId   = requireLogin(); // ต้องล็อกอินเสมอ
$recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
if ($recipeId <= 0) {
    jsonOutput(['success' => false, 'message' => 'recipe_id ไม่ถูกต้อง'], 400);
}

try {
    // (1) ลบรีวิวของผู้ใช้ (ถ้าไม่มีจะไม่มีผลกระทบ — ทำให้ idempotent)
    dbExec('DELETE FROM review WHERE recipe_id = ? AND user_id = ?', [$recipeId, $userId]);

    // (2) Aggregate ใหม่หลังลบ
    $row = dbOne('SELECT AVG(rating) AS avg, COUNT(*) AS cnt FROM review WHERE recipe_id = ?', [$recipeId]);
    $avg   = round((float)($row['avg'] ?? 0), 2);
    $count = (int)   ($row['cnt'] ?? 0);

    // (3) อัปเดตตาราง recipe เพื่อให้ endpoint อื่นใช้ค่าล่าสุด
    dbExec('UPDATE recipe SET average_rating = ?, nReviewer = ? WHERE recipe_id = ?', [$avg, $count, $recipeId]);

    // (4) ส่งค่าใหม่กลับ
    jsonOutput(['success' => true, 'data' => [
        'average_rating' => $avg,
        'review_count'   => $count
    ]]);

} catch (Throwable $e) {
    error_log('[delete_comment] ' . $e->getMessage()); // log ภายใน (สามารถเพิ่ม context เพิ่มเติมได้ภายหลัง)
    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
