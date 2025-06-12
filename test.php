<?php
echo "เวลาปัจจุบันของ PHP: " . date("c");
echo "<br>";
echo "เวลาปัจจุบันของ MySQL: " . date("c", strtotime($pdo->query("SELECT NOW()")->fetchColumn()));