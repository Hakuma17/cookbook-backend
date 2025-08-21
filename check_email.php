<?php
require_once __DIR__.'/inc/functions.php';
require_once __DIR__.'/inc/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  jsonOutput(['success'=>false,'errors'=>['Method not allowed']],405);
}
$email = trim(sanitize($_POST['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  jsonOutput(['success'=>false,'errors'=>['รูปแบบอีเมลไม่ถูกต้อง']],400);
}
$exists = (bool) dbVal("SELECT 1 FROM user WHERE email = ? LIMIT 1", [$email]);
jsonOutput([
  'success' => true,
  'exists'  => $exists,
  'message' => $exists ? 'อีเมลนี้มีอยู่แล้ว' : 'อีเมลนี้ใช้ได้',
], 200);
