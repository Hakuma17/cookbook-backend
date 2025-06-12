<?php

// หน้า Protected หลังล็อกอิน – ต้องเรียก requireLogin()

require_once __DIR__ . '/inc/functions.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>Dashboard</title>
</head>
<body>
  <h2>ยินดีต้อนรับ, <?= htmlspecialchars($_SESSION['user_email'], ENT_QUOTES, 'UTF-8') ?></h2>
  <p>นี่คือหน้าหลักหลังล็อกอิน</p>
  <p><a href="logout.php">ออกจากระบบ</a></p>
</body>
</html>
