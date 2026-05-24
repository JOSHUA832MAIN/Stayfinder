<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include_once('main_connection.php');

if (isset($_POST['email'], $_POST['password']) && isset($_POST['login_btn'])) {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];

    $result = mysqli_query($conn, "SELECT * FROM registerusers WHERE email = '$email'");

    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);

        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['fullname'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['phone'] = $user['phone'];
            $_SESSION['profile_img'] = $user['profile_img'];

            echo "<script>alert('✅ Login successful!'); window.location.href = '../seekerdashboard.php';</script>";
            exit;
        } else {
            echo "<script>alert('SORRY INCORRECT EMAIL OR PASSWORD'); window.location.href='../auseregisterlogform/loginseeker.php';</script>";
            exit;
        }
    } else {
        echo "<script>alert('SORRY INCORRECT EMAIL OR PASSWORD'); window.location.href='../auseregisterlogform/loginseeker.php';</script>";
        exit;
    }
}
?>
