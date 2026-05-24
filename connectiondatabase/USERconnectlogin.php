<?php

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'main_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // <CHANGE> Removed Google OAuth login - only email/password login
    $email = $_POST['email'];
    $pass = $_POST['password'];

    // Use prepared statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT fullname, email, password, phone, profile_img FROM registerusers WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();
        $fullname = $row['fullname'];
        $email = $row['email'];
        $phone = $row['phone'];
        $profile_img = $row['profile_img'];
        $hashed_password = $row['password'];

        if (password_verify($pass, $hashed_password)) {
            // Store all user data in session
            $_SESSION['name'] = $fullname;
            $_SESSION['email'] = $email;
            $_SESSION['phone'] = $phone;
            $_SESSION['profile_img'] = $profile_img;
            
            header("Location: ../dashboardprofile.php");
            exit;
        } else {
            echo "<script>alert('Incorrect email or password'); window.location.href='../auseregisterlogform/login.php';</script>";
        }
    } else {
        echo "<script>alert('Incorrect email or password'); window.location.href='../auseregisterlogform/login.php';</script>";
    }
    
    $stmt->close();
} else {
    header("Location: ../auseregisterlogform/login.php");
    exit;
}

?>