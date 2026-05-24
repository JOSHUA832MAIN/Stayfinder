<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include_once('main_connection.php');
require '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$envPath = dirname(__DIR__, 2) . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        putenv(trim($key) . '=' . trim($value));
    }
}

if (
    isset($_POST['name'], $_POST['email'], $_POST['password'], $_POST['number']) &&
    isset($_FILES['profile_picture']) &&
    $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK &&
    !isset($_POST['otp_submit'])
) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $plainPassword = $_POST['password'];
    $phone = mysqli_real_escape_string($conn, $_POST['number']);

    if (strlen($plainPassword) < 8) {
        echo "<script>alert('❌ Password must be at least 8 characters long.'); window.location.href='../auseregisterlogform/register.php';</script>";
        exit;
    }

    $password = password_hash($plainPassword, PASSWORD_DEFAULT);

    $targetDir = "../profile_picture/";
    if (!file_exists($targetDir)) mkdir($targetDir, 0777, true);
    $fileName = time() . "_" . preg_replace("/[^A-Za-z0-9\.\-_]/", '', basename($_FILES["profile_picture"]["name"]));
    $targetFilePath = $targetDir . $fileName;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $_FILES["profile_picture"]["tmp_name"]);
    finfo_close($finfo);

    if (strpos($mimeType, 'image/') !== 0) {
        echo "<script>alert('❌ Please upload a valid image file.'); window.location.href='../auseregisterlogform/register.php';</script>";
        exit;
    }

    if ($_FILES["profile_picture"]["size"] > 5000000) {
        echo "<script>alert('File too large (max 5MB).'); window.location.href='../auseregisterlogform/register.php';</script>";
        exit;
    }

    if (!move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $targetFilePath)) {
        echo "<script>alert('Failed to upload profile picture.'); window.location.href='../auseregisterlogform/register.php';</script>";
        exit;
    }

    $result = mysqli_query($conn, "SELECT * FROM registerusers WHERE email = '$email'");
    if (mysqli_num_rows($result) > 0) {
        echo "<script>alert('❌ Email already registered. Please use a different email.'); window.location.href='../auseregisterlogform/register.php';</script>";
        exit;
    }

    $phoneCheck = mysqli_query($conn, "SELECT * FROM registerusers WHERE phone = '$phone'");
    if (mysqli_num_rows($phoneCheck) > 0) {
        echo "<script>alert('❌ Phone number already registered. Please use a different phone number.'); window.location.href='../auseregisterlogform/register.php';</script>";
        exit;
    }

    $otp = rand(100000, 999999);

    $_SESSION['pending_user'] = [
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'phone' => $phone,
        'profile_img' => "profile_picture/" . $fileName,
        'otp' => $otp
    ];

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USERNAME');
        $mail->Password   = getenv('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = getenv('SMTP_PORT') ?: 587;

        $mail->setFrom(getenv('SMTP_FROM_EMAIL'), getenv('SMTP_FROM_NAME'));
        $mail->addAddress($email, $name);
        $mail->Subject = "Your StayFinder OTP Code";
        $mail->Body = "Hello $name,\n\nYour StayFinder verification code is: $otp\n\nEnter this code to complete your registration.";

        $mail->send();

        echo "<script>
            alert('✅ We sent a 6-digit code to your email. Please enter it to confirm.');
            window.location.href='../auseregisterlogform/register.php?show_otp=1';
        </script>";
    } catch (Exception $e) {
        echo "<script>alert('Failed to send OTP. Try again.'); window.location.href='../auseregisterlogform/register.php';</script>";
    }
    exit;
}

if (isset($_POST['otp_submit'], $_POST['otp_code'])) {
    $enteredOtp = $_POST['otp_code'];

    if (isset($_SESSION['pending_user']) && $_SESSION['pending_user']['otp'] == $enteredOtp) {
        $u = $_SESSION['pending_user'];
        $sql = "INSERT INTO registerusers (fullname, email, password, phone, profile_img)
                VALUES ('{$u['name']}', '{$u['email']}', '{$u['password']}', '{$u['phone']}', '{$u['profile_img']}')";

        if (mysqli_query($conn, $sql)) {
            $_SESSION['name'] = $u['name'];
            $_SESSION['email'] = $u['email'];
            $_SESSION['phone'] = $u['phone'];
            $_SESSION['profile_img'] = $u['profile_img'];

            unset($_SESSION['pending_user']);

echo "<script>alert('✅ Registration successful!'); window.location.href = '../seekerdashboard.php';</script>";
        } else {
            echo "Error: " . mysqli_error($conn);
        }
    } else {
        echo "<script>alert('❌ Incorrect code. Try again.'); window.location.href='../auseregisterlogform/register.php?show_otp=1&error=1';</script>";
    }
    exit;
}

?>
