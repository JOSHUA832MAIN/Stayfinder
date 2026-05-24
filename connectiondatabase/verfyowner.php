<?php
session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $enteredCode = $_POST['code'];

    // Check if session has reset_code and expiry
    if (!isset($_SESSION['reset_code']) || !isset($_SESSION['reset_expires'])) {
        echo "<script>alert('Session expired. Please try again.'); window.location.href='../connectiondatabase/forgotowner.php';</script>";
        exit;
    }

    // Expired code check
    if (time() > $_SESSION['reset_expires']) {
        echo "<script>alert('❌ Code expired. Request a new one.'); window.location.href='../connectiondatabase/forgotowner.php';</script>";
        exit;
    }

    // Verify code
    if ($enteredCode == $_SESSION['reset_code']) {
        header("Location: owneresetpass.php"); // 👈 this will be your owner reset password page
        exit();
    } else {
        echo "<script>alert('❌ Invalid code.'); window.location.href='../owneregister/veryonwer.php';</script>";
    }
}
?>
