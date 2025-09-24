<?php // inc/db.php – ฟังก์ชัน wrapper สำหรับ PDO ให้เรียกสั้นและสม่ำเสมอ

// ถ้ายังไม่ได้ประกาศฟังก์ชัน pdo() (อาจยังไม่ include functions.php) → include เพิ่ม
if (!function_exists('pdo')) {
    require_once __DIR__ . '/functions.php';
}

// ======================= dbRow =======================
// คืน 1 แถวแรกเป็น associative array หรือ null ถ้าไม่พบ
if (!function_exists('dbRow')) {
    function dbRow(string $sql, array $params = [], int $mode = PDO::FETCH_ASSOC): ?array {
        $stmt = pdo()->prepare($sql);   // เตรียมคำสั่ง (ป้องกัน SQL Injection ผ่าน parameterized)
        $stmt->execute($params);        // ใส่ค่า $params
        $row = $stmt->fetch($mode);     // ดึง 1 แถว
        return $row === false ? null : $row; // ถ้าไม่มี → null
    }
}

// ======================= dbAll =======================
// คืน array ของทุกแถว
if (!function_exists('dbAll')) {
    function dbAll(string $sql, array $params = [], int $mode = PDO::FETCH_ASSOC): array {
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll($mode);  // อาจได้ [] ถ้าไม่มีข้อมูล
    }
}

// ======================= dbOne =======================
// ดึงแถวเดียว (เหมือน dbRow แต่ไม่ cast null → ใช้ตามเดิม)
if (!function_exists('dbOne')) {
    function dbOne(string $sql, array $params = [], int $mode = PDO::FETCH_ASSOC) {
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch($mode);     // false ถ้าไม่พบ
    }
}

// ======================= dbVal =======================
// คืนค่าคอลัมน์แรกของแถวแรก – เหมาะกับ SELECT COUNT(*) หรือ SUM()
if (!function_exists('dbVal')) {
    function dbVal(string $sql, array $params = []) {
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();    // false ถ้าไม่มี – ผู้เรียกควรแปลง (int) เอง
    }
}

// ======================= dbExec =======================
// ใช้กับ INSERT / UPDATE / DELETE คืนจำนวนแถวที่ได้รับผลกระทบ
if (!function_exists('dbExec')) {
    function dbExec(string $sql, array $params = []): int {
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();       // นับจำนวนแถวที่มีผล
    }
}

// *** EOF ***
