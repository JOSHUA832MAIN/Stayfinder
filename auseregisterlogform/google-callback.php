<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ✅ Corrected path to your DB connection
require_once '../connectiondatabase/main_connection.php'; 

// Decode Google credential token
if (isset($_POST['credential'])) {
    $jwt = $_POST['credential'];
    
    try {
        // Decode JWT without verification (for simplicity)
        $payload = explode('.', $jwt)[1];
        $payload .= str_repeat('=', 4 - (strlen($payload) % 4)); // fix padding
        $data = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)), true);

        $email = $data['email'] ?? '';
        $name = $data['name'] ?? '';
        $google_id = $data['sub'] ?? '';
        $profile_img = $data['picture'] ?? null;

        if ($email) {
            // Check if user exists
            $stmt = $conn->prepare("SELECT * FROM registerusers WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                // Generate a random password for Google login users
                $password = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                $phone = ''; // placeholder
                $age = null;
                $gender = null;
                $address = null;

                // Insert new user
                $stmt2 = $conn->prepare("
                    INSERT INTO registerusers 
                    (fullname, email, password, phone, age, gender, address, profile_img) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt2->bind_param("ssssisss", $name, $email, $password, $phone, $age, $gender, $address, $profile_img);
                $stmt2->execute();
                $stmt2->close();
            }

            // Set session variables
            $_SESSION['email'] = $email;
            $_SESSION['name'] = $name;
            $_SESSION['profile_img'] = $profile_img;

            header("Location: ../dashboard.php");
            exit();
        } else {
            echo "Failed to get Google account info.";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }

} else {
    echo "No Google credential received.";
}
?>
