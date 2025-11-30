<?php
session_start();

// Debug information
error_log("Logout process started");
error_log("Session data before destroy: " . print_r($_SESSION, true));

// Destroy all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Check if redirect parameter is set
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';

// Debug the redirect
error_log("Redirecting to: " . $redirect);

// Redirect to the specified page
header("Location: " . $redirect);
exit();
?>