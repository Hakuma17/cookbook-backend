<?php
require_once __DIR__.'/inc/config.php';
require_once __DIR__.'/inc/db.php';
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$list = [];
if ($q !== '') {
    $list = dbAll(
        "SELECT name FROM recipe
          WHERE name LIKE ? ORDER BY name LIMIT 10",
        ["%$q%"]
    );
}
echo json_encode(array_column($list, 'name'), JSON_UNESCAPED_UNICODE);
