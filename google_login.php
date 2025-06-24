<?php
// google_login.php — Sign in / Sign up ด้วย Google OAuth

require_once __DIR__.'/inc/config.php';
require_once __DIR__.'/inc/functions.php';
require_once __DIR__.'/inc/db.php';
require_once __DIR__.'/vendor/autoload.php';

use Google\Client as Google_Client;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['message' => 'Method not allowed'], 405);
}

$idToken = trim($_POST['id_token'] ?? '');
if ($idToken === '') respond(false, ['message' => 'ID token required'], 400);

try {
    /* ── 1) verify token ── */
    $client  = new Google_Client(['client_id' => GOOGLE_CLIENT_ID]);
    $payload = $client->verifyIdToken($idToken);
    if (!$payload) respond(false, ['message' => 'Invalid ID token'], 401);

    $googleId = $payload['sub'];
    $email    = $payload['email'];
    $name     = $payload['name'];
    $picture  = $payload['picture'] ?? '';

    /* ── 2) UPSERT ผู้ใช้ ── */
    $pdo = pdo();
    $pdo->beginTransaction();

    // 2.1 มี user อยู่แล้วหรือไม่
    $uid = dbOne("SELECT user_id FROM user WHERE google_id=? OR email=?", 
                 [$googleId, $email])['user_id'] ?? null;

    if ($uid) {
        // update
        dbExec("
            UPDATE user
               SET google_id = :gid, profile_name=:name, path_imgProfile=:pic
             WHERE user_id   = :uid
        ", ['gid'=>$googleId,'name'=>$name,'pic'=>$picture,'uid'=>$uid]);
    } else {
        // insert (ให้ password = '' ไม่ null)
        dbExec("
            INSERT INTO user(email,password,google_id,profile_name,path_imgProfile,created_at)
            VALUES(:email,'',:gid,:name,:pic,NOW())
        ", ['email'=>$email,'gid'=>$googleId,'name'=>$name,'pic'=>$picture]);
        $uid = (int)$pdo->lastInsertId();
    }

    $pdo->commit();

    /* ── 3) start session แล้วส่งกลับ ── */
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = $uid;

    $imgUrl = preg_match('#^https?://#', $picture)
              ? $picture
              : getBaseUrl().'/'.ltrim($picture,'/');

    respond(true, [
        'user_id'         => $uid,
        'email'           => $email,
        'profile_name'    => $name,
        'path_imgProfile' => $imgUrl,
    ]);

} catch (Throwable $e) {
    if ($pdo?->inTransaction()) $pdo->rollBack();
    error_log('[google_login] '.$e->getMessage());
    respond(false, ['message'=>'Server error'], 500);
}
