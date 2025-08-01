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
    // ครอบด้วย @ เผื่อ proc_open ใช้ไม่ได้ในสภาพแวดล้อมนั้น ๆ
    $proc = @proc_open($cmd, $descs, $pipes, null, null, ['timeout' => 1]);
    if (!is_resource($proc)) {
        return [];
    }

    fwrite($pipes[0], $q);
    fclose($pipes[0]);

    $json = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // เดิมใช้ JSON_THROW_ON_ERROR อาจโยน exception ได้ ถ้า script คืนไม่เป็น JSON
    // ครอบ try/catch ไว้เพื่อให้ฟังก์ชันนี้ "ปลอดภัย" และ fallback เป็น []
    try {
        $tokens = json_decode($json, true, 32, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (!is_array($tokens)) return [];
        // unique + จำกัด 5 คำ
        $tokens = array_values(array_unique($tokens));
        return array_slice($tokens, 0, 5);
    } catch (Throwable $e) {
        // error_log('[thaiTokens] ' . $e->getMessage());
        return [];
    }
}

/* ───────── 4. [NEW] ตัวช่วยสำหรับสวิตช์ "ตัดคำ" & การแยกคำ ───────── */

/**
 * อ่าน boolean จากคำขอ (GET/POST)
 * ตัวอย่างค่า true: '1', 'true', 'yes', 'on'
 */
function reqBool(string $key, bool $default = false): bool
{
    $v = $_GET[$key] ?? $_POST[$key] ?? null;
    if ($v === null) return $default;
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'yes', 'on'], true);
}

/**
 * (ทางเลือก) ดีฟอลต์ของสวิตช์ "ตัดคำ" จาก config
 * ถ้าใน inc/config.php มี define('SEARCH_TOKENIZE_DEFAULT', true/false)
 * ให้ใช้ค่านั้น มิฉะนั้นใช้ false
 */
function defaultSearchTokenize(): bool
{
    return defined('SEARCH_TOKENIZE_DEFAULT') ? (bool)SEARCH_TOKENIZE_DEFAULT : false;
}

/**
 * แยกคำแบบง่าย: ใช้ช่องว่าง/จุลภาคเป็นตัวคั่น
 * - รองรับกรณี "กุ้ง กระเทียม" หรือ "กุ้ง,กระเทียม"
 * - ตัดซ้ำ/ตัดว่าง และจำกัดไม่เกิน 5 คำ
 */
function splitSimpleTerms(string $q): array
{
    $q = sanitize($q);
    if ($q === '') return [];
    $parts = preg_split('/[,\s]+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
    $parts = array_map('sanitize', $parts);
    $parts = array_values(array_unique($parts));
    return array_slice($parts, 0, 5);
}

/**
 * parseSearchTerms:
 * - เมื่อ $tokenize = true → ใช้ thaiTokens($q) หากล้มเหลว fallback เป็น splitSimpleTerms($q)
 * - เมื่อ $tokenize = false → ใช้ splitSimpleTerms($q) ตามสเปก “กุ้ง กระเทียม / กุ้ง,กระเทียม”
 */
function parseSearchTerms(string $q, bool $tokenize = false): array
{
    $q = sanitize($q);
    if ($q === '') return [];
    if ($tokenize) {
        $toks = thaiTokens($q);
        if (!empty($toks)) return $toks;
        // fallback หาก Python/script ใช้งานไม่ได้
    }
    return splitSimpleTerms($q);
}

/**
 * LIKE pattern helper: แปลง term → %term% และ escape %/_
 * ใช้คู่กับ "… LIKE ? ESCAPE '\\' "
 */
function likePattern(string $term): string
{
    $term = str_replace(['%', '_'], ['\\%', '\\_'], $term);
    return '%' . $term . '%';
}
