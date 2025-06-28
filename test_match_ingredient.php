<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';

header('Content-Type: text/plain; charset=UTF-8');

// ─── กำหนดวัตถุดิบที่ต้องมี และห้ามมี ───
$includeIds = [263]; // เช่น: 263 = ข่า
$excludeIds = [717]; // เช่น: 717 = ไข่

try {
    $pdo = pdo();

    // ─── 1) ค้นหา recipe_id ที่มี includeIds ครบทุกตัว ───
    $inStr = implode(',', array_fill(0, count($includeIds), '?'));
    $sql = "
        SELECT recipe_id
        FROM recipe_ingredient
        WHERE ingredient_id IN ($inStr)
        GROUP BY recipe_id
        HAVING COUNT(DISTINCT ingredient_id) = ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$includeIds, count($includeIds)]);
    $includeRecipeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$includeRecipeIds) {
        echo "❌ ไม่พบสูตรที่มีวัตถุดิบครบตาม include_ids\n";
        exit;
    }

    // ─── 2) กรองสูตรที่ไม่มี excludeIds ───
    $exStr = implode(',', array_fill(0, count($excludeIds), '?'));
    $inStr = implode(',', array_fill(0, count($includeRecipeIds), '?'));
    $sql = "
        SELECT DISTINCT recipe_id
        FROM recipe_ingredient
        WHERE recipe_id IN ($inStr)
        AND ingredient_id IN ($exStr)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$includeRecipeIds, ...$excludeIds]);
    $badRecipeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $finalRecipeIds = array_diff($includeRecipeIds, $badRecipeIds);
    if (!$finalRecipeIds) {
        echo "❌ ไม่พบสูตรที่ผ่านเงื่อนไข include + exclude\n";
        exit;
    }

    // ─── 3) แสดงชื่อเมนูที่ได้ ───
    $inStr = implode(',', array_fill(0, count($finalRecipeIds), '?'));
    $sql = "SELECT recipe_id, name FROM recipes WHERE recipe_id IN ($inStr)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($finalRecipeIds);
    $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "✅ พบสูตรทั้งหมด " . count($recipes) . " รายการ:\n";
    foreach ($recipes as $r) {
        echo "- ID: {$r['recipe_id']} → {$r['name']}\n";
    }

} catch (Throwable $e) {
    echo "เกิดข้อผิดพลาด: " . $e->getMessage();
}
