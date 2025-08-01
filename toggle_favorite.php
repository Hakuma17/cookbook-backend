<?php
// toggle_favorite.php — กดถูกใจ / เลิกถูกใจเมนู (idempotent + นับยอดล่าสุด)

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/json.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/functions.php';

try {
    // 1) allow only POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOutput(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    // 2) require login
    $uid = requireLogin();

    // 3) inputs
    $recipeId = filter_input(INPUT_POST, 'recipe_id', FILTER_VALIDATE_INT) ?: 0;
    // รองรับ "1"/1/true
    $favRaw   = $_POST['favorite'] ?? null; // ← เดิมเป็น 0; ปรับเป็น null เพื่อให้รองรับโหมด toggle อัตโนมัติเมื่อไม่ได้ส่ง favorite มา
    // โหมดตีความค่า favorite:
    // - ถ้า client ส่งมา → แปลงเป็น boolean ตามเดิม
    // - ถ้า client ไม่ส่งมา → toggle อัตโนมัติจากสถานะปัจจุบันใน DB (ลดโอกาส desync เมื่อผู้ใช้กดซ้ำเร็ว ๆ)
    if ($recipeId <= 0) {
        jsonOutput(['success' => false, 'message' => 'recipe_id ไม่ถูกต้อง'], 400);
    }

    // 4) transaction (อะตอมิก)
    // [FIX] โปรเจ็กต์นี้ไม่มี dbBegin/dbCommit/dbRollback → ใช้ PDO โดยตรง
    /** @var PDO $pdo */
    $pdo = pdo();
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    if ($favRaw === null) {
        // โหมด toggle อัตโนมัติ (client ไม่ได้ส่ง favorite มา)
        $exists = dbVal('SELECT COUNT(*) FROM favorites WHERE user_id = ? AND recipe_id = ?', [$uid, $recipeId]) > 0;
        $fav = !$exists;
    } else {
        // โหมด set ตามที่ client ส่งมา
        $fav = (string)$favRaw === '1' || (int)$favRaw === 1 || $favRaw === true;
    }

    if ($fav) {
        // add if not exists
        dbExec('INSERT IGNORE INTO favorites (user_id, recipe_id) VALUES (?, ?)', [$uid, $recipeId]);
    } else {
        dbExec('DELETE FROM favorites WHERE user_id = ? AND recipe_id = ?', [$uid, $recipeId]);
    }

    // นับยอดล่าสุดจากตาราง favorites
    $cnt = (int) dbVal('SELECT COUNT(*) FROM favorites WHERE recipe_id = ?', [$recipeId]);

    // อัปเดตตาราง recipe (ถ้ามีคอลัมน์ favorite_count)
    // NOTE: ถ้าฐานข้อมูลคุณใช้ชื่อ table อื่น เช่น `recipes` ให้แก้ชื่อให้ตรง
    dbExec('UPDATE recipe SET favorite_count = ? WHERE recipe_id = ?', [$cnt, $recipeId]);

    // (เพิ่มเติม) นับจำนวนเมนูโปรดทั้งหมดของผู้ใช้ เพื่อนำไปอัปเดต badge รวมได้สะดวก
    $userTotal = (int) dbVal('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$uid]);

    // [FIX] commit ด้วย PDO
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    // 5) response (มาตรฐานเดียวกับ endpoint อื่น: ห่อใน data, ใช้ is_favorited)
    jsonOutput([
        'success' => true,
        'data' => [
            'recipe_id'             => $recipeId,
            'is_favorited'          => $fav,
            'favorite_count'        => $cnt,
            'total_user_favorites'  => $userTotal, // (เพิ่มเติม) ยอดรวมของผู้ใช้ปัจจุบัน
        ],
    ]);

} catch (Throwable $e) {
    // [FIX] rollback ด้วย PDO ถ้ายังเปิด transaction อยู่
    try {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $_) {
        // เงียบไว้ ไม่ให้ error กลบของเดิม
    }

    error_log('[toggle_favorite] ' . $e->getMessage());

    // ให้ 401 ถ้าไม่ล็อกอิน
    if (strpos($e->getMessage(), 'LoginRequired') !== false) {
        jsonOutput(['success' => false, 'message' => 'ต้องล็อกอินก่อน'], 401);
    }

    jsonOutput(['success' => false, 'message' => 'Server error'], 500);
}
