<?php
// logout.php — ทำลาย session เมื่อถูกเรียกจาก Flutter

session_start();
$_SESSION = [];
session_destroy();

echo json_encode(['success' => true]);
