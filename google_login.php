<?php
// google_login.php — Sign in/Sign up ด้วย Google OAuth

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/db.php'; // เพิ่ม helper
require_once __DIR__ . '/vendor/autoload.php';

use Google\Client as Google_Client;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['message' => 'Method not allowed'], 405);
}

$idToken = trim($_POST['id_token'] ?? '');
if ($idToken === '') {
    respond(false, ['message' => 'ID token required'], 400);
}

try {
    /* ──────────────────── 1) ตรวจสอบ Token ──────────────────── */
    $client  = new Google_Client(['client_id' => GOOGLE_CLIENT_ID]);
    $payload = $client->verifyIdToken($idToken);
    if (!$payload) {
        respond(false, ['message' => 'Invalid ID token'], 401);
    }

    $googleId = $payload['sub'];
    $email    = $payload['email'];
    $name     = $payload['name'];
    $picture  = $payload['picture'] ?? '';

    $pdo = pdo(); // ใช้เฉพาะตรง lastInsertId()

    /* ──────────────────── 2) มี google_id นี้แล้วหรือยัง ──────────────────── */
    $row = dbOne("SELECT user_id FROM user WHERE google_id = ?", [$googleId]);

    if ($row) {
        $uid = (int) $row['user_id'];

        dbExec("
            UPDATE user
            SET profile_name = ?, path_imgProfile = ?
            WHERE user_id = ?
        ", [$name, $picture, $uid]);

    } else {
        /* ──────────────────── 2.1) email นี้มีผู้ใช้ปกติแล้วหรือไม่ ──────────────────── */
        $row = dbOne("SELECT user_id FROM user WHERE email = ?", [$email]);

        if ($row) {
            $uid = (int) $row['user_id'];

            dbExec("
                UPDATE user
                SET google_id = ?, profile_name = ?, path_imgProfile = ?
                WHERE user_id = ?
            ", [$googleId, $name, $picture, $uid]);

        } else {
            /* ──────────────────── 2.2) ลงทะเบียนใหม่ ──────────────────── */
            dbExec("
                INSERT INTO user(email, password, google_id, profile_name, path_imgProfile, created_at)
                VALUES(?, NULL, ?, ?, ?, NOW())
            ", [$email, $googleId, $name, $picture]);

            $uid = (int) $pdo->lastInsertId(); // ต้องใช้ PDO ตรงนี้
        }
    }

    /* ──────────────────── 3) ตั้งค่า Session ──────────────────── */
    $_SESSION['user_id'] = $uid;

    $imgUrl = preg_match('#^https?://#', $picture)
        ? $picture
        : getBaseUrl() . '/' . ltrim($picture, '/');

    respond(true, [
        'user_id'         => $uid,
        'email'           => $email,
        'profile_name'    => $name,
        'path_imgProfile' => $imgUrl,
    ]);

} catch (Throwable $e) {
    error_log('[google_login] ' . $e->getMessage());
    respond(false, ['message' => 'Server error'], 500);
}
