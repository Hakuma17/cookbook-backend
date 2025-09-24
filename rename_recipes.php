<?php
// rename_recipes.php
// รันด้วย: php rename_recipes.php (CLI เท่านั้น)

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

// 1) โหลดการตั้งค่า DB และฟังก์ชันเสริม
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

// 2) กำหนดโฟลเดอร์รูปเมนู
$dir = __DIR__ . '/uploads/recipes';
if (!is_dir($dir)) {
    echo "โฟลเดอร์ไม่ถูกต้อง: {$dir}\n";
    exit(1);
}

// 3) ดึง mapping ชื่อเมนู (ภาษาไทย) → recipe_id
try {
    $stmt = $pdo->query("SELECT recipe_id, name FROM recipe");
    $mapping = [];
    while ($row = $stmt->fetch()) {
        // ชื่อใน DB ควรตรงกับชื่อไฟล์ (basename) ก่อน .jpg/.png
        $mapping[ $row['name'] ] = $row['recipe_id'];
    }
} catch (Exception $e) {
    echo "Error ดึงข้อมูลจาก DB: " . $e->getMessage() . "\n";
    exit(1);
}

// 4) สแกนไฟล์ทั้งหมดในโฟลเดอร์
$files = scandir($dir);
foreach ($files as $file) {
    // ข้าม . และ ..
    if ($file === '.' || $file === '..') continue;

    $ext      = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $basename = pathinfo($file, PATHINFO_FILENAME);  // ชื่อไม่รวม .jpg/.png

    // สนับสนุน jpg, jpeg, png เท่านั้น
    if (!in_array($ext, ['jpg','jpeg','png'], true)) {
        echo "[SKIP] ไม่สนับสนุนไฟล์ “{$file}”\n";
        continue;
    }

    // ถ้า mapping มี key ตรงกับ basename (ชื่อภาษาไทย)
    if (isset($mapping[$basename])) {
        $id    = $mapping[$basename];
        $new   = "recipe_{$id}.{$ext}";
        $oldFP = "{$dir}/{$file}";
        $newFP = "{$dir}/{$new}";

        if (file_exists($newFP)) {
            echo "[WARN] “{$new}” มีอยู่แล้ว — ข้าม\n";
            continue;
        }

        if (rename($oldFP, $newFP)) {
            echo "[OK] เปลี่ยน “{$file}” → “{$new}”\n";
        } else {
            echo "[ERROR] ไม่สามารถเปลี่ยนชื่อ “{$file}”\n";
        }
    } else {
        echo "[SKIP] ไม่พบ mapping สำหรับ “{$basename}”\n";
    }
}

echo "เสร็จสิ้นการรีเนมไฟล์\n";
