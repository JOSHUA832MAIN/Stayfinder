
<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include_once("../connectiondatabase/main_connection.php");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

// ✅ FIXED .ENV LOADER - THIS WILL WORK
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        die("❌ ERROR: .env file not found at: $filePath<br>Current directory: " . __DIR__);
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            $value = trim($value, '"\'');
            
            // Set as environment variable
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

$envPaths = [
    __DIR__ . '/../../.env',           // Two levels up (outside public_html)
    __DIR__ . '/../../../.env',        // Three levels up
    $_SERVER['DOCUMENT_ROOT'] . '/../.env',  // Server root parent
];

$envLoaded = false;
foreach ($envPaths as $path) {
    if (file_exists($path)) {
        loadEnv($path);
        $envLoaded = true;
        break;
    }
}

if (!$envLoaded) {
    die("❌ ERROR: .env file not found in any expected location. Checked paths: " . implode(', ', $envPaths));
}

// ✅ HELPER FUNCTION TO GET ENV VARIABLES
function env($key, $default = null) {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// ✅ Handle OTP Verification - REDIRECT TO CREATEBOARDINGHOUSE.PHP
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_otp'])) {
    $entered_otp = trim($_POST['otp_code']);
    $stored_otp = $_SESSION['otp_code'];
    $otp_expiry = $_SESSION['otp_expiry'];
    
    if (time() > $otp_expiry) {
        echo json_encode(['success' => false, 'message' => 'OTP has expired. Please register again.']);
        exit();
    }
    
    if ($entered_otp == $stored_otp) {
        // OTP is correct, proceed with registration
        $fullname = $_SESSION['reg_fullname'];
        $address_full = $_SESSION['reg_address'];
        $contact = $_SESSION['reg_contact'];
        $email = $_SESSION['reg_email'];
        $profilePath = $_SESSION['reg_profile'];
        $hashedPassword = $_SESSION['reg_password'];
        $status = "approved";
        
        $stmt = $conn->prepare("INSERT INTO ownerregister 
            (user_fullname, user_address, user_contact, user_email, user_profile, user_password, user_status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssssss", $fullname, $address_full, $contact, $email, $profilePath, $hashedPassword, $status);
        
        if ($stmt->execute()) {
            // ✅ GET THE NEW USER ID
            $new_owner_id = $conn->insert_id;
            
            // ✅ SET SESSION VARIABLES - MATCHING CREATEBOARDINGHOUSE.PHP
            $_SESSION['owner_logged_in'] = true;
            $_SESSION['owner_id'] = $new_owner_id;
            $_SESSION['owner_name'] = $fullname;
            $_SESSION['owner_email'] = $email;
            
            // Clear OTP session data
            unset($_SESSION['otp_code']);
            unset($_SESSION['otp_expiry']);
            unset($_SESSION['reg_fullname']);
            unset($_SESSION['reg_address']);
            unset($_SESSION['reg_contact']);
            unset($_SESSION['reg_email']);
            unset($_SESSION['reg_profile']);
            unset($_SESSION['reg_password']);
            
            // ✅ REDIRECT TO CREATEBOARDINGHOUSE.PHP
            echo json_encode([
                'success' => true, 
                'message' => 'Registration successful!',
                'redirect' => '../baordinghouseOWNER/createboardinghouse.php'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
        }
        
        $stmt->close();
        $conn->close();
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Please try again.']);
        exit();
    }
}

// ✅ Handle Registration Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['verify_otp'])) {
    $fullname     = trim($_POST['user_fullname']);
    $contact      = trim($_POST['user_contact']);
    $email        = trim($_POST['user_email']);
    $address_full = ""; // Removed province field - address is now empty

    if (empty($fullname) || empty($contact) || empty($email) || empty($_POST['user_password'])) {
        echo "<script>alert('Please fill in all required fields.'); window.history.back();</script>";
        exit();
    }

    // ✅ Check for duplicate EMAIL
    $checkEmail = $conn->prepare("SELECT 1 FROM ownerregister WHERE user_email = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $resultEmail = $checkEmail->get_result();
    if ($resultEmail->num_rows > 0) {
        echo "<script>alert('❌ Email already registered. Please use a different email.'); window.history.back();</script>";
        exit();
    }
    $checkEmail->close();

    // ✅ Check for duplicate PHONE NUMBER
    $checkContact = $conn->prepare("SELECT 1 FROM ownerregister WHERE user_contact = ?");
    $checkContact->bind_param("s", $contact);
    $checkContact->execute();
    $resultContact = $checkContact->get_result();
    if ($resultContact->num_rows > 0) {
        echo "<script>alert('❌ Phone number already registered. Please use a different phone number.'); window.history.back();</script>";
        exit();
    }
    $checkContact->close();

    // ✅ Handle profile picture upload
    $profilePath = "";
    if (isset($_FILES['user_profile']) && $_FILES['user_profile']['error'] === 0) {
        $profileDir = "uploads/profile_images/";
        if (!is_dir($profileDir)) mkdir($profileDir, 0777, true);
        $profileExt = strtolower(pathinfo($_FILES['user_profile']['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png'];
        if (!in_array($profileExt, $allowedExts)) {
            echo "<script>alert('❌ Profile image must be JPG or PNG only.'); window.history.back();</script>";
            exit();
        }
        $profilePath = $profileDir . "profile_" . time() . "_" . uniqid() . "." . $profileExt;
        move_uploaded_file($_FILES['user_profile']['tmp_name'], $profilePath);
    }

    // ✅ Generate OTP
    $otp = sprintf("%06d", mt_rand(0, 999999));
    $raw_password = $_POST['user_password'];
    
    // ✅ Store registration data in session
    $_SESSION['otp_code'] = $otp;
    $_SESSION['otp_expiry'] = time() + 600;
    $_SESSION['reg_fullname'] = $fullname;
    $_SESSION['reg_address'] = $address_full;
    $_SESSION['reg_contact'] = $contact;
    $_SESSION['reg_email'] = $email;
    $_SESSION['reg_profile'] = $profilePath;
    $_SESSION['reg_password'] = password_hash($raw_password, PASSWORD_DEFAULT);
    
    // ✅ Send OTP via email - USING ENV VARIABLES
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = env('SMTP_HOST', 'smtp.gmail.com');
        $mail->SMTPAuth   = true;
        $mail->Username   = env('SMTP_USERNAME');
        $mail->Password   = env('SMTP_PASSWORD');
        $mail->SMTPSecure = 'tls';
        $mail->Port       = env('SMTP_PORT', 587);
        
        $mail->setFrom(env('SMTP_FROM_EMAIL'), env('SMTP_FROM_NAME', 'StayFinder System'));
        $mail->addAddress($email, $fullname);
        
        $mail->isHTML(true);
        $mail->Subject = 'StayFinder - Email Verification Code';
        $mail->Body    = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; }
                .container { background-color: #ffffff; padding: 30px; border-radius: 10px; max-width: 600px; margin: 0 auto; }
                .header { text-align: center; color: #ffc107; font-size: 24px; font-weight: bold; margin-bottom: 20px; }
                .otp-code { background-color: #ffc107; color: #fff; font-size: 32px; font-weight: bold; text-align: center; padding: 15px; border-radius: 8px; letter-spacing: 5px; margin: 20px 0; }
                .message { color: #333; line-height: 1.6; text-align: center; }
                .footer { text-align: center; color: #999; font-size: 12px; margin-top: 30px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>StayFinder Email Verification</div>
                <div class='message'>
                    <p>Hello <strong>$fullname</strong>,</p>
                    <p>Thank you for registering with StayFinder! Please use the verification code below to complete your registration:</p>
                </div>
                <div class='otp-code'>$otp</div>
                <div class='message'>
                    <p>This code will expire in 10 minutes.</p>
                    <p>If you did not request this code, please ignore this email.</p>
                </div>
                <div class='footer'>
                    <p>&copy; 2025 StayFinder. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->send();
        $_SESSION['show_otp_modal'] = true;
        $_SESSION['otp_email'] = $email;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        echo "<script>alert('Failed to send verification email.\\n\\n PLS CHECK YOUR EMAIL: {$mail->ErrorInfo}\\n\\n CHECK YOUR EMAIL FORMAT INPUT'); window.history.back();</script>";
        exit();
    }
    
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Stay Finder - Register Owner</title>
    <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
body {
    background-color: #f7f7f7;
    font-family: 'Segoe UI', sans-serif;
}
.container {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    margin-top: -40px;
    min-height: auto;
}
.card {
    background: white;
    border: none;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    padding: 2.5rem;
    width: 450px;
    max-width: 90%;
    position: relative;
}
.logo-container {
    text-align: center;
    margin: 20px 0 0 0;
}
.logo {
    width: 370px;
    height: 370px;
    object-fit: cover;
    border-radius: 15px;
}
.form-heading {
    font-size: 2rem;
    font-weight: 500;
    margin-bottom: 2rem;
    text-align: center;
}
.form-control {
    border-radius: 10px;
    padding: 0.75rem 1.25rem;
    background-color: #f2f2f2;
    border: 1px solid #e0e0e0;
}
.form-control::placeholder {
    color: #999;
}
.btn-primary {
    background-color: #ffc107;
    border: none;
    font-weight: 600;
    color: #fff;
    padding: 0.75rem 0;
    border-radius: 10px;
    margin-bottom: 15px;
}
.btn-primary:hover {
    background-color: #e0a800;
    color: #fff;
}
.back-button {
    position: absolute;
    top: 15px;
    left: 15px;
    /* SET SIZE TO MATCH intendeduser (5).php */
    width: 50px;
    height: 50px;
    background: #ffc107;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    /* SET BASE FONT SIZE TO MATCH intendeduser (5).php */
    font-size: 28px; 
    font-weight: bold;
    color: #000;
    transition: all 0.3s ease;
    z-index: 10;
    /* Added box shadow for consistency */
    box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
}
.back-button:hover {
    background: #ffed4e;
    /* Increased scale to match intendeduser (5).php */
    transform: scale(1.12); 
    color: #000;
}
/* ADDED: Specific style to make the Font Awesome arrow thick (matches intendeduser (5).php) */
.back-button i.fa-arrow-left {
    font-size: 24px; /* Size adjustment */
    font-weight: 900; /* Ensure maximum thickness */
}


.label-upload {
    font-size: 0.9rem;
    font-weight: 500;
    color: #555;
    margin-top: 5px;
    text-align: center;
    display: block;
}
.notice-text {
    font-size: 0.85rem;
    color: #777;
    text-align: center;
    margin-top: 4px;
}
#profilePreview img {
    display: block;
    margin: 0 auto;
}
.strength {
    font-size: 12px;
    margin-top: 5px;
    margin-bottom: 10px;
}

.otp-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.7);
    justify-content: center;
    align-items: center;
    z-index: 9999;
}
.otp-modal-content {
    background: white;
    border-radius: 20px;
    padding: 2.5rem;
    width: 400px;
    max-width: 90%;
    box-shadow: 0 10px 50px rgba(0, 0, 0, 0.3);
    text-align: center;
}
.otp-modal-content h3 {
    color: #333;
    margin-bottom: 1rem;
    font-size: 1.5rem;
}
.otp-modal-content p {
    color: #666;
    margin-bottom: 1.5rem;
}
.otp-input {
    width: 100%;
    padding: 1rem;
    font-size: 1.5rem;
    text-align: center;
    border: 2px solid #ffc107;
    border-radius: 10px;
    letter-spacing: 10px;
    margin-bottom: 1rem;
}
.otp-input:focus {
    outline: none;
    border-color: #e0a800;
}
.btn-verify {
    background-color: #ffc107;
    border: none;
    font-weight: 600;
    color: #fff;
    padding: 0.75rem 2rem;
    border-radius: 10px;
    font-size: 1rem;
    cursor: pointer;
    width: 100%;
}
.btn-verify:hover {
    background-color: #e0a800;
}
.error-message {
    color: #dc3545;
    font-size: 0.9rem;
    margin-top: 0.5rem;
    display: none;
}
.password-container {
    position: relative;
}
.password-toggle {
    position: absolute;
    right: 15px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #6c757d;
    cursor: pointer;
    padding: 5px;
    font-size: 16px;
    border-radius: 4px;
    transition: all 0.3s ease;
}
.password-toggle:hover {
    background: #f0f0f0;
}
</style>
</head>
<body>

<div class="logo-container">
    <img src="/img/rt" alt="StayFinder Logo" class="logo">
</div>

<div class="container">
<div class="card">
    <a href="javascript:history.back()" class="back-button">
        <i class="fas fa-arrow-left"></i> 
    </a>

    <h2 class="form-heading">Sign up as Owner</h2>

    <form method="post" enctype="multipart/form-data" id="registrationForm">

        <div class="mb-3">
            <input type="file" name="user_profile" class="form-control" accept="image/jpeg,image/png" required onchange="previewProfile(this)">
            <span class="label-upload">Profile Upload</span>
            <span class="notice-text">(Only JPEG or PNG allowed)</span>
            <div id="profilePreview" class="mt-2"></div>
        </div>

        <div class="mb-3">
            <input type="text" name="user_fullname" class="form-control" placeholder="Fullname" required>
        </div>

        <div class="mb-3">
            <input type="email" name="user_email" class="form-control" placeholder="Email" required>
        </div>

        <div class="mb-3">
            <input type="text" name="user_contact" class="form-control" placeholder="Contact Number" required 
            oninput="this.value = this.value.replace(/[^0-9]/g, '');" maxlength="11">
        </div>

        <div class="mb-3">
            <div class="password-container">
                <input type="password" id="password" name="user_password" class="form-control" placeholder="Password" required>
                <button type="button" class="password-toggle" onclick="togglePassword()">
                    <i id="eyeIcon" class="fas fa-eye"></i>
                </button>
            </div>
            <div id="strengthMessage" class="strength"></div>
        </div>

        <button type="submit" class="btn btn-primary w-100">Register</button>

        <div class="text-center mb-3">
            Already have an account? <a href="loginowner.php" class="fw-bold" style="color: #ffd700;">Login here</a>
        </div>
    </form>

</div>
</div>

<div id="otpModal" class="otp-modal">
    <div class="otp-modal-content">
        <h3><i class="fas fa-envelope-open-text" style="color: #ffc107;"></i> Email Verification</h3>
        <p>Please enter the 6-digit code sent to your email</p>
        <input type="text" id="otpInput" class="otp-input" maxlength="6" placeholder="000000" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
        <div class="error-message" id="errorMessage">Incorrect OTP. Please try again.</div>
        <button class="btn-verify" onclick="verifyOTP()">Verify & Register</button>
    </div>
</div>

<script>
function togglePassword() {
    const pwd = document.getElementById("password");
    const eyeIcon = document.getElementById("eyeIcon");
    
    if (pwd.type === "password") {
        pwd.type = "text";
        eyeIcon.classList.remove("fa-eye");
        eyeIcon.classList.add("fa-eye-slash");
    } else {
        pwd.type = "password";
        eyeIcon.classList.remove("fa-eye-slash");
        eyeIcon.classList.add("fa-eye");
    }
}

function previewProfile(input) {
    const preview = document.getElementById('profilePreview');
    preview.innerHTML = '';
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.style.width = '120px';
            img.style.height = '120px';
            img.style.objectFit = 'cover';
            img.style.borderRadius = '50%';
            img.style.border = '3px solid #ffc107';
            preview.appendChild(img);
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function verifyOTP() {
    const otpInput = document.getElementById('otpInput').value;
    const errorMessage = document.getElementById('errorMessage');
    
    if (otpInput.length !== 6) {
        errorMessage.textContent = 'Please enter a 6-digit code.';
        errorMessage.style.display = 'block';
        return;
    }
    
    const formData = new FormData();
    formData.append('verify_otp', '1');
    formData.append('otp_code', otpInput);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message + ' Redirecting to create your boarding house...');
            window.location.href = data.redirect;
        } else {
            errorMessage.textContent = data.message;
            errorMessage.style.display = 'block';
            document.getElementById('otpInput').value = '';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        errorMessage.textContent = 'An error occurred. Please try again.';
        errorMessage.style.display = 'block';
    });
}

// ✅ PASSWORD VALIDATION - 8 CHARACTERS & 1 SPECIAL CHARACTER
document.getElementById("registrationForm").addEventListener("submit", function(e) {
    const password = document.getElementById("password").value;
    if (password.length < 8) {
        e.preventDefault();
        alert("❌ Password must be at least 8 characters long!");
        document.getElementById("password").focus();
        return false;
    }
    if (!password.match(/[^a-zA-Z0-9]/)) {
        e.preventDefault();
        alert("❌ Password must contain at least one special character!");
        document.getElementById("password").focus();
        return false;
    }
});

// ✅ PASSWORD STRENGTH INDICATOR (improved scoring and thresholds)
document.getElementById("password").addEventListener("input", function () {
    const strengthMessage = document.getElementById("strengthMessage");
    const val = this.value || "";
    const length = val.length;
    const hasLower = /[a-z]/.test(val);
    const hasUpper = /[A-Z]/.test(val);
    const hasDigit = /[0-9]/.test(val);
    const hasSpecial = /[^a-zA-Z0-9]/.test(val);

    // Quick blacklist for very common weak passwords (explicitly mark Weak)
    const commonBad = ["password","123456","12345678","qwerty","abc123","111111","123123","iloveyou"];
    if (commonBad.includes(val.toLowerCase())) {
        strengthMessage.textContent = "Weak Password";
        strengthMessage.style.color = "#dc3545";
        return;
    }

    // Length score: gives more weight for longer passwords
    let lengthScore = 0;
    if (length >= 8 && length <= 10) lengthScore = 1;
    else if (length >= 11 && length <= 14) lengthScore = 2;
    else if (length >= 15) lengthScore = 3;

    // Character variety score
    let variety = 0;
    if (hasLower) variety++;
    if (hasUpper) variety++;
    if (hasDigit) variety++;
    if (hasSpecial) variety += 2; // special chars get extra weight

    const score = lengthScore + variety; // max possible ~7

    if (length === 0) {
        strengthMessage.textContent = "";
    } else if (length < 8) {
        strengthMessage.textContent = "⚠️ Password must be at least 8 characters";
        strengthMessage.style.color = "#dc3545";
    } else if (!hasSpecial) {
        strengthMessage.textContent = "⚠️ Add at least one special character";
        strengthMessage.style.color = "#dc3545";
    } else if (score <= 2) {
        strengthMessage.textContent = "Weak Password";
        strengthMessage.style.color = "#dc3545";
    } else if (score <= 4) {
        strengthMessage.textContent = "Medium Strength";
        strengthMessage.style.color = "#fd7e14";
    } else {
        strengthMessage.textContent = "Strong Password";
        strengthMessage.style.color = "#198754";
    }
});

<?php if (isset($_SESSION['show_otp_modal']) && $_SESSION['show_otp_modal'] === true): ?>
    document.addEventListener('DOMContentLoaded', function() {
        alert('A 6-digit verification code has been sent to your email: <?php echo $_SESSION['otp_email']; ?>');
        document.getElementById('otpModal').style.display = 'flex';
        document.getElementById('otpInput').focus();
    });
    <?php 
        unset($_SESSION['show_otp_modal']); 
        unset($_SESSION['otp_email']);
    ?>
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function() {
    const otpInput = document.getElementById('otpInput');
    if (otpInput) {
        otpInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                verifyOTP();
            }
        });
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<footer class="footer" style="background-color:#111 !important; background-image:none !important; color:#fff !important; padding:8px 0; margin-top:20px; text-align:center; width:100%; margin-left:0; z-index:800;">
    <div class="container-fluid" style="width:100%; padding-left:15px; padding-right:15px;">
        <p style="margin:6px 0;font-size:13px;">StayFinder: Boarding Locator and Management System</p>
        <div class="footer-links" style="margin-top:4px;">
            <a href="../terms.php" target="_blank" rel="noopener" style="color:yellow !important; text-decoration:none !important; font-weight:600;">Terms &amp; Conditions</a>
        </div>
    </div>
</footer>
</body>
</html>
