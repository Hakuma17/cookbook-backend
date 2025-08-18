<?php
require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';

$basePath = realpath(__DIR__ . '/../uploads/ingredients'); // ปรับ path ตามโครงจริง
if (!$basePath) die("uploads/ingredients not found\n");

// อ่านรายการทั้งหมดจาก DB
$rows = dbAll("SELECT ingredient_id, image_url FROM ingredients ORDER BY ingredient_id");
$exts = ['.jpg','.jpeg','.png','.webp','.JPG','.JPEG','.PNG','.WEBP'];
$updated = 0; $missing = 0;

foreach ($rows as $r) {
    $id  = (int)$r['ingredient_id'];

    // ชื่อมาตรฐานที่เราคาดหวัง
    $baseName = "ingredients_{$id}";

    // ถ้ามีตามที่ DB ระบุและไฟล์อยู่จริง ข้ามได้เลย
    $raw = trim((string)$r['image_url']);
    $rawFile = basename(parse_url(str_replace('\\','/', $raw), PHP_URL_PATH));
    if ($rawFile !== '') {
        if (is_file("$basePath/$rawFile")) continue;
    }

    // หาไฟล์จริงในโฟลเดอร์ตาม baseName + extensions
    $found = null;
    foreach ($exts as $ext) {
        $try = $baseName . $ext;
        if (is_file("$basePath/$try")) { $found = $try; break; }
    }

    if ($found) {
        $newUrl = "uploads/ingredients/" . $found;
        dbExec("UPDATE ingredients SET image_url = ? WHERE ingredient_id = ?", [$newUrl, $id]);
        $updated++;
        echo "FIXED #$id -> $newUrl\n";
    } else {
        $missing++;
        echo "MISSING file for #$id (DB='$raw')\n";
    }
}

echo "Done. Updated=$updated, Missing=$missing\n";
