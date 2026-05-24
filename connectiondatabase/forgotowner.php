<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'main_connection.php'; // DB connection
require __DIR__ . '/../vendor/autoload.php';

$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);

    // 1️⃣ Check if email exists in Owner table (fixed: removed user_username column)
    $stmt = $conn->prepare("SELECT user_id FROM ownerregister WHERE user_email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        // 2️⃣ Generate 6-digit reset code
        $code = rand(100000, 999999);

        // 3️⃣ Save reset info in session
        $_SESSION['reset_email']   = $email;
        $_SESSION['reset_code']    = $code;
        $_SESSION['reset_expires'] = time() + 600; // 10 minutes expiry

        // 4️⃣ Send reset code via Email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['SMTP_USERNAME'];
            $mail->Password   = $_ENV['SMTP_PASSWORD'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;

            $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME']);
            $mail->addAddress($email);

            $mail->isHTML(true);
            $mail->Subject = 'StayFinder Owner Password Reset Code';
            $mail->Body    = "<p>Your 6-digit password reset code is: <b>{$code}</b></p><p>Valid for 10 minutes only.</p>";
            $mail->AltBody = "Your 6-digit password reset code is: {$code} (Valid for 10 minutes)";

            $mail->send();

            // 5️⃣ Redirect to verification page
            header("Location: ../owneregister/veryonwer.php");
            exit();

        } catch (Exception $e) {
            echo "<script>alert('❌ Error sending email: {$mail->ErrorInfo}'); window.location.href='../owneregister/ownerforgotpas.php';</script>";
        }

    } else {
        echo "<script>alert('❌ This email is not registered as an Owner.'); window.location.href='../owneregister/ownerforgotpas.php';</script>";
    }

    $stmt->close();
    $conn->close();
}
?>