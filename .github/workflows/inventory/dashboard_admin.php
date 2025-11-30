<?php
session_start();
if (!isset($_SESSION["role"]) || $_SESSION["role"] != "admin") {
    header("Location: login_account.php");
    exit;
}
?>

<h2>Admin Dashboard</h2>
<p>Welcome, <?= $_SESSION["username"] ?>!</p>