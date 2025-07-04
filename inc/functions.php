<?php
/* inc/functions.php */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/json.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ───────── 1. PDO ───────── */
$__pdo = null;
function pdo(): PDO
{
    global $__pdo;
    if ($__pdo === null) {
        $__pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $__pdo;
}

/* ───────── 2. Helpers (ทั่วไป) ───────── */
function sanitize(string $v): string
{
    return trim($v);   // ไม่ escape HTML เพื่อให้ LIKE ตรงเป๊ะ
}

function getBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return rtrim($scheme . $host . $dir, '/');
}

function getLoggedInUserId(): ?int        { return $_SESSION['user_id'] ?? null; }
function requireLogin(): int
{
    $uid = getLoggedInUserId();
    if (!$uid) {
        jsonOutput(['success' => false, 'message' => 'ต้องล็อกอินก่อน'], 401);
    }
    return $uid;
}

function respond(bool $ok, array $data = [], int $code = 200): void
{
    jsonOutput([
        'success' => $ok,
        'message' => $ok ? 'ดำเนินการสำเร็จ' : ($data['message'] ?? 'เกิดข้อผิดพลาด'),
        'data'    => $ok ? $data : (object)[],
    ], $code);
}

/* ───────────────── 3. Thai tokenizer (PyThaiNLP) ───────────────── */
/**
 * เรียกสคริปต์ Python (PyThaiNLP newmm) เพื่อแตกคำไทย
 * @param string $q  สตริง UTF-8 จากผู้ใช้
 * @return string[]  tokens ไม่เกิน 5 คำ; ถ้า error → []
 */
function thaiTokens(string $q): array
{
    static $cmd = null;                     // cache คำสั่ง

    if ($cmd === null) {
        // ระบุ path python & สคริปต์ให้ชัด (ปรับตามโครงจริงถ้าแตกต่าง)
        $script = __DIR__ . '/../scripts/thai_tokenize.py';
        $python = 'python';                 // หรือ python3 / path เต็ม
        $cmd    = $python . ' -X utf8 ' . escapeshellarg($script);
    }

    $descs = [
        0 => ['pipe', 'r'],   // STDIN
        1 => ['pipe', 'w'],   // STDOUT
        2 => ['pipe', 'w'],   // STDERR
    ];
    $proc = proc_open($cmd, $descs, $pipes, null, null, ['timeout' => 1]);
    if (!is_resource($proc)) {
        return [];
    }

    fwrite($pipes[0], $q);
    fclose($pipes[0]);

    $json = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $tokens = json_decode($json, true, 32, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    return is_array($tokens) ? $tokens : [];
}
