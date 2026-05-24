<?php
session_start();
$envPath = dirname(dirname(__FILE__)) . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

if (empty($_SESSION['email'])) {
    echo "<script>alert('Please login first to access your profile.'); window.location.href='auseregisterlogform/login.php';</script>";
    exit;
}

require_once __DIR__ . '/connectiondatabase/main_connection.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/vendor/autoload.php';

if (!$conn) {
    die("Database connection failed.");
}

$session_email = trim($_SESSION['email']);

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
            // Get house_id first for all subsequent queries
            $house_sql = "SELECT id FROM boarding_houses WHERE name = ? LIMIT 1";
            $house_stmt = $conn->prepare($house_sql);
            $house_stmt->bind_param("s", $booking_data['boardingHouse']);
            $house_stmt->execute();
            $house_result = $house_stmt->get_result();
            $house_id = null;
            
            if ($house_result->num_rows > 0) {
                $house_data = $house_result->fetch_assoc();
                $house_id = $house_data['id'];
            }
            $house_stmt->close();
            
            // Extract room and bed numbers from availableSlots (format: "Room X, Bed Y")
            $room_number = null;
            $bed_number = null;
            if (!empty($booking_data['availableSlots'])) {
                preg_match('/Room\s+(\d+)/i', $booking_data['availableSlots'], $room_match);
                preg_match('/Bed\s+(\d+)/i', $booking_data['availableSlots'], $bed_match);
                $room_number = isset($room_match[1]) ? (int)$room_match[1] : null;
                $bed_number = isset($bed_match[1]) ? (int)$bed_match[1] : null;
            }
            
            // Trim email to avoid whitespace issues
            $clean_email = trim($booking_data['email']);

            if (!empty($booking_data['tenant_request_id'])) {
                // Use tenant_request_id for direct deletion
                $delete_requests_sql = "DELETE FROM tenant_requests WHERE id = ?";
                $delete_requests_stmt = $conn->prepare($delete_requests_sql);
                $delete_requests_stmt->bind_param("i", $booking_data['tenant_request_id']);
                $delete_requests_stmt->execute();
                $delete_requests_stmt->close();
            } elseif ($house_id && $room_number && $bed_number) {
                // Delete on email, house_id, room_number, bed_number
                $delete_requests_sql = "DELETE FROM tenant_requests WHERE email = ? AND house_id = ? AND room_number = ? AND bed_number = ?";
                $delete_requests_stmt = $conn->prepare($delete_requests_sql);
                $delete_requests_stmt->bind_param("siii", $clean_email, $house_id, $room_number, $bed_number);
                $delete_requests_stmt->execute();
                $delete_requests_stmt->close();
            } else {
                // Fallback: delete on email and house_id
                $delete_requests_sql = "DELETE FROM tenant_requests WHERE email = ? AND house_id = ?";
                $delete_requests_stmt = $conn->prepare($delete_requests_sql);
                $delete_requests_stmt->bind_param("si", $clean_email, $house_id);
                $delete_requests_stmt->execute();
                $delete_requests_stmt->close();
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
                        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = $_ENV['SMTP_USERNAME'];
                        $mail->Password   = $_ENV['SMTP_PASSWORD'];
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;
                        $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME'] ?? 'StayFinder Booking System');
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

function getOwnerContactInfo($conn, $boarding_house) {
    $owner_sql = "SELECT owner_email, owner_phone FROM boarding_houses WHERE name = ? LIMIT 1";
    $owner_stmt = $conn->prepare($owner_sql);
    $owner_stmt->bind_param("s", $boarding_house);
    $owner_stmt->execute();
    $owner_result = $owner_stmt->get_result();
    
    if ($owner_result && $owner_result->num_rows > 0) {
        return $owner_result->fetch_assoc();
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

function getBedImage($conn, $boarding_house, $room_bed_info) {
    $parts = explode(',', $room_bed_info);
    $room_str = trim($parts[0] ?? '');
    $bed_str = trim($parts[1] ?? '');
    preg_match('/\d+/', $room_str, $rmatch);
    preg_match('/\d+/', $bed_str, $bmatch);
    $room_number = $rmatch[0] ?? null;
    $bed_number = $bmatch[0] ?? null;

    if (!$room_number || !$bed_number) return null;

    $house_sql = "SELECT id FROM boarding_houses WHERE name = '" . mysqli_real_escape_string($conn, $boarding_house) . "' LIMIT 1";
    $house_result = mysqli_query($conn, $house_sql);
    if (!$house_result || mysqli_num_rows($house_result) === 0) return null;
    $house = mysqli_fetch_assoc($house_result);
    $house_id = $house['id'];

    // First try to get bed-specific image
    $stmt = $conn->prepare("SELECT image_path FROM bed_images WHERE house_id = ? AND room_number = ? AND bed_number = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("iii", $house_id, $room_number, $bed_number);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $bed_image = $row['image_path'];
            $stmt->close();
            // Verify the file exists before returning
            if (!empty($bed_image) && (
                file_exists(__DIR__ . DIRECTORY_SEPARATOR . $bed_image) ||
                file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bed_images' . DIRECTORY_SEPARATOR . basename($bed_image)) ||
                file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'baordinghouseOWNER' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bed_images' . DIRECTORY_SEPARATOR . basename($bed_image))
            )) {
                return $bed_image;
            }
        }
        $stmt->close();
    }
    return null;
}

/**
 * Get house image from boarding_houses table for a given house name
 */
function getHouseImage($conn, $boarding_house) {
    if (empty($boarding_house)) return null;
    
    $house_sql = "SELECT images FROM boarding_houses WHERE name = ? LIMIT 1";
    $house_stmt = $conn->prepare($house_sql);
    if (!$house_stmt) return null;
    
    $house_stmt->bind_param("s", $boarding_house);
    $house_stmt->execute();
    $res = $house_stmt->get_result();
    
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $images = array_filter(array_map('trim', explode(',', $row['images'] ?? '')));
        if (!empty($images)) {
            return $images[0]; // Return first image
        }
    }
    $house_stmt->close();
    return null;
}

function generateImageTag($imagePath, $attrs = '') {
    if (empty($imagePath)) {
        return '<div style="width:100%;height:140px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#f8f9fa;border:2px dashed #e9ecef;"> <span style="font-size:28px;">🏠</span></div>';
    }

    $cwd = __DIR__ . DIRECTORY_SEPARATOR;
    $base = basename($imagePath);
    $candidates = [
        $cwd . $base => $base,
        $cwd . 'uploads' . DIRECTORY_SEPARATOR . 'boarding_houses' . DIRECTORY_SEPARATOR . $base => 'uploads/boarding_houses/' . $base,
        $cwd . 'uploads' . DIRECTORY_SEPARATOR . 'bed_images' . DIRECTORY_SEPARATOR . $base => 'uploads/bed_images/' . $base,
        $cwd . 'baordinghouseOWNER' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bed_images' . DIRECTORY_SEPARATOR . $base => 'baordinghouseOWNER/uploads/bed_images/' . $base,
        $cwd . 'uploads' . DIRECTORY_SEPARATOR . $base => 'uploads/' . $base,
        dirname($cwd) . DIRECTORY_SEPARATOR . $base => '../' . $base,
        dirname($cwd) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'boarding_houses' . DIRECTORY_SEPARATOR . $base => '../uploads/boarding_houses/' . $base,
        dirname($cwd) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bed_images' . DIRECTORY_SEPARATOR . $base => '../uploads/bed_images/' . $base,
        dirname($cwd) . DIRECTORY_SEPARATOR . 'baordinghouseOWNER' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bed_images' . DIRECTORY_SEPARATOR . $base => '../baordinghouseOWNER/uploads/bed_images/' . $base,
    ];

    $finalWebPath = '';
    foreach ($candidates as $fs => $web) {
        if (@file_exists($fs)) {
            $finalWebPath = $web;
            break;
        }
    }

    // If still empty, allow absolute or remote URLs
    if (empty($finalWebPath)) {
        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            $finalWebPath = $imagePath;
        } elseif (strpos($imagePath, '/') === 0 || strpos($imagePath, 'uploads') === 0) {
            $finalWebPath = $imagePath;
        } else {
            // fallback placeholder
            return '<div style="width:100%;height:180px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#f8f9fa;border:2px dashed #e9ecef;"> <span style="font-size:36px;">🏠</span></div>';
        }
    }

    $finalWebPathEsc = htmlspecialchars($finalWebPath);
    $style = 'max-width:100%;height:auto;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,0.12);display:block;';
    // If caller provided inline style via $attrs, preserve it and remove style attr inside $attrs
    if (stripos($attrs, 'style=') !== false) {
        // keep attrs as-is
        return '<img src="' . $finalWebPathEsc . '" ' . $attrs . ' />';
    }
    return '<img src="' . $finalWebPathEsc . '" ' . $attrs . ' style="' . $style . '" />';
}

function resolveImageWebPath($imagePath) {
    if (empty($imagePath)) return '';
    $cwd = __DIR__ . DIRECTORY_SEPARATOR;
    $base = basename($imagePath);
    $candidates = [
        $cwd . $base => $base,
        $cwd . 'uploads' . DIRECTORY_SEPARATOR . 'boarding_houses' . DIRECTORY_SEPARATOR . $base => 'uploads/boarding_houses/' . $base,
        $cwd . 'uploads' . DIRECTORY_SEPARATOR . 'bed_images' . DIRECTORY_SEPARATOR . $base => 'uploads/bed_images/' . $base,
        $cwd . 'baordinghouseOWNER' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bed_images' . DIRECTORY_SEPARATOR . $base => 'baordinghouseOWNER/uploads/bed_images/' . $base,
        $cwd . 'uploads' . DIRECTORY_SEPARATOR . $base => 'uploads/' . $base,
        dirname($cwd) . DIRECTORY_SEPARATOR . $base => '../' . $base,
        dirname($cwd) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'boarding_houses' . DIRECTORY_SEPARATOR . $base => '../uploads/boarding_houses/' . $base,
        dirname($cwd) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bed_images' . DIRECTORY_SEPARATOR . $base => '../uploads/bed_images/' . $base,
        dirname($cwd) . DIRECTORY_SEPARATOR . 'baordinghouseOWNER' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bed_images' . DIRECTORY_SEPARATOR . $base => '../baordinghouseOWNER/uploads/bed_images/' . $base,
    ];

    $finalWebPath = '';
    foreach ($candidates as $fs => $web) {
        if (@file_exists($fs)) {
            $finalWebPath = $web;
            break;
        }
    }

    if (empty($finalWebPath)) {
        if (filter_var($imagePath, FILTER_VALIDATE_URL)) return $imagePath;
        return $imagePath; // allow relative paths
    }
    return $finalWebPath;
}

$user_sql = "SELECT fullname, email, profile_img, age, gender, address, phone FROM registerusers WHERE email = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("s", $session_email);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();
$user_stmt->close();

$user_name = $user_data['fullname'] ?? $_SESSION['fullname'] ?? 'User';
$user_profile_img = $user_data['profile_img'] ?? '';
$user_age = $user_data['age'] ?? 'N/A';
$user_gender = $user_data['gender'] ?? 'N/A';
$user_address = $user_data['address'] ?? 'N/A';
$user_phone = $user_data['phone'] ?? 'N/A';

$create_table_sql = "
CREATE TABLE IF NOT EXISTS payment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    house_id INT NOT NULL,
    tenant_name VARCHAR(255) NOT NULL,
    payment_month VARCHAR(7) NOT NULL,
    payment_date DATE DEFAULT NULL,
    next_due_date DATE DEFAULT NULL,
    status ENUM('paid', 'unpaid', 'near_due') DEFAULT 'unpaid',
    is_new TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_payment (house_id, tenant_name, payment_month)
)";
$conn->query($create_table_sql);

$bookings_sql = "SELECT * FROM yourbook WHERE TRIM(email) = TRIM(?) ORDER BY id DESC";
$bookings_stmt = $conn->prepare($bookings_sql);
$bookings_stmt->bind_param("s", $session_email);
$bookings_stmt->execute();
$bookings_result = $bookings_stmt->get_result();

$bookings = [];
while ($row = $bookings_result->fetch_assoc()) {
    $bookings[] = $row;
}
$bookings_stmt->close();

// Get count of unread global notifications for this user (uses notification_reads.last_viewed_at)
$notif_count = 0;
if (isset($conn) && !$conn->connect_error) {
    // If notification_reads table exists, count notifications newer than user's last_viewed_at
    $check = $conn->query("SHOW TABLES LIKE 'notification_reads'");
    if ($check && $check->num_rows > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM global_notifications WHERE created_at > COALESCE((SELECT last_viewed_at FROM notification_reads WHERE user_email = ? LIMIT 1), '1970-01-01')");
        if ($stmt) {
            $stmt->bind_param("s", $session_email);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                $r = $res->fetch_assoc();
                $notif_count = isset($r['cnt']) ? (int)$r['cnt'] : 0;
                $res->close();
            }
            $stmt->close();
        }
    } else {
        // Fallback: show total notifications if read-tracking not set up yet
        $notif_res = $conn->query("SELECT COUNT(*) AS cnt FROM global_notifications");
        if ($notif_res) {
            $r = $notif_res->fetch_assoc();
            $notif_count = isset($r['cnt']) ? (int)$r['cnt'] : 0;
            $notif_res->close();
        }
    }
}

$active_bookings_count = 0;
foreach ($bookings as $booking) {
    if ($booking['status'] !== 'cancelled' && $booking['status'] !== 'cancelled_by_owner' && $booking['status'] !== 'declined') {
        $active_bookings_count++;
    }
}

$session_email = trim($_SESSION['email']);
$user_has_confirmed = false;
if (!empty($session_email)) {
    // Consider a user to have a confirmed booking if either:
    // - there is a tenant_requests row with status = 'accepted', OR
    // - there is a yourbook row for this user with status indicating active/accepted
    $confirm_check_sql = "SELECT COUNT(*) as cnt FROM tenant_requests WHERE email = ? AND status = 'accepted'";
    $confirm_stmt = $conn->prepare($confirm_check_sql);
    if ($confirm_stmt) {
        $confirm_stmt->bind_param("s", $session_email);
        $confirm_stmt->execute();
        $res = $confirm_stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($row && isset($row['cnt']) && $row['cnt'] > 0) {
            $user_has_confirmed = true;
        }
        $confirm_stmt->close();
    }

    if (!$user_has_confirmed) {
        // Fallback: check yourbook table for an active/accepted booking
        $yourbook_check_sql = "SELECT COUNT(*) as cnt FROM yourbook WHERE TRIM(email) = TRIM(?) AND status IN ('active', 'accepted')";
        $yb_stmt = $conn->prepare($yourbook_check_sql);
        if ($yb_stmt) {
            $yb_stmt->bind_param("s", $session_email);
            $yb_stmt->execute();
            $yb_res = $yb_stmt->get_result();
            $yb_row = $yb_res ? $yb_res->fetch_assoc() : null;
            if ($yb_row && isset($yb_row['cnt']) && $yb_row['cnt'] > 0) {
                $user_has_confirmed = true;
            }
            $yb_stmt->close();
        }
    }
}

$current_month = date('Y-m');
$current_day = date('d');
$days_in_month = date('t');
$days_remaining = $days_in_month - $current_day;

function getPaymentInfo($conn, $tenant_name, $boarding_house) {
    global $current_month, $days_remaining;

    $house_stmt = $conn->prepare("SELECT id FROM boarding_houses WHERE name = ? LIMIT 1");
    $house_stmt->bind_param("s", $boarding_house);
    $house_stmt->execute();
    $house_result = $house_stmt->get_result();

    if ($house_result && $house_result->num_rows > 0) {
        $house = $house_result->fetch_assoc();
        $house_id = $house['id'];

        $sql = "SELECT * FROM payment_history 
                WHERE house_id = ? AND tenant_name = ? 
                ORDER BY payment_date DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $house_id, $tenant_name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();

            if ($row['payment_month'] == $current_month && $row['status'] == 'paid') {
                return [
                    'status' => 'paid',
                    'payment_date' => $row['payment_date'],
                    'next_due_date' => $row['next_due_date']
                ];
            } else {
                $next_due = date('Y-m-t');
                if ($days_remaining <= 5 && $days_remaining > 0) {
                    return [
                        'status' => 'near_due',
                        'payment_date' => $row['payment_date'] ?? null,
                        'next_due_date' => $next_due
                    ];
                } else {
                    return [
                        'status' => 'unpaid',
                        'payment_date' => $row['payment_date'] ?? null,
                        'next_due_date' => $next_due
                    ];
                }
            }
        } else {
            $next_due = date('Y-m-t');
            if ($days_remaining <= 5 && $days_remaining > 0) {
                return [
                    'status' => 'near_due',
                    'payment_date' => null,
                    'next_due_date' => $next_due
                ];
            } else {
                return [
                    'status' => 'unpaid',
                    'payment_date' => null,
                    'next_due_date' => $next_due
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

$payment_history = [];
$booking_house_ids = [];
$all_payments = [];

foreach ($bookings as $booking) {
    $house_stmt = $conn->prepare("SELECT id FROM boarding_houses WHERE name = ?");
    $house_stmt->bind_param("s", $booking['boardingHouse']);
    $house_stmt->execute();
    $house_result = $house_stmt->get_result();
    $house_data = $house_result->fetch_assoc();
    $house_stmt->close();

    if ($house_data) {
        $house_id = $house_data['id'];
        $booking_house_ids[$booking['id']] = $house_id;

        $payment_stmt = $conn->prepare("SELECT * FROM payment_history WHERE house_id = ? AND tenant_name = ? ORDER BY payment_date DESC LIMIT 5");
        $payment_stmt->bind_param("is", $house_id, $booking['fullName']);
        $payment_stmt->execute();
        $payment_result = $payment_stmt->get_result();

        $booking_payments = [];
        while ($payment = $payment_result->fetch_assoc()) {
            $booking_payments[] = $payment;
            $all_payments[] = $payment;
        }

        $payment_history[$booking['id']] = $booking_payments;
        $payment_stmt->close();
    }
}

usort($all_payments, function($a, $b) {
    return strtotime($b['payment_date'] ?? '0') - strtotime($a['payment_date'] ?? '0');
});

$boarding_houses = [];
foreach ($bookings as $booking) {
    $house_stmt = $conn->prepare("SELECT id, name, map_lat, map_lng, full_location FROM boarding_houses WHERE name = ?");
    $house_stmt->bind_param("s", $booking['boardingHouse']);
    $house_stmt->execute();
    $house_result = $house_stmt->get_result();
    if ($house_data = $house_result->fetch_assoc()) {
        $boarding_houses[$booking['id']] = $house_data;
    }
    $house_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - StayFinder</title>
    <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="logintenant.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --golden-yellow: #FFD700;
            --dark-golden: #FFA500;
        }

        .route-info-box {
          display: none; /* Initially hidden */
          margin-top: 20px;
          padding: 15px;
          background: #ffd700; /* Golden background */
          border-radius: 12px;
          box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
          color: #000;
        }

        .route-info-box.active {
          display: block; /* Shown when route is active */
        }

        .route-info-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          border-bottom: 2px solid rgba(0, 0, 0, 0.1);
          margin-bottom: 12px;
          padding-bottom: 12px;
        }

        .route-close-btn {
          background: rgba(0, 0, 0, 0.1) !important;
          border: none !important;
          color: #000 !important;
          width: 24px;
          height: 24px;
          border-radius: 50%;
          cursor: pointer;
          transition: all 0.3s ease;
          font-weight: bold;
          font-size: 16px;
        }

        .route-close-btn:hover {
          background: rgba(0, 0, 0, 0.2) !important;
        }

        .route-info-item {
          display: flex;
          align-items: center;
          gap: 10px;
          padding: 8px;
          background: rgba(0, 0, 0, 0.05);
          border-radius: 8px;
          color: #000;
        }

                .show-route-btn {
                    background: linear-gradient(135deg, #FFD700, #FFA500) !important;
                    color: #000 !important;
                    border: none !important;
                    padding: 8px 16px !important;
                    border-radius: 6px !important;
                    font-weight: 600 !important;
                    font-size: 12px !important;
                    cursor: pointer !important;
                    transition: all 0.3s ease !important;
                    display: inline-flex !important;
                    align-items: center !important;
                    gap: 6px !important;
                    margin-left: 10px;
                }

                .show-route-btn:hover {
                    background: linear-gradient(135deg, #FFA500, #FF8C00) !important;
                    transform: translateY(-2px) !important;
                }

        .mobile-menu-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 2001;
            background: linear-gradient(135deg, var(--golden-yellow), var(--dark-golden));
            border: none;
            padding: 12px 15px;
            border-radius: 8px;
            color: white;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            display: none; /* Hidden on larger screens */
        }
        
        /* New style to make the active view button stand out */
        .sidebar-btn.active-view {
            background-color: #f1f1f1 !important; /* Lighter background for active button */
            border-left: 4px solid var(--golden-yellow) !important; /* Highlight bar */
            font-weight: bold !important;
        }
        
        /* The payment history view is initially hidden */
        .payment-history-view {
            display: none;
        }
        .payment-history-view.active {
            display: block;
        }
        
        /* The bookings view content should be hidden when in payment view */
        .bookings-view.hidden {
            display: none;
        }

        /* Amenities and Room Details Styling for Dashboard */
        .amenities-list-dashboard {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .amenity-badge-dashboard {
            display: inline-block;
            background: #e9ecef;
            color: #495057;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            border: 1px solid #dee2e6;
            font-weight: 500;
        }

        .room-type-badge-dashboard {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            color: white;
        }

        .room-type-badge-dashboard.single_occupancy {
            background: #FFD700;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
            color: #333;
        }

        .room-type-badge-dashboard.multi_occupancy {
            background: #FFD700;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
            color: #333;
        }

        .pricing-type-badge-dashboard {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            color: white;
        }

        .pricing-type-badge-dashboard.conditional {
            background: #FFD700;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
            color: #333;
        }

        .pricing-type-badge-dashboard.fixed {
            background: #FFD700;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
            color: #333;
        }

        /* Booking section titles: larger and bold as requested */
        .booking-section-title {
            font-size: 20px;
            font-weight: 900;
            color: #111;
            margin-bottom: 12px;
            letter-spacing: 0.5px;
        }

        @media (max-width: 768px) {
            .booking-section-title {
                font-size: 18px;
            }
            .mobile-menu-toggle {
                display: block !important; /* Show on small screens */
            }
        }
        
        /* Main content positioning */
        .main-content {
            position: relative; /* For tenant-badge positioning */
        }

        /* Page header similar to seekerdashboard.php */
        .page-header {
            background: linear-gradient(135deg, var(--golden-yellow) 0%, var(--dark-golden) 100%);
            color: #000;
            padding: 20px 30px 20px 290px; /* Desktop: space for sidebar */
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            font-size: 28px;
            font-weight: 800;
            box-shadow: 0 8px 16px rgba(255, 215, 0, 0.3);
            letter-spacing: 2px;
            font-family: 'Poppins', sans-serif;
            transition: padding 200ms ease, font-size 200ms ease; /* Smooth responsive changes */
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
        }

        /* Responsive adjustments for header */
        @media (max-width: 1200px) {
            .page-header { padding-left: 200px; font-size: 26px; }
        }
        @media (max-width: 992px) {
            .page-header { padding-left: 140px; font-size: 22px; justify-content: flex-start; text-align: left; }
        }
        @media (max-width: 768px) {
            /* On mobile reduce left padding so header fits and show menu toggle */
            .page-header {
                padding: 16px 16px 16px 72px; /* leave room for mobile menu button */
                font-size: 18px;
                justify-content: flex-start;
                text-align: left;
            }
            /* Make sure the tenant badge doesn't overlap header on small screens */
            .tenant-badge { position: static; margin-left: 12px; margin-top: 8px; font-size: 18px; }
        }
        @media (max-width: 480px) {
            .page-header { padding: 12px 12px 12px 64px; font-size: 16px; }
            .tenant-badge { font-size: 14px; }
        }

        /* Notification bell in sidebar profile (moved slightly up, no transform animation) */
        .sidebar-profile {
            position: relative; /* For absolute positioning of bell and badge */
            text-align: center;
            margin-bottom: 30px;
        }

        .notification-bell {
            position: absolute;
            top: 6px; /* moved a little higher */
            right: 12px;
            background: #ffd700;
            color: #000;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            border: 3px solid #000;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            transition: box-shadow 0.18s ease; /* subtle hover only */
            text-decoration: none;
        }

        .notification-bell:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.16);
        }

        /* Small red notification badge (count) */
        .notification-count {
            position: absolute;
            top: -8px;
            right: -8px;
            background-color: #e74c3c;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            font-size: 12px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }

        /* Fancy Tenant header (prominent, not circular) */
        .tenant-badge {
            position: absolute;
            top: 12px;
            right: 92px; /* leave space for the bell */
            background: linear-gradient(90deg, #ffd54a 0%, #ffb74d 100%);
            color: #111;
            padding: 6px 16px;
            border-radius: 12px; /* subtle rounded rectangle, not pill */
            font-weight: 900;
            letter-spacing: 1px;
            text-transform: uppercase;
            font-size: 28px; /* much larger */
            line-height: 1;
            box-shadow: 0 10px 30px rgba(255, 183, 77, 0.12);
            border: 1px solid rgba(0,0,0,0.06);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        /* hide the small circular icon — user requested no circle */
        .tenant-badge .badge-icon {
            display: none;
        }
        
        /* Specific class for booking pending alerts */
        .alert.booking-pending {
            background-color: #fff3cd;
            border-color: #ffeeba;
            color: #856404;
        }

        /* Styles for the payment history table */
        .payment-table-container {
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 25px;
            overflow-x: auto; /* Add horizontal scroll for small screens */
        }

        .payment-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 10px; /* Space between rows */
            font-size: 0.95rem;
        }

        .payment-table thead tr {
            background-color: #f8f9fa; /* Light gray header */
        }

        .payment-table th,
        .payment-table td {
            padding: 15px 20px;
            text-align: left;
            border-bottom: 1px solid #dee2e6; /* Subtle row separator */
        }

        .payment-table tbody tr:last-child td {
            border-bottom: none; /* No border on the last row's cells */
        }

        .payment-table th {
            font-weight: 700;
            color: #495057;
            letter-spacing: 0.5px;
        }
        
        /* Rounded corners for the first and last cells in header and body */
        .payment-table th:first-child, .payment-table td:first-child {
            border-top-left-radius: 8px;
            border-bottom-left-radius: 8px;
        }

        .payment-table th:last-child, .payment-table td:last-child {
            border-top-right-radius: 8px;
            border-bottom-right-radius: 8px;
        }


        .payment-status {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .payment-status.paid {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .payment-status.unpaid {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .payment-status.near_due {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
            color: #333; /* Darker text for better readability on yellow */
        }
        
        /* Custom alert for booking pending */
        .alert-golden {
            background-color: #fff9e6;
            border-color: #ffe8b3;
            color: #8a6d00;
        }
        
        .alert-golden h6 {
            font-weight: 700;
        }

        /* Responsive sidebar toggle */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                width: 280px; /* Width of the sidebar */
                background-color: #ffffff;
                box-shadow: 2px 0 15px rgba(0,0,0,0.1);
                z-index: 2000;
                transition: transform 0.3s ease-in-out;
                overflow-y: auto; /* Allows scrolling if content is too long */
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 1999;
                display: none; /* Hidden by default */
                opacity: 0;
                transition: opacity 0.3s ease-in-out;
            }
            .sidebar-overlay.active {
                display: block;
                opacity: 1;
            }
        }

        /* Style for the map container itself */
        .booking-map {
            width: 100%;
            height: 400px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

<button class="mobile-menu-toggle" onclick="toggleMobileSidebar()">
    <i class="fas fa-bars"></i>
</button>
<div class="sidebar-overlay" onclick="toggleMobileSidebar()"></div>

    <header class="page-header">
 RENTER DASHBOARD
    </header>

<div class="dashboard-container">
    <div class="sidebar">
        <div class="sidebar-section">
            <div class="sidebar-profile">
                <?php if (!empty($user_profile_img) && file_exists($user_profile_img)): ?>
                    <img src="<?php echo htmlspecialchars($user_profile_img); ?>" alt="Profile" class="profile-avatar">
                <?php else: ?>
                    <div class="profile-placeholder">
                        <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <a href="notif.php" class="notification-bell" title="Notifications" aria-label="Notifications">
                    <i class="fas fa-bell" aria-hidden="true"></i>
                </a>
                <div class="profile-name"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="profile-email"><?php echo htmlspecialchars($session_email); ?></div>
            </div>
            
            <div class="sidebar-item">
                <span class="sidebar-item-label">Active Bookings</span>
                <span class="sidebar-item-value"><?php echo $active_bookings_count; ?></span>
            </div>
        </div>
        <div class="sidebar-buttons">
            <a href="profile.php" class="sidebar-btn btn-boarding-houses">
                <i class="fas fa-user"></i> Account Info
            </a>
            <button class="sidebar-btn btn-boarding-houses active-view" onclick="toggleView('bookings')">
                <i class="fas fa-home"></i> My Bookings
            </button>
            <button class="sidebar-btn btn-boarding-houses" onclick="toggleView('payments')">
                <i class="fas fa-history"></i> My Payment History
            </button>
            <a href="auseregisterlogform/login.php?logout=1" class="sidebar-btn btn-logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
    
    <div class="main-content">
        <div class="content-header">

            <div>
                <h1 class="content-title" id="page-title">My Bookings</h1>
                <p class="content-subtitle" id="page-subtitle">Manage your boarding house bookings and payments</p>
            </div>
            </div>

        <div class="bookings-view" id="bookings-view">
            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🏠</div>
                    <h4>No Bookings Yet</h4>
                    <p>You haven't made any bookings yet. Start exploring boarding houses!</p>
                    <a href="seekerdashboard.php" class="btn btn-primary mt-3">
                        <i class="fas fa-search"></i> Browse Boarding Houses
                    </a>
                </div>
            <?php else: ?>
    <?php foreach ($bookings as $booking): ?>
    <?php
  $yourbook_status = $booking['status'] ?? 'active';
    
    // ✅ FIXED: Check yourbook status FIRST before looking at tenant_requests
    $booking_status = 'pending';
    
    // Priority 1: Check if terminated by owner
    if ($yourbook_status === 'cancelled_by_owner') {
        $booking_status = 'cancelled_by_owner'; // TERMINATED
    }
    // Priority 2: Check if cancelled by tenant
    elseif ($yourbook_status === 'cancelled') {
        $booking_status = 'cancelled';
    }
    // Priority 3: Check tenant_requests table for accepted/pending/declined
    else {
        if (!empty($booking['tenant_request_id'])) {
            // If we have a tenant_request_id, use it for precise matching
            $status_sql = "SELECT status FROM tenant_requests WHERE id = ?";
            $status_stmt = $conn->prepare($status_sql);
            $status_stmt->bind_param("i", $booking['tenant_request_id']);
            $status_stmt->execute();
            $status_result = $status_stmt->get_result();
            
            if ($status_result && $status_result->num_rows > 0) {
                $status_row = $status_result->fetch_assoc();
                $booking_status = $status_row['status'];
            }
        } else {
            // Fallback: Try to find the tenant_request
            $house_id_sql = "SELECT id FROM boarding_houses WHERE name = ? LIMIT 1";
            $house_id_stmt = $conn->prepare($house_id_sql);
            $house_id_stmt->bind_param("s", $booking['boardingHouse']);
            $house_id_stmt->execute();
            $house_id_result = $house_id_stmt->get_result();
            
            if ($house_id_result && $house_id_result->num_rows > 0) {
                $house_data = $house_id_result->fetch_assoc();
                $house_id = $house_data['id'];
                
                // ✅ CRITICAL FIX: Get MOST RECENT request matching THIS booking
                $find_request_sql = "SELECT id, status FROM tenant_requests 
                                    WHERE house_id = ? AND email = ? AND full_name = ? AND start_date = ?
                                    ORDER BY id DESC LIMIT 1";
                $find_request_stmt = $conn->prepare($find_request_sql);
                $find_request_stmt->bind_param("isss", $house_id, $booking['email'], $booking['fullName'], $booking['startDate']);
                $find_request_stmt->execute();
                $find_result = $find_request_stmt->get_result();
                
                if ($find_result && $find_result->num_rows > 0) {
                    $request_data = $find_result->fetch_assoc();
                    $booking_status = $request_data['status'];
                    
                    // Update the booking record with the tenant_request_id for future lookups
                    $update_booking = "UPDATE yourbook SET tenant_request_id = ? WHERE id = ?";
                    $update_stmt = $conn->prepare($update_booking);
                    $update_stmt->bind_param("ii", $request_data['id'], $booking['id']);
                    $update_stmt->execute();
                }
            }
        }
    }

    $is_owner_cancelled = ($yourbook_status === 'cancelled_by_owner');
    $is_tenant_cancelled = ($yourbook_status === 'cancelled');
    $is_terminated = ($yourbook_status === 'cancelled_by_owner');
    $is_declined = ($yourbook_status === 'declined');
    $is_cancelled = ($yourbook_status === 'cancelled');

    if ($is_terminated) {
        $display_status_label = 'TERMINATED';
        $status_class = 'terminated-badge';
    } elseif ($is_tenant_cancelled) {
        $display_status_label = 'CANCELLED';
        $status_class = 'cancelled-badge';
    } elseif ($is_declined) {
        $display_status_label = 'DECLINED';
        $status_class = 'declined-badge';
    } else {
        $display_status_label = ucfirst($booking_status ?? ($booking['status'] ?? 'Active'));
        // ... (handle other statuses like 'accepted', 'pending')
    }
  
    $payment_info = getPaymentInfo($conn, $booking['fullName'], $booking['boardingHouse']);
    $payment_status = $payment_info['status'];
    $payment_date = $payment_info['payment_date'];
    $next_due_date = $payment_info['next_due_date'];
    ?>
                <div class="booking-card">
                    <div class="booking-card-header">
                        <div class="booking-house-name">
                            <i class="fas fa-building"></i> <?php echo htmlspecialchars($booking['boardingHouse']); ?>
                        </div>
                        <span class="booking-status-badge">
                            <?php echo htmlspecialchars($display_status_label); ?>
                        </span>
                    </div>
                    
                    
                    <div class="booking-card-body">
                        <div class="price-display">
                            <div class="price-label">Monthly Price</div>
                            <div class="price-value"><?php echo htmlspecialchars($booking['price']); ?></div>
                        </div>
                        
                        <?php if ($is_terminated && $booking_status !== 'accepted'): ?>
                            <?php 
                                $owner_info = getOwnerContactInfo($conn, $booking['boardingHouse']);
                            ?>
                            <div class="alert alert-danger text-center mb-3" style="background-color: #f8d7da; border-color: #f5c6cb;">
                                <h6 class="alert-heading mb-2" style="color: #721c24;">❌ TERMINATED</h6>
                                <p class="mb-2 small" style="color: #721c24;">Your booking has been terminated by the owner. Please contact the owner for more information or try booking again:</p>
                                <div style="background-color: rgba(0,0,0,0.1); padding: 12px; border-radius: 6px; margin: 10px 0;">
                                    <?php if ($owner_info && !empty($owner_info['owner_email'])): ?>
                                        <p class="mb-1" style="color: #721c24;"><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($owner_info['owner_email']); ?>" style="color: #721c24; text-decoration: underline; font-weight: bold;"><?php echo htmlspecialchars($owner_info['owner_email']); ?></a></p>
                                    <?php endif; ?>
                                    <?php if ($owner_info && !empty($owner_info['owner_phone'])): ?>
                                        <p class="mb-0" style="color: #721c24;"><strong>Phone:</strong> <a href="tel:<?php echo htmlspecialchars($owner_info['owner_phone']); ?>" style="color: #721c24; text-decoration: underline; font-weight: bold;"><?php echo htmlspecialchars($owner_info['owner_phone']); ?></a></p>
                                    <?php endif; ?>
                                </div>
                                <?php // Removed the "Book Again" button section for terminated bookings ?>
                            </div>
                    <?php elseif ($is_declined): ?>
    <?php 
        $owner_info = getOwnerContactInfo($conn, $booking['boardingHouse']);
    ?>
    <div class="alert alert-warning text-center mb-3">
        <h6 class="alert-heading mb-2">BOOKING DECLINED</h6>
        <p class="mb-2 small fst-italic">The owner has declined your request. Please try again or contact the owner for more information.</p>
        <div style="background-color: rgba(0,0,0,0.1); padding: 12px; border-radius: 6px; margin: 10px 0;">
            <?php if ($owner_info && !empty($owner_info['owner_email'])): ?>
                <p class="mb-1"><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($owner_info['owner_email']); ?>" style="color: #856404; text-decoration: underline; font-weight: bold;"><?php echo htmlspecialchars($owner_info['owner_email']); ?></a></p>
            <?php endif; ?>
            <?php if ($owner_info && !empty($owner_info['owner_phone'])): ?>
                <p class="mb-0"><strong>Phone:</strong> <a href="tel:<?php echo htmlspecialchars($owner_info['owner_phone']); ?>" style="color: #856404; text-decoration: underline; font-weight: bold;"><?php echo htmlspecialchars($owner_info['owner_phone']); ?></a></p>
            <?php endif; ?>
        </div>
    </div>
                        <?php elseif ($is_cancelled && $booking_status !== 'accepted'): ?>
                            <div class="alert alert-info text-center mb-3">
                                <h6 class="alert-heading mb-2">BOOKING CANCELLED</h6>
                                <p class="mb-0 small fst-italic">You have cancelled this booking. You can make a new booking anytime.</p>
                            </div>
              <?php elseif ($booking_status === 'accepted'): ?>
                            <div class="alert alert-success text-center mb-3">
                                <h6 class="alert-heading mb-0">Your booking has been confirmed!</h6>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-golden text-center mb-3 booking-pending">
                                <h6 class="alert-heading mb-2">Your booking still pending</h6>
                                <p class="mb-0 small fst-italic">Please wait for the owner to review and approve your booking request.</p>
                            </div>
                        <?php endif; ?>

                        
                        <?php if ($booking_status === 'accepted' && !$is_cancelled && !$is_terminated && !$is_declined): ?>
                        <div class="text-center mb-3">
                            <span class="payment-status-badge-large status-<?php echo $payment_status; ?>">
                                <?php 
                                if ($payment_status == 'paid') echo 'PAID';
                                elseif ($payment_status == 'near_due') echo 'NEAR DUE';
                                else echo 'UNPAID';
                                ?>
                            </span>
                        </div>
                        
                        <div class="payment-info-cards">
                            <div class="payment-info-card">
                                <span class="payment-info-label">Last Payment</span>
                                <div class="payment-info-value">
                                    <?php echo $payment_date ? date('M d, Y', strtotime($payment_date)) : 'Not paid yet'; ?>
                                </div>
                            </div>
                            <div class="payment-info-card">
                                <span class="payment-info-label">Next Due</span>
                                <div class="payment-info-value text-danger">
                                    <?php echo date('M d, Y', strtotime($next_due_date)); ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="booking-content">
                            <div class="booking-section">
                                <h5 class="booking-section-title">Your Information</h5>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">Full Name</div>
                                    <div class="booking-info-value"><?php echo htmlspecialchars($booking['fullName']); ?></div>
                                </div>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">Email</div>
                                    <div class="booking-info-value"><?php echo htmlspecialchars($booking['email']); ?></div>
                                </div>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">Age</div>
                                    <div class="booking-info-value"><?php echo htmlspecialchars($booking['age']); ?></div>
                                </div>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">Gender</div>
                                    <div class="booking-info-value"><?php echo ucfirst(htmlspecialchars($booking['gender'])); ?></div>
                                </div>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">Phone</div>
                                    <div class="booking-info-value"><?php echo htmlspecialchars($booking['phone']); ?></div>
                                </div>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">Address</div>
                                    <div class="booking-info-value"><?php echo htmlspecialchars($booking['address']); ?></div>
                                </div>
                         <?php
    // Get house and bed images
    $house_image = getHouseImage($conn, $booking['boardingHouse']);
    $bed_image = getBedImage($conn, $booking['boardingHouse'], $booking['availableSlots']);
?>
                            </div>
                            
                            <!-- IMAGES SECTION: House Image and Bed Image -->
                            <div class="booking-section">
                                <h5 class="booking-section-title">🖼️ Images</h5>
                                
                                <!-- House Image -->
                                <?php if (!empty($house_image)): ?>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">🏠 House Image</div>
                                    <div class="booking-info-value">
                                        <div style="width:100%;max-width:500px;">
                                            <?php $house_web = resolveImageWebPath($house_image); ?>
                                            <a href="<?php echo htmlspecialchars($house_web); ?>" target="_blank" rel="noopener noreferrer">
                                                <?php echo generateImageTag($house_image, 'style="width:100%;height:280px;object-fit:cover;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.15);"'); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">🏠 House Image</div>
                                    <div class="booking-info-value text-muted fst-italic">No house image available</div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Bed Image -->
                                <?php if (!empty($bed_image)): ?>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">🛏️ Bed Image</div>
                                    <div class="booking-info-value">
                                        <div style="width:100%;max-width:500px;">
                                            <?php $bed_web = resolveImageWebPath($bed_image); ?>
                                            <a href="<?php echo htmlspecialchars($bed_web); ?>" target="_blank" rel="noopener noreferrer">
                                                <?php echo generateImageTag($bed_image, 'style="width:100%;height:280px;object-fit:cover;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.15);"'); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">🛏️ Bed Image</div>
                                    <div class="booking-info-value text-muted fst-italic">No bed image available</div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="booking-section">
                                <h5 class="booking-section-title">Boarding House Info</h5>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">House Name</div>
                                    <div class="booking-info-value"><?php echo htmlspecialchars($booking['boardingHouse']); ?></div>
                                </div>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">Room & Bed</div>
                                    <div class="booking-info-value"><?php echo htmlspecialchars($booking['availableSlots']); ?></div>
                                </div>
                                <?php 
                                    $room_details = getRoomDetails($conn, $booking['boardingHouse'], $booking['availableSlots']);
                                    
                                    // Display room amenities
                                    if (!empty($room_details['amenities'])) {
                                        echo '<div class="booking-info-item">
                                                <div class="booking-info-label">Amenities</div>
                                                <div class="booking-info-value">';
                                        $amenities = explode(',', $room_details['amenities']);
                                        foreach ($amenities as $amenity) {
                                            echo '<span class="amenity-badge-dashboard">' . htmlspecialchars(trim($amenity)) . '</span>';
                                        }
                                        echo '</div>
                                            </div>';
                                    }
                                    
                                    // Display occupancy type
                                    if (!empty($room_details['occupancy_type'])) {
                                        $occupancy_text = ($room_details['occupancy_type'] === 'single_occupancy') ? 'Single Occupancy' : 'Multi Occupancy';
                                        $occupancy_class = $room_details['occupancy_type'];
                                        echo '<div class="booking-info-item">
                                                <div class="booking-info-label">Occupancy Type</div>
                                                <div class="booking-info-value">
                                                    <span class="room-type-badge-dashboard ' . $occupancy_class . '">' . htmlspecialchars($occupancy_text) . '</span>
                                                </div>
                                            </div>';
                                    }
                                    
                                    // Display pricing type
                                    if (!empty($room_details['pricing_type'])) {
                                        $pricing_text = ($room_details['pricing_type'] === 'conditional') ? 'Per Head Pricing' : 'Per Room Pricing';
                                        $pricing_class = $room_details['pricing_type'];
                                        echo '<div class="booking-info-item">
                                                <div class="booking-info-label">Pricing Type</div>
                                                <div class="booking-info-value">
                                                    <span class="pricing-type-badge-dashboard ' . $pricing_class . '">' . htmlspecialchars($pricing_text) . '</span>
                                                </div>
                                            </div>';
                                    }
                                ?>
                                
                                <?php if (isset($boarding_houses[$booking['id']]) && !empty($boarding_houses[$booking['id']]['full_location'])): ?>
                                <div class="booking-info-item">
                                    <div class="booking-info-label">Location</div>
                                    <div class="booking-info-value"><?php echo htmlspecialchars($boarding_houses[$booking['id']]['full_location']); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (isset($boarding_houses[$booking['id']]) && !empty($boarding_houses[$booking['id']]['map_lat']) && !empty($boarding_houses[$booking['id']]['map_lng'])): ?>
                        <div class="booking-map-container" style="margin-top: 20px;">
                            <h5 class="booking-section-title">Location Map</h5>
                            <div id="bookingMapContainer-<?php echo $booking['id']; ?>" class="booking-map" 
                                 data-lat="<?php echo htmlspecialchars($boarding_houses[$booking['id']]['map_lat']); ?>"
                                 data-lng="<?php echo htmlspecialchars($boarding_houses[$booking['id']]['map_lng']); ?>"
                                 data-house="<?php echo htmlspecialchars($booking['boardingHouse']); ?>"
                                 data-booking-id="<?php echo $booking['id']; ?>"
                                 style="height: 400px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);"></div>
                            
                            <div style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                                <button class="show-route-btn" onclick="showRouteToBoardingDashboard(<?php echo $booking['id']; ?>)">
                                    <i class="fas fa-route"></i> Show Route
                                </button>
                            </div>

                            <div id="routeInfoContainer-<?php echo $booking['id']; ?>" class="route-info-box d-none"></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!$is_declined && !$is_cancelled && !$is_terminated && $booking_status !== 'accepted'): ?>
                        <div class="booking-actions">
                            <button class="btn-action btn-cancel" onclick="openCancelModal(<?php echo $booking['id']; ?>)">
                                <i class="fas fa-times"></i> Cancel Booking
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="booking-actions">
                            <p class="text-center text-muted fst-italic">
                                <?php 
                                if ($is_declined) {
                                    echo 'Your request has been declined - Please try again or contact the owner';
                                } elseif ($is_terminated) {
                                    echo 'This booking has been terminated by the owner - Please contact them for more information';
                                } elseif ($booking_status === 'accepted') {
                                    echo 'Your booking is confirmed - You cannot cancel a confirmed booking';
                                } else {
                                    echo 'This booking has been cancelled - You can make a new booking anytime';
                                }
                                ?>
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($payment_history[$booking['id']])): ?>
                        <button class="payment-history-toggle" onclick="togglePaymentHistory(this)">
                            <span><i class="fas fa-chevron-down"></i> Payment History (<?php echo count($payment_history[$booking['id']]); ?>)</span>
                        </button>
                        <div class="payment-history-content">
                            <?php foreach ($payment_history[$booking['id']] as $payment): ?>
                            <div class="payment-item">
                                <div class="payment-info">
                                    <div><?php 
                                        $date = DateTime::createFromFormat('Y-m', $payment['payment_month']);
                                        echo $date ? $date->format('F Y') : htmlspecialchars($payment['payment_month']);
                                    ?></div>
                                    <div class="payment-month">
                                        Paid: <?php echo $payment['payment_date'] ? date("M d, Y", strtotime($payment['payment_date'])) : "—"; ?>
                                    </div>
                                </div>
                                <span class="payment-status <?php echo strtolower($payment['status']); ?>">
                                    <?php 
                                    if ($payment['status'] == 'paid') echo 'Paid';
                                    elseif ($payment['status'] == 'near_due') echo 'Due';
                                    else echo 'Unpaid';
                                    ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="payment-history-view" id="payment-history-view">
            <?php if (empty($all_payments)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">💰</div>
                    <h4>No Payment Records</h4>
                    <p>You don't have any payment records yet.</p>
                </div>
            <?php else: ?>
                <div class="payment-table-container">
                <table class="payment-table">
    <thead>
        <tr>
            <th>Tenant</th>
            <th>Boarding House</th>
            <th>Month</th>
            <!-- Added Payment Amount column header -->
            <th>Payment Amount</th>
            <th>Payment Date</th>
            <th>Next Due</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($all_payments as $payment): ?>
        <tr>
            <td><?php echo htmlspecialchars($payment['tenant_name']); ?></td>
            <td>
                <?php 
                $house_name = '';
                foreach ($bookings as $booking) {
                    if (isset($booking_house_ids[$booking['id']]) && $booking_house_ids[$booking['id']] == $payment['house_id']) {
                        $house_name = $booking['boardingHouse'];
                        break;
                    }
                }
                echo htmlspecialchars($house_name);
                ?>
            </td>
            <td>
                <?php 
                    $date = DateTime::createFromFormat('Y-m', $payment['payment_month']);
                    echo $date ? $date->format('F Y') : htmlspecialchars($payment['payment_month']);
                ?>
            </td>
            <!-- Added Payment Amount cell - displays room price for tenant -->
            <td>
                <?php
                $house_id = $payment['house_id'];
                $tenant_name = $payment['tenant_name'];
                
                $price_sql = "SELECT price FROM room_prices 
                              WHERE house_id = ? 
                              AND room_number = (
                                SELECT room_number FROM tenant_requests 
                                WHERE house_id = ? AND full_name = ? LIMIT 1
                              ) LIMIT 1";
                
                $price_stmt = $conn->prepare($price_sql);
                $price_stmt->bind_param("iis", $house_id, $house_id, $tenant_name);
                $price_stmt->execute();
                $price_result = $price_stmt->get_result();
                
                if ($price_result->num_rows > 0) {
                    $price_row = $price_result->fetch_assoc();
                 echo '<strong>₱' . htmlspecialchars($price_row['price']) . '</strong>';
                } else {
                    echo "—";
                }
                $price_stmt->close();
                ?>
            </td>
            <td><?php echo $payment['payment_date'] ? date("M d, Y", strtotime($payment['payment_date'])) : "—"; ?></td>
            <td><?php echo $payment['next_due_date'] ? date("M d, Y", strtotime($payment['next_due_date'])) : "—"; ?></td>
            <td>
                <span class="payment-status <?php echo strtolower($payment['status']); ?>">
                    <?php 
                    if ($payment['status'] == 'paid') echo 'Paid';
                    elseif ($payment['status'] == 'near_due') echo 'Due';
                    else echo 'Unpaid';
                    ?>
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title text-danger" id="cancelModalLabel">Cancel Booking</h5>
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

<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($_ENV['GOOGLE_MAPS_API_KEY'] ?? ''); ?>&libraries=places&callback=initializeDashboardMaps" async defer></script>
<script>
var cancelId = null;
var cancelModal = null;
document.addEventListener("DOMContentLoaded", function() {
    cancelModal = new bootstrap.Modal(document.getElementById('cancelModal'));
    // initializeDashboardMaps(); // Moved initialization inside the callback for async loading
});


let bookingMaps = {};
let currentRouteLayer = null;
let currentDestinationMarker = null;

function showRouteToBoardingDashboard(bookingId) {
    const allBookings = <?php echo json_encode($bookings); ?>;
    const booking = allBookings.find(b => b.id == bookingId);
    if (!booking) return;

    const houses = <?php echo json_encode($boarding_houses); ?>;
    const house = houses[bookingId];
    if (!house) return;

    const houseLat = parseFloat(house.map_lat);
    const houseLng = parseFloat(house.map_lng);

    navigator.geolocation.getCurrentPosition(
        position => {
            const userLat = position.coords.latitude;
            const userLng = position.coords.longitude;
            drawRouteDashboard(bookingId, { lat: userLat, lng: userLng }, { lat: houseLat, lng: houseLng }, "Your Location", booking.boardingHouse);
        },
        error => {
            const defaultLat = 8.359; // Example fallback coordinates (e.g., in the Philippines)
            const defaultLng = 123.843;
            drawRouteDashboard(bookingId, { lat: defaultLat, lng: defaultLng }, { lat: houseLat, lng: houseLng }, "Default Location", booking.boardingHouse);
        }
    );
}

// <REPLACE> The existing drawRouteDashboard function
function drawRouteDashboard(bookingId, startCoords, endCoords, startName, houseName) {
    const map = bookingMaps[bookingId];
    if (!map) return;

    // Clear previous route
    if (currentRouteLayer) {
        currentRouteLayer.setMap(null);
    }
    if (currentDestinationMarker) {
        currentDestinationMarker.setMap(null);
    }

    const directionsService = new google.maps.DirectionsService();
    const directionsRenderer = new google.maps.DirectionsRenderer({
        map: map,
        polylineOptions: {
            strokeColor: '#4A90E2',
            strokeOpacity: 0.8,
            strokeWeight: 5
        },
        suppressMarkers: true,
        preserveViewport: false // Important for allowing map to resize to fit route
    });

    const request = {
        origin: startCoords,
        destination: endCoords,
        travelMode: google.maps.TravelMode.DRIVING
    };

    directionsService.route(request, function(result, status) {
        if (status == google.maps.DirectionsStatus.OK) {
            directionsRenderer.setDirections(result);
            currentRouteLayer = directionsRenderer;

            // Add custom destination marker
            currentDestinationMarker = new google.maps.Marker({
                position: endCoords,
                map: map,
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 8,
                    fillColor: '#FF6B6B',
                    fillOpacity: 1,
                    strokeColor: '#FFFFFF',
                    strokeWeight: 2
                }
            });

            const route = result.routes[0];
            const leg = route.legs[0];
            const distanceKm = (leg.distance.value / 1000).toFixed(2);
            const durationMin = Math.round(leg.duration.value / 60);

            const routeInfoContainer = document.getElementById('routeInfoContainer-' + bookingId);
            routeInfoContainer.innerHTML = `
                <div class="route-info-header">
                    <strong>Route to ${houseName}</strong>
                    <button class="route-close-btn" onclick="clearRouteDashboard(${bookingId})">✕</button>
                </div>
                <div class="route-info-item">
                    <i class="fas fa-route"></i>
                    <span><strong>${distanceKm}km</strong> | <strong>~${durationMin}min</strong></span>
                </div>
            `;
            routeInfoContainer.classList.remove('d-none'); // Using 'd-none' from Bootstrap
        } else {
            console.error('Route error:', status);
            alert('Failed to fetch route. Please try again.');
        }
    });
}

function clearRouteDashboard(bookingId) {
    const map = bookingMaps[bookingId]; // Get the specific map for this booking
    if (!map) return; // Exit if map is not found

    // Clear previous route layer if it exists
    if (currentRouteLayer) {
        currentRouteLayer.setMap(null);
        currentRouteLayer = null;
    }
    // Remove the custom destination marker if it exists
    if (currentDestinationMarker) {
        currentDestinationMarker.setMap(null);
        currentDestinationMarker = null;
    }


    const routeInfoContainer = document.getElementById('routeInfoContainer-' + bookingId);
    routeInfoContainer.classList.add('d-none'); // Use Bootstrap's 'd-none' class for hiding
    routeInfoContainer.innerHTML = ''; // Clear its content
}

document.addEventListener('DOMContentLoaded', function() {
    const mapElements = document.querySelectorAll('.booking-map'); // Select elements with class 'booking-map'

    const boardingHouses = <?php echo json_encode($boarding_houses); ?>;

    mapElements.forEach(mapElement => {
        const lat = parseFloat(mapElement.getAttribute('data-lat'));
        const lng = parseFloat(mapElement.getAttribute('data-lng'));
        const houseName = mapElement.getAttribute('data-house');
        const bookingId = mapElement.getAttribute('data-booking-id');

        if (!isNaN(lat) && !isNaN(lng)) {
            // Create Google Map with scroll wheel enabled
            const map = new google.maps.Map(mapElement, {
                center: { lat: lat, lng: lng },
                zoom: 17,
                mapTypeId: google.maps.MapTypeId.HYBRID, // Hybrid map type
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
                scrollwheel: true, // Enable scroll wheel zoom
                gestureHandling: 'greedy', // Allow gestures for map interaction
                disableDoubleClickZoom: false // Allow double-click to zoom
            });

            // Create Google Maps Marker
            const marker = new google.maps.Marker({
                position: { lat: lat, lng: lng },
                map: map,
                title: houseName,
                icon: {
                    url: 'img/icons/house_9408891.png', // Custom marker icon
                    scaledSize: new google.maps.Size(40, 40),
                    anchor: new google.maps.Point(20, 40)
                }
            });

            // Get the house ID from the boarding_houses array using the bookingId
            const houseData = boardingHouses[bookingId];
            const houseId = houseData ? houseData.id : '';
            
            // Create Google Maps Info Window with View Details link
            const infoWindow = new google.maps.InfoWindow({
                content: `
                    <div style="text-align: center; padding: 5px;">
                        <strong style="display: block; margin-bottom: 8px;">${houseName}</strong>
<a href="accommodationoverview/view_house_details.php?id=${houseId}"
                                    style="display: inline-block; padding: 6px 12px; background: linear-gradient(135deg,#FFD700,#FFA500); color: #000; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                    View Details
                                </a>
                    </div>
                `
            });

            marker.addListener('click', function() {
                infoWindow.open(map, marker);
            });

            bookingMaps[bookingId] = map; // Store map instance for later use

            // Open info window by default after a short delay
            setTimeout(() => {
                infoWindow.open(map, marker);
            }, 500);
        }
    });
});

function openCancelModal(id) {
    cancelId = id;
    cancelModal.show();
}

function confirmCancel() {
    if (!cancelId) return;

    const data = new URLSearchParams();
    data.append('cancel_id', cancelId);

    fetch("dashboardprofile.php", {
        method: "POST",
        body: data
    })
    .then(response => response.text())
    .then(data => {
        if (data.trim() === "success") {
            alert("BOOKING CANCELLED\nYou have cancelled this booking. You can make a new booking anytime.");
            // Stay on current page and refresh to show updated booking status
            location.reload();
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

function toggleMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    
    sidebar.classList.toggle('active');
    overlay.classList.toggle('active');
}

document.addEventListener('DOMContentLoaded', function() {
    // Close mobile sidebar when a link/button is clicked
    if (window.innerWidth <= 768) {
        const sidebarLinks = document.querySelectorAll('.sidebar a, .sidebar button');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', function(e) {
    
                if (!this.classList.contains('payment-history-toggle') && !this.classList.contains('show-route-btn')) {
                    setTimeout(() => {
                        const sidebar = document.querySelector('.sidebar');
                        const overlay = document.querySelector('.sidebar-overlay');
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                    }, 300); // Short delay for transition
                }
            });
        });
    }
});

function toggleView(view) {
    const bookingsView = document.getElementById('bookings-view');
    const paymentView = document.getElementById('payment-history-view');
    const allViewButtons = document.querySelectorAll('.sidebar-buttons .sidebar-btn[onclick^="toggleView"]');
    const pageTitle = document.getElementById('page-title');
    const pageSubtitle = document.getElementById('page-subtitle');
    
    // 1. Remove active class from all view buttons
    allViewButtons.forEach(btn => btn.classList.remove('active-view'));
    
    if (view === 'bookings') {
        // 2. Set view visibility
        bookingsView.classList.remove('hidden');
        paymentView.classList.remove('active');
        

        document.querySelector('.sidebar-buttons .sidebar-btn[onclick="toggleView(\'bookings\')"]').classList.add('active-view');
        

        pageTitle.textContent = 'My Bookings';
        pageSubtitle.textContent = 'Manage your boarding house bookings and payments';

        setTimeout(initializeDashboardMaps, 100); 
    } else {

        bookingsView.classList.add('hidden');
        paymentView.classList.add('active');
        
        // 3. Set active button class
        document.querySelector('.sidebar-buttons .sidebar-btn[onclick="toggleView(\'payments\')"]').classList.add('active-view');
        
        // 4. Update header text
        pageTitle.textContent = 'Payment History';
        pageSubtitle.textContent = 'View all your payment records';
    }
}

function togglePaymentHistory(button) {
    const content = button.nextElementSibling; // The content is the element immediately following the button
    content.classList.toggle('show'); // Toggle a class to show/hide
    
    const icon = button.querySelector('i'); // Get the icon element
    if (content.classList.contains('show')) {
        // If content is shown, change icon to up arrow
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
    } else {
        // If content is hidden, change icon to down arrow
        icon.classList.remove('fa-chevron-up');
        icon.classList.add('fa-chevron-down');
    }
}

function initializeDashboardMaps() {

    document.dispatchEvent(new Event('DOMContentLoaded'));
}
</script>
<?php
mysqli_close($conn);
?>
