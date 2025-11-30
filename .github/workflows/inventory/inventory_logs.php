<?php
session_start();
if (!isset($_SESSION["role"]) || $_SESSION["role"] != "admin") {
    header("Location: login_account.php");
    exit;
}
include "db.php";

// Add your inventory logs management code here
?>