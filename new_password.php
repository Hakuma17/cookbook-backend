<?php
// new_password.php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

// 1) POST เท่านั้น
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit(json_encode(['success'=>false,'message'=>'Method not allowed']));
}

// 2) รับค่า & sanitize
$email       = sanitize($_POST['email']        ?? '');
$otp         = sanitize($_POST['otp']          ?? '');
-$newPassword = sanitize($_POST['new_password'] ?? '');
-$confirmPass = sanitize($_POST['confirm_pass']  ?? '');
+$newPassword = sanitize($_POST['new_password'] ?? '');

// 3) เช็คข้อมูลครบถ้วน (ไม่ต้องเช็ค confirm_pass อีก)
if (!$email || !$otp || !$newPassword) {
  http_response_code(400);
  exit(json_encode(['success'=>false,'message'=>'กรุณากรอกข้อมูลให้ครบถ้วน']));
}

// 4) OTP ต้องตรงกันใน user_otp และยังไม่หมดอายุ
$stmt = $pdo->prepare(
  'SELECT otp, otp_expires_at
     FROM user_otp
    WHERE email = ?
    LIMIT 1'
);
$stmt->execute([$email]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (
     ! $row
  || $row['otp'] !== $otp
  || strtotime($row['otp_expires_at']) < time()
) {
  http_response_code(401);
  exit(json_encode(['success'=>false,'message'=>'OTP ไม่ถูกต้องหรือหมดอายุ']));
}

// 5) (ไม่ต้องเช็ค confirm_pass เพราะ UI ตรวจไปแล้ว)

// 6) แฮชและอัปเดตตาราง user
$hash = password_hash($newPassword, PASSWORD_ARGON2ID);
$upd  = $pdo->prepare('UPDATE `user` SET password = ? WHERE email = ?');
$ok   = $upd->execute([$hash, $email]);

if (! $ok) {
  http_response_code(500);
  exit(json_encode(['success'=>false,'message'=>'อัปเดตข้อมูลล้มเหลว']));
}

// 7) ลบแถว OTP ทิ้ง เพื่อป้องกันใช้งานซ้ำ
$pdo->prepare('DELETE FROM user_otp WHERE email = ?')->execute([$email]);

// 8) ดึงข้อมูล profile_name, path_imgProfile กลับมาให้ UI บันทึก
$userStmt = $pdo->prepare(
  'SELECT profile_name, path_imgProfile FROM `user` WHERE email = ? LIMIT 1'
);
$userStmt->execute([$email]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// 9) คืนผลลัพธ์สำเร็จ พร้อม data
echo json_encode([
  'success' => true,
  'message' => 'เปลี่ยนรหัสผ่านสำเร็จ',
  'data'    => [
    'profile_name'    => $user['profile_name']    ?? '',
    'path_imgProfile' => $user['path_imgProfile'] ?? '',
  ],
]);
exit;
