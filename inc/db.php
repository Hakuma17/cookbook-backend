<?php
// inc/db.php

// ถ้ายังไม่ได้ include functions.php → include เลย
if (!function_exists('pdo')) {
    require_once __DIR__ . '/functions.php';
}

// ───────────── PDO wrapper functions ─────────────

// ★ เพิ่ม: dbRow() คืน 1 row แบบ associative หรือ null (เพื่อให้ไฟล์อื่นเรียกได้)
if (!function_exists('dbRow')) {
    function dbRow(string $sql, array $params = [], int $mode = PDO::FETCH_ASSOC): ?array
    {
        $stmt = pdo()->prepare($sql); // ★ ใช้ pdo() ไม่อิง $pdo global
        $stmt->execute($params);
        $row = $stmt->fetch($mode);
        return $row === false ? null : $row;
    }
}

if (!function_exists('dbAll')) {
    function dbAll(string $sql, array $params = [], int $mode = PDO::FETCH_ASSOC): array
    {
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll($mode);
    }
}

if (!function_exists('dbOne')) {
    function dbOne(string $sql, array $params = [], int $mode = PDO::FETCH_ASSOC)
    {
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch($mode);
    }
}

if (!function_exists('dbVal')) {
    function dbVal(string $sql, array $params = [])
    {
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}

if (!function_exists('dbExec')) {
    /**
     * ใช้กับคำสั่งที่ไม่ต้องการผลลัพธ์ (INSERT / UPDATE / DELETE)
     * คืนจำนวนแถวที่ถูกกระทบ
     */
    function dbExec(string $sql, array $params = []): int
    {
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}

?>
