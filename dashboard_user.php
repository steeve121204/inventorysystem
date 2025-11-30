<?php
session_start();
if (!isset($_SESSION["role"]) || $_SESSION["role"] != "user") {
    header("Location: login_account.php");
    exit;
}
?>

<h2>User Dashboard</h2>
<p>Welcome, <?= $_SESSION["username"] ?>!</p>