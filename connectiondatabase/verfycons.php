<?php
session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $enteredCode = $_POST['code'];

    if (!isset($_SESSION['reset_code']) || !isset($_SESSION['reset_expires'])) {
        echo "<script>alert('Session expired. Please try again.'); window.location.href='../auseregisterlogform/forgot-password.php';</script>";
        exit;
    }

    if (time() > $_SESSION['reset_expires']) {
        echo "<script>alert('❌ Code expired. Request a new one.'); window.location.href='../auseregisterlogform/forgot-password.php';</script>";
        exit;
    }

    if ($enteredCode == $_SESSION['reset_code']) {
        header("Location: reset_password.php");
        exit();
    } else {
        echo "<script>alert('❌ Invalid code.'); window.location.href='../auseregisterlogform/verify_code.php';</script>";
    }
}
