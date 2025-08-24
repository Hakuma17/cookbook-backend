<?php
/**
 * bootstrap.php — โหลด Composer autoload + .env (แบบมี fallback)
 * ----------------------------------------------------------------
 * ใช้กับสภาพแวดล้อมที่อาจยังไม่ได้ติดตั้ง vlucas/phpdotenv
 * - ถ้ามี Dotenv: ใช้ Dotenv โหลด .env
 * - ถ้าไม่มี Dotenv: ใช้ตัวอ่าน .env แบบง่าย ๆ (fallback) เพื่อให้ getenv() ใช้ได้
 * หมายเหตุ: ต้องเซฟไฟล์เป็น UTF-8 (ไม่มี BOM) และห้ามมีเอาต์พุตก่อน header ใด ๆ
 */

declare(strict_types=1);

// ───────────────────────── Composer autoload ─────────────────────────
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
} else {
    error_log('[bootstrap] vendor/autoload.php not found at: ' . $autoloadPath);
}

// ───────────────────────── Helper: set env var ───────────────────────
/**
 * ตั้งค่า env ทั้ง putenv(), $_ENV, $_SERVER ให้สอดคล้องกัน
 */
function _env_set(string $key, string $val): void {
    // trim เฉพาะ CR/LF รอบนอก (เผื่อค่ามีช่องว่างจริง)
    $val = preg_replace('/\R+\z/u', '', $val ?? '') ?? $val;
    putenv("$key=$val");
    $_ENV[$key]    = $val;
    $_SERVER[$key] = $val;
}

/**
 * Fallback loader แบบง่าย ๆ:
 * - รองรับบรรทัดรูปแบบ KEY=VALUE
 * - ตัดคอมเมนต์ด้วย # เฉพาะเมื่อ # อยู่นอกเครื่องหมายคำพูด
 * - รองรับค่าใน "..." หรือ '...'
 * - ไม่ทำ variable expansion ซับซ้อน (เช่น ${FOO})
 */
function _load_env_fallback(string $envFile): void {
    if (!is_file($envFile) || !is_readable($envFile)) {
        error_log('[bootstrap] .env not found or unreadable at: ' . $envFile);
        return;
    }
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // ตัดคอมเมนต์ท้ายบรรทัด (#) เฉพาะที่อยู่นอก quote
        $inSingle = false; $inDouble = false; $buf = '';
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $buf .= $ch;
                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $buf .= $ch;
                continue;
            }
            if ($ch === '#' && !$inSingle && !$inDouble) {
                // ตัดทิ้งตั้งแต่ # ไป
                break;
            }
            $buf .= $ch;
        }
        $line = trim($buf);
        if ($line === '') continue;

        // แยก KEY=VALUE ครั้งแรกเท่านั้น
        $eqPos = strpos($line, '=');
        if ($eqPos === false) continue;
        $key = trim(substr($line, 0, $eqPos));
        $val = trim(substr($line, $eqPos + 1));

        // รองรับ export KEY=VALUE
        if (strncasecmp($key, 'export ', 7) === 0) {
            $key = trim(substr($key, 7));
        }
        if ($key === '') continue;

        // ลอกเครื่องหมายคำพูดรอบนอกถ้ามี
        if ((str_starts_with($val, '"') && str_ends_with($val, '"'))
            || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $quote = $val[0];
            $val = substr($val, 1, -1);
            if ($quote === '"') {
                // แกะ escape พื้นฐานใน double-quoted
                $val = str_replace(["\\n","\\r","\\t","\\\\"], ["\n","\r","\t","\\"], $val);
            }
        }

        _env_set($key, $val);
    }
}

// ───────────────────────── โหลด .env ────────────────────────────────
$envDir  = __DIR__;
$envFile = $envDir . '/.env';

// กรณีมีไลบรารี Dotenv
if (class_exists(\Dotenv\Dotenv::class)) {
    try {
        // บางเวอร์ชันมี safeLoad() ถ้าไม่มีให้ใช้ load() แล้วจับ Throwable
        $dotenv = \Dotenv\Dotenv::createImmutable($envDir);
        if (method_exists($dotenv, 'safeLoad')) {
            $dotenv->safeLoad();
        } else {
            $dotenv->load(); // จะโยน InvalidPathException ถ้าไม่มีไฟล์
        }
    } catch (\Throwable $e) {
        // ถ้าโหลดด้วย Dotenv ไม่ได้ ให้ fallback แบบ manual
        error_log('[bootstrap] Dotenv load failed: ' . $e->getMessage());
        _load_env_fallback($envFile);
    }
} else {
    // ไม่มีแพ็กเกจ vlucas/phpdotenv → ใช้ fallback
    error_log('[bootstrap] Dotenv class not found, using fallback parser for .env');
    _load_env_fallback($envFile);
}

// ───────────────────────── ค่าตั้งค่าเพิ่มเติม (ออปชัน) ─────────────────────────
// ตั้ง timezone ตามที่ต้องการ (ค่าเริ่มต้น Asia/Bangkok ถ้าไม่ได้ตั้ง)
if (!ini_get('date.timezone')) {
    date_default_timezone_set(getenv('APP_TZ') ?: 'Asia/Bangkok');
}

// โดยทั่วไปไม่แนะนำให้เปิด error display ใน production
if (strtolower((string)(getenv('APP_ENV') ?: 'production')) !== 'production') {
    // dev: แสดง error
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    // prod: ซ่อน error (เก็บใน error_log แทน)
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
}

// *** จบไฟล์: ไม่ปิด PHP tag เพื่อกัน whitespace หลุดออก ***