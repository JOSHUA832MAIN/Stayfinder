<?php
session_start();
include_once("../connectiondatabase/main_connection.php");

$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['user_email']) && isset($_POST['user_password'])) {
    $email = trim($_POST['user_email']);
    $password = $_POST['user_password'];

    if (empty($email) || empty($password)) {
        $error_message = "Please fill in all fields.";
    } else {
        $stmt = $conn->prepare("SELECT user_id, user_fullname, user_password FROM ownerregister WHERE user_email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['user_password'])) {
                // Use a cookie to remember device after OTP verification
                if (!isset($_COOKIE['owner_device_verified']) || $_COOKIE['owner_device_verified'] !== '1') {
                    // New device: ask user to confirm email first (two-step flow)
                    $_SESSION['pending_owner_id'] = $user['user_id'];
                    $_SESSION['pending_owner_name'] = $user['user_fullname'];
                    $_SESSION['pending_owner_email'] = $email;
                    $_SESSION['pending_owner_otp'] = null;
                    $_SESSION['pending_owner_otp_time'] = null;

                    // Show confirm-email modal where user confirms email before OTP is sent
                    $show_confirm_modal = true;
                } else {
                    // Device already verified, proceed to login
                    $_SESSION['owner_logged_in'] = true;
                    $_SESSION['owner_id'] = $user['user_id'];
                    $_SESSION['owner_name'] = $user['user_fullname'];
                    echo "<script>alert('Login successful! Welcome.'); window.location.href='../baordinghouseOWNER/createboardinghouse.php';</script>";
                    exit();
                }
            } else {
                $error_message = "Invalid email or password.";
            }
        } else {
            $error_message = "Invalid email or password.";
        }
        $stmt->close();
    }
}

// Handle request to send OTP after user confirms email
if (isset($_POST['send_otp'])) {
    // Ensure pending email exists
    if (isset($_SESSION['pending_owner_id'])) {
        // prefer the email confirmed by user, otherwise fall back to session
        $to = isset($_POST['confirm_email']) && filter_var($_POST['confirm_email'], FILTER_VALIDATE_EMAIL)
            ? trim($_POST['confirm_email'])
            : (isset($_SESSION['pending_owner_email']) ? $_SESSION['pending_owner_email'] : null);

        if ($to) {
            $otp = rand(100000, 999999);
            $_SESSION['pending_owner_otp'] = $otp;
            $_SESSION['pending_owner_otp_time'] = time();

            // update session email to one user confirmed
            $_SESSION['pending_owner_email'] = $to;

            // Send OTP email
        $subject = "Your Stay Finder Owner Login OTP";
        $message = "Your OTP code for Stay Finder Owner Login is: <b>$otp</b><br>This code is valid for 10 minutes.";
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: Stay Finder <no-reply@stayfinder.com>" . "\r\n";
        mail($to, $subject, $message, $headers);

            // Show OTP modal
            $show_otp_modal = true;
        } else {
            $error_message = "Please provide a valid email to send the OTP.";
            $show_confirm_modal = true;
        }
    } else {
        $error_message = "Unable to send OTP. Please try logging in again.";
    }
}

// Handle OTP submission
if (isset($_POST['otp_code'])) {
    $entered_otp = trim($_POST['otp_code']);
    if (
        isset($_SESSION['pending_owner_otp']) &&
        isset($_SESSION['pending_owner_otp_time']) &&
        time() - $_SESSION['pending_owner_otp_time'] <= 600 && // 10 min
        $entered_otp == $_SESSION['pending_owner_otp']
    ) {
        // OTP correct, mark device as verified by setting a cookie
        $user_id = $_SESSION['pending_owner_id'];
        setcookie('owner_device_verified', '1', time() + (86400 * 365), "/");

        $_SESSION['owner_logged_in'] = true;
        $_SESSION['owner_id'] = $user_id;
        $_SESSION['owner_name'] = $_SESSION['pending_owner_name'];

        // Clear OTP session
        unset($_SESSION['pending_owner_id'], $_SESSION['pending_owner_name'], $_SESSION['pending_owner_email'], $_SESSION['pending_owner_otp'], $_SESSION['pending_owner_otp_time']);

        echo "<script>alert('Device verified! Login successful.'); window.location.href='../baordinghouseOWNER/createboardinghouse.php';</script>";
        exit();
    } else {
        $error_message = "Invalid or expired OTP. Please try again.";
        $show_otp_modal = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Stay Finder - Owner Login</title>
      <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
html, body {
    height: 100%;
    margin: 0;
    padding: 0;
}

body {
    background-color: #f7f7f7;
    font-family: 'Segoe UI', sans-serif;
    display: flex;
    flex-direction: column;
}

body > :not(.footer) {
    flex: 1;
}

.footer {
    margin-top: auto;
}

.logo-container {
    text-align: center;
    margin: 20px 0 0 0;
}

.logo {
    width: 310px;
    height: 310px;
    object-fit: cover;
    border-radius: 15px;
}

.container {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    margin-top: -70px;
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
}

.btn-primary:hover {
    background-color: #e0a800;
    color: #fff;
}

.btn-outline-secondary {
    border: 2px solid #ffc107;
    color: #ffc107;
    font-weight: 600;
    border-radius: 10px;
}

.btn-outline-secondary:hover {
    background-color: #ffc107;
    color: white;
}

.position-relative .btn-outline-secondary {
    padding: 0.25rem 0.5rem;
    font-size: 0.8rem;
    color: #6c757d;
    border-color: #6c757d;
}

.position-relative .btn-outline-secondary:hover {
    background-color: #6c757d;
    color: white;
}

.error-text {
    color: red;
    font-size: 0.9em;
    display: <?= !empty($error_message) ? 'block' : 'none' ?>;
    margin-bottom: 10px;
}

.back-button {
    position: absolute;
    top: 15px;
    left: 15px;
    /* Updated for size and thickness consistency */
    width: 50px; 
    height: 50px;
    background: #ffc107;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 28px; /* Base size for icon container */
    font-weight: bold;
    color: #000;
    transition: all 0.3s ease;
    z-index: 10;
    box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3); /* Added shadow for consistency */
}

.back-button:hover {
    background: #ffed4e;
    transform: scale(1.12); /* Increased scale on hover */
    color: #000;
    box-shadow: 0 6px 16px rgba(255, 193, 7, 0.4); /* Added stronger shadow on hover */
}

/* ADDED: Specific style to make the Font Awesome arrow thick */
.back-button i.fa-arrow-left {
    font-size: 24px; /* Size adjustment */
    font-weight: 900; /* Ensure maximum thickness */
}
</style>
</head>
<body>

<div class="logo-container">
    <img src="/img/rt" alt="StayFinder Logo" class="logo">
</div>


<div class="container">
<div class="card">
        <a href="../index.php" class="back-button">
                <i class="fas fa-arrow-left"></i>
        </a>

        <h2 class="form-heading">Owner Login</h2>

        <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger">
                ⚠️ <?= htmlspecialchars($error_message) ?>
        </div>
        <?php endif; ?>

        <form method="post">
                <div class="mb-3">
                        <input type="email" name="user_email" class="form-control" placeholder="Email Address" 
                        value="<?= isset($_POST['user_email']) ? htmlspecialchars($_POST['user_email']) : '' ?>" required>
                </div>

                <div class="mb-3 position-relative">
                        <input type="password" id="password" name="user_password" class="form-control" placeholder="Password" required>
                        <button type="button" class="btn btn-outline-secondary position-absolute top-50 end-0 translate-middle-y me-2" onclick="togglePassword()">
                                <i id="eyeIcon" class="fas fa-eye"></i>
                        </button>
                </div>

                <button type="submit" class="btn btn-primary w-100 mb-3">Login</button>

                <div class="text-center mb-2">
                    Don't have an account? <a href="registerowner.php" class="fw-bold">Register here</a>
                </div>

                <div class="text-center mb-3">
                    <a href="ownerforgotpas.php" class="fw-bold">🔒 Forgot Password?</a>
                </div>
        </form>
</div>
</div>

<div class="modal fade" id="otpModal" tabindex="-1" aria-labelledby="otpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title" id="otpModalLabel">Device Verification</h5>
                </div>
                <div class="modal-body">
                    <p>An OTP code has been sent to your email. Please enter it below to verify this device.</p>
                    <div class="mb-3">
                        <input type="text" name="otp_code" class="form-control" placeholder="Enter OTP" maxlength="6" required autofocus>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary w-100">Verify</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="confirmEmailModal" tabindex="-1" aria-labelledby="confirmEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmEmailModalLabel">Confirm Email</h5>
                </div>
                <div class="modal-body">
                    <p>Please confirm the email where you'd like to receive the OTP.</p>
                    <div class="mb-3">
                        <input type="email" name="confirm_email" class="form-control" placeholder="Email Address" 
                        value="<?= isset($_SESSION['pending_owner_email']) ? htmlspecialchars($_SESSION['pending_owner_email']) : '' ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="send_otp" class="btn btn-primary w-100">Send OTP</button>
                </div>
            </form>
        </div>
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
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if ((isset($show_confirm_modal) && $show_confirm_modal) || (isset($show_otp_modal) && $show_otp_modal)): ?>
<script>
    window.onload = function() {
        <?php if (isset($show_confirm_modal) && $show_confirm_modal): ?>
            var confirmModal = new bootstrap.Modal(document.getElementById('confirmEmailModal'));
            confirmModal.show();
        <?php endif; ?>

        <?php if (isset($show_otp_modal) && $show_otp_modal): ?>
            var otpModal = new bootstrap.Modal(document.getElementById('otpModal'));
            otpModal.show();
        <?php endif; ?>
    };
</script>
<?php endif; ?>

<footer class="footer" style="background-color:#111 !important; background-image:none !important; color:#fff !important; padding:8px 0; margin-top:20px; text-align:center; width:100vw; margin-left:calc(-50vw + 50%); z-index:800;">
    <div class="container-fluid" style="width:100%; padding-left:15px; padding-right:15px;">
        <p style="margin:6px 0;font-size:13px;">StayFinder: Boarding Locator and Management System</p>
        <div class="footer-links" style="margin-top:4px;">
            <a href="../terms.php" target="_blank" rel="noopener" style="color:yellow !important; text-decoration:none !important; font-weight:600;">Terms &amp; Conditions</a>
        </div>
    </div>
</footer>
</body>
</html>