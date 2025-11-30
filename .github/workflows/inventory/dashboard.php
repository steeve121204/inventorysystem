<?php
session_start();
include "db.php";


if (!isset($_SESSION['email']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];
$email = $_SESSION['email'];


if ($role === "admin") {
    header("Location: admin_dashboard.php");
    exit();
} elseif ($role === "user") {
    header("Location: users_dashboard.php");
    exit();
} else {
    header("Location: login.php");
    exit();
}
?>