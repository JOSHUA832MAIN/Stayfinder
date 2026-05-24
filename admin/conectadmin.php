<?php
session_start();

// Correct path to the database connection file
require_once '../connectiondatabase/main_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // Secure prepared statement
    $stmt = $conn->prepare("SELECT id, username, password, full_name FROM admin_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();
        
        // Plain password match (use password_hash in production)
        if ($password === $row['password']) {
            $_SESSION['admin_id'] = $row['id'];
            $_SESSION['admin_username'] = $row['username'];
            $_SESSION['admin_name'] = $row['full_name'];
            $_SESSION['user_type'] = 'admin';

            header("Location: dashadmin.php");
            exit;
        } else {
            echo "<script>
                alert('Incorrect username or password.');
                window.location.href = './adminlog.php';
            </script>";
        }
    } else {
        echo "<script>
            alert('Incorrect username or password.');
            window.location.href = './adminlog.php';
        </script>";
    }

    $stmt->close();
} else {
    // Redirect if not a POST request
    header("Location: adminlog.php");
    exit;
}
?>