<?php
/* inc/functions.php */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/json.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ───────── 1. PDO ───────── */

/**
 * Get the PDO database connection instance.
 * Uses a static variable to ensure a single connection per request.
 * @return PDO
 */
function pdo(): PDO
{
    // ★ แก้ไข: ใช้ static variable แทน global เพื่อ Encapsulation ที่ดีกว่า
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
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
    return $pdo;
}

/* ───────── 2. Helpers (ทั่วไป) ───────── */

/**
 * Sanitizes a string for database LIKE queries by trimming whitespace.
 * WARNING: This function does NOT protect against XSS. Do not use for HTML output.
 * @param string $v The string to sanitize.
 * @return string Trimmed string.
 */
function sanitize(string $v): string
{
    // ★ เพิ่มเติม: เพิ่ม comment เตือนเรื่อง XSS
    return trim($v);
}

function getBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return rtrim($scheme . $host . $dir, '/');
}

function getLoggedInUserId(): ?int      { return $_SESSION['user_id'] ?? null; }
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
    static $cmd = null;

    if ($cmd === null) {
        // ระบุ path python & สคริปต์ให้ชัด (ปรับตามโครงจริงถ้าแตกต่าง)
        $script = __DIR__ . '/../scripts/thai_tokenize.py';
        $python = 'python3'; // แนะนำให้ระบุ python3 เพื่อความชัดเจน
        $cmd    = $python . ' -X utf8 ' . escapeshellarg($script);
    }

    $descs = [
        0 => ['pipe', 'r'], // STDIN
        1 => ['pipe', 'w'], // STDOUT
        2 => ['pipe', 'w'], // STDERR
    ];
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
        // ★ แก้ไข: เปิด log เพื่อให้ตรวจสอบปัญหาได้ง่ายขึ้น
        error_log('[thaiTokens] Failed to decode JSON from Python script: ' . $e->getMessage());
        return [];
    }
}

/* ───────── 4. ตัวช่วยสำหรับสวิตช์ "ตัดคำ" & การแยกคำ ───────── */

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
    return defined('SEARCH_TOKENIZE_DEFAULT') ? (bool)constant('SEARCH_TOKENIZE_DEFAULT') : false;
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
 * parseSearchTerms: แยกคำค้นหาโดยเลือกระหว่างตัดคำไทยหรือแบบง่าย
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


/* ───────── 5. ★ ใหม่: Helpers สำหรับแอปพลิเคชัน (Cart/Ingredient) ───────── */

/**
 * ทำ URL รูปภาพให้เป็น absolute + fallback ถ้าไฟล์ไม่มีจริง
 */
function normalizeImageUrl(?string $raw, string $defaultFile = 'default_ingredients.png'): string
{
    $baseUrl  = rtrim(getBaseUrl(), '/');
    $baseWeb  = $baseUrl . '/uploads/ingredients';
    $basePath = __DIR__ . '/../uploads/ingredients'; // Path จาก root ของ project

    $raw = trim((string)$raw);
    if ($raw === '') return $baseWeb . '/' . $defaultFile;

    if (preg_match('~^https?://~i', $raw)) return $raw;

    $filename = basename(str_replace('\\', '/', $raw));
    $abs = $basePath . '/' . $filename;
    if (is_file($abs)) return $baseWeb . '/' . $filename;

    if (strpos($filename, 'ingredient_') === 0) {
        $alt = 'ingredients_' . substr($filename, strlen('ingredient_'));
        if (is_file($basePath . '/' . $alt)) return $baseWeb . '/' . $alt;
    }
    return $baseWeb . '/' . $defaultFile;
}

/**
 * แปลง nutrition_id → กลุ่มวัตถุดิบ (code/name สั้น)
 */
function mapGroupFromNutritionId(?string $nid): array
{
    $nid = trim((string)$nid);

    if ($nid === '') {
        $code = '16';
    } elseif (preg_match('/^([0-9]{2})/', $nid, $m)) {
        $code = $m[1];
    } elseif (preg_match('/^([A-Z])/', $nid, $m)) {
        $letter = $m[1];
        $letterMap = [
            'A'=>'01', 'B'=>'02', 'C'=>'03', 'D'=>'04', 'E'=>'05',
            'F'=>'06', 'G'=>'07', 'H'=>'08', 'J'=>'09', 'K'=>'10',
            'M'=>'12', 'N'=>'10', 'Q'=>'11', 'S'=>'11', 'T'=>'11',
            'U'=>'16', 'Z'=>'16',
        ];
        $code = $letterMap[$letter] ?? '16';
    } else {
        $code = '16';
    }

    $short = [
        '01'=>'ธัญพืช','02'=>'ราก/หัว','03'=>'ถั่ว/เมล็ด','04'=>'ผัก','05'=>'ผลไม้',
        '06'=>'เนื้อสัตว์','07'=>'สัตว์น้ำ','08'=>'ไข่','09'=>'นม',
        '10'=>'เครื่องปรุง','11'=>'พร้อมกิน','12'=>'ของหวาน','13'=>'แมลง',
        '14'=>'อื่นๆ','16'=>'อื่นๆ',
    ];
    return [$code, $short[$code] ?? 'อื่นๆ'];
}