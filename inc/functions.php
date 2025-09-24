<?php
/**
 * inc/functions.php
 *  ศูนย์รวมฟังก์ชันพื้นฐานที่ endpoint เกือบทุกไฟล์ต้องใช้:
 *   - จัดการ Session + ความปลอดภัยของคุกกี้
 *   - สร้าง/รีใช้การเชื่อมต่อฐานข้อมูล (pdo())
 *   - Helper ทั่วไป (sanitize, respond, getBaseUrl)
 *   - ฟังก์ชันเกี่ยวกับ Tokenize ภาษาไทย (เรียก python)
 *   - ตัวช่วยแยกคำค้น / สร้าง LIKE pattern
 *   - Helper เฉพาะโดเมน (เช่น normalizeImageUrl, mapGroupFromNutritionId)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/json.php';

// ======================================================================
// 1) SESSION HARDENING
//    - กำหนดคุกกี้ให้ Secure (ถ้าเป็น HTTPS), HttpOnly, SameSite=Lax
//    - หลีกเลี่ยงกำหนด domain ตรง ๆ (ให้ PHP จัดการตาม host ปัจจุบัน ลด misconfig)
// ======================================================================
if (session_status() === PHP_SESSION_NONE) {               // ยังไม่เริ่ม session มาก่อน
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $cookieParams = [
        'lifetime' => 0,                                   // หมดเมื่อปิดเบราว์เซอร์
        'path'     => '/',                                 // ใช้ได้ทั้งโดเมน
        'secure'   => $isHttps ? true : false,             // ส่งผ่านเฉพาะ HTTPS ถ้าเป็นโปรโตคอลนั้น
        'httponly' => true,                                // JS เข้าถึงไม่ได้ กัน XSS ขโมย session
        'samesite' => 'Lax',                               // ป้องกัน CSRF พื้นฐาน แต่ยังคลิกลิงก์ข้ามได้
    ];
    if (PHP_VERSION_ID >= 70300) {
        @session_set_cookie_params($cookieParams);         // เวอร์ชันใหม่ รองรับ array
    } else {
        // โค้ดรองรับ PHP เก่า (ไม่ควรใช้งานแล้ว แต่กันเผื่อ)
        @session_set_cookie_params(0, '/; samesite=Lax', '', $isHttps, true);
    }
    session_start();                                       // เปิด session
}

// ======================================================================
// 2) PDO CONNECTION
//    - ใช้ static ตัวเดียวเพื่อลด overhead การสร้างซ้ำ
//    - ตั้งค่า ERRMODE_EXCEPTION, FETCH_ASSOC, ปิด emulate prepared
// ======================================================================

/**
 * Get the PDO database connection instance.
 * Uses a static variable to ensure a single connection per request.
 * @return PDO
 */
function pdo(): PDO {
    static $pdo = null;                                    // เก็บ instance reuse ตลอด request
    if ($pdo === null) {                                   // สร้างครั้งแรกเท่านั้น
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // โยน exception เมื่อ error
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // คืน associative array โดยตรง
                PDO::ATTR_EMULATE_PREPARES   => false,                  // ใช้ native prepared ปลอดภัยกว่า
            ]
        );
    }
    return $pdo;                                           // คืน connection
}

// ======================================================================
// 3) GENERIC HELPERS
// ======================================================================

/**
 * Sanitizes a string for database LIKE queries by trimming whitespace.
 * WARNING: This function does NOT protect against XSS. Do not use for HTML output.
 * @param string $v The string to sanitize.
 * @return string Trimmed string.
 */
function sanitize(string $v): string {                     // ตัดช่องว่างหัวท้าย (ไม่ป้องกัน XSS)
    return trim($v);
}

function getBaseUrl(): string {                            // สร้าง base URL ปัจจุบัน (ไม่รวม query)
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return rtrim($scheme . $host . $dir, '/');
}

function getLoggedInUserId(): ?int { return $_SESSION['user_id'] ?? null; } // ดึง user_id จาก session หรือ null
function requireLogin(): int {                              // บังคับต้องล็อกอิน ไม่งั้น 401
    $uid = getLoggedInUserId();
    if (!$uid) {
        jsonOutput(['success' => false, 'message' => 'ต้องล็อกอินก่อน'], 401);
    }
    return $uid;
}

function respond(bool $ok, array $data = [], int $code = 200): void { // wrapper ตอบ JSON มาตรฐาน
    jsonOutput([
        'success' => $ok,
        'message' => $ok ? 'ดำเนินการสำเร็จ' : ($data['message'] ?? 'เกิดข้อผิดพลาด'),
        'data'    => $ok ? $data : (object)[],
    ], $code);
}

// ======================================================================
// 4) THAI TOKENIZER (เรียก python: scripts/thai_tokenize.py)
//    - ใช้เมื่อเปิดโหมด tokenize เพื่อความแม่นยำของการค้นหา
// ======================================================================
/**
 * เรียกสคริปต์ Python (PyThaiNLP newmm) เพื่อแตกคำไทย
 * @param string $q  สตริง UTF-8 จากผู้ใช้
 * @return string[]  tokens ไม่เกิน 5 คำ; ถ้า error → []
 */
function thaiTokens(string $q): array {
    static $cmd = null;                                      // cache คำสั่งเรียก python ไว้
    if ($cmd === null) {                                     // สร้างครั้งแรก
        $script = __DIR__ . '/../scripts/thai_tokenize.py';  // สคริปต์ tokenizer
        $python = 'python3';                                 // หรือกำหนดจาก ENV ถ้าต้องการ
        $cmd    = $python . ' -X utf8 ' . escapeshellarg($script);
    }
    $descs = [                                               // กำหนด pipe สำหรับ proc_open
        0 => ['pipe', 'r'], // STDIN
        1 => ['pipe', 'w'], // STDOUT
        2 => ['pipe', 'w'], // STDERR
    ];
    $proc = @proc_open($cmd, $descs, $pipes, null, null, ['timeout' => 1]); // timeout 1s
    if (!is_resource($proc)) return [];                      // เปิดไม่ติด → คืน []

    fwrite($pipes[0], $q); fclose($pipes[0]);                // ส่งข้อความเข้า STDIN
    $json = stream_get_contents($pipes[1]); fclose($pipes[1]); // อ่านผลลัพธ์ JSON
    fclose($pipes[2]); proc_close($proc);                    // ปิด STDERR + process

    try {                                                    // แปลง JSON → array
        $tokens = json_decode($json, true, 32, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (!is_array($tokens)) return [];
        $tokens = array_values(array_unique($tokens));       // unique
        return array_slice($tokens, 0, 5);                   // จำกัด 5 คำ
    } catch (Throwable $e) {                                 // พัง → log แล้วคืน []
        error_log('[thaiTokens] Failed to decode JSON from Python script: ' . $e->getMessage());
        return [];
    }
}

// ======================================================================
// 5) SEARCH TERM HELPERS (toggle tokenize / split / pattern)
// ======================================================================

/**
 * อ่าน boolean จากคำขอ (GET/POST)
 * ตัวอย่างค่า true: '1', 'true', 'yes', 'on'
 */
function reqBool(string $key, bool $default = false): bool { // ดึง boolean จาก GET/POST
    $v = $_GET[$key] ?? $_POST[$key] ?? null;
    if ($v === null) return $default;                        // ไม่มี → default
    if (is_bool($v)) return $v;                              // เป็น bool อยู่แล้ว
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'yes', 'on'], true);
}

/**
 * (ทางเลือก) ดีฟอลต์ของสวิตช์ "ตัดคำ" จาก config
 * ถ้าใน inc/config.php มี define('SEARCH_TOKENIZE_DEFAULT', true/false)
 * ให้ใช้ค่านั้น มิฉะนั้นใช้ false
 */
function defaultSearchTokenize(): bool {                    // ค่าดีฟอลต์จาก config (หรือ false)
    return defined('SEARCH_TOKENIZE_DEFAULT') ? (bool)constant('SEARCH_TOKENIZE_DEFAULT') : false;
}

/**
 * แยกคำแบบง่าย: ใช้ช่องว่าง/จุลภาคเป็นตัวคั่น
 * - รองรับกรณี "กุ้ง กระเทียม" หรือ "กุ้ง,กระเทียม"
 * - ตัดซ้ำ/ตัดว่าง และจำกัดไม่เกิน 5 คำ
 */
function splitSimpleTerms(string $q): array {               // แยกคำแบบง่าย (เว้นวรรค/คอมมา)
    $q = sanitize($q);
    if ($q === '') return [];
    $parts = preg_split('/[,\s]+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
    $parts = array_map('sanitize', $parts);
    $parts = array_values(array_unique($parts));
    return array_slice($parts, 0, 5);                        // จำกัด 5 คำ
}

/**
 * parseSearchTerms: แยกคำค้นหาโดยเลือกระหว่างตัดคำไทยหรือแบบง่าย
 */
function parseSearchTerms(string $q, bool $tokenize = false): array { // เลือก tokenizer หรือ split
    $q = sanitize($q);
    if ($q === '') return [];
    if ($tokenize) {                                        // ถ้าเปิดโหมดตัดคำ
        $toks = thaiTokens($q);
        if (!empty($toks)) return $toks;                    // ได้ token → ใช้เลย
        // ถ้า python ใช้ไม่ได้ fallback ด้านล่าง
    }
    return splitSimpleTerms($q);
}

/**
 * LIKE pattern helper: แปลง term → %term% และ escape %/_
 * ใช้คู่กับ "… LIKE ? ESCAPE '\\' "
 */
function likePattern(string $term): string {                // สร้าง %term% + escape wildcard
    $term = str_replace(['%', '_'], ['\\%', '\\_'], $term);
    return '%' . $term . '%';
}


// ======================================================================
// 6) DOMAIN-SPECIFIC HELPERS (รูปภาพวัตถุดิบ / กลุ่มวัตถุดิบ)
// ======================================================================

/**
 * ทำ URL รูปภาพให้เป็น absolute + fallback ถ้าไฟล์ไม่มีจริง
 */
function normalizeImageUrl(?string $raw, string $defaultFile = 'default_ingredients.png'): string { // ทำ URL ให้ใช้ได้เสมอ
    $baseUrl  = rtrim(getBaseUrl(), '/');
    $baseWeb  = $baseUrl . '/uploads/ingredients';          // โฟลเดอร์ public
    $basePath = __DIR__ . '/../uploads/ingredients';         // พาธจริงในเซิร์ฟเวอร์
    $raw = trim((string)$raw);
    if ($raw === '') return $baseWeb . '/' . $defaultFile;   // ว่าง → ใช้ default
    if (preg_match('~^https?://~i', $raw)) return $raw;      // เป็น URL อยู่แล้ว → ส่งกลับ
    $filename = basename(str_replace('\\', '/', $raw));
    $abs = $basePath . '/' . $filename;
    if (is_file($abs)) return $baseWeb . '/' . $filename;    // ไฟล์มีอยู่จริง
    if (strpos($filename, 'ingredient_') === 0) {            // ลองรูปแบบ alternate (ingredients_)
        $alt = 'ingredients_' . substr($filename, strlen('ingredient_'));
        if (is_file($basePath . '/' . $alt)) return $baseWeb . '/' . $alt;
    }
    return $baseWeb . '/' . $defaultFile;                   // สุดท้าย fallback
}

/**
 * แปลง nutrition_id → กลุ่มวัตถุดิบ (code/name สั้น)
 */
function mapGroupFromNutritionId(?string $nid): array {      // เดา / แปลง nutrition_id → กลุ่ม (รหัส + ชื่อ)
    $nid = trim((string)$nid);
    if ($nid === '') {                                       // ว่าง → กลุ่ม 16 (อื่นๆ)
        $code = '16';
    } elseif (preg_match('/^([0-9]{2})/', $nid, $m)) {       // ขึ้นต้นด้วยตัวเลข 2 หลัก
        $code = $m[1];
    } elseif (preg_match('/^([A-Z])/', $nid, $m)) {          // ขึ้นต้นด้วยตัวอักษร A-Z
        $letter = $m[1];
        $letterMap = [
            'A'=>'01','B'=>'02','C'=>'03','D'=>'04','E'=>'05','F'=>'06','G'=>'07','H'=>'08','J'=>'09','K'=>'10',
            'M'=>'12','N'=>'10','Q'=>'11','S'=>'11','T'=>'11','U'=>'16','Z'=>'16',
        ];
        $code = $letterMap[$letter] ?? '16';                  // ไม่แมตช์ → 16
    } else {
        $code = '16';
    }
    $short = [                                               // แม็พรหัส → ชื่อสั้นภาษาไทย
        '01'=>'ธัญพืช','02'=>'ราก/หัว','03'=>'ถั่ว/เมล็ด','04'=>'ผัก','05'=>'ผลไม้','06'=>'เนื้อสัตว์','07'=>'สัตว์น้ำ','08'=>'ไข่','09'=>'นม',
        '10'=>'เครื่องปรุง','11'=>'พร้อมกิน','12'=>'ของหวาน','13'=>'แมลง','14'=>'อื่นๆ','16'=>'อื่นๆ',
    ];
    return [$code, $short[$code] ?? 'อื่นๆ'];
}

// *** END OF FILE ***