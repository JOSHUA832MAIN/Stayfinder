<?php
session_start();

require_once 'main_connection.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email_or_id = $_POST["email_or_id"];
    $input_password = $_POST["password"];
    $expected_house_id = isset($_POST["expected_house_id"]) ? (int)$_POST["expected_house_id"] : null;
    
    $loginSuccess = false;
    $redirectLocation = "";

    if (filter_var($email_or_id, FILTER_VALIDATE_EMAIL)) {
        // It's an email, check ownerregister table
        $query = "SELECT * FROM ownerregister WHERE user_email = ?";
        $stmt = mysqli_prepare($conn, $query);
        
        if ($stmt === false) {
            die("Error preparing statement: " . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "s", $email_or_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) == 1) {
            $user = mysqli_fetch_assoc($result);
            
            if (password_verify($input_password, $user['user_password'])) {
                // Clear ONLY house-specific sessions
                unset($_SESSION['house_owner_id']);
                unset($_SESSION['house_owner_name']);
                unset($_SESSION['house_owner_logged_in']);
                unset($_SESSION['house_data']);
                
                // Set MAIN OWNER sessions
                $_SESSION['owner_logged_in'] = true;  // THIS IS CRITICAL!
                $_SESSION['owner_email'] = $email_or_id;
                $_SESSION['owner_id'] = $user['user_id'];
                $_SESSION['owner_name'] = $user['user_fullname'];
                $_SESSION['login_method'] = 'email';

                switch(strtolower($email_or_id)) {
                    case 'anitos@gmail.com':
                        $redirectLocation = "../BoardinghouseOwnerInfo/1house.php";
                        break;
                    case 'tagatboardinghouse@gmail.com':
                        $redirectLocation = "../BoardinghouseOwnerInfo/2house.php";
                        break;
                    default:
                        $redirectLocation = "../baordinghouseOWNER/createboardinghouse.php";
                        break;
                }
                $loginSuccess = true;
            }
        }
        mysqli_stmt_close($stmt);
    } 
    // METHOD 2: Check if it's a HOUSE ID login (boarding_houses table)
    else if (is_numeric($email_or_id)) {

        $house_id = (int)$email_or_id;
        
        if ($expected_house_id !== null && $house_id !== $expected_house_id) {
            echo "<script>
                alert('Invalid credentials for this boarding house. You are trying to login to a different boarding house.');
                window.location.href='../baordinghouseOWNER/ownerlog.php?house_id=" . $expected_house_id . "';
            </script>";
            exit();
        }
        
        $query = "SELECT * FROM boarding_houses WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        
        if ($stmt === false) {
            die("Error preparing statement: " . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "i", $house_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) == 1) {
            $house = mysqli_fetch_assoc($result);
            
            if (password_verify($input_password, $house['dashboard_password'])) {
                // CRITICAL FIX: PRESERVE owner session data!
                $preserve_owner_logged_in = $_SESSION['owner_logged_in'] ?? false;
                $preserve_owner_id = $_SESSION['owner_id'] ?? null;
                $preserve_owner_name = $_SESSION['owner_name'] ?? null;
                $preserve_owner_email = $_SESSION['owner_email'] ?? null;
                
                // Clear ONLY house sessions (not owner sessions!)
                unset($_SESSION['house_owner_id']);
                unset($_SESSION['house_owner_name']);
                unset($_SESSION['house_owner_logged_in']);
                unset($_SESSION['house_data']);
                unset($_SESSION['login_method']);
                
                // Set the boarding house session variables
                $_SESSION['house_owner_id'] = $house['id'];
                $_SESSION['house_owner_name'] = $house['name'];
                $_SESSION['house_owner_logged_in'] = true;
                $_SESSION['house_data'] = $house;
                $_SESSION['login_method'] = 'house_id';
                
                // RESTORE the owner session data!
                if ($preserve_owner_logged_in) {
                    $_SESSION['owner_logged_in'] = $preserve_owner_logged_in;
                }
                if ($preserve_owner_id) {
                    $_SESSION['owner_id'] = $preserve_owner_id;
                }
                if ($preserve_owner_name) {
                    $_SESSION['owner_name'] = $preserve_owner_name;
                }
                if ($preserve_owner_email) {
                    $_SESSION['owner_email'] = $preserve_owner_email;
                }

                $redirectLocation = "../baordinghouseOWNER/owner_dashboard.php?house_id=" . $house_id;
                $loginSuccess = true;
            }
        }
        mysqli_stmt_close($stmt);
    }
    

    // Handle login result
    if ($loginSuccess) {
        echo "<script>
            alert('Login Successful! Redirecting to your dashboard...');
            window.location.href = '" . $redirectLocation . "';
        </script>";
        exit();
    } else {
        echo "<script>
            alert('Invalid ID or Password');
            window.location.href='../baordinghouseOWNER/createboardinghouse.php';
        </script>";
        exit();
    }
}
?>