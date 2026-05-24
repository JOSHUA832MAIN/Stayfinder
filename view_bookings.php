<?php
session_start();
date_default_timezone_set('Asia/Manila');
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (isset($_GET['house_id'])) {
    // If house_id is provided in URL, allow access
    $house_id = $_GET['house_id'];
} elseif (!isset($_SESSION['house_owner_logged_in']) || !$_SESSION['house_owner_logged_in']) {
    // Only redirect if no house_id AND not logged in
    header("Location: logintenatboardinghouseOWNER.php");
    exit();
}

require_once '../connectiondatabase/main_connection.php';

// ✅ Load PHPMailer
require_once '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$envPath = dirname(__DIR__, 2) . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0 || !str_contains($line, '=')) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        putenv(trim($key) . '=' . trim($value));
    }
}

function sendOwnerNotification($ownerEmail, $ownerName, $tenantName, $tenantEmail, $action, $houseName) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USERNAME'];
        $mail->Password   = $_ENV['SMTP_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;

        $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME']);
        $mail->addAddress($ownerEmail, $ownerName);

        $mail->isHTML(true);
        $mail->Subject = "Booking $action Notification - $houseName";
        $mail->Body    = "
            <h3>Hello, $ownerName</h3>
            <p>Your Booking  <b>$tenantName</b> ($tenantEmail) has been <b>$action</b> in your boarding house <b>$houseName</b>.</p>
        ";

        $mail->send();
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
    }
}

function sendTenantTerminationNotification($tenantEmail, $tenantName, $houseName, $ownerName) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USERNAME'];
        $mail->Password   = $_ENV['SMTP_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;

        $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME']);
        $mail->addAddress($tenantEmail, $tenantName);

        $mail->isHTML(true);
        $mail->Subject = "Booking Termination Notice - $houseName";
        $mail->Body    = "
            <h3>Hello, $tenantName</h3>
            <p>We regret to inform you that your booking at <b>$houseName</b> has been <b>TERMINATED</b> by the owner <b>$ownerName</b>.</p>
            <p>If you have any questions or concerns, please contact the boarding house owner for further clarification.</p>
            <br>
            <p>Thank you,<br>StayFinder Team</p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

function sendTenantRestorationNotification($tenantEmail, $tenantName, $houseName, $ownerName, $ownerUsername) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USERNAME'];
        $mail->Password   = $_ENV['SMTP_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;

        $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME']);
        $mail->addAddress($tenantEmail, $tenantName);

        $mail->isHTML(true);
        $mail->Subject = "Booking Restoration Notice - $houseName";
        $mail->Body    = "
            <h3>Hello, $tenantName</h3>
            <p>Good news! Your booking at <b>$houseName</b> has been <b>RESTORED</b>.</p>
            <p>Your booking is now active again. Thank you for your patience!</p>
            <br>
            <p>Best regards,<br>StayFinder Team</p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

function getTenantProfileImage($conn, $email) {
    // Free any previous results to prevent "Commands out of sync" error
    while ($conn->more_results()) {
        $conn->next_result();
        if ($result = $conn->store_result()) {
            $result->free();
        }
    }
    
    $stmt = $conn->prepare("SELECT profile_img FROM registerusers WHERE email = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("s", $email);
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        $stmt->close();
        return null;
    }
    
    $result = $stmt->get_result();
    $profile_img = null;
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $profile_img = $row['profile_img'];
    }
    
    $stmt->close();
    return $profile_img;
}

if ($_POST['action'] ?? false) {
    $booking_id = $_POST['booking_id'];
    $action = $_POST['action'];

    if ($action === 'remove') {
        
        // Get booking details first
        $getBookingStmt = $conn->prepare("
            SELECT y.fullName, y.email, y.boardingHouse, y.availableSlots, bh.id as house_id, bh.owner_id
            FROM yourbook y
            JOIN boarding_houses bh ON y.boardingHouse = bh.name
            WHERE y.id = ?
        ");
        $getBookingStmt->bind_param("i", $booking_id);
        $getBookingStmt->execute();
        $bookingData = $getBookingStmt->get_result()->fetch_assoc();
        $getBookingStmt->close();
        
        if ($bookingData) {
            $updateYourbook = $conn->prepare("UPDATE yourbook SET status = 'cancelled_by_owner' WHERE id = ?");
            $updateYourbook->bind_param("i", $booking_id);
            $updateYourbook->execute();
            $updateYourbook->close();
            
            // Extract room and bed numbers
            preg_match('/Room (\d+)/', $bookingData['availableSlots'], $roomMatch);
            preg_match('/Bed (\d+)/', $bookingData['availableSlots'], $bedMatch);
            $roomNumber = $roomMatch[1] ?? null;
            $bedNumber = $bedMatch[1] ?? null;
            
            if ($roomNumber && $bedNumber) {
                // Remove from bed_occupancy (mark as unoccupied)
                $removeBed = $conn->prepare("
                    DELETE FROM bed_occupancy 
                    WHERE house_id = ? AND room_number = ? AND bed_number = ?
                ");
                $removeBed->bind_param("iii", $bookingData['house_id'], $roomNumber, $bedNumber);
                $removeBed->execute();
                $removeBed->close();
            }
            
            // Get owner info for notification
            $ownerStmt = $conn->prepare("
                SELECT o.user_fullname, o.user_email, bh.name as house_name
                FROM ownerregister o
                JOIN boarding_houses bh ON o.user_id = bh.owner_id
                WHERE bh.id = ?
            ");
            $ownerStmt->bind_param("i", $bookingData['house_id']);
            $ownerStmt->execute();
            $ownerInfo = $ownerStmt->get_result()->fetch_assoc();
            $ownerStmt->close();

            if ($ownerInfo) {
                $emailSent = sendTenantTerminationNotification(
                    $bookingData['email'],
                    $bookingData['fullName'],
                    $ownerInfo['house_name'],
                    $ownerInfo['user_fullname']
                );
                
                // Send notification to owner
                sendOwnerNotification(
                    $ownerInfo['user_email'],
                    $ownerInfo['user_fullname'],
                    $bookingData['fullName'],
                    $bookingData['email'],
                    "TERMINATED",
                    $ownerInfo['house_name']
                );
                
                if ($emailSent) {
                    $message = "✅ Tenant terminated successfully! Termination email sent to tenant.";
                } else {
                    $message = "✅ Tenant terminated successfully! (Email notification failed)";
                }
            } else {
                $message = "✅ Tenant terminated successfully!";
            }
        } else {
            $message = "❌ Error: Booking not found!";
        }
    } elseif ($action === 'restore') {
        
        // Get booking details first
        $getBookingStmt = $conn->prepare("
            SELECT y.fullName, y.email, y.boardingHouse, y.availableSlots, bh.id as house_id, bh.owner_id
            FROM yourbook y
            JOIN boarding_houses bh ON y.boardingHouse = bh.name
            WHERE y.id = ?
        ");
        $getBookingStmt->bind_param("i", $booking_id);
        $getBookingStmt->execute();
        $bookingData = $getBookingStmt->get_result()->fetch_assoc();
        $getBookingStmt->close();
        
        if ($bookingData) {
            // Extract room and bed numbers
            preg_match('/Room (\d+)/', $bookingData['availableSlots'], $roomMatch);
            preg_match('/Bed (\d+)/', $bookingData['availableSlots'], $bedMatch);
            $roomNumber = $roomMatch[1] ?? null;
            $bedNumber = $bedMatch[1] ?? null;
            
            $checkBedStmt = $conn->prepare("
                SELECT COUNT(*) as occupied_count 
                FROM bed_occupancy 
                WHERE house_id = ? AND room_number = ? AND bed_number = ? AND is_occupied = 1
            ");
            $checkBedStmt->bind_param("iii", $bookingData['house_id'], $roomNumber, $bedNumber);
            $checkBedStmt->execute();
            $bedCheckResult = $checkBedStmt->get_result()->fetch_assoc();
            $checkBedStmt->close();
            
            if ($bedCheckResult['occupied_count'] > 0) {
                $message = "❌ RESTORATION FAILED! This bed (Room $roomNumber, Bed $bedNumber) is currently occupied by another tenant. This room cannot be restored until the current tenant vacates.";
            } else {
                $updateYourbook = $conn->prepare("UPDATE yourbook SET status = 'active' WHERE id = ?");
                $updateYourbook->bind_param("i", $booking_id);
                $updateYourbook->execute();
                $updateYourbook->close();
    
                
                if ($roomNumber && $bedNumber) {
                    // Restore bed occupancy: check existing record to avoid duplicates
                    $checkExist = $conn->prepare(
                        "SELECT id FROM bed_occupancy WHERE house_id = ? AND room_number = ? AND bed_number = ? LIMIT 1"
                    );
                    $checkExist->bind_param("iii", $bookingData['house_id'], $roomNumber, $bedNumber);
                    $checkExist->execute();
                    $checkResult = $checkExist->get_result();

                    if ($checkResult && $checkResult->num_rows > 0) {
                        $row = $checkResult->fetch_assoc();
                        $occupancy_id = $row['id'];
                        $checkExist->close();

                        $updateOcc = $conn->prepare(
                            "UPDATE bed_occupancy SET is_occupied = 1, tenant_name = ?, booking_id = ? WHERE id = ?"
                        );
                        $updateOcc->bind_param("sii", $bookingData['fullName'], $booking_id, $occupancy_id);
                        $updateOcc->execute();
                        $updateOcc->close();
                    } else {
                        $checkExist->close();
                        $insertOcc = $conn->prepare(
                            "INSERT INTO bed_occupancy (house_id, room_number, bed_number, tenant_name, booking_id, is_occupied) VALUES (?, ?, ?, ?, ?, 1)"
                        );
                        $insertOcc->bind_param("iiisi", $bookingData['house_id'], $roomNumber, $bedNumber, $bookingData['fullName'], $booking_id);
                        $insertOcc->execute();
                        $insertOcc->close();
                    }
                }
                
                // Get owner info for notification
                $ownerStmt = $conn->prepare("
                    SELECT o.user_fullname, o.user_email, bh.name as house_name
                    FROM ownerregister o
                    JOIN boarding_houses bh ON o.user_id = bh.owner_id
                    WHERE bh.id = ?
                ");
                $ownerStmt->bind_param("i", $bookingData['house_id']);
                $ownerStmt->execute();
                $ownerInfo = $ownerStmt->get_result()->fetch_assoc();
                $ownerStmt->close();

                if ($ownerInfo) {
                    // Send restoration email to tenant
                    $emailSent = sendTenantRestorationNotification(
                        $bookingData['email'],
                        $bookingData['fullName'],
                        $ownerInfo['house_name'],
                        $ownerInfo['user_fullname'],
                        $ownerInfo['user_email']
                    );
                    
                    // Send notification to owner
                    sendOwnerNotification(
                        $ownerInfo['user_email'],
                        $ownerInfo['user_fullname'],
                        $bookingData['fullName'],
                        $bookingData['email'],
                        "RESTORED",
                        $ownerInfo['house_name']
                    );
                    
                    if ($emailSent) {
                        $message = "✅ Tenant restored successfully! Restoration email sent to tenant.";
                    } else {
                        $message = "✅ Tenant restored successfully! (Email notification failed)";
                    }
                } else {
                    $message = "✅ Tenant restored successfully!";
                }
            }
        } else {
            $message = "❌ Error: Booking not found!";
        }
    }
}

$house_id = $_GET['house_id'] ?? $_SESSION['house_owner_id'];
$tab_filter = $_GET['tab'] ?? 'all';
$status_filter = $_GET['status'] ?? null;

$stmt = $conn->prepare("SELECT * FROM boarding_houses WHERE id = ?");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$house = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$house) {
    echo "<script>alert('Boarding house not found!'); window.location.href='logintenatboardinghouseOWNER.php';</script>";
    exit();
}

$allBookings = [];

$stmt = $conn->prepare("
    SELECT 
        id,
        full_name,
        age,
        gender,
        address,
        phone,
        email,
        start_date,
        room_number,
        bed_number,
        status,
        request_date,
        source_table,
        room_type
    FROM (
        SELECT 
            COALESCE(y.id, tr.id) as id,
            COALESCE(y.fullName, tr.full_name) as full_name,
            COALESCE(y.age, tr.age) as age,
            COALESCE(y.gender, tr.gender) as gender,
            COALESCE(y.address, tr.address) as address,
            COALESCE(y.phone, tr.phone) as phone,
            COALESCE(y.email, tr.email) as email,
            COALESCE(y.startDate, tr.start_date) as start_date,
            COALESCE(
                SUBSTRING_INDEX(SUBSTRING_INDEX(y.availableSlots, 'Room ', -1), ',', 1),
                tr.room_number
            ) as room_number,
            COALESCE(
                SUBSTRING_INDEX(y.availableSlots, 'Bed ', -1),
                tr.bed_number
            ) as bed_number,
           CASE 
                WHEN tr.status = 'declined' THEN 'declined'                     /* <-- NEW PRIORITY: Checks for Declined Request first */
                WHEN y.status = 'cancelled_by_owner' THEN 'cancelled_by_owner'  /* <-- NEW SECOND PRIORITY */
                WHEN tr.status = 'cancelled' THEN 'cancelled'
                WHEN y.status = 'cancelled' THEN 'cancelled'
                WHEN tr.status = 'accepted' THEN 'accepted'
                WHEN tr.status = 'pending' THEN 'pending'
                WHEN y.status IS NOT NULL THEN y.status
                ELSE 'pending'
            END as status,
            COALESCE(tr.request_date, y.startDate) as request_date,
            CASE 
                WHEN tr.id IS NOT NULL THEN 'tenant_request'
                ELSE 'yourbook'
            END as source_table,
            hr.room_type,
            bh.id as house_id
        FROM yourbook y 
        JOIN boarding_houses bh ON y.boardingHouse = bh.name 
            INNER JOIN tenant_requests tr ON (
            tr.house_id = bh.id AND 
            tr.email = y.email AND 
            tr.full_name = y.fullName AND
            tr.status IN ('accepted', 'declined', 'cancelled')
        )
        LEFT JOIN house_rooms hr ON (
            hr.house_id = bh.id AND 
            hr.room_number = COALESCE(
                SUBSTRING_INDEX(SUBSTRING_INDEX(y.availableSlots, 'Room ', -1), ',', 1),
                tr.room_number
            )
        )
        WHERE bh.id = ? AND y.status IN ('active', 'cancelled', 'cancelled_by_owner')
        
        UNION ALL
        
        SELECT 
            tr.id,
            tr.full_name,
            tr.age,
            tr.gender,
            tr.address,
            tr.phone,
            tr.email,
            tr.start_date,
            tr.room_number,
            tr.bed_number,
            COALESCE(tr.status, 'pending') as status,
            tr.request_date,
            'tenant_request' as source_table,
            hr.room_type,
            tr.house_id as house_id
        FROM tenant_requests tr 
        LEFT JOIN yourbook y ON (
            y.email = tr.email AND 
            y.fullName = tr.full_name AND
            y.status IN ('active', 'cancelled', 'cancelled_by_owner')
        )
        LEFT JOIN house_rooms hr ON (
            hr.house_id = tr.house_id AND 
            hr.room_number = tr.room_number
        )
        WHERE tr.house_id = ? AND y.id IS NULL AND tr.status IN ('accepted', 'declined', 'cancelled')
        
        -- Include yourbook entries that are cancelled by tenant even if tenant_requests row was deleted
        UNION ALL
        SELECT
            y.id,
            y.fullName as full_name,
            y.age,
            y.gender,
            y.address,
            y.phone,
            y.email,
            y.startDate as start_date,
            COALESCE(
                SUBSTRING_INDEX(SUBSTRING_INDEX(y.availableSlots, 'Room ', -1), ',', 1),
                NULL
            ) as room_number,
            COALESCE(
                SUBSTRING_INDEX(y.availableSlots, 'Bed ', -1),
                NULL
            ) as bed_number,
            CASE WHEN y.status = 'cancelled' THEN 'cancelled' WHEN y.status = 'cancelled_by_owner' THEN 'cancelled_by_owner' ELSE y.status END as status,
            y.startDate as request_date,
            'yourbook' as source_table,
            hr.room_type,
            bh.id as house_id
        FROM yourbook y
        JOIN boarding_houses bh ON y.boardingHouse = bh.name
        LEFT JOIN tenant_requests tr2 ON (tr2.house_id = bh.id AND tr2.email = y.email AND tr2.full_name = y.fullName)
        LEFT JOIN house_rooms hr ON (
            hr.house_id = bh.id AND 
            hr.room_number = COALESCE(
                SUBSTRING_INDEX(SUBSTRING_INDEX(y.availableSlots, 'Room ', -1), ',', 1),
                NULL
            )
        )
        WHERE bh.id = ? AND y.status = 'cancelled' AND tr2.id IS NULL
    ) as combined_bookings
    ORDER BY request_date DESC
");
if (!$stmt) {
    error_log("SQL Error: " . $conn->error);
    $allBookings = []; 
} else {
    $stmt->bind_param("iii", $house_id, $house_id, $house_id);
    if (!$stmt->execute()) {
        error_log("SQL Execution Error: " . $stmt->error);
        $allBookings = [];
    } else {
        $allBookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}


if ($tab_filter === 'all') {
    // Show ONLY accepted bookings
    $allBookings = array_filter($allBookings, function($booking) {
        $status = $booking['status'] ?? 'pending';
        return $status === 'accepted';
    });
} elseif ($tab_filter === 'cancelled_only') {
    // Show ONLY cancelled, terminated, and declined bookings
    $allBookings = array_filter($allBookings, function($booking) {
        $status = $booking['status'] ?? 'pending';
        return $status === 'cancelled' || $status === 'cancelled_by_owner' || $status === 'declined';
    });
}

if ($status_filter) {
    $allBookings = array_filter($allBookings, function($booking) use ($status_filter) {
        return ($booking['status'] ?? 'pending') === $status_filter;
    });
}

$totalBookings = count($allBookings);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
<link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($house['name']); ?> - Bookings</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }

        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Reserve vertical space for fixed header + tabs + total-info so content isn't hidden */
        main {
            flex: 1;
            padding: 200px 20px 20px; /* top padding = header (72px) + tabs (56px) + total-info (~72px) + gap */
        }
        
        /* Make the top banner fixed to viewport */
        .header {
            background-color: #FFD700;
            color: #333;
            height: 72px;
            line-height: 72px;
            padding: 0 20px;
            text-align: center;
            margin: 0; /* no flow margin since fixed */
            border-radius: 0; /* avoid rounding when fixed */
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .back-btn {
            background-color: #666;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            display: inline-block;
            margin-bottom: 20px;
        }

        /* Simplified tab navigation for 2 tabs only */
        .tab-navigation {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            position: fixed; /* pin under the header */
            top: 72px; /* height of .header */
            left: 20px; /* match page padding */
            right: 20px;
            z-index: 1001;
            background-color: white;
            padding: 10px 12px;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }

        /* Strong override: ensure header and tabs stay fixed and are not affected
           by other styles (transforms, overriding rules, etc). */
        .header, .tab-navigation {
            position: fixed !important;
            transform: none !important;
            -webkit-transform: none !important;
            will-change: top !important;
        }

        /* precise placement */
        .header { top: 0 !important; left: 0 !important; right: 0 !important; }
        .tab-navigation { top: 84px !important; left: 20px !important; right: 20px !important; margin-bottom: 0 !important; }

        /* keep tabs visible above content */
        .header { z-index: 2000 !important; }
        .tab-navigation { z-index: 2001 !important; }

        .tab-btn {
            background-color: #e0e0e0;
            color: #333;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            transition: all 0.3s;
        }

        .tab-btn:hover {
            background-color: #d0d0d0;
        }

        .tab-btn.active {
            background-color: #FFD700;
            color: #333;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .tab-btn.cancelled {
            background-color: #ffc107;
        }

        .tab-btn.cancelled.active {
            background-color: #ff9800;
            color: white;
        }
        
        .booking-item {
            background-color: white;
            margin-bottom: 15px;
            padding: 15px;
            border-left: 5px solid #FFD700;
        }

        .booking-item.cancelled {
            background-color: #f9f9f9;
            border-left-color: #ffc107;
            opacity: 0.9;
        }

        .booking-item.terminated {
            background-color: #fff3cd;
            border-left-color: #ff6b6b;
        }
        
        .booking-number {
            background-color: #FFD700;
            color: #333;
            padding: 5px 10px;
            font-weight: bold;
            margin-bottom: 10px;
            display: inline-block;
        }

        .booking-item.cancelled .booking-number {
            background-color: #ffc107;
            color: #333;
        }

        .booking-item.terminated .booking-number {
            background-color: #ff6b6b;
            color: white;
        }
        
        .data-row {
            margin: 8px 0;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        
        .label {
            font-weight: bold;
            color: #333;
            display: inline-block;
            width: 180px;
        }
        
        .status-pending { background-color: #FFA500; color: white; padding: 5px 10px; border-radius: 3px; }
        .status-accepted { background-color: #28a745; color: white; padding: 5px 10px; border-radius: 3px; }
        .status-declined { background-color: #dc3545; color: white; padding: 5px 10px; border-radius: 3px; }
        .status-cancelled { background-color: #ffc107; color: #333; padding: 5px 10px; border-radius: 3px; }
        .status-terminated { background-color: #ff6b6b; color: white; padding: 5px 10px; border-radius: 3px; }
        
        .total-info {
            background-color: #FFD700;
            color: #333;
            padding: 15px;
            text-align: center;
            margin-bottom: 20px;
            font-weight: bold;
        }

        /* Keep the total-info visible while scrolling (fixed under header + tabs) */
        .total-info {
            position: fixed !important;
            top: 140px !important; /* header (72) + tabs (68) approximated to align with layout */
            left: 20px !important;
            right: 20px !important;
            z-index: 2002 !important;
            border-radius: 6px;
            margin-bottom: 0 !important;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }
        
        .remove-btn {
            background-color: #dc3545;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            font-size: 16px;
            margin-top: 15px;
            width: 100%;
            transition: background-color 0.3s;
        }
        
        .remove-btn:hover {
            background-color: #c82333;
        }

        .restore-btn {
            background-color: #28a745;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            font-size: 16px;
            margin-top: 15px;
            width: 100%;
            transition: background-color 0.3s;
        }

        .restore-btn:hover {
            background-color: #218838;
        }
        
        .profile-display-section {
            background: linear-gradient(135deg, #FFF9E6, #FFFBF0);
            padding: 15px;
            border-radius: 10px;
            border: 2px solid #FFD700;
            margin-bottom: 15px;
        }

        .booking-item.cancelled .profile-display-section {
            background: linear-gradient(135deg, #fff8e1, #fffbf0);
            border-color: #ffc107;
        }

        .booking-item.terminated .profile-display-section {
            background: linear-gradient(135deg, #ffe6e6, #fff0f0);
            border-color: #ff6b6b;
        }
        
        .profile-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .tenant-profile-img {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #FFD700;
            box-shadow: 0 4px 12px rgba(218, 165, 32, 0.3);
        }

        .booking-item.cancelled .tenant-profile-img {
            border-color: #ffc107;
            opacity: 0.8;
        }

        .booking-item.terminated .tenant-profile-img {
            border-color: #ff6b6b;
            opacity: 0.8;
        }
        
        .tenant-profile-placeholder {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: #FFD700;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 700;
            color: #000;
            border: 3px solid #DAA520;
            box-shadow: 0 4px 12px rgba(218, 165, 32, 0.3);
        }

        /* View payment link */
        .view-payment-link {
            display: inline-block;
            margin-left: 12px;
            background: #ffd700;
            color: #111;
            padding: 6px 10px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 700;
            font-size: 12px;
            border: 2px solid #DAA520;
        }

        .view-payment-link.inline { margin-left: 8px; vertical-align: middle; }

        .view-payment-link:hover { background: #ffcf33; color: #000; }

        .booking-item.cancelled .tenant-profile-placeholder {
            background: #ffc107;
            border-color: #e0a800;
            opacity: 0.8;
        }

        .booking-item.terminated .tenant-profile-placeholder {
            background: #ff6b6b;
            border-color: #e55555;
            opacity: 0.8;
        }
        
        .profile-info h3 {
            margin: 0;
            color: #333;
            font-size: 20px;
        }
        
        .profile-info p {
            margin: 5px 0 0 0;
            color: #666;
            font-size: 14px;
        }

        .footer {
            background-color: #ffd700;
            color: #333;
            padding: 30px 0;
            margin-top: 40px;
            text-align: center;
        }

        .footer p {
            margin: 8px 0;
            font-size: 14px;
        }

        .footer a {
            color: #333;
            text-decoration: none;
            font-weight: 600;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        .footer-links {
            margin-top: 15px;
        }

        .footer-links a {
            margin: 0 10px;
        }

        .cancelled-badge {
            background-color: #ffc107;
            color: #333;
            padding: 12px 18px;
            border-radius: 5px;
            font-weight: bold;
            display: inline-block;
            margin-top: 10px;
            font-size: 18px;
        }

        .terminated-badge {
            background-color: #ff6b6b;
            color: white;
            padding: 8px 12px;
            border-radius: 5px;
            font-weight: bold;
            display: inline-block;
            margin-top: 10px;
        }
        .booking-item.declined {
            background-color: #f8d7da;
            border-left-color: #dc3545;
            opacity: 0.95;
        }

        .booking-item.declined .booking-number {
            background-color: #dc3545;
            color: white;
        }

        .booking-item.declined .tenant-profile-img {
            border-color: #dc3545;
            opacity: 0.85;
        }

        .booking-item.declined .tenant-profile-placeholder {
            background: #dc3545;
            border-color: #bb2d2d;
            opacity: 0.85;
        }
.back-btn-circle {
    background: #ffd700; /* yellow */
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 28px;
    font-weight: 900;
    color: #000; /* black */
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
    border: none;
}

.back-btn-circle:hover {
    background: #ffed4e; /* lighter yellow */
    transform: scale(1.12);
    box-shadow: 0 6px 16px rgba(255, 215, 0, 0.4);
}

.back-btn-circle i.fa-arrow-left {
    font-size: 24px;
    font-weight: 900;
}

/* Collapsible booking styles */
.booking-header {
    display: flex;
    align-items: center;
    gap: 15px;
    cursor: pointer;
    padding: 10px;
    background: linear-gradient(135deg, #FFF9E6, #FFFBF0);
    border-radius: 10px;
    border: 2px solid #FFD700;
    transition: all 0.3s ease;
}

.booking-header:hover {
    background: linear-gradient(135deg, #fff5cc, #fffbf0);
    box-shadow: 0 4px 12px rgba(218, 165, 32, 0.3);
}

.booking-item.cancelled .booking-header {
    background: linear-gradient(135deg, #fff8e1, #fffbf0);
    border-color: #ffc107;
}

.booking-item.terminated .booking-header {
    background: linear-gradient(135deg, #ffe6e6, #fff0f0);
    border-color: #ff6b6b;
}

.booking-item.declined .booking-header {
    background: linear-gradient(135deg, #f8d7da, #f9e6eb);
    border-color: #dc3545;
}

.toggle-arrow {
    font-size: 24px;
    font-weight: bold;
    color: #333;
    transition: transform 0.3s ease;
    min-width: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.toggle-arrow.expanded {
    transform: rotate(90deg);
}

.booking-header-content {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 15px;
}

.booking-details {
    display: none;
    padding: 15px;
    border-top: 2px solid #FFD700;
    margin-top: 10px;
    background-color: #fafafa;
}

.booking-details.expanded {
    display: block;
}

.booking-item.cancelled .booking-details {
    border-color: #ffc107;
}

.booking-item.terminated .booking-details {
    border-color: #ff6b6b;
}

.booking-item.declined .booking-details {
    border-color: #dc3545;
}
    </style>
</head>
<body>

<script>
    function toggleBooking(bookingId) {
        const header = document.getElementById('header-' + bookingId);
        const details = document.getElementById('details-' + bookingId);
        const arrow = document.getElementById('arrow-' + bookingId);
        
        details.classList.toggle('expanded');
        arrow.classList.toggle('expanded');
    }
</script>

<div class="header">
    <a href="javascript:history.back()" class="back-btn-circle" title="Go back" aria-label="Go back"><i class="fas fa-arrow-left"></i></a>
    <h2 class="header-title"><?php echo strtoupper(htmlspecialchars($house['name'])); ?> - BOOKINGS</h2>
</div>

<main>

    <?php if (isset($message)): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 15px; margin-bottom: 20px; text-align: center; border-radius: 5px;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="tab-navigation">
        <a href="?house_id=<?php echo $house_id; ?>&tab=all" class="tab-btn <?php echo ($tab_filter === 'all') ? 'active' : ''; ?>">
            📋 All Bookings
        </a>
        <a href="?house_id=<?php echo $house_id; ?>&tab=cancelled_only" class="tab-btn cancelled <?php echo ($tab_filter === 'cancelled_only') ? 'active' : ''; ?>">
            ⚠️ Cancelled/Terminated
        </a>
    </div>

    <div class="total-info">
        Total Bookings: <?php echo $totalBookings; ?>
    </div>

    <?php if ($totalBookings == 0): ?>
        <div style="text-align: center; padding: 50px; background-color: white;">
            <h3>No bookings found</h3>
        </div>
    <?php else: ?>
        <?php $bookingCount = 1; ?>
        <?php foreach ($allBookings as $booking): ?>
            <?php 
                $status = $booking['status'] ?? 'accepted';
                $roomType = $booking['room_type'] ?? 'student';
                
                $tenant_profile_img = getTenantProfileImage($conn, $booking['email']);
                
                $bookingClass = '';
                if ($status === 'declined') {
                    $bookingClass = 'declined';
                } elseif ($status === 'cancelled') {
                    $bookingClass = 'cancelled';
                } elseif ($status === 'cancelled_by_owner') {
                    $bookingClass = 'terminated';
                }

                // Extract variables for bind_param (cannot use array values or ?? directly)
                $email = $booking['email'];
                $house_id_val = isset($booking['house_id']) ? $booking['house_id'] : $house_id;
                $full_name = $booking['full_name'];

                $tenant_check_sql = "SELECT status FROM tenant_requests 
                                     WHERE email = ? AND house_id = ? AND full_name = ?
                                     ORDER BY id DESC LIMIT 1";
                $tenant_check_stmt = $conn->prepare($tenant_check_sql);
                $tenant_check_stmt->bind_param("sis", $email, $house_id_val, $full_name);
                $tenant_check_stmt->execute();
                $tenant_check_stmt->close();
            ?>
            
            <div class="booking-item <?php echo $bookingClass; ?>">
                <div class="booking-number">BOOKING #<?php echo $bookingCount; ?></div>
                
                <?php if ($roomType === 'faculty'): ?>
                    <div style="background-color: #7c3aed; color: white; padding: 10px; margin-bottom: 10px; text-align: center; font-weight: bold;">
                        🎓 FACULTY ROOM 🎓
                    </div>
                <?php endif; ?>
                
                <!-- Collapsible Header -->
                <div class="booking-header" id="header-<?php echo $booking['id']; ?>" onclick="toggleBooking(<?php echo $booking['id']; ?>)">
                    <div class="toggle-arrow" id="arrow-<?php echo $booking['id']; ?>">▶</div>
                    <div class="booking-header-content">
                        <?php 
                            $display_profile_img = $tenant_profile_img;
                            if (!empty($tenant_profile_img)) {
                                if (strpos($tenant_profile_img, '../') !== 0 && strpos($tenant_profile_img, 'profile_picture/') === 0) {
                                    $display_profile_img = '../' . $tenant_profile_img;
                                }
                            }
                        ?>
                        
                        <?php if (!empty($display_profile_img) && file_exists($display_profile_img)): ?>
                            <img src="<?php echo htmlspecialchars($display_profile_img); ?>" alt="Profile" class="tenant-profile-img">
                        <?php else: ?>
                            <?php $initial = strtoupper(substr($booking['full_name'], 0, 1)); ?>
                            <div class="tenant-profile-placeholder"><?php echo $initial; ?></div>
                        <?php endif; ?>
                        <div class="profile-info">
                            <h3 style="margin: 0; font-size: 18px;">
                                <?php echo htmlspecialchars($booking['full_name']); ?>
                            </h3>
                            <p style="margin: 5px 0 0 0; font-size: 14px; color: #666;">
                                <?php echo htmlspecialchars($booking['email']); ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Expandable Details Section -->
                <div class="booking-details" id="details-<?php echo $booking['id']; ?>">
                    <div class="data-row">
                        <span class="label">Boarding House:</span>
                        <?php echo htmlspecialchars($house['name']); ?>
                    </div>
                    
                    <div class="data-row">
                        <span class="label">Room & Bed:</span>
                        Room <?php echo htmlspecialchars($booking['room_number']); ?>, Bed <?php echo htmlspecialchars($booking['bed_number']); ?>
                    </div>
                    
                    <div class="data-row">
                        <span class="label">Full Name:</span>
                        <?php echo htmlspecialchars($booking['full_name']); ?>
                        <a href="payment_info.php?house_id=<?php echo $house_id; ?>&tenant_name=<?php echo urlencode($booking['full_name']); ?>" class="view-payment-link inline">View Payment Info</a>
                    </div>
                    
                    <div class="data-row">
                        <span class="label">Age:</span>
                        <?php echo htmlspecialchars($booking['age']); ?>
                    </div>
                    
                    <div class="data-row">
                        <span class="label">Gender:</span>
                        <?php echo htmlspecialchars($booking['gender']); ?>
                    </div>
                    
                    <div class="data-row">
                        <span class="label">Address:</span>
                        <?php echo htmlspecialchars($booking['address']); ?>
                    </div>
                    
                    <div class="data-row">
                        <span class="label">Phone:</span>
                        <?php echo htmlspecialchars($booking['phone']); ?>
                    </div>
                    
                    <div class="data-row">
                        <span class="label">Email:</span>
                        <?php echo htmlspecialchars($booking['email']); ?>
                    </div>
                    
                    <div class="data-row">
                        <span class="label">Start Book Date:</span>
                        <?php 
                            if (!empty($booking['start_date'])) {
                                $start_date = new DateTime($booking['start_date'], new DateTimeZone('UTC'));
                                $start_date->setTimezone(new DateTimeZone(date_default_timezone_get()));
                                echo $start_date->format('F j Y'); 
                            } else {
                                echo 'N/A';
                            }
                        ?>
                    </div>
                    
                    <div class="data-row">
                        <span class="label">Request Time:</span>
                        <?php 
                            if (!empty($booking['request_date'])) {
                                $request_date = new DateTime($booking['request_date'], new DateTimeZone('UTC'));
                                $request_date->setTimezone(new DateTimeZone(date_default_timezone_get()));
                                echo $request_date->format('h:i A'); 
                            } else {
                                echo 'N/A';
                            }
                        ?>
                    </div>
                    
                    <div class="data-row">
                        <span class="label">Status:</span>
                        <?php if ($status === 'cancelled'): ?>
                            <span class="status-cancelled">CANCELLED BY TENANT</span>
                        <?php elseif ($status === 'declined'): ?>
                            <span class="status-declined">DECLINED BY OWNER</span>
                        <?php elseif ($status === 'cancelled_by_owner'): ?>
                            <span class="status-terminated">❌ TERMINATED</span>
                        <?php else: ?>
                            <span class="status-accepted">ACCEPTED</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($status === 'cancelled'): ?>
                        <div class="cancelled-badge">⚠️ This booking was CANCELLED by tenant</div>
                    <?php elseif ($status === 'declined'): ?>
                        <div class="cancelled-badge" style="background-color: #dc3545; color: white;">
                            ❌ This booking has been DECLINED by owner
                        </div>
                    <?php elseif ($status === 'cancelled_by_owner'): ?>
                        <div class="terminated-badge" style="background-color: #dc3545; color: white;">❌ This booking has been TERMINATED</div>
                    <?php else: ?>
                        <button class="remove-btn" onclick="showTerminationConfirmation(<?php echo $booking['id']; ?>, '<?php echo addslashes($booking['full_name']); ?>')">
                            ❌ TERMINATE TENANT 
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php $bookingCount++; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
<footer class="footer" style="background-color:#111 !important; background-image:none !important; color:#fff !important; padding:8px 0; margin-top:20px; text-align:center; width:100%; z-index:800;">
    <div class="container-fluid" style="width:100%; padding-left:15px; padding-right:15px;">
        <p style="margin:6px 0;font-size:13px;">StayFinder: Boarding Locator and Management System</p>
        <div class="footer-links" style="margin-top:4px;">
            <a href="../terms.php" target="_blank" rel="noopener" style="color:yellow !important; text-decoration:none !important; font-weight:600;">Terms &amp; Conditions</a>
        </div>
    </div>
</footer>


</body>
</html>

<!-- TERMINATION CONFIRMATION MODAL -->
<div id="terminationConfirmModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 400px;">
        <h2 style="color: #dc3545; margin-bottom: 20px;">⚠️ CONFIRM TERMINATION</h2>
        <p style="font-size: 16px; margin-bottom: 10px;">Are you sure you want to terminate:</p>
        <p style="font-size: 20px; font-weight: bold; color: #333; margin-bottom: 30px; padding: 15px; background-color: #f8f9fa; border-radius: 5px;" id="terminationTenantName"></p>
        
        <div style="display: flex; gap: 10px;">
            <button onclick="cancelTermination()" class="btn btn-secondary" style="flex: 1;">
                ❌ NO, CANCEL
            </button>
            <button onclick="proceedToCodeVerification()" class="btn btn-danger" style="flex: 1; background-color: #dc3545; color: white; border: none; padding: 12px; border-radius: 5px; font-weight: bold; cursor: pointer;">
                ✅ YES, CONTINUE
            </button>
        </div>
    </div>
</div>

<!-- CODE VERIFICATION MODAL -->
<div id="codeVerificationModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 450px;">
        <h2 style="color: #007bff; margin-bottom: 20px;">🔐 ENTER ACCESS CODE</h2>
        <p style="font-size: 14px; color: #555; margin-bottom: 20px;">
            This is a security measure. Please enter the 6-digit dashboard access code for this boarding house to confirm termination.
        </p>
        
        <form id="codeVerificationForm" onsubmit="verifyCode(event)">
            <div style="margin-bottom: 20px;">
                <label style="font-weight: bold; display: block; margin-bottom: 8px;">6-Digit Access Code <span style="color: #dc3545;">*</span></label>
                <input type="text" 
                       id="accessCode" 
                       name="accessCode" 
                       placeholder="Enter 6 digits" 
                       maxlength="6" 
                       inputmode="numeric"
                       style="width: 100%; padding: 12px; border: 2px solid #FFD700; border-radius: 5px; font-size: 18px; font-weight: bold; letter-spacing: 5px; text-align: center; font-family: monospace;"
                       required
                       oninput="this.value = this.value.replace(/\D/g, '').slice(0, 6)">
                <small style="color: #666; display: block; margin-top: 8px;">Only numbers allowed</small>
            </div>
            
            <div id="codeErrorMessage" style="color: #dc3545; font-weight: bold; margin-bottom: 15px; padding: 10px; background-color: #ffe5e5; border-radius: 5px; display: none;"></div>
            
            <div style="display: flex; gap: 10px;">
                <button type="button" onclick="cancelCodeVerification()" class="btn btn-secondary" style="flex: 1; padding: 12px; background-color: #6c757d; color: white; border: none; border-radius: 5px; font-weight: bold; cursor: pointer;">
                    ❌ CANCEL
                </button>
                <button type="submit" id="verifyCodeBtn" class="btn btn-primary" style="flex: 1; padding: 12px; background-color: #007bff; color: white; border: none; border-radius: 5px; font-weight: bold; cursor: pointer;">
                    ✅ VERIFY & TERMINATE
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2000;
    }

    .modal-content {
        background-color: white;
        padding: 30px;
        border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        animation: slideIn 0.3s ease-out;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .btn {
        padding: 12px;
        border: none;
        border-radius: 5px;
        font-weight: bold;
        cursor: pointer;
        font-size: 14px;
    }

    .btn-secondary {
        background-color: #6c757d;
        color: white;
    }

    .btn-secondary:hover {
        background-color: #5a6268;
    }

    .btn-danger {
        background-color: #dc3545;
        color: white;
    }

    .btn-danger:hover {
        background-color: #c82333;
    }

    .btn-primary {
        background-color: #007bff;
        color: white;
    }

    .btn-primary:hover {
        background-color: #0056b3;
    }
</style>

<script>
    let currentBookingId = null;
    let currentTenantName = null;

    function showTerminationConfirmation(bookingId, tenantName) {
        currentBookingId = bookingId;
        currentTenantName = tenantName;
        
        document.getElementById('terminationTenantName').textContent = tenantName;
        document.getElementById('terminationConfirmModal').style.display = 'flex';
    }

    function cancelTermination() {
        document.getElementById('terminationConfirmModal').style.display = 'none';
        currentBookingId = null;
        currentTenantName = null;
    }

    function proceedToCodeVerification() {
        document.getElementById('terminationConfirmModal').style.display = 'none';
        document.getElementById('codeVerificationModal').style.display = 'flex';
        document.getElementById('accessCode').focus();
        document.getElementById('codeErrorMessage').style.display = 'none';
    }

    function cancelCodeVerification() {
        document.getElementById('codeVerificationModal').style.display = 'none';
        document.getElementById('accessCode').value = '';
        document.getElementById('codeErrorMessage').style.display = 'none';
        currentBookingId = null;
        currentTenantName = null;
    }

    function verifyCode(event) {
        event.preventDefault();
        
        const accessCode = document.getElementById('accessCode').value;
        const verifyCodeBtn = document.getElementById('verifyCodeBtn');
        
        if (accessCode.length !== 6) {
            document.getElementById('codeErrorMessage').style.display = 'block';
            document.getElementById('codeErrorMessage').textContent = '❌ Please enter a valid 6-digit code';
            return;
        }

        // Disable the button and show loading state
        verifyCodeBtn.disabled = true;
        verifyCodeBtn.innerHTML = '⏳ Verifying...';

        // Get house_id from URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const houseId = urlParams.get('house_id');

        // Send verification request
        fetch('verify_termination_code.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'house_id=' + houseId + '&access_code=' + accessCode + '&booking_id=' + currentBookingId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Code verified, now submit the termination
                submitTermination();
            } else {
                // Code incorrect
                document.getElementById('codeErrorMessage').style.display = 'block';
                document.getElementById('codeErrorMessage').textContent = '❌ Invalid access code. Please try again.';
                document.getElementById('accessCode').value = '';
                document.getElementById('accessCode').focus();
                verifyCodeBtn.disabled = false;
                verifyCodeBtn.innerHTML = '✅ VERIFY & TERMINATE';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('codeErrorMessage').style.display = 'block';
            document.getElementById('codeErrorMessage').textContent = '❌ An error occurred. Please try again.';
            verifyCodeBtn.disabled = false;
            verifyCodeBtn.innerHTML = '✅ VERIFY & TERMINATE';
        });
    }

    function submitTermination() {
        // Create a hidden form and submit it
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';

        const bookingInput = document.createElement('input');
        bookingInput.type = 'hidden';
        bookingInput.name = 'booking_id';
        bookingInput.value = currentBookingId;

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'remove';

        form.appendChild(bookingInput);
        form.appendChild(actionInput);
        document.body.appendChild(form);
        form.submit();
    }

    // Close modals when clicking outside
    document.addEventListener('click', function(event) {
        const confirmModal = document.getElementById('terminationConfirmModal');
        const codeModal = document.getElementById('codeVerificationModal');

        if (event.target === confirmModal) {
            cancelTermination();
        }
        if (event.target === codeModal) {
            cancelCodeVerification();
        }
    });

    // Close modals with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const confirmModal = document.getElementById('terminationConfirmModal');
            const codeModal = document.getElementById('codeVerificationModal');
            
            if (confirmModal.style.display === 'flex') {
                cancelTermination();
            }
            if (codeModal.style.display === 'flex') {
                cancelCodeVerification();
            }
        }
    });
</script>
