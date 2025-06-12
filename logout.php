<?php
// ทำลาย session แล้ว redirect ไป login.php

session_start();
$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;
