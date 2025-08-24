<?php
// google_login.php — Sign in / Sign up ด้วย Google OAuth (non-destructive update)

require_once __DIR__.'/inc/config.php';
require_once __DIR__.'/inc/functions.php';
require_once __DIR__.'/inc/db.php';
require_once __DIR__.'/vendor/autoload.php';

use Google\Client as Google_Client;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, ['message' => 'Method not allowed'], 405);
}

$idToken = trim($_POST['id_token'] ?? '');
if ($idToken === '') {
    respond(false, ['message' => 'ID token required'], 400);
}

/* ───────────────────────── helpers ───────────────────────── */
function normalize_google_avatar(?string $url): string {
    $u = trim((string)$url);
    if ($u === '') return '';
    $parts = parse_url($u);
    $host  = strtolower($parts['host'] ?? '');
    if (str_contains($host, 'googleusercontent.com')) {
        if (isset($parts['query'])) {
            parse_str($parts['query'], $q);
            $q['sz'] = '512';
            $parts['query'] = http_build_query($q);
            return build_url_from_parts($parts);
        }
        if (preg_match('/=s\d+(?:-c)?$/', $u)) {
            return preg_replace('/=s\d+(?:-c)?$/', '=s512-c', $u);
        }
        return $u.(str_contains($u, '?') ? '&' : '?').'sz=512';
    }
    return $u;
}
function build_url_from_parts(array $p): string {
    $scheme = $p['scheme'] ?? 'https';
    $host   = $p['host']   ?? '';
    $path   = $p['path']   ?? '';
    $query  = isset($p['query']) && $p['query'] !== '' ? '?'.$p['query'] : '';
    return $scheme.'://'.$host.$path.$query;
}
function is_google_hosted_avatar(?string $url): bool {
    if (!$url) return false;
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    return $host !== '' && str_contains($host, 'googleusercontent.com');
}
function looks_like_custom_avatar(?string $val): bool {
    if (!$val) return false;
    if (preg_match('#^https?://#i', $val)) {
        return !is_google_hosted_avatar($val);
    }
    return trim($val) !== ''; // local path e.g. uploads/...
}

/* ───────────────────────── main ───────────────────────── */
try {
    // 1) verify token
    $client  = new Google_Client(['client_id' => GOOGLE_CLIENT_ID]);
    $payload = $client->verifyIdToken($idToken);
    if (!$payload) {
        respond(false, ['message' => 'Invalid ID token'], 401);
    }

    $googleId = $payload['sub']      ?? '';
    $email    = trim($payload['email']   ?? '');
    $name     = trim($payload['name']    ?? '');
    $picture  = trim($payload['picture'] ?? '');

    if ($googleId === '' || $email === '' || $name === '') {
        respond(false, ['message' => 'Incomplete user data from Google'], 400);
    }

    $normPic = normalize_google_avatar($picture);

    // 2) upsert
    $pdo = pdo();
    $pdo->beginTransaction();

    $row = dbOne("
        SELECT user_id, profile_name, path_imgProfile
          FROM user
         WHERE google_id = ? OR email = ?
         LIMIT 1
    ", [$googleId, $email]);

    if (is_array($row) && isset($row['user_id'])) {
        $uid       = (int)$row['user_id'];
        $oldName   = trim((string)($row['profile_name'] ?? ''));
        $oldAvatar = trim((string)($row['path_imgProfile'] ?? ''));

        // non-destructive rules
        $shouldUpdateName   = ($oldName === '');
        $shouldUpdateAvatar = false;
        if ($oldAvatar === '') {
            $shouldUpdateAvatar = true;
        } elseif (!looks_like_custom_avatar($oldAvatar)) {
            $shouldUpdateAvatar = true; // old is Google/system → allow refresh
        }

        $sets   = ['google_id = :gid'];
        $params = ['gid' => $googleId, 'uid' => $uid];

        if ($shouldUpdateName) {
            $sets[] = 'profile_name = :name';
            $params['name'] = $name;
        }
        if ($shouldUpdateAvatar && $normPic !== '') {
            $sets[] = 'path_imgProfile = :pic';
            $params['pic'] = $normPic;
        }

        $sql = "UPDATE user SET ".implode(', ', $sets)." WHERE user_id = :uid";
        dbExec($sql, $params);
    } else {
        dbExec("
            INSERT INTO user(email, password, google_id, profile_name, path_imgProfile, created_at)
            VALUES(:email, '', :gid, :name, :pic, NOW())
        ", [
            'email' => $email,
            'gid'   => $googleId,
            'name'  => $name,
            'pic'   => $normPic
        ]);
        $uid = (int)$pdo->lastInsertId();
    }

    $pdo->commit();

    // 3) start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user_id'] = $uid;

    // 4) re-fetch fresh row to build response safely/accurately
    $fresh = dbOne("
        SELECT user_id, email, google_id, profile_name, path_imgProfile
          FROM user
         WHERE user_id = ?
         LIMIT 1
    ", [$uid]) ?: [];

    $respGoogleId = (string)($fresh['google_id'] ?? $googleId);
    $respName     = trim((string)($fresh['profile_name'] ?? ''));
    if ($respName === '') $respName = $name;

    $storedAvatar = trim((string)($fresh['path_imgProfile'] ?? ''));
    $finalPic     = looks_like_custom_avatar($storedAvatar) && $storedAvatar !== ''
        ? $storedAvatar
        : $normPic;

    // make absolute if local path
    if ($finalPic !== '' && !preg_match('#^https?://#i', $finalPic)) {
        $finalPic = getBaseUrl().'/'.ltrim($finalPic, '/');
    }

    respond(true, [
        'user_id'         => (int)($fresh['user_id'] ?? $uid),
        'email'           => (string)($fresh['email'] ?? $email),
        'google_id'       => $respGoogleId,          // ★ ส่งกลับไปให้ FE บันทึก
        'profile_name'    => $respName,
        'path_imgProfile' => $finalPic,
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo?->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[google_login] '.$e->getMessage());
    respond(false, ['message' => 'Server error'], 500);
}
