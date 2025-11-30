<?php
include "db.php";

$message = "";
$redirect = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (isset($_POST['ajax_check']) && in_array($_POST['ajax_check'], ['username','email'])) {
        $field = $_POST['ajax_check'];
        $value = mysqli_real_escape_string($conn, trim($_POST['value'] ?? ''));
        if ($field === 'username') {
            $sql_check = "SELECT 'users' as role FROM users WHERE LOWER(username)=LOWER('$value') UNION SELECT 'admin' as role FROM admin WHERE LOWER(username)=LOWER('$value') LIMIT 1";
        } else {
           
            $sql_check = "SELECT 'users' as role FROM users WHERE LOWER(email)=LOWER('$value') UNION SELECT 'admin' as role FROM admin WHERE LOWER(email)=LOWER('$value') LIMIT 1";
        }
        $res = mysqli_query($conn, $sql_check);
        $exists = ($res && mysqli_num_rows($res) > 0);
        header('Content-Type: application/json');
        echo json_encode(['exists' => $exists]);
        exit;
    }
    $role     = $_POST["role"] ?? "";
    $table    = $role === 'user' ? 'users' : 'admin';
    $username = mysqli_real_escape_string($conn, $_POST["username"] ?? "");
    $contact  = mysqli_real_escape_string($conn, $_POST["contact"] ?? "");
    $email    = mysqli_real_escape_string($conn, $_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";
    $captcha  = $_POST["captcha"] ?? "";
    $captcha_generated = $_POST["captcha_generated"] ?? "";

    if ($captcha !== $captcha_generated) {
        $message = "Captcha incorrect!";
    }

    elseif ($password !== $confirm_password) {
        $message = "Passwords do not match!";
    }

    elseif (!in_array($role, ["user", "admin"])) {
        $message = "Invalid role!";
    }

    elseif ($role === "admin" && !preg_match('/ADMIN$/', $password)) {
        $message = "Invalid PAssword!";
    } else {
        $password_hashed = password_hash($password, PASSWORD_DEFAULT);


        $username_l = mysqli_real_escape_string($conn, strtolower($username));
        $email_l = mysqli_real_escape_string($conn, strtolower($email));
        $check_sql = "SELECT 'users' as role FROM users WHERE LOWER(username)='$username_l' OR LOWER(email)='$email_l' UNION SELECT 'admin' as role FROM admin WHERE LOWER(username)='$username_l' OR LOWER(email)='$email_l' LIMIT 1";
        $check_result = mysqli_query($conn, $check_sql);

     
        if ($check_result === false) {
            $message = "Database error: " . mysqli_error($conn);
        } elseif (mysqli_num_rows($check_result) > 0) {
            $row = mysqli_fetch_assoc($check_result);
            $message = "Username or email already taken.";
        } else {
   
            $sql = "INSERT INTO $table (username, contact, email, password) 
                    VALUES ('$username', '$contact', '$email', '$password_hashed')";

            if (mysqli_query($conn, $sql)) {
                $message = "Registration successful! Redirecting to login...";
                $redirect = true;
            } else {
                $message = "Error: " . mysqli_error($conn);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Register Account</title>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: "Poppins", sans-serif;
}

body {
    background: #1e293b;
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
    background: rgba(169, 217, 231, 0.98);
    width: 420px;
    max-width: 95vw;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    border: 1px solid rgba(255, 255, 255, 0.3);
    position: relative;
}

.form-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.form-header h2 {
    color: #090909;
    font-weight: 600;
    font-size: 1.4em;
    margin: 0;
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

.form-row {
    display: flex;
    gap: 12px;
    margin-bottom: 15px;
}

.form-group {
    flex: 1;
    position: relative;
}

label {
    font-size: 12px;
    color: #000000ff;
    font-weight: 550;
    display: block;
    margin-bottom: 5px;
}

input, select {
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
    width: 100%;
    height: 42px;
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
    margin: 0;
}

.toggle-password:hover {
    color: #374151;
}

input:focus, select:focus {
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.captcha-section {
    margin: 20px 0;
}

.captcha-section label {
    margin-bottom: 8px;
}

.captcha-container {
    display: flex;
    align-items: center;
    gap: 10px;
}

#captchaCanvas {
    width: 100px;
    height: 35px;
    border-radius: 6px;
    border: 1px solid #d1d5db;
    background: #f9fafb;
}

.captcha-container input {
    flex: 1;
    margin-bottom: 0;
    padding: 8px 12px;
    height: 42px;
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
    transition: all 0.2s ease;
}

button[type="submit"]:hover {
    background: #15803d;
    transform: translateY(-1px);
}

.login-bar {
    margin-top: 20px;
    padding: 12px;
    text-align: center;
    border-radius: 6px;
    background: #f6f3f3ff;
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

.success {
    color: #059669;
    background: #f0fdf4;
    border-color: #bbf7d0;
}

/* Responsive Design */
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
    
    .form-row {
        gap: 10px;
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
        padding: 20px 15px;
    }
    
    .form-header h2 {
        font-size: 1.3em;
    }
    
    .back-homepage-btn {
        padding: 6px 12px;
        font-size: 11px;
    }
    
    .form-row {
        flex-direction: column;
        gap: 0;
    }
    
    .captcha-container {
        flex-direction: column;
        gap: 8px;
    }
    
    #captchaCanvas {
        width: 120px;
        height: 40px;
    }
    
    .captcha-container input {
        width: 100%;
    }
}

@media (max-width: 320px) {
    .form-container {
        padding: 15px 12px;
    }
    
    .homepage-btn {
        width: 45px;
        height: 45px;
    }
    
    .homepage-btn span {
        font-size: 18px;
    }
    
    input, select, button[type="submit"] {
        height: 38px;
        font-size: 13px;
    }
    
    .password-container input {
        height: 38px;
    }
}
</style>

<?php if ($redirect): ?>
<meta http-equiv="refresh" content="2; url=login.php">
<?php endif; ?>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const canvas = document.getElementById("captchaCanvas");
    if (canvas) generateCaptcha();
    
 
    const toggleButtons = document.querySelectorAll('.toggle-password');
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const input = this.previousElementSibling;
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
            this.textContent = type === 'password' ? '👁️‍🗨️' : '👁️‍🗨️';
        });
    });
    

    const firstInput = document.querySelector('input[name="contact"]');
    if (firstInput) {
        firstInput.focus();
    }
    
   
    const roleSelect = document.querySelector('select[name="role"]');
    const passwordInput = document.querySelector('input[name="password"]');
    
    if (roleSelect && passwordInput) {
        roleSelect.addEventListener('change', function() {
            if (this.value === 'admin') {
                passwordInput.placeholder = "Password";
            } else {
                passwordInput.placeholder = "Enter password";
            }
        });
    }

   
    const usernameInput = document.getElementById('registerUsername');
    const emailInput = document.getElementById('registerEmail');
    const usernameFeedback = document.getElementById('usernameFeedback');
    const emailFeedback = document.getElementById('emailFeedback');

    function checkAvailability(field, value) {
        const data = new URLSearchParams();
        data.append('ajax_check', field);
        data.append('value', value);
        return fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data.toString()
        }).then(r => r.json());
    }

    if (usernameInput) {
        usernameInput.addEventListener('blur', function() {
            const val = this.value.trim();
            if (!val) { usernameFeedback.style.display = 'none'; return; }
            checkAvailability('username', val).then(data => {
                if (data.exists) {
                    usernameFeedback.textContent = 'This username is already taken.';
                    usernameFeedback.style.display = 'block';
                } else {
                    usernameFeedback.style.display = 'none';
                }
            }).catch(() => {
               
            });
        });
    }

    if (emailInput) {
        emailInput.addEventListener('blur', function() {
            const val = this.value.trim();
            if (!val) { emailFeedback.style.display = 'none'; return; }
            checkAvailability('email', val).then(data => {
                if (data.exists) {
                    emailFeedback.textContent = 'This email address is already registered.';
                    emailFeedback.style.display = 'block';
                } else {
                    emailFeedback.style.display = 'none';
                }
            }).catch(() => {
               
            });
        });
    }

 
    const regForm = document.querySelector('form[action=""]');
    if (regForm) {
        regForm.addEventListener('submit', function(e) {
            const username = usernameInput ? usernameInput.value.trim() : '';
            const email = emailInput ? emailInput.value.trim() : '';
            if (!username && !email) return; 

            e.preventDefault();
            const promises = [];
            if (username) promises.push(checkAvailability('username', username));
            if (email) promises.push(checkAvailability('email', email));

            Promise.all(promises).then(results => {
                let blocked = false;
                results.forEach((res, idx) => {
                    if (idx === 0 && username && res.exists) {
                        usernameFeedback.textContent = 'This username is already taken.';
                        usernameFeedback.style.display = 'block';
                        blocked = true;
                    }
                    if (idx === (username ? 1 : 0) && email && res.exists) {
                        emailFeedback.textContent = 'This email address is already registered.';
                        emailFeedback.style.display = 'block';
                        blocked = true;
                    }
                });
                if (!blocked) {
                    regForm.submit();
                }
            }).catch(() => {
             
                regForm.submit();
            });
        });
    }
});

function generateCaptcha() {
    const chars = "ABCDEFGHJKLMNPQRSTUVWXYZ123456789";
    let captchaText = "";
    for (let i = 0; i < 5; i++) {
        captchaText += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById("captcha_generated").value = captchaText;

    const canvas = document.getElementById("captchaCanvas");
    const ctx = canvas.getContext("2d");
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
   
    ctx.fillStyle = "#f9fafb";
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    

    ctx.font = "bold 20px Arial";
    ctx.fillStyle = "#1f2937";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    
  
    for (let i = 0; i < captchaText.length; i++) {
        const x = 20 + i * 16;
        const y = 18 + Math.random() * 4 - 2;
        ctx.fillText(captchaText[i], x, y);
    }
    
 
    ctx.strokeStyle = "#e5e7eb";
    for (let i = 0; i < 10; i++) {
        ctx.beginPath();
        ctx.moveTo(Math.random() * canvas.width, Math.random() * canvas.height);
        ctx.lineTo(Math.random() * canvas.width, Math.random() * canvas.height);
        ctx.stroke();
    }
}
</script>
</head>

<body>

<div class="container">
    <div class="form-container">
        <div class="form-header">
            <h2>Create Account</h2>
            <a href="index.php" class="back-homepage-btn">
                <span>←</span> Back Homepage
            </a>
        </div>

        <form action="" method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" required>
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="text" name="contact" placeholder="09*********" required value="<?php echo htmlspecialchars($_POST['contact'] ?? ''); ?>">
                </div>
            </div>

            <label>Username</label>
            <input type="text" name="username" id="registerUsername" placeholder="Enter username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            <div id="usernameFeedback" class="form-help-text" style="display:none;color:#dc2626;margin-top: -8px;"></div>

            <label>Email Address</label>
            <input type="email" name="email" id="registerEmail" placeholder="your@email.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            <div id="emailFeedback" class="form-help-text" style="display:none;color:#dc2626;margin-top: -8px;"></div>

            <div class="form-row">
                <div class="form-group">
                    <label>Password</label>
                    <div class="password-container">
                        <input type="password" name="password" placeholder="Enter password" required>
                        <button type="button" class="toggle-password">👁️‍🗨️</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Confirm Password</label>
                    <div class="password-container">
                        <input type="password" name="confirm_password" placeholder="Confirm password" required>
                        <button type="button" class="toggle-password">👁️‍🗨️</button>
                    </div>
                </div>
            </div>

            <div class="captcha-section">
                <label>Enter CAPTCHA</label>
                <div class="captcha-container">
                    <canvas id="captchaCanvas" width="120" height="40"></canvas>
                    <input type="text" name="captcha" placeholder="Type the code" required maxlength="5">
                </div>
                <input type="hidden" id="captcha_generated" name="captcha_generated">
            </div>

            <button type="submit">Create Account</button>

            <div class="login-bar">
                <p>Already have an account? <a href="login.php">Login here</a></p>
            </div>
        </form>

        <p class="message <?= $redirect ? 'success' : '' ?>">
            <?= htmlspecialchars($message) ?>
        </p>
    </div>
</div>

</body>
</html> 