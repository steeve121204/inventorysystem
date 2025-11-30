<?php
session_start();
include "db.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = mysqli_real_escape_string($conn, $_POST["username"] ?? "");
    $email = mysqli_real_escape_string($conn, $_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    function checkLogin($conn, $table, $username, $email, $password, $role, $redirect) {
        $sql = "SELECT * FROM $table WHERE username='$username' AND email='$email' LIMIT 1";
        $res = mysqli_query($conn, $sql);
        
        if (mysqli_num_rows($res) == 1) {
            $row = mysqli_fetch_assoc($res);
            if (password_verify($password, $row["password"])) {
                $_SESSION["user_id"] = $row["id"];  
                $_SESSION["username"] = $row["username"];
                $_SESSION["contact"] = $row["contact"] ?? '';
                $_SESSION["role"] = $role;
                $_SESSION["email"] = $email;
                
                header("Location: $redirect");
                exit();
            }
        }
        return false;
    }

    // FIX: Check ADMIN table FIRST, then users table
    if (checkLogin($conn, "admin", $username, $email, $password, "admin", "admin_dashboard.php")) {
        // Admin login successful - redirected in function
    } else if (checkLogin($conn, "users", $username, $email, $password, "user", "users_dashboard.php")) {
        // User login successful - redirected in function
    } else {
        $message = "Incorrect username, email or password!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login - Inventory System</title>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: "Poppins", sans-serif;
    }

    body {
        background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.6)), 
                    url('https://images.unsplash.com/photo-1581094794329-c6dbb6c8d0d3?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=2070&q=80');
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        background-attachment: fixed;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        padding: 20px;
    }

    .container {
        display: flex;
        gap: 20px;
        align-items: center;
        max-width: 500px;
        width: 100%;
    }

    .homepage-btn {
        background: rgba(255, 255, 255, 0.95);
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: all 0.3s ease;
    }

    .homepage-btn:hover {
        transform: translateY(-3px) scale(1.05);
        box-shadow: 0 6px 20px rgba(0,0,0,0.4);
        background: rgba(255, 255, 255, 1);
    }

    .homepage-btn span {
        font-size: 24px;
        color: #4f46e5;
    }

    .form-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        width: 420px;
        max-width: 95vw;
        padding: 30px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        border: 1px solid rgba(255, 255, 255, 0.2);
        animation: fadeInUp 0.3s ease-out;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .form-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .form-header h2 {
        text-align: center;
        color: #090909;
        font-weight: 600;
        font-size: 1.4em;
        margin: 0;
        flex: 1;
    }

    .back-homepage-btn {
        background: rgba(255, 255, 255, 0.9);
        color: #4f46e5;
        border: none;
        padding: 8px 16px;
        border-radius: 20px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 500;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 5px;
        white-space: nowrap;
    }

    .back-homepage-btn:hover {
        background: rgba(255, 255, 255, 1);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        color: #4338ca;
    }

    .back-homepage-btn span {
        font-size: 14px;
    }

    label {
        font-size: 12px;
        color: #000000;
        font-weight: 550;
        display: block;
        margin-bottom: 5px;
    }

    input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        margin-bottom: 15px;
        font-size: 14px;
        outline: none;
        height: 42px;
        background: #fff;
        transition: border-color 0.2s ease;
    }

    .password-container {
        position: relative;
        margin-bottom: 15px;
    }

    .password-container input {
        padding-right: 40px;
        margin-bottom: 0;
    }

    .toggle-password {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        cursor: pointer;
        font-size: 16px;
        color: #6b7280;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0;
    }

    .toggle-password:hover {
        color: #374151;
    }

    input:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    }

    button[type="submit"] {
        width: 100%;
        padding: 12px;
        border: none;
        background: #16a34a;
        color: white;
        font-size: 14px;
        border-radius: 6px;
        cursor: pointer;
        margin-top: 10px;
        font-weight: 500;
        height: 42px;
        transition: background-color 0.2s ease;
    }

    button[type="submit"]:hover {
        background: #15803d;
    }

    .login-bar {
        margin-top: 20px;
        padding: 12px;
        text-align: center;
        border-radius: 6px;
        background: #f3f4f6;
    }

    .login-bar p {
        margin: 0;
        font-size: 13px;
        color: #374151;
    }

    .login-bar a {
        color: #dc2626;
        font-weight: 600;
        text-decoration: none;
    }

    .login-bar a:hover {
        text-decoration: underline;
    }

    .message {
        margin-top: 15px;
        text-align: center;
        color: #dc2626;
        font-weight: 500;
        font-size: 13px;
        padding: 8px;
        background: #fef2f2;
        border-radius: 4px;
        border: 1px solid #fecaca;
    }

    @media (max-width: 768px) {
        .container {
            flex-direction: column-reverse;
            gap: 15px;
            max-width: 400px;
        }
        
        .form-container {
            width: 100%;
            padding: 25px 20px;
        }
        
        .homepage-btn {
            align-self: flex-end;
            width: 50px;
            height: 50px;
            margin-right: 10px;
        }
        
        .homepage-btn span {
            font-size: 20px;
        }
        
        .form-header {
            flex-direction: column;
            gap: 12px;
            align-items: flex-start;
        }
        
        .back-homepage-btn {
            align-self: flex-end;
        }
    }

    @media (max-width: 480px) {
        body {
            padding: 15px;
            align-items: flex-start;
            padding-top: 40px;
        }
        
        .container {
            gap: 12px;
        }
        
        .form-container {
            padding: 25px 20px;
        }
        
        .form-header h2 {
            font-size: 1.3em;
            margin-bottom: 15px;
        }
        
        .back-homepage-btn {
            padding: 6px 12px;
            font-size: 11px;
        }
    }

    @media (max-width: 320px) {
        .form-container {
            padding: 20px 15px;
        }
        
        .homepage-btn {
            width: 45px;
            height: 45px;
        }
        
        .homepage-btn span {
            font-size: 18px;
        }
        
        input, button[type="submit"] {
            height: 38px;
        }
    }
    </style>
</head>
<body>

<div class="container">
    <a href="index.php" class="homepage-btn">
        <span>🏠</span>
    </a>

    <div class="form-container">
        <div class="form-header">
            <h2>Login to Your Account</h2>
            <a href="index.php" class="back-homepage-btn">
                <span>←</span> Back Homepage
            </a>
        </div>

        <form action="" method="POST">
            <label>Username</label>
            <input type="text" name="username" placeholder="Enter your username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">

            <label>Email Address</label>
            <input type="email" name="email" placeholder="Enter your email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">

            <label>Password</label>
            <div class="password-container">
                <input type="password" name="password" placeholder="Enter your password" required>
                <button type="button" class="toggle-password">👁️‍🗨️</button>
            </div>

            <button type="submit">Login</button>
        </form>

        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="login-bar">
            <p>
                Don't have an account? 
                <a href="register_account.php">Register</a>
            </p>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    // Add password toggle functionality
    const toggleButtons = document.querySelectorAll('.toggle-password');
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const input = this.previousElementSibling;
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
            this.textContent = type === 'password' ? '👁️‍🗨️' : '👁️‍🗨️';
        });
    });
    
    // Focus on first input field
    const firstInput = document.querySelector('input[name="username"]');
    if (firstInput) {
        firstInput.focus();
    }
});
</script>

</body>
</html> 