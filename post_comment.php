<?php
// post_comment.php — สร้างหรืออัปเดตรีวิว (1 รีวิวต่อคนต่อสูตร)
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
session_start();

$userId   = getLoggedInUserId();
$recipeId = intval($_POST['recipe_id'] ?? 0);
$text     = htmlspecialchars(trim($_POST['comment'] ?? ''), ENT_QUOTES, 'UTF-8');
$rating   = floatval($_POST['rating'] ?? 0);

// ✅ ตรวจสอบการล็อกอิน
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'ต้องล็อกอินก่อน'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ✅ ตรวจสอบว่า recipe_id ถูกต้อง
if ($recipeId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบ'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ✅ ตรวจสอบว่าให้คะแนนอยู่ในช่วงที่กำหนด
if ($rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ให้คะแนนได้เฉพาะ 1 ถึง 5 ดาว'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ป้องกัน flood: ตรวจว่าเพิ่งโพสต์ไปใน 5 วินาทีไหม
$floodCheck = $pdo->prepare("
    SELECT created_at
      FROM review
     WHERE recipe_id = ? AND user_id = ?
     ORDER BY created_at DESC
     LIMIT 1
");
$floodCheck->execute([$recipeId, $userId]);
$last = $floodCheck->fetchColumn();

if ($last && strtotime($last) >= time() - 5 ) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'กรุณารอสักครู่ก่อนแสดงความคิดเห็นอีกครั้ง'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    //เช็กว่ามีรีวิวอยู่แล้วหรือไม่
    $chk = $pdo->prepare("SELECT 1 FROM review WHERE recipe_id = ? AND user_id = ?");
    $chk->execute([$recipeId, $userId]);
    if ($chk->fetch()) {
        //ถ้ามีแล้ว → UPDATE
        $upd = $pdo->prepare("
            UPDATE review
               SET rating = ?, comment = ?, created_at = NOW()
             WHERE recipe_id = ? AND user_id = ?
        ");
        $upd->execute([$rating, $text, $recipeId, $userId]);
    } else {
        // ยังไม่เคยรีวิว → INSERT
        $ins = $pdo->prepare("
            INSERT INTO review (recipe_id, user_id, rating, comment, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $ins->execute([$recipeId, $userId, $rating, $text]);
    }

    // รีคำนวณ average_rating และจำนวนรีวิวใน recipe
    $avgStmt = $pdo->prepare("
        SELECT AVG(rating)    AS avg_rating,
               COUNT(*)       AS count_rating
          FROM review
         WHERE recipe_id = ?
    ");
    $avgStmt->execute([$recipeId]);
    $avgRow = $avgStmt->fetch(PDO::FETCH_ASSOC);
    $avg   = floatval($avgRow['avg_rating']   ?? 0);
    $count = intval($avgRow['count_rating']  ?? 0);

    $updRec = $pdo->prepare("
        UPDATE recipe
           SET average_rating = ?, nReviewer = ?
         WHERE recipe_id = ?
    ");
    $updRec->execute([round($avg, 2), $count, $recipeId]);

    //ดึงรีวิวล่าสุดของผู้ใช้กับสูตรนั้นกลับไป
    $sql = "
        SELECT
          r.user_id,
          u.profile_name    AS user_name,
          u.path_imgProfile AS avatar_url,
          r.rating,
          r.comment,
          r.created_at
        FROM review r
        JOIN user u ON u.user_id = r.user_id
        WHERE r.recipe_id = ? AND r.user_id = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$recipeId, $userId]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
      'success' => true,
      'data'    => $c ?: (object)[]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
      'success' => false,
      'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
