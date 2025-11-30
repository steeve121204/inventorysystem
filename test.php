<?php
echo "1. Script started<br>";

error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "2. Error reporting set<br>";

session_start();
echo "3. Session started<br>";

echo "4. Session role: " . ($_SESSION["role"] ?? 'NOT SET') . "<br>";

include "db.php";
echo "5. Database included<br>";

echo "6. Database connection: " . ($conn ? "SUCCESS" : "FAILED") . "<br>";

echo "7. Test complete!";
?>