<?php
// verify_otp.php
// ยืนยันรหัส OTP พร้อมจำกัดจำนวนครั้งและล็อกชั่วคราว

header('Content-Type: application/json; charset=UTF-8');
session_start();

// (DEV) เปิดการแสดงข้อผิดพลาด เพื่อดีบัก
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/inc/config.php';    // เชื่อมฐานข้อมูล → $pdo
require_once __DIR__ . '/inc/functions.php'; // sanitize()

// ── Constants ─────────────────────────────────────────────────────────────
define('MAX_ATTEMPTS',  5);    // OTP ผิดได้กี่ครั้งก่อนล็อก
define('LOCK_DURATION', 600);  // ระยะเวลาล็อก (วินาที)

try {
  // 1) ยอมรับเฉพาะ POST
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    throw new Exception('Method not allowed');
  }

  // 2) รับค่า & sanitize
  $email = sanitize($_POST['email'] ?? '');
  $otp   = sanitize($_POST['otp']   ?? '');
  if (!$email || !$otp) {
    http_response_code(400);
    throw new Exception('กรุณากรอกอีเมลและ OTP');
  }

  // 3) โหลด record จาก user_otp
  $stmt = $pdo->prepare(
    'SELECT otp, otp_expires_at, attempts, lock_until
       FROM user_otp
      WHERE email = ?
      LIMIT 1'
  );
  $stmt->execute([$email]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    http_response_code(404);
    throw new Exception('ยังไม่เคยขอ OTP');
  }

  // 4) เช็คบัญชีถูกล็อกชั่วคราวหรือไม่
  if (!empty($row['lock_until']) && time() < strtotime($row['lock_until'])) {
    $wait = strtotime($row['lock_until']) - time();
    http_response_code(423);
    throw new Exception("บัญชีล็อกชั่วคราว กรุณารออีก {$wait} วินาที");
  }

  // 5) ตรวจสอบ OTP ถูกหรือไม่
  if ($row['otp'] !== $otp) {
    $newAttempts = $row['attempts'] + 1;

    if ($newAttempts >= MAX_ATTEMPTS) {
      // เกิน limit → ล็อกบัญชี 10 นาที + รีเซ็ต counter
      $lockUntil = date('Y-m-d H:i:s', time() + LOCK_DURATION);
      $pdo->prepare(
        'UPDATE user_otp 
            SET attempts   = 0,
                lock_until = ?
          WHERE email = ?'
      )->execute([$lockUntil, $email]);

      http_response_code(429);
      throw new Exception('กรอก OTP ผิดเกินกำหนด บัญชีล็อก 10 นาที');
    } else {
      // ยังไม่ถึง limit → เพิ่ม counter
      $pdo->prepare(
        'UPDATE user_otp 
            SET attempts = ?
          WHERE email = ?'
      )->execute([$newAttempts, $email]);

      $left = MAX_ATTEMPTS - $newAttempts;
      http_response_code(401);
      throw new Exception("OTP ไม่ถูกต้อง (เหลือ {$left} ครั้ง)");
    }
  }

  // 6) ตรวจวันหมดอายุ
  if (time() > strtotime($row['otp_expires_at'])) {
    // ลบทันที เพราะหมดอายุ
    $pdo->prepare('DELETE FROM user_otp WHERE email = ?')
        ->execute([$email]);

    http_response_code(410);
    throw new Exception('OTP หมดอายุแล้ว กรุณาขอใหม่');
  }

  // 7) รหัสถูกต้อง → รีเซ็ต attempts & lock แต่ **ยังไม่ลบ record**
  $pdo->prepare(
    'UPDATE user_otp 
        SET attempts   = 0,
            lock_until = NULL
      WHERE email = ?'
  )->execute([$email]);

  // 8) สร้าง session ไว้ใช้ใน new_password.php
  $_SESSION['verified_email'] = $email;
  $_SESSION['verified_at']    = time();

  // 9) ตอบกลับสำเร็จ
  echo json_encode([
    'success' => true,
    'message' => 'OTP ถูกต้อง'
  ]);
  exit;

} catch (Exception $e) {
  // Log error
  error_log('verify_otp error: ' . $e->getMessage());

  // กำหนด HTTP status code ให้เหมาะสม
  $code = http_response_code();
  if ($code < 400 || $code >= 600) {
    http_response_code(500);
  }

  // ตอบกลับ failure
  echo json_encode([
    'success' => false,
    'message' => $e->getMessage()
  ]);
  exit;
}
