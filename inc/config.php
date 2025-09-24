<?php // inc/config.php (ค่าคงที่พื้นฐานของแอป)

// =========================== DATABASE ===========================
// โฮสต์ MySQL (ปรกติ localhost)
define('DB_HOST', 'localhost');
// ชื่อฐานข้อมูล
define('DB_NAME', 'cookbook_db');
// ผู้ใช้ฐานข้อมูล
define('DB_USER', 'root');
// รหัสผ่านฐานข้อมูล (เครื่อง dev ส่วนใหญ่เว้นว่าง)
define('DB_PASS', '');

// =========================== GOOGLE OAUTH =======================
// Client ID สำหรับ Sign‑In ด้วย Google (FE จะใช้ส่ง token มาให้ BE ตรวจ)
define('GOOGLE_CLIENT_ID', '84901598956-f1jcvtke9f9lg84lgso1qpr3hf5rhhkr.apps.googleusercontent.com');

// =========================== ERROR DISPLAY ======================
// การแสดง error หลักจะควบคุมผ่าน bootstrap.php (ดู ENV: APP_ENV)
// ที่นี่ปิดไว้เป็นค่าเริ่มต้นเพื่อกันหลุด output ที่ละเอียดใน production
if (!headers_sent()) {                      // ตรวจว่ามีการส่ง header ไปหรือยัง
  ini_set('display_errors', '0');           // ไม่แสดง error บนหน้าเว็บ
  ini_set('display_startup_errors', '0');   // ไม่แสดง startup error
}
// รายงานเฉพาะ error สำคัญ ตัด NOTICE / STRICT / DEPRECATED ลดสัญญาณรบกวน log
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);

// *** ไม่ปิดแท็ก PHP เพื่อป้องกัน whitespace เกิน ***
