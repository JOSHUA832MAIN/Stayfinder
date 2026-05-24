<?php
session_start();

// Correct path to your DB connection
require_once __DIR__ . "/main_connection.php";

// Redirect if reset_email session is not set
if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot-password-owner.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == "POST") {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Server-side validation: min length and match
    if (strlen($newPassword) < 8) {
        echo "<script>alert('❌ Password must be at least 8 characters long.');</script>";
    } elseif ($newPassword !== $confirmPassword) {
        echo "<script>alert('❌ Passwords do not match.');</script>";
    } else {
        // Hash the password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update the password in the ownerregister table
        $stmt = $conn->prepare("UPDATE ownerregister SET user_password = ? WHERE user_email = ?");
        $stmt->bind_param("ss", $hashedPassword, $_SESSION['reset_email']);

        if ($stmt->execute()) {
            // Clear session variables
            unset($_SESSION['reset_code']);
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_expires']);

           echo "<script>
        alert('✅ Password updated successfully.');
        window.location='/owneregister/loginowner.php';
      </script>";
exit;

        } else {
            echo "<script>alert('❌ Failed to update password.');</script>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Reset Password (Owner)</title>
  <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Make card a positioning context for the back button */
        .card { position: relative; }

        /* Yellow circular back button */
        .back-circle {
            position: absolute;
            top: 12px;
            left: 12px;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #ffd700; /* yellow */
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.12);
            border: 2px solid rgba(0,0,0,0.08);
        }
        .back-circle i { color: #000; font-size: 18px; line-height: 1; }

        /* Yellow submit button style to match UI */
        .btn-yellow {
            background: #ffd700;
            border-color: #d4a500;
            color: #111;
            font-weight: 700;
        }
        .btn-yellow:hover, .btn-yellow:focus {
            background: #ffcc33;
            color: #111;
        }
    </style>
</head>

<body class="bg-light d-flex align-items-center justify-content-center min-vh-100">
    <div class="card shadow-lg border-0 rounded-4 p-4" style="max-width: 400px; width: 100%;">
        <a href="javascript:history.back()" class="back-circle" aria-label="Go back">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
        </a>
        <h3 class="text-center mb-4">Reset Password </h3>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">New Password</label>
                <div class="input-group">
                    <input type="password" id="password" name="password" class="form-control" required aria-describedby="togglePassword1">
                    <span class="input-group-text" id="togglePassword1" style="cursor:pointer; background:transparent; border-left:0;">
                        <i class="fas fa-eye text-muted" id="pwIcon1" aria-hidden="true"></i>
                    </span>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <div class="input-group">
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required aria-describedby="togglePassword2">
                    <span class="input-group-text" id="togglePassword2" style="cursor:pointer; background:transparent; border-left:0;">
                        <i class="fas fa-eye text-muted" id="pwIcon2" aria-hidden="true"></i>
                    </span>
                </div>
                <div id="pwHelp" class="form-text text-muted">Password must be at least 8 characters.</div>
            </div>
            <button type="submit" id="resetBtn" class="btn btn-yellow w-100">Reset Password</button>
        </form>
    </div>
    <script>
        // Toggle password visibility
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (!input || !icon) return;
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        document.getElementById('togglePassword1').addEventListener('click', function(e){ togglePassword('password','pwIcon1'); });
        document.getElementById('togglePassword2').addEventListener('click', function(e){ togglePassword('confirm_password','pwIcon2'); });

        // Client-side validation: min 8 chars and match before submit
        document.querySelector('form').addEventListener('submit', function(e){
            const pw = document.getElementById('password').value || '';
            const cpw = document.getElementById('confirm_password').value || '';
            if (pw.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long.');
                return false;
            }
            if (pw !== cpw) {
                e.preventDefault();
                alert('Passwords do not match.');
                return false;
            }
            return true;
        });
    </script>
</body>

</html>