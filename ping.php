<?php
require_once __DIR__.'/inc/json.php';
session_start();
$userId = $_SESSION['user_id'] ?? 0;

if ($userId <= 0) {
    jsonOutput(['valid' => false]);
}

jsonOutput(['valid' => true, 'user_id' => $userId]);
