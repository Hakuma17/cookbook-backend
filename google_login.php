<?php
// google_login.php
// ใช้ตรวจสอบ Google ID token และจัดการข้อมูลผู้ใช้ (login / register)

ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');
session_start();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/inc/config.php';

use Google\Client as Google_Client;
use Firebase\JWT\JWT;
JWT::$leeway = 300;

/// ตอบกลับเป็น JSON โดยห่อใน data
function respond(bool $ok, array $data = [], int $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'สำเร็จ' : 'เกิดข้อผิดพลาด',
        'data' => $data
    ]);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, ['message' => 'Method Not Allowed'], 405);
    }

    $idToken = $_POST['id_token'] ?? '';
    if (!$idToken) {
        respond(false, ['message' => 'ID token is required'], 400);
    }

    $client = new Google_Client(['client_id' => GOOGLE_CLIENT_ID]);
    $payload = $client->verifyIdToken($idToken);
    if (!$payload) {
        respond(false, ['message' => 'Invalid ID token'], 401);
    }

    $googleId = $payload['sub'];
    $email    = $payload['email'];
    $name     = $payload['name'];
    $picture  = $payload['picture'] ?? null;

    // ตรวจหาผู้ใช้ตาม google_id
    $stmt = $pdo->prepare("SELECT user_id FROM user WHERE google_id = ?");
    $stmt->execute([$googleId]);

    if ($row = $stmt->fetch()) {
        $userId = $row['user_id'];
        $upd = $pdo->prepare("
            UPDATE user 
            SET profile_name = ?, path_imgProfile = ? 
            WHERE user_id = ?
        ");
        $upd->execute([$name, $picture, $userId]);

    } else {
        $stmt2 = $pdo->prepare("SELECT user_id FROM user WHERE email = ?");
        $stmt2->execute([$email]);

        if ($row2 = $stmt2->fetch()) {
            $userId = $row2['user_id'];
            $upd2 = $pdo->prepare("
                UPDATE user 
                SET google_id = ?, profile_name = ?, path_imgProfile = ? 
                WHERE user_id = ?
            ");
            $upd2->execute([$googleId, $name, $picture, $userId]);

        } else {
            $ins = $pdo->prepare("
                INSERT INTO user 
                (email, password, google_id, profile_name, path_imgProfile, created_at)
                VALUES (?, NULL, ?, ?, ?, NOW())
            ");
            $ins->execute([$email, $googleId, $name, $picture]);
            $userId = $pdo->lastInsertId();
        }
    }

    $_SESSION['user_id'] = $userId;

    // ✅ สำคัญ: wrap ค่าทั้งหมดใน key 'data'
    respond(true, [
        'user_id'         => (int) $userId,
        'email'           => $email,
        'profile_name'    => $name,
        'path_imgProfile' => $picture ?? ''
    ]);

} catch (\Throwable $e) {
    error_log('google_login.php error: ' . $e->getMessage());
    respond(false, ['message' => 'Server error: ' . $e->getMessage()], 500);
}
