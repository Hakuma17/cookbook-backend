<?php
// update_cart.php

header('Content-Type: application/json; charset=UTF-8');
session_start();

// ตรวจสอบว่า user ล็อกอินแล้วหรือยัง
if (empty($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'กรุณาเข้าสู่ระบบก่อน',
    ]);
    exit;
}

$user_id   = $_SESSION['user_id'];
$recipe_id = isset($_POST['recipe_id']) ? (int)$_POST['recipe_id'] : 0;
$count     = isset($_POST['count'])     ? (float)$_POST['count'] : 0.0;

if ($recipe_id <= 0 || $count <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'ข้อมูลไม่ถูกต้อง',
    ]);
    exit;
}

// เชื่อมต่อฐานข้อมูล (ปรับพารามิเตอร์ให้ตรงกับ inc/config.php ของคุณ)
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';        // หรือรหัสผ่านของคุณ
$dbName = 'cookbook';

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($conn->connect_errno) {
    echo json_encode([
        'success' => false,
        'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้',
    ]);
    exit;
}

// ป้องกัน SQL Injection
$user_id   = $conn->real_escape_string($user_id);
$recipe_id = $conn->real_escape_string($recipe_id);
$count     = $conn->real_escape_string($count);

// ตรวจสอบว่ามีเรคคอร์ดนี้อยู่แล้วหรือไม่
$sql = "SELECT 1 
        FROM cart 
        WHERE user_id = '$user_id' 
          AND recipe_id = '$recipe_id' 
        LIMIT 1";
$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    // ถ้ามีอยู่แล้ว → อัปเดต
    $upd = "UPDATE cart
            SET nServings = '$count'
            WHERE user_id   = '$user_id'
              AND recipe_id = '$recipe_id'";
    if ($conn->query($upd)) {
        echo json_encode([
            'success' => true,
            'message' => 'อัปเดตตะกร้าเรียบร้อย',
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'อัปเดตไม่สำเร็จ: ' . $conn->error,
        ]);
    }
} else {
    // ยังไม่มี → แทรกใหม่
    $ins = "INSERT INTO cart (user_id, recipe_id, nServings)
            VALUES ('$user_id', '$recipe_id', '$count')";
    if ($conn->query($ins)) {
        echo json_encode([
            'success' => true,
            'message' => 'เพิ่มสินค้าในตะกร้าเรียบร้อย',
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'ไม่สามารถเพิ่มสินค้าได้: ' . $conn->error,
        ]);
    }
}

$conn->close();
