<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
    // Also populate $_ENV for consistency
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
} else {
    die("⚠️ Server configuration error.");
}

// Secure database connection
$conn = new mysqli(
    $env['DB_HOST'],
    $env['DB_USER'],
    $env['DB_PASS'],
    $env['DB_NAME']
);

// Check for connection error
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error); // Logs it privately
    die("⚠️ Database temporarily unavailable. Please try again later.");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/vendor/autoload.php';

$create_table_sql = "CREATE TABLE IF NOT EXISTS payment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    house_id INT NOT NULL,
    tenant_name VARCHAR(255) NOT NULL,
    payment_month VARCHAR(7) NOT NULL,
    payment_date DATE DEFAULT NULL,
    next_due_date DATE DEFAULT NULL,
    status ENUM('paid', 'unpaid', 'near_due') DEFAULT 'unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_payment (house_id, tenant_name, payment_month)
)";
mysqli_query($conn, $create_table_sql);

$add_status_column = "ALTER TABLE yourbook ADD COLUMN IF NOT EXISTS status ENUM('active', 'cancelled', 'cancelled_by_owner', 'declined') DEFAULT 'active'";
mysqli_query($conn, $add_status_column);

$add_tenant_request_id = "ALTER TABLE yourbook ADD COLUMN IF NOT EXISTS tenant_request_id INT DEFAULT NULL";
mysqli_query($conn, $add_tenant_request_id);

$current_month = date('Y-m');
$current_day = date('d');
$days_in_month = date('t');
$days_remaining = $days_in_month - $current_day;

function getOwnerContactInfo($conn, $boarding_house_name) {
    $sql = "SELECT owner_email, owner_phone FROM boarding_houses WHERE name = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $boarding_house_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

function getPaymentInfo($conn, $tenant_name, $boarding_house) {
    global $current_month, $days_remaining;
    
    $house_sql = "SELECT id FROM boarding_houses WHERE name = '" . mysqli_real_escape_string($conn, $boarding_house) . "' LIMIT 1";
    $house_result = mysqli_query($conn, $house_sql);
    
    if ($house_result && mysqli_num_rows($house_result) > 0) {
        $house = mysqli_fetch_assoc($house_result);
        $house_id = $house['id'];
        
        $sql = "SELECT * FROM payment_history 
                WHERE house_id = $house_id AND tenant_name = '" . mysqli_real_escape_string($conn, $tenant_name) . "' 
                ORDER BY payment_date DESC LIMIT 1";
        $result = mysqli_query($conn, $sql);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            
            if ($row['payment_month'] == $current_month && $row['status'] == 'paid') {
                return [
                    'status' => 'paid',
                    'payment_date' => $row['payment_date'],
                    'next_due_date' => $row['next_due_date']
                ];
            } else {
                if ($days_remaining <= 5 && $days_remaining > 0) {
                    $next_due = date('Y-m-t');
                    return [
                        'status' => 'near_due',
                        'payment_date' => $row['payment_date'] ?? null,
                        'next_due_date' => $next_due
                    ];
                } else {
                    $next_due = date('Y-m-t');
                    return [
                        'status' => 'unpaid',
                        'payment_date' => $row['payment_date'] ?? null,
                        'next_due_date' => $next_due
                    ];
                }
            }
        } else {
            if ($days_remaining <= 5 && $days_remaining > 0) {
                $next_due = date('Y-m-t');
                return [
                    'status' => 'near_due',
                    'payment_date' => null,
                    'next_due_date' => $next_due
                ];
            } else {
                $next_due = date('Y-m-t');
                return [
                    'status' => 'unpaid',
                    'payment_date' => null,
                    'next_due_date' => date('Y-m-t')
                ];
            }
        }
    }
    
    return [
        'status' => 'unpaid',
        'payment_date' => null,
        'next_due_date' => date('Y-m-t')
    ];
}

function getBoardingHouseLocation($conn, $boarding_house_name) {
    $sql = "SELECT bh.map_lat, bh.map_lng, COALESCE(owner.user_address, 'Not specified') as full_location 
            FROM boarding_houses bh
            LEFT JOIN ownerregister owner ON bh.owner_id = owner.user_id
            WHERE bh.name = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $boarding_house_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

function getRoomDetails($conn, $boarding_house, $room_bed_info) {
    // Extract room number from "Room X, Bed Y" format
    $parts = explode(',', $room_bed_info);
    $room_str = trim($parts[0]);
    preg_match('/\d+/', $room_str, $matches);
    $room_number = $matches[0] ?? null;
    
    if (!$room_number) {
        return [];
    }
    
    $house_sql = "SELECT id FROM boarding_houses WHERE name = '" . mysqli_real_escape_string($conn, $boarding_house) . "' LIMIT 1";
    $house_result = mysqli_query($conn, $house_sql);
    
    if (!$house_result || mysqli_num_rows($house_result) === 0) {
        return [];
    }
    
    $house = mysqli_fetch_assoc($house_result);
    $house_id = $house['id'];
    
    $details = [];
    
    // Get room amenities
    $amenities_sql = "SELECT amenities FROM room_amenities WHERE house_id = $house_id AND room_number = $room_number LIMIT 1";
    $amenities_result = mysqli_query($conn, $amenities_sql);
    if ($amenities_result && mysqli_num_rows($amenities_result) > 0) {
        $amenities = mysqli_fetch_assoc($amenities_result);
        $details['amenities'] = $amenities['amenities'];
    }
    
    // Get room pricing type
    $pricing_sql = "SELECT pricing_type FROM room_pricing_types WHERE house_id = $house_id AND room_number = $room_number LIMIT 1";
    $pricing_result = mysqli_query($conn, $pricing_sql);
    if ($pricing_result && mysqli_num_rows($pricing_result) > 0) {
        $pricing = mysqli_fetch_assoc($pricing_result);
        $details['pricing_type'] = $pricing['pricing_type'];
    }
    
    // Get room occupancy type
    $occupancy_sql = "SELECT occupancy_type FROM room_occupancy_types WHERE house_id = $house_id AND room_number = $room_number LIMIT 1";
    $occupancy_result = mysqli_query($conn, $occupancy_sql);
    if ($occupancy_result && mysqli_num_rows($occupancy_result) > 0) {
        $occupancy = mysqli_fetch_assoc($occupancy_result);
        $details['occupancy_type'] = $occupancy['occupancy_type'];
    }
    
    return $details;
}

/**
 * Get bed-specific image path from bed_images table for a given booking string "Room X, Bed Y"
 */
function getBedImage($conn, $boarding_house, $room_bed_info) {
    // Extract room and bed numbers
    $parts = explode(',', $room_bed_info);
    $room_str = trim($parts[0] ?? '');
    $bed_str = trim($parts[1] ?? '');
    preg_match('/\d+/', $room_str, $rmatch);
    preg_match('/\d+/', $bed_str, $bmatch);
    $room_number = $rmatch[0] ?? null;
    $bed_number = $bmatch[0] ?? null;

    if (!$room_number || !$bed_number) return null;

    // Resolve house id from name
    $house_sql = "SELECT id FROM boarding_houses WHERE name = '" . mysqli_real_escape_string($conn, $boarding_house) . "' LIMIT 1";
    $house_result = mysqli_query($conn, $house_sql);
    if (!$house_result || mysqli_num_rows($house_result) === 0) return null;
    $house = mysqli_fetch_assoc($house_result);
    $house_id = $house['id'];

    $stmt = $conn->prepare("SELECT image_path FROM bed_images WHERE house_id = ? AND room_number = ? AND bed_number = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("iii", $house_id, $room_number, $bed_number);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        return $row['image_path'];
    }
    return null;
}

function isBookedRoomFaculty($conn, $boarding_house, $room_bed_info) {
    $house_sql = "SELECT id FROM boarding_houses WHERE name = '" . mysqli_real_escape_string($conn, $boarding_house) . "' LIMIT 1";
    $house_result = mysqli_query($conn, $house_sql);
    
    if ($house_result && mysqli_num_rows($house_result) > 0) {
        $house = mysqli_fetch_assoc($house_result);
        $house_id = $house['id'];
        
        if (preg_match('/(?:Faculty\s+)?Room\s+(\d+)/', $room_bed_info, $matches)) {
            $room_number = $matches[1];
            
            if (strpos($room_bed_info, 'Faculty') !== false) {
                return true;
            }
            
            $check_column = $conn->query("SHOW COLUMNS FROM house_rooms LIKE 'room_type'");
            if ($check_column && $check_column->num_rows > 0) {
                $room_sql = "SELECT room_type FROM house_rooms WHERE house_id = ? AND room_number = ?";
                $stmt = $conn->prepare($room_sql);
                $stmt->bind_param("ii", $house_id, $room_number);
                $stmt->execute();
                $room_result = $stmt->get_result();
                
                if ($room_result && $room_result->num_rows > 0) {
                    $room_data = $room_result->fetch_assoc();
                    return $room_data['room_type'] === 'faculty';
                }
            }
        }
    }
    
    return false;
}

function generateImageTag($imagePath) {
    if (empty($imagePath)) {
return '<div class="text-center text-muted p-4 border border-2 border-dashed rounded bg-light">
            <span class="display-1 d-block mb-2">🏠</span>
            <p class="mb-0 fw-bold" style="font-size: 2rem;">No image available</p>
        </div>';
    }
    // Try multiple filesystem locations (project-relative) and map them to web paths
    $cwd = __DIR__; // yourbook.php directory
    $candidates = [
        // key => web path
        $cwd . DIRECTORY_SEPARATOR . $imagePath => $imagePath,
        $cwd . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $imagePath => '../' . $imagePath,
        $cwd . DIRECTORY_SEPARATOR . 'baordinghouseOWNER' . DIRECTORY_SEPARATOR . $imagePath => 'baordinghouseOWNER/' . $imagePath,
        $cwd . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'baordinghouseOWNER' . DIRECTORY_SEPARATOR . $imagePath => '../baordinghouseOWNER/' . $imagePath,
        $cwd . DIRECTORY_SEPARATOR . $imagePath => $imagePath,
        $imagePath => $imagePath,
        $cwd . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bed_images' . DIRECTORY_SEPARATOR . basename($imagePath) => 'uploads/bed_images/' . basename($imagePath),
        $cwd . DIRECTORY_SEPARATOR . 'baordinghouseOWNER' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bed_images' . DIRECTORY_SEPARATOR . basename($imagePath) => 'baordinghouseOWNER/uploads/bed_images/' . basename($imagePath),
    ];

    $foundWebPath = null;
    foreach ($candidates as $fs => $web) {
        if (@file_exists($fs)) {
            $foundWebPath = $web;
            break;
        }
    }

    // If none found, fall back to the original path (may still be valid URL)
    $finalWebPath = $foundWebPath ?? $imagePath;

    return '<img src="' . htmlspecialchars($finalWebPath) . '" '
         . 'class="img-fluid rounded border mt-2" '
         . 'style="max-height:250px;object-fit:cover; width: 100%;" '
         . 'onerror="this.onerror=null; this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';">'
        . '<div class="text-center text-muted p-4 border border-2 border-dashed rounded bg-light mt-2" style="display:none;">'
        . '<span class="display-4 d-block mb-2">🏠</span>'
        . '<small class="text-muted">Image not available</small>'
        . '</div>';
}

function getUserProfileImage($conn, $email) {
    $stmt = $conn->prepare("SELECT profile_img FROM registerusers WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['profile_img'];
    }
    
    return null;
}

// Check if user is logged in
if (empty($_SESSION['email'])) {
    echo "<script>alert('Please login first to view your bookings.'); window.location.href='login.php';</script>";
    exit;
}

$session_email = trim($_SESSION['email']);

// Get user profile image
$user_profile_img = getUserProfileImage($conn, $session_email);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
    $id = (int)$_POST['cancel_id'];
    
    $verify_sql = "SELECT id, fullName, email, boardingHouse, startDate, phone, age, gender, address, tenant_request_id, availableSlots FROM yourbook WHERE id = ? AND email = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("is", $id, $session_email);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows > 0) {
        $booking_data = $verify_result->fetch_assoc();
        
        $sql = "UPDATE yourbook SET status = 'cancelled' WHERE id = ? AND email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $id, $session_email);
        
        if ($stmt->execute()) {
            $deleted = false;
            
            if (!empty($booking_data['tenant_request_id'])) {
                $delete_requests_sql = "DELETE FROM tenant_requests WHERE id = ?";
                $delete_requests_stmt = $conn->prepare($delete_requests_sql);
                $delete_requests_stmt->bind_param("i", $booking_data['tenant_request_id']);
                if ($delete_requests_stmt->execute()) {
                    $deleted = ($delete_requests_stmt->affected_rows > 0);
                }
                $delete_requests_stmt->close();
            }
            
            if (!$deleted) {
                $house_id_sql = "SELECT id FROM boarding_houses WHERE name = ? LIMIT 1";
                $house_id_stmt = $conn->prepare($house_id_sql);
                $house_id_stmt->bind_param("s", $booking_data['boardingHouse']);
                $house_id_stmt->execute();
                $house_id_result = $house_id_stmt->get_result();
                
                if ($house_id_result && $house_id_result->num_rows > 0) {
                    $house_data = $house_id_result->fetch_assoc();
                    $house_id = $house_data['id'];

                    $delete_requests_sql = "DELETE FROM tenant_requests 
                                           WHERE house_id = ? AND email = ? AND full_name = ? AND start_date = ?";
                    $delete_requests_stmt = $conn->prepare($delete_requests_sql);
                    $delete_requests_stmt->bind_param("isss", $house_id, $booking_data['email'], $booking_data['fullName'], $booking_data['startDate']);
                    $delete_requests_stmt->execute();
                    $delete_requests_stmt->close();
                }
            }
            
            $owner_sql = "SELECT owner_email, owner_phone FROM boarding_houses WHERE name = ?";
            $owner_stmt = $conn->prepare($owner_sql);
            $owner_stmt->bind_param("s", $booking_data['boardingHouse']);
            $owner_stmt->execute();
            $owner_result = $owner_stmt->get_result();
            
            if ($owner_result->num_rows > 0) {
                $owner_data = $owner_result->fetch_assoc();
                $owner_email = $owner_data['owner_email'];
                
                if (!empty($owner_email)) {
                    try {
                        $mail = new PHPMailer(true);
                        $mail->isSMTP();
                        $mail->Host       = $_ENV['SMTP_HOST'] ?? $env['SMTP_HOST'] ?? 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = $_ENV['SMTP_USERNAME'] ?? $env['SMTP_USERNAME'] ?? '';
                        $mail->Password   = $_ENV['SMTP_PASSWORD'] ?? $env['SMTP_PASSWORD'] ?? '';
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = $_ENV['SMTP_PORT'] ?? $env['SMTP_PORT'] ?? 587;
                        
                        // Sender info with fallback
                        $mail->setFrom($_ENV['SMTP_FROM_EMAIL'] ?? $env['SMTP_FROM_EMAIL'] ?? 'noreply@stayfinder.com', $_ENV['SMTP_FROM_NAME'] ?? $env['SMTP_FROM_NAME'] ?? 'StayFinder Booking System');
                        $mail->addAddress($owner_email);
                        
                        $mail->isHTML(true);
                        $mail->Subject = '⚠️ Booking Cancellation Notice - ' . $booking_data['fullName'];
                        
                        $mail->Body = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9f9f9;'>
                            <div style='background-color: #e74c3c; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0;'>
                                <h1 style='margin: 0; font-size: 24px;'>⚠️ Booking Cancelled</h1>
                            </div>
                            <div style='background-color: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                                <p style='font-size: 18px; color: #2c3e50; margin-bottom: 20px;'>Dear Boarding House Owner,</p>
                                
                                <p style='font-size: 16px; color: #34495e; line-height: 1.6;'>
                                    A tenant has <strong style='color: #e74c3c;'>CANCELLED</strong> their booking. 
                                    Here are the cancellation details:
                                </p>
                                
                                <div style='background-color: #ecf0f1; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                                    <h3 style='color: #2c3e50; margin-top: 0;'>📋 Tenant Information</h3>
                                    <table style='width: 100%; border-collapse: collapse;'>
                                        <tr>
                                            <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Tenant Name:</td>
                                            <td style='padding: 8px 0; color: #2c3e50;'>" . htmlspecialchars($booking_data['fullName']) . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Tenant Email:</td>
                                            <td style='padding: 8px 0; color: #2c3e50;'>" . htmlspecialchars($booking_data['email']) . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Phone:</td>
                                            <td style='padding: 8px 0; color: #2c3e50;'>" . htmlspecialchars($booking_data['phone']) . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Age:</td>
                                            <td style='padding: 8px 0; color: #2c3e50;'>" . htmlspecialchars($booking_data['age']) . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Gender:</td>
                                            <td style='padding: 8px 0; color: #2c3e50;'>" . ucfirst(htmlspecialchars($booking_data['gender'])) . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Address:</td>
                                            <td style='padding: 8px 0; color: #2c3e50;'>" . htmlspecialchars($booking_data['address']) . "</td>
                                        </tr>
                                    </table>
                                </div>

                                <div style='background-color: #ecf0f1; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                                    <h3 style='color: #2c3e50; margin-top: 0;'>📋 Booking Details</h3>
                                    <table style='width: 100%; border-collapse: collapse;'>
                                        <tr>
                                            <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Boarding House:</td>
                                            <td style='padding: 8px 0; color: #2c3e50;'>" . htmlspecialchars($booking_data['boardingHouse']) . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Booking ID:</td>
                                            <td style='padding: 8px 0; color: #2c3e50;'>#" . htmlspecialchars($id) . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Start Date:</td>
                                            <td style='padding: 8px 0; color: #2c3e50;'>" . date('M d, Y', strtotime($booking_data['startDate'])) . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Cancellation Date:</td>
                                            <td style='padding: 8px 0; color: #e74c3c; font-weight: bold;'>" . date('M d, Y H:i A') . "</td>
                                        </tr>
                                    </table>
                                </div>
                                
                                <div style='background-color: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                                    <h4 style='margin: 0 0 10px 0;'>📌 Action Required:</h4>
                                    <p style='margin: 0; line-height: 1.5;'>
                                        The bed/room is now available for other tenants. 
                                        Please log in to your dashboard to manage pending requests and update your room availability.
                                    </p>
                                </div>
                                
                                <p style='color: #7f8c8d; font-size: 14px; text-align: center; margin-top: 30px;'>
                                    Thank you for using StayFinder!<br>
                                    <em>This is an automated notification email.</em>
                                </p>
                            </div>
                        </div>";
                        
                        $mail->AltBody = "Booking Cancellation Notice\n\n" .
                            "TENANT INFORMATION:\n" .
                            "Tenant Name: " . $booking_data['fullName'] . "\n" .
                            "Tenant Email: " . $booking_data['email'] . "\n" .
                            "Phone: " . $booking_data['phone'] . "\n" .
                            "Age: " . $booking_data['age'] . "\n" .
                            "Gender: " . ucfirst($booking_data['gender']) . "\n" .
                            "Address: " . $booking_data['address'] . "\n\n" .
                            "BOOKING DETAILS:\n" .
                            "Boarding House: " . $booking_data['boardingHouse'] . "\n" .
                            "Booking ID: #" . $id . "\n" .
                            "Start Date: " . date('M d, Y', strtotime($booking_data['startDate'])) . "\n" .
                            "Cancellation Date: " . date('M d, Y H:i A') . "\n\n" .
                            "The bed/room is now available for other tenants.\n" .
                            "Please log in to your dashboard to manage pending requests.\n\n" .
                            "Thank you for using StayFinder!";
                        
                        $mail->send();
                    } catch (Exception $e) {
                        error_log("Cancellation email sending failed: " . $e->getMessage());
                    }
                }
            }
            
            echo "success";
        } else {
            echo "error";
        }
    } else {
        echo "unauthorized";
    }
    exit;
}

// Fetch user bookings
if (isset($_GET['fetch'])) {
    $sql = "SELECT * FROM yourbook WHERE TRIM(email) = TRIM(?) ORDER BY id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $session_email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 0) {
        echo '<div class="text-center py-5 text-muted">
                <h3>No bookings found</h3>
                <p>You haven\'t made any bookings yet.</p>
                <p><strong>Your registered email:</strong> ' . htmlspecialchars($session_email) . '</p>
                                <a href="seekerdashboard.php" class="btn btn-primary fw-bold">← Go to Seeker Dashboard to make a booking</a>
              </div>';
    } else {
        $mapDataArray = [];
        
        while ($row = $result->fetch_assoc()) {
              $yourbook_status = $row['status'] ?? 'active';
            
            $booking_status = 'pending';
            
            // Priority 1: Check if terminated by owner (HIGHEST PRIORITY)
            if ($yourbook_status === 'cancelled_by_owner') {
                $booking_status = 'cancelled_by_owner'; // TERMINATED
            }
            // Priority 2: Check if declined by owner
            elseif ($yourbook_status === 'declined') {
                $booking_status = 'declined'; // DECLINED
            }
            // Priority 3: Check if cancelled by tenant
            elseif ($yourbook_status === 'cancelled') {
                $booking_status = 'cancelled';
            }
            // Priority 4: Only check tenant_requests if yourbook is active
            else {
                if (!empty($row['tenant_request_id'])) {
                    // If we have a tenant_request_id, use it for precise matching
                    $status_sql = "SELECT status FROM tenant_requests WHERE id = ?";
                    $status_stmt = $conn->prepare($status_sql);
                    $status_stmt->bind_param("i", $row['tenant_request_id']);
                    $status_stmt->execute();
                    $status_result = $status_stmt->get_result();
                    
                    if ($status_result && $status_result->num_rows > 0) {
                        $status_row = $status_result->fetch_assoc();
                        $booking_status = $status_row['status'];
                    }
                } else {
                    // Fallback: Try to find the tenant_request and update the booking record
                    $house_id_sql = "SELECT id FROM boarding_houses WHERE name = ? LIMIT 1";
                    $house_id_stmt = $conn->prepare($house_id_sql);
                    $house_id_stmt->bind_param("s", $row['boardingHouse']);
                    $house_id_stmt->execute();
                    $house_id_result = $house_id_stmt->get_result();
                    
                    if ($house_id_result && $house_id_result->num_rows > 0) {
                        $house_data = $house_id_result->fetch_assoc();
                        $house_id = $house_data['id'];
                        
                        // Get MOST RECENT request matching THIS booking
                        $find_request_sql = "SELECT id, status FROM tenant_requests 
                                            WHERE house_id = ? AND email = ? AND full_name = ? AND start_date = ?
                                            ORDER BY id DESC LIMIT 1";
                        $find_request_stmt = $conn->prepare($find_request_sql);
                        $find_request_stmt->bind_param("isss", $house_id, $row['email'], $row['fullName'], $row['startDate']);
                        $find_request_stmt->execute();
                        $find_result = $find_request_stmt->get_result();
                        
                        if ($find_result && $find_result->num_rows > 0) {
                            $request_data = $find_result->fetch_assoc();
                            $booking_status = $request_data['status'];
                            
                            // Update the booking record with the tenant_request_id for future lookups
                            $update_booking = "UPDATE yourbook SET tenant_request_id = ? WHERE id = ?";
                            $update_stmt = $conn->prepare($update_booking);
                            $update_stmt->bind_param("ii", $request_data['id'], $row['id']);
                            $update_stmt->execute();
                        }
                    }
                }
            }
            
$is_owner_cancelled = ($yourbook_status === 'cancelled_by_owner');
$is_cancelled = ($yourbook_status === 'cancelled');

// TERMINATED: Only when explicitly cancelled by owner (from List of Tenants)
$is_terminated = ($yourbook_status === 'cancelled_by_owner');

// DECLINED: Only when explicitly declined by owner (from Pending Requests)
$is_declined = ($yourbook_status === 'declined');
            
$is_faculty_room = isBookedRoomFaculty($conn, $row['boardingHouse'], $row['availableSlots']);

            $faculty_indicator = $is_faculty_room ? ' <span class="badge bg-golden text-white ms-2 faculty-badge">🎓 FACULTY</span>' : '';
            
            // Get room details (amenities, pricing type, occupancy type)
            $room_details = getRoomDetails($conn, $row['boardingHouse'], $row['availableSlots']);
            
            $location_data = getBoardingHouseLocation($conn, $row['boardingHouse']);
            $has_map = $location_data && !empty($location_data['map_lat']) && !empty($location_data['map_lng']);
            $map_id = 'houseMap_' . $row['id'];
            
            // --- START OF NEW LAYOUT STRUCTURE ---

            // Try bed-specific image first; fall back to booking/house image
            $bed_image = getBedImage($conn, $row['boardingHouse'], $row['availableSlots']);
            $display_image = !empty($bed_image) ? $bed_image : ($row['imagePath'] ?? $row['image'] ?? '');

            echo '<div class="card mb-4 shadow-sm booking-card">
                    <div class="card-body">
                        
                        <div class="text-center mb-4 border-bottom border-golden pb-3">
                            <h3 class="card-title text-golden fw-bold mb-0">🏠 ' . htmlspecialchars($row['boardingHouse']) . $faculty_indicator . '</h3>
                            <p class="mb-0 text-muted small">Booking ID: #' . htmlspecialchars($row['id']) . '</p>
                        </div>

                        <div class="row g-4 mb-4">
                            <div class="col-lg-6">
                                <h5 class="card-title text-golden border-bottom border-golden pb-2 mb-3">🏡 Boarding House Details</h5>
                                
                                <div class="alert alert-golden border-golden price-highlight mb-3">
                                    <p class="mb-0 fw-bold text-dark">
                                        <strong>💰 Monthly Price:</strong> 
                                        <span class="badge bg-golden text-white fs-6 price-value">' . htmlspecialchars($row['price']) . '</span>
                                    </p>
                                </div>
                                <p class="mb-2"><strong>🛏️ Room & Bed:</strong> ' . htmlspecialchars($row['availableSlots']) . '</p>';
                                
            // Display room amenities
            if (!empty($room_details['amenities'])) {
                echo '<div class="mb-3">
                        <strong>🎁 Amenities:</strong>
                        <div class="amenities-list-yourbook">';
                $amenities = explode(',', $room_details['amenities']);
                foreach ($amenities as $amenity) {
                    echo '<span class="amenity-badge-yourbook">' . htmlspecialchars(trim($amenity)) . '</span>';
                }
                echo '</div>
                    </div>';
            }
            
            // Display room occupancy type
            if (!empty($room_details['occupancy_type'])) {
                $room_type_text = ($room_details['occupancy_type'] === 'single_occupancy') ? 'Single Occupancy' : 'Multi Occupancy';
                $room_type_class = $room_details['occupancy_type'];
                echo '<p class="mb-2"><strong>🏢 Occupancy Type:</strong> <span class="room-type-badge-yourbook ' . $room_type_class . '">' . htmlspecialchars($room_type_text) . '</span></p>';
            }
            
            // Display pricing type
            if (!empty($room_details['pricing_type'])) {
                $pricing_type_text = ($room_details['pricing_type'] === 'conditional') ? 'Per Head Pricing' : 'Per Room Pricing';
                echo '<p class="mb-2"><strong>💵 Pricing Type:</strong> <span class="pricing-type-badge-yourbook ' . $room_details['pricing_type'] . '">' . htmlspecialchars($pricing_type_text) . '</span></p>';
            }
            
            echo '';
                                
            if ($has_map) {
                echo '<p class="mb-2"><strong>📍 Full Location:</strong> ' . htmlspecialchars($location_data['full_location']) . '</p>
                      <div id="' . $map_id . '" 
                           class="boarding-house-map" 
                           data-lat="' . htmlspecialchars($location_data['map_lat']) . '" 
                           data-lng="' . htmlspecialchars($location_data['map_lng']) . '" 
                           data-house-name="' . htmlspecialchars($row['boardingHouse']) . '"
                          style="height: 250px; border-radius: 8px; margin: 10px 0; border: 2px solid #ffd700;"></div>';
                
                $mapDataArray[] = [
                    'mapId' => $map_id,
                    'lat' => floatval($location_data['map_lat']),
                    'lng' => floatval($location_data['map_lng']),
                    'houseName' => $row['boardingHouse']
                ];
            } else {
                echo '<p class="mb-2 text-muted fst-italic"><strong>📍 Location:</strong> Not set by owner</p>';
            }
            
            echo '            </div>
                            <div class="col-lg-6 d-flex flex-column justify-content-start align-items-center">
                                <h5 class="card-title text-golden border-bottom border-golden pb-2 mb-3 w-100">🖼️ House Image</h5>';
            
            // Always show house image first, then bed image (if present)
            $house_image = $row['imagePath'] ?? $row['image'] ?? '';
            echo '<div class="w-100">';
            if (!empty($house_image)) {
                echo generateImageTag($house_image);
            } else {
                echo '<div class="text-center py-5 text-muted w-100">'
                    . '<span class="display-4 d-block mb-2">🏠</span>'
                    . '<small class="text-muted">No image available</small>'
                    . '</div>';
            }
            echo '</div>';

            if (!empty($bed_image)) {
                echo '<div class="w-100 mt-3 text-center">'
                    . '<h6 class="mb-2">🛏️ Bed Image</h6>'
                    . generateImageTag($bed_image)
                    . '</div>';
            }
            echo "\n                            </div>\n";
            echo '                        </div>
                        
                        <div class="mb-4">
<h3 class="card-title text-golden text-center border-bottom border-golden pb-2 mb-3" style="font-size: 2rem;">👤 YOUR INFORMATION</h3>
                            
                            <div class="profile-display-section mb-3">
                                <div class="d-flex align-items-center gap-3">';
            
            // Display profile picture
            if (!empty($user_profile_img) && file_exists($user_profile_img)) {
                echo '<img src="' . htmlspecialchars($user_profile_img) . '" alt="Profile" class="booking-profile-img">';
            } else {
                $initial = strtoupper(substr($row['fullName'], 0, 1));
                echo '<div class="booking-profile-placeholder">' . $initial . '</div>';
            }
            
            echo '          <div>
                                    <h6 class="mb-0 fw-bold">' . htmlspecialchars($row['fullName']) . '</h6>
                                    <small class="text-muted">' . htmlspecialchars($row['email']) . '</small>
                                </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>Full Name:</strong> ' . htmlspecialchars($row['fullName']) . '</p>
                                    <p class="mb-2"><strong>Age:</strong> ' . htmlspecialchars($row['age']) . '</p>
                                    <p class="mb-2"><strong>Gender:</strong> ' . ucfirst(htmlspecialchars($row['gender'])) . '</p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-2"><strong>Address:</strong> ' . htmlspecialchars($row['address']) . '</p>
                                    <p class="mb-2"><strong>Phone:</strong> ' . htmlspecialchars($row['phone']) . '</p>
                                    <p class="mb-2"><strong>Start Date:</strong> ' . date('M d, Y', strtotime($row['startDate'])) . '</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row align-items-center pt-3 border-top border-golden">
                            <div class="col-md-12 text-center">  <h5 class="card-title text-golden mb-3">⚡ Booking Status</h5>';
            
            $button_html = '';
            
          if ($is_terminated) {
    echo '<div class="alert alert-danger text-center mb-md-0" style="background-color: #f8d7da; border-color: #f5c6cb; padding: 1.5rem;">
                <h4 class="alert-heading mb-2" style="color: #721c24; font-size: 1.8rem; font-weight: 700;">🚫 TERMINATED</h4>
                <p class="mb-0" style="color: #721c24; font-size: 1.1rem;">Your booking has been TERMINATED by the owner.</p>
            </div>';
    // Show owner contact info when booking is TERMINATED by owner
    $owner_info = getOwnerContactInfo($conn, $row['boardingHouse']);
    if ($owner_info) {
         echo '<div class="small mt-2 text-danger">
                    <p class="mb-1"><strong>Contact Owner:</strong></p>
                    ' . (!empty($owner_info['owner_email']) ? '<p class="mb-0">📧 <a href="mailto:' . htmlspecialchars($owner_info['owner_email']) . '">' . htmlspecialchars($owner_info['owner_email']) . '</a></p>' : '') . '
                    ' . (!empty($owner_info['owner_phone']) ? '<p class="mb-0">📱 <a href="tel:' . htmlspecialchars($owner_info['owner_phone']) . '">' . htmlspecialchars($owner_info['owner_phone']) . '</a></p>' : '') . '
                </div>';
    }

            } elseif ($is_declined) {
                echo '<div class="alert alert-warning text-center mb-md-0">
                            <h6 class="alert-heading mb-1">❌ DECLINED</h6>
                            <p class="mb-0 small fst-italic">Owner declined your request.</p>
                        </div>';
            } elseif ($is_cancelled) {
                echo '<div class="alert alert-info text-center mb-md-0">
                            <h6 class="alert-heading mb-1">✋ CANCELLED</h6>
                            <p class="mb-0 small fst-italic">You cancelled this booking.</p>
                        </div>';
            } elseif ($booking_status === 'accepted') {
                echo '<div class="alert alert-success text-center mb-2 mx-auto" style="max-width: 300px;"> <h6 class="alert-heading mb-0">✅ CONFIRMED!</h6>
                        </div>';
                
                $payment_info = getPaymentInfo($conn, $row['fullName'], $row['boardingHouse']);
                $payment_status = $payment_info['status'];
                $payment_date = $payment_info['payment_date'];
                $next_due_date = $payment_info['next_due_date'];
                
                echo '<div class="card bg-light border-0 mx-auto" style="max-width: 400px;"> <div class="card-body p-2">
                                <div class="text-center mb-2">
                                    <span class="badge fs-6 payment-status-badge payment-status-' . $payment_status . '">';
                
                if ($payment_status == 'paid') {
                    echo '✅ PAID';
                } elseif ($payment_status == 'near_due') {
                    echo '⚠️ NEAR DUE';
                } else {
                    echo '❌ UNPAID';
                }
                
                echo '</span>
                                </div>
                                <div class="row g-1 small">
                                    <div class="col-6">
                                        <div class="card bg-white border">
                                            <div class="card-body p-1 text-center">
                                                <small class="text-muted d-block"><strong>Last Payment:</strong></small>
                                                <small class="fw-bold">' . ($payment_date ? date('M d, Y', strtotime($payment_date)) : 'Not paid yet') . '</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="card bg-white border">
                                            <div class="card-body p-1 text-center">
                                                <small class="text-muted d-block"><strong>Next Due:</strong></small>
                                                <small class="fw-bold text-danger">' . date('M d, Y', strtotime($next_due_date)) . '</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>';
                
                // Updated button to center it under the status/payment box
                // Each tenant gets redirected to their correct dashboard based on booking_id or tenant email
                $button_html = '<a href="dashboardprofile.php?booking_id=' . htmlspecialchars($row['id']) . '&email=' . urlencode($row['email']) . '" class="btn btn-primary fw-bold mx-auto mt-3" style="max-width: 400px; display: block;">📊 PROCEED TO ACCESS TENANT PORTAL</a>';

            } else { // Pending
                echo '<div class="alert alert-golden text-center mb-md-0 booking-pending">
                            <h6 class="alert-heading mb-1">⏳ PENDING</h6>
                            <p class="mb-0 small fst-italic">Waiting for owner approval.</p>
                        </div>';
            }
            
            echo '            </div>
                            
                            <div class="col-md-12 text-center mt-3 d-flex justify-content-center"> ' . $button_html;

            // Cancel button logic
            if (!$is_terminated && !$is_declined && !$is_cancelled) {
                // The original logic kept the cancel button available even for 'accepted' status if $booking_status !== 'accepted' was not checked.
                // Keeping the cancel button for 'pending' status only as per common UX.
                if ($booking_status !== 'accepted') {
                    echo '<button onclick="openModal(' . $row['id'] . ')" class="btn btn-danger btn-md">❌ Cancel Booking</button>';
                }
            }
                                
            echo '            </div>
                        </div>
                    </div>
                </div>';
            // --- END OF NEW LAYOUT STRUCTURE ---
        }
        
    }
    exit;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Bookings - StayFinder</title>
  <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    
    <style>
        :root {
            --golden-yellow: #FFD700;
            --dark-golden: #DAA520;
            --light-golden: #FFF8DC;
            --pure-white: #FFFFFF;
        }
        
        body {
            background: linear-gradient(135deg, var(--golden-yellow) 0%, var(--dark-golden) 100%);
            min-height: 100vh;
        }
        
        .main-container {
            background: var(--pure-white);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(218, 165, 32, 0.3);
        }

        /* Custom circular back button to match intendeduserlogin.php */
        .custom-back {
            background: #ffd700 !important;
            width: 50px;
            height: 50px;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 0 !important;
            font-size: 28px !important;
            font-weight: bold !important;
            color: #000 !important;
            border: none !important;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3) !important;
            transition: all 0.3s ease !important;
            border-radius: 50% !important;
            text-decoration: none !important;
        }

        .custom-back:hover {
            transform: scale(1.12) !important;
            background: #ffed4e !important;
            box-shadow: 0 6px 16px rgba(255, 215, 0, 0.4) !important;
            color: #000 !important;
            text-decoration: none !important;
        }
        
        .custom-back i.fa-arrow-left {
            font-size: 24px !important;
            font-weight: 900 !important;
        }
        
        .booking-card {
            transition: transform 0.2s, box-shadow 0.2s;
            border: 2px solid var(--light-golden);
        }
        
        .booking-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(218, 165, 32, 0.25) !important;
        }
        
        .price-highlight {
            background-color: var(--light-golden) !important;
            border-color: var(--golden-yellow) !important;
        }
        
        .price-value {
            font-size: 1.1rem !important;
            font-weight: 900 !important;
            background-color: var(--golden-yellow) !important;
            color: var(--pure-white) !important;
        }
        
        .payment-status-badge {
            padding: 8px 16px !important;
            font-weight: bold !important;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .payment-status-paid {
            background: linear-gradient(135deg, var(--golden-yellow), var(--dark-golden)) !important;
            color: var(--pure-white) !important;
        }
        
        .payment-status-unpaid {
            background: linear-gradient(135deg, #e74c3c, #c0392b) !important;
            color: var(--pure-white) !important;
        }
        
        .payment-status-near_due {
            background: linear-gradient(135deg, var(--golden-yellow), var(--dark-golden)) !important;
            color: var(--pure-white) !important;
        }
        
        .faculty-badge {
            background: linear-gradient(135deg, var(--dark-golden), var(--golden-yellow)) !important;
            border: 2px solid var(--pure-white) !important;
        }
        
        .bg-golden {
            background-color: var(--golden-yellow) !important;
        }
        
        .text-golden {
            color: var(--dark-golden) !important;
        }
        
        .border-golden {
            border-color: var(--golden-yellow) !important;
        }
        
        .alert-golden {
            background-color: var(--light-golden) !important;
            border-color: var(--golden-yellow) !important;
            color: var(--dark-golden) !important;
        }
        
        .btn-golden {
            background-color: var(--golden-yellow) !important;
            border-color: var(--golden-yellow) !important;
            color: var(--pure-white) !important;
        }
        
        .btn-golden:hover {
            background-color: var(--dark-golden) !important;
            border-color: var(--dark-golden) !important;
            color: var(--pure-white) !important;
        }
        
        .btn-primary {
            background-color: var(--golden-yellow) !important;
            border-color: var(--golden-yellow) !important;
            color: var(--pure-white) !important;
        }
        
        .btn-primary:hover {
            background-color: var(--dark-golden) !important;
            border-color: var(--dark-golden) !important;
            color: var(--pure-white) !important;
        }
        
        .text-primary {
            color: var(--dark-golden) !important;
        }
        
        .border-primary {
            border-color: var(--golden-yellow) !important;
        }
        
        .alert-primary {
            background-color: var(--light-golden) !important;
            border-color: var(--golden-yellow) !important;
            color: var(--dark-golden) !important;
        }
        
        .booking-pending {
            background-color: var(--light-golden) !important;
            border-color: var(--golden-yellow) !important;
            color: var(--dark-golden) !important;
        }
        
        .loading-spinner {
            width: 20px;
            height: 20px;
            border: 3px solid var(--light-golden);
            border-top: 3px solid var(--golden-yellow);
            border-radius: 50%;
        }
        
        .alert-success {
            background-color: var(--light-golden) !important;
            border-color: var(--golden-yellow) !important;
            color: var(--dark-golden) !important;
        }
        
        .card-title {
            color: var(--dark-golden) !important;
        }
        
        .modal-header {
            background-color: var(--light-golden);
            border-bottom: 2px solid var(--golden-yellow);
        }
        
        .modal-content {
            border: 2px solid var(--golden-yellow);
        }
        
        .boarding-house-map {
            box-shadow: 0 4px 12px rgba(218, 165, 32, 0.3);
            background-color: #f0f0f0;
            height: 250px; /* default height */
            transition: height 0.2s ease;
        }

        /* Make maps a bit taller on small screens so controls don't overlap */
        @media (max-width: 992px) {
            .boarding-house-map {
                height: 360px !important;
            }
        }

        @media (max-width: 480px) {
            .boarding-house-map {
                height: 420px !important;
            }
        }
        
        .leaflet-popup-content-wrapper {
            background-color: var(--light-golden);
            color: var(--dark-golden);
            font-weight: bold;
        }
        
        .leaflet-popup-tip {
            background-color: var(--light-golden);
        }
        
        /* Profile display styles */
        .profile-display-section {
            background: linear-gradient(135deg, #FFF9E6, #FFFBF0);
            padding: 15px;
            border-radius: 10px;
            border: 2px solid var(--golden-yellow);
        }
        
        .booking-profile-img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--golden-yellow);
            box-shadow: 0 4px 12px rgba(218, 165, 32, 0.3);
        }
        
        .booking-profile-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--golden-yellow);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
            color: #000;
            border: 3px solid var(--dark-golden);
            box-shadow: 0 4px 12px rgba(218, 165, 32, 0.3);
        }

        /* Amenities and Room Details Styling */
        .amenities-list-yourbook {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .amenity-badge-yourbook {
            display: inline-block;
            background: #e9ecef;
            color: #495057;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            border: 1px solid #dee2e6;
            font-weight: 500;
        }

        .room-type-badge-yourbook {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            color: #000; /* black text */
            background: #ffd700; /* yellow background */
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            border: 1px solid rgba(0,0,0,0.08);
        }

        .room-type-badge-yourbook.single_occupancy {
            background: #ffd700;
            color: #000;
        }

        .room-type-badge-yourbook.multi_occupancy {
            background: #ffd700;
            color: #000;
        }

        .pricing-type-badge-yourbook {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            color: #000; /* black text */
            background: #ffd700; /* yellow */
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            border: 1px solid rgba(0,0,0,0.08);
        }

        .pricing-type-badge-yourbook.conditional {
            background: #ffd700;
            color: #000;
        }

        .pricing-type-badge-yourbook.fixed {
            background: #ffd700;
            color: #000;
        }

        /* Make footer stick to bottom of viewport and avoid overlapping content */
        .footer {
            position: fixed !important;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            z-index: 1050;
        }

        /* Provide space so page content isn't hidden behind the fixed footer */
        body {
            padding-bottom: 90px; /* adjust if footer height changes */
        }
    </style>
</head>
<body>

<div class="container my-4">
    <div class="main-container p-4">
<a href="javascript:history.back()" class="btn btn-warning rounded-circle d-flex align-items-center justify-content-center mb-4 custom-back" title="Go back" aria-label="Go back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i>
</a>
        <h2 class="text-center text-primary mb-4">📋 MY Bookings</h2>

        <div id="booking-container">
            <div class="text-center py-5 text-muted">
                <div class="d-inline-flex align-items-center">
                    <span>Loading your bookings...</span>
                    <div class="loading-spinner ms-2"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title text-danger" id="cancelModalLabel">⚠️ Cancel Booking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <p class="mb-3">Are you sure you want to cancel this booking?</p>
                <p class="small text-muted">This action cannot be undone and your bed will become available for others.</p>
            </div>
            <div class="modal-footer border-0 justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Booking</button>
                <button type="button" class="btn btn-danger" onclick="confirmCancel()">Yes, Cancel</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($env['GOOGLE_MAPS_API_KEY'] ?? ''); ?>&libraries=places&callback=initGoogleMaps" async defer></script>

<script>
var cancelId = null;
var cancelModal = null;

document.addEventListener("DOMContentLoaded", function() {
    cancelModal = new bootstrap.Modal(document.getElementById('cancelModal'));
    loadBookings();
});

let bookingMaps = {};

function initializeMaps() {
    const mapElements = document.querySelectorAll('[id^="houseMap_"]');
    
    mapElements.forEach(function(mapElement) {
        const lat = parseFloat(mapElement.getAttribute('data-lat'));
        const lng = parseFloat(mapElement.getAttribute('data-lng'));
        const houseName = mapElement.getAttribute('data-house-name');
        const mapId = mapElement.id;

        if (!isNaN(lat) && !isNaN(lng)) {
            // Create Google Map
            const map = new google.maps.Map(mapElement, {
                center: { lat: lat, lng: lng },
                zoom: 17,
                mapTypeId: google.maps.MapTypeId.HYBRID,
                mapTypeControl: true,
                mapTypeControlOptions: {
                    style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
                    position: google.maps.ControlPosition.TOP_RIGHT
                },
                zoomControl: true,
                zoomControlOptions: {
                    position: google.maps.ControlPosition.RIGHT_CENTER
                },
                streetViewControl: true,
                fullscreenControl: true,
                scrollwheel: true,
                gestureHandling: 'greedy',
                disableDoubleClickZoom: false
            });

            // Give map controls extra top padding so they don't overlap with page buttons
            // Use a larger top padding on small screens
            var topPadding = (window.innerWidth <= 480) ? 120 : (window.innerWidth <= 992 ? 90 : 70);
            map.setOptions({ padding: { top: topPadding } });

            // Create Google Maps Marker
            const marker = new google.maps.Marker({
                position: { lat: lat, lng: lng },
                map: map,
                title: houseName,
                icon: {
                    url: '../img/icons/house_9408891.png',
                    scaledSize: new google.maps.Size(40, 40),
                    anchor: new google.maps.Point(20, 40)
                }
            });

            // Create Google Maps Info Window
            const infoWindow = new google.maps.InfoWindow({
                content: `<strong>${houseName}</strong>`
            });

            marker.addListener('click', function() {
                infoWindow.open(map, marker);
            });

            bookingMaps[mapId] = map;

            // Open info window by default
            setTimeout(() => {
                infoWindow.open(map, marker);
            }, 500);
            // Recenter after a short delay to ensure map displays correctly when resized/fullscreen
            setTimeout(() => { google.maps.event.trigger(map, 'resize'); map.setCenter({ lat: lat, lng: lng }); }, 800);
        }
    });
}

function loadBookings() {
    fetch("yourbook.php?fetch=1")
        .then(response => response.text())
        .then(data => {
            document.getElementById("booking-container").innerHTML = data;
          setTimeout(() => {
    if (typeof initializeMaps === 'function') {
        initializeMaps();
    }
}, 500);
        })
        .catch(error => {
            console.error('Error loading bookings:', error);
            document.getElementById("booking-container").innerHTML = 
                '<div class="alert alert-danger text-center">Unable to load your bookings. Please refresh the page or try again later.</div>';
        });
}

function openModal(id) {
    cancelId = id;
    cancelModal.show();
}

function confirmCancel() {
    if (!cancelId) return;

    const data = new URLSearchParams();
    data.append('cancel_id', cancelId);

    fetch("yourbook.php", {
        method: "POST",
        body: data
    })
    .then(response => response.text())
    .then(data => {
        if (data.trim() === "success") {
            loadBookings();
            alert("Your booking has been cancelled successfully!");
        } else if (data.trim() === "unauthorized") {
            alert("Unauthorized action. This booking doesn't belong to you.");
        } else {
            alert("There was a problem cancelling your booking. Please try again.");
        }
        cancelModal.hide();
        cancelId = null;
    })
    .catch(error => {
        console.error('Error cancelling booking:', error);
        alert("Network error. Please check your connection and try again.");
        cancelModal.hide();
        cancelId = null;
    });
}


setInterval(loadBookings, 30000);
</script>

    <footer class="footer" style="background-color:#111;color:#fff;padding:10px 0;text-align:center;">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <p class="mb-0" style="font-size:13px;">
                        StayFinder: Boarding Locator and Management System
                    </p>
                    <p class="mt-1 mb-0" style="font-size:13px;">
                        <a href="terms.php" class="text-warning text-decoration-none fw-bold">Terms &amp; Conditions</a>
                    </p>
                </div>
            </div>
        </div>
    </footer>

</body>
</html>
