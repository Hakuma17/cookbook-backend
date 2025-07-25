#!/usr/bin/env php
<?php
// rename_ingredients.php
// ใช้: php rename_ingredients.php   (หรือ chmod +x แล้ว ./rename_ingredients.php)

$dir = __DIR__ . '/uploads/ingredients';
if (!is_dir($dir)) {
    fwrite(STDERR, "❌ ไม่พบโฟลเดอร์ $dir\n");
    exit(1);
}

foreach (scandir($dir) as $file) {
    // หาชื่อ pattern ingredient_<เลข>
    if (!preg_match('/^ingredient_(\d+)(\.[a-z0-9]+)?$/i', $file, $m)) {
        continue;               // ไม่ตรง pattern → ข้าม
    }

    $num = (int)$m[1];
    if ($num < 1 || $num > 999) {         // *** เฉพาะ 001-999 ***
        echo "⏭️  ข้าม: ingredient_{$num} นอกช่วง 1-999\n";
        continue;
    }

    $newNum  = sprintf('%03d', $num);     // เติม 0 ให้ครบสามหลัก
    $newName = "ingredient_{$newNum}.png";
    $oldPath = "$dir/$file";
    $newPath = "$dir/$newName";

    if (realpath($oldPath) === realpath($newPath)) {
        continue;                         // ชื่อเดิมตรงแล้ว
    }
    if (file_exists($newPath)) {
        echo "⚠️  ข้าม: {$newName} มีอยู่แล้ว\n";
        continue;
    }

    if (rename($oldPath, $newPath)) {
        echo "✅ Rename: {$file} → {$newName}\n";
    } else {
        echo "❌ Error: เปลี่ยนชื่อ {$file} ไม่สำเร็จ\n";
    }
}
