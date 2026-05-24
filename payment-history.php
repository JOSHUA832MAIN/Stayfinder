<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// ✅ Secure database connection (uses centralized main_connection.php)
require_once __DIR__ . '/../connectiondatabase/main_connection.php';

$envPath = dirname(__DIR__, 2) . '/.env';

if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
} else {
    die("❌ Missing .env file at: " . $envPath);
}


if (!$conn) {
    die("Database connection failed.");
}

// PHPMailer Setup (autoload)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/../vendor/autoload.php';

// Get house_id from GET or SESSION
if (isset($_GET['house_id'])) {
    $house_id = intval($_GET['house_id']);
    $_SESSION['owner_house_id'] = $house_id;
} elseif (isset($_SESSION['owner_house_id'])) {
    $house_id = $_SESSION['owner_house_id'];
} else {
    die("No house ID provided.");
}

// Get House Name and Price
$house_sql = "SELECT id, name, price FROM boarding_houses WHERE id = $house_id LIMIT 1";
$house_result = mysqli_query($conn, $house_sql);
if (!$house_result || mysqli_num_rows($house_result) == 0) {
    die("Invalid house ID.");
}
$house = mysqli_fetch_assoc($house_result);
$house_name = $house['name'];
$house_price = (!empty($house['price']) && $house['price'] !== 'Contact Owner') ? $house['price'] : 'Not Set';

// Create payment_history table if not exists
$create_table_sql = "CREATE TABLE IF NOT EXISTS payment_history (
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
mysqli_query($conn, $create_table_sql);

// Handle Month/Year Filter
$filter_month = isset($_GET['filter_month']) ? $_GET['filter_month'] : '';
$filter_year = isset($_GET['filter_year']) ? $_GET['filter_year'] : '';

// Current month and remaining days
$current_month = date('Y-m');
$current_day = date('d');
$days_in_month = date('t');
$days_remaining = $days_in_month - $current_day;

// Variable to store success message
$success_message = '';
$success_tenant_name = '';

$user_sql = "
    SELECT 
        id,
        fullName,
        email,
        phone,
        status,
        booking_date,
        profile_img
    FROM (
        SELECT 
            COALESCE(y.id, tr.id) as id,
            COALESCE(y.fullName, tr.full_name) as fullName,
            COALESCE(y.email, tr.email) as email,
            COALESCE(y.phone, tr.phone) as phone,
            COALESCE(ru.profile_img, '') as profile_img,
            CASE
                WHEN y.status = 'cancelled' THEN 'cancelled'
                WHEN y.status = 'cancelled_by_owner' THEN 'cancelled_by_owner'
                WHEN tr.status = 'accepted' THEN 'accepted'
                WHEN tr.status = 'pending' THEN 'pending'
                WHEN tr.status = 'declined' THEN 'declined'
                WHEN y.status IS NOT NULL THEN y.status
                ELSE 'accepted'
            END as status,
            tr.request_date as booking_date,
            ROW_NUMBER() OVER (PARTITION BY COALESCE(y.email, tr.email) ORDER BY COALESCE(y.id, tr.id) DESC) as rn
        FROM yourbook y
        JOIN boarding_houses bh ON y.boardingHouse = bh.name
        LEFT JOIN tenant_requests tr ON (
            tr.house_id = bh.id AND
            tr.email = y.email AND
            tr.full_name = y.fullName
        )
        LEFT JOIN registerusers ru ON (y.fullName = ru.fullname OR y.email = ru.email)

        WHERE bh.id = $house_id AND (y.status = 'accepted' OR y.status = 'cancelled_by_owner' OR tr.status = 'accepted')

        UNION ALL

        SELECT 
            tr.id,
            tr.full_name as fullName,
            tr.email,
            tr.phone,
            COALESCE(ru.profile_img, '') as profile_img,
            COALESCE(tr.status, 'accepted') as status,
            tr.request_date as booking_date,
            ROW_NUMBER() OVER (PARTITION BY tr.email ORDER BY tr.id DESC) as rn
        FROM tenant_requests tr
        LEFT JOIN yourbook y ON (
            y.email = tr.email AND
            y.fullName = tr.full_name
        )
        LEFT JOIN registerusers ru ON (tr.full_name = ru.fullname OR tr.email = ru.email)
        WHERE tr.house_id = $house_id
            AND y.id IS NULL
            AND tr.status = 'accepted'
    ) as combined_tenants
    WHERE rn = 1
    ORDER BY fullName
";

$users_result = mysqli_query($conn, $user_sql);

if (!$users_result) {
    die("Query failed: " . mysqli_error($conn));
}

// Handle Mark Paid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
    $tenant_name = mysqli_real_escape_string($conn, $_POST['tenant_name']);
    $tenant_email = mysqli_real_escape_string($conn, $_POST['tenant_email']);
    $payment_date = date('Y-m-d');
    $next_due_date = date('Y-m-d', strtotime('+1 month', strtotime($payment_date)));

    // Save to DB (mark as paid + remove NEW)
    $payment_sql = "INSERT INTO payment_history (house_id, tenant_name, payment_month, payment_date, next_due_date, status, is_new)
                   VALUES ($house_id, '$tenant_name', '$current_month', '$payment_date', '$next_due_date', 'paid', 0)
                   ON DUPLICATE KEY UPDATE payment_date = '$payment_date', next_due_date = '$next_due_date', status = 'paid', is_new = 0";
    mysqli_query($conn, $payment_sql);

    // Set success message
    $success_message = "Payment successfully marked as PAID!";
    $success_tenant_name = $tenant_name;

    if (!empty($tenant_email)) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = !empty($env['SMTP_HOST']) ? $env['SMTP_HOST'] : '';
            $mail->SMTPAuth   = true;
            $mail->Username   = !empty($env['SMTP_USERNAME']) ? $env['SMTP_USERNAME'] : '';
            $mail->Password   = !empty($env['SMTP_PASSWORD']) ? $env['SMTP_PASSWORD'] : '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = !empty($env['SMTP_PORT']) ? $env['SMTP_PORT'] : 587;

            if (empty($env['SMTP_FROM_EMAIL'])) {
                throw new Exception("SMTP_FROM_EMAIL not configured in .env");
            }

            $mail->setFrom($env['SMTP_FROM_EMAIL'], $env['SMTP_FROM_NAME'] ?? 'StayFinder');
            $mail->addAddress($tenant_email, $tenant_name);

            $mail->isHTML(true);
            $mail->Subject = "Payment Confirmation - $house_name";
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; padding: 20px; max-width: 600px; margin: 0 auto;'>
                    <div style='background: linear-gradient(135deg, #ffd700, #ffed4e); padding: 20px; border-radius: 10px 10px 0 0;'>
                        <h2 style='color: #333; margin: 0;'>Payment Confirmation ✓</h2>
                    </div>
                    <div style='background: #f8f9fa; padding: 20px; border-radius: 0 0 10px 10px;'>
                        <p>Dear <strong>" . htmlspecialchars($tenant_name) . "</strong>,</p>
                        <p>This is to confirm that your room payment has been <span style='color: #28a745; font-weight: bold;'>SUCCESSFULLY RECEIVED</span>.</p>
                        
                        <div style='background: white; padding: 15px; border-left: 4px solid #ffd700; margin: 20px 0;'>
                            <p style='margin: 8px 0;'><strong>Property:</strong> " . htmlspecialchars($house_name) . "</p>
                            <p style='margin: 8px 0;'><strong>Payment Date:</strong> " . date('F d, Y', strtotime($payment_date)) . "</p>
                            <p style='margin: 8px 0;'><strong>Next Due Date:</strong> " . date('F d, Y', strtotime($next_due_date)) . "</p>
                            <p style='margin: 8px 0;'><strong>Status:</strong> <span style='color: #28a745; font-weight: bold;'>PAID</span></p>
                        </div>
                        
                        <p style='margin-top: 20px; font-size: 14px; color: #666;'>
                            Thank you for your prompt payment!<br>
                            If you have any questions, please contact us.
                        </p>
                        
                        <p style='margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 12px; color: #999;'>
                            <em>© 2025 StayFinder | Boarding House Management</em>
                        </p>
                    </div>
                </div>";
            
            $mail->AltBody = "Dear $tenant_name, your payment for $house_name has been successfully received. Payment Date: " . date('F d, Y', strtotime($payment_date)) . ". Next Due Date: " . date('F d, Y', strtotime($next_due_date)) . ".";

            $mail->send();
            error_log("Payment confirmation email sent to: " . $tenant_email);
            
        } catch (Exception $e) {
            error_log("Email sending failed for $tenant_email: " . $mail->ErrorInfo);
        }
    }
}

function getRoomPrice($conn, $house_id, $tenant_name) {
    $tenant_name_safe = mysqli_real_escape_string($conn, $tenant_name);
    
    // Query to get the tenant's room number from tenant_requests
    $sql = "SELECT tr.room_number 
            FROM tenant_requests tr
            WHERE tr.house_id = $house_id 
            AND tr.full_name = '$tenant_name_safe'
            AND tr.status = 'accepted'
            LIMIT 1";
    
    $result = mysqli_query($conn, $sql);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $room_number = $row['room_number'];
        
        // Now get the price for this specific room from room_prices table
        $price_sql = "SELECT price FROM room_prices 
                      WHERE house_id = $house_id 
                      AND room_number = '$room_number'
                      LIMIT 1";
        
        $price_result = mysqli_query($conn, $price_sql);
        
        if ($price_result && mysqli_num_rows($price_result) > 0) {
            $price_row = mysqli_fetch_assoc($price_result);
            $price = $price_row['price'];
            
            // Return the price or "Not Set" if empty
            return (!empty($price) && $price !== 'Contact Owner') ? $price : 'Not Set';
        }
    }
    
    return 'Not Set';
}

function getPaymentInfo($conn, $house_id, $tenant_name, $filter_month = '', $filter_year = '') {
    global $current_month, $days_remaining;

    $tenant_name_safe = mysqli_real_escape_string($conn, $tenant_name);

    // Build query with filter
    $sql = "SELECT * FROM payment_history
            WHERE house_id = $house_id AND tenant_name = '$tenant_name_safe'";

    if (!empty($filter_month) && !empty($filter_year)) {
        $filter_month_safe = mysqli_real_escape_string($conn, $filter_month);
        $filter_year_safe = mysqli_real_escape_string($conn, $filter_year);
        $sql .= " AND MONTH(payment_date) = '$filter_month_safe' AND YEAR(payment_date) = '$filter_year_safe'";
    } elseif (!empty($filter_year)) {
        $filter_year_safe = mysqli_real_escape_string($conn, $filter_year);
        $sql .= " AND YEAR(payment_date) = '$filter_year_safe'";
    }

    $sql .= " ORDER BY payment_date DESC LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);

        // 🔥 CHECK IF 2+ MONTHS HAVE PASSED SINCE LAST PAYMENT
        if (!empty($row['payment_date'])) {
            $last_payment = new DateTime($row['payment_date']);
            $today = new DateTime();
            $months_diff = $last_payment->diff($today)->m + ($last_payment->diff($today)->y * 12);

            // If 2 or more months passed, reset to unpaid and update next due date
            if ($months_diff >= 2 && empty($filter_month) && empty($filter_year)) {
                $new_next_due = date('Y-m-t'); // End of current month

                // Update the database
                $update_sql = "UPDATE payment_history
                              SET status = 'unpaid',
                                  next_due_date = '$new_next_due',
                                  is_new = 1
                              WHERE house_id = $house_id
                              AND tenant_name = '$tenant_name_safe'";
                mysqli_query($conn, $update_sql);

                return [
                    'status' => ($days_remaining <= 5 && $days_remaining > 0) ? 'near_due' : 'unpaid',
                    'payment_date' => $row['payment_date'],
                    'next_due_date' => $new_next_due,
                    'is_new' => 1
                ];
            }
        }

        // Normal flow - check current month payment
        if ($row['payment_month'] == $current_month && $row['status'] == 'paid' && empty($filter_month) && empty($filter_year)) {
            return ['status' => 'paid','payment_date' => $row['payment_date'],'next_due_date' => $row['next_due_date'],'is_new'=>$row['is_new']];
        } else if (!empty($filter_month) || !empty($filter_year)) {
            // When filtering, show the payment status for that period
            return ['status' => $row['status'],'payment_date' => $row['payment_date'],'next_due_date' => $row['next_due_date'],'is_new'=>$row['is_new']];
        } else {
            $next_due = date('Y-m-t');
            return [
                'status' => ($days_remaining <= 5 && $days_remaining > 0) ? 'near_due' : 'unpaid',
                'payment_date' => $row['payment_date'] ?? null,
                'next_due_date' => $next_due,
                'is_new'=>1
            ];
        }
    } else {
        $next_due = date('Y-m-t');
        return [
            'status' => ($days_remaining <= 5 && $days_remaining > 0) ? 'near_due' : 'unpaid',
            'payment_date' => null,
            'next_due_date' => $next_due,
            'is_new'=>1
        ];
    }
}

function getDaysSinceTermination($booking_date) {
    if (!$booking_date) return 0;
    $termination_date = new DateTime($booking_date);
    $today = new DateTime();
    $interval = $today->diff($termination_date);
    return $interval->days;
}

// Generate Year Options (last 5 years + current + next year)
$current_year_num = date('Y');
$years = range($current_year_num - 5, $current_year_num + 1);

// Month Names
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>List of Rental Dues - <?php echo htmlspecialchars($house_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        main {
            flex: 1;
        }

        .header {
            background-color: #FFD700;
            color: #333;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            border-radius: 5px;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .back-btn {
            background-color: #666;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            display: inline-block;
            margin-bottom: 20px;
            border: none;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s;
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

        .back-btn:hover {
            background-color: #555;
            color: white;
        }

        .header-title {
            font-size: 1.5rem;
            margin: 0;
            font-weight: bold;
            color: #333;
        }

        .filter-section {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filter-input, .filter-select {
            background-color: #fff;
            border: 2px solid #ffd700;
            border-radius: 8px;
            padding: 10px 15px;
            font-size: 14px;
            width: 100%;
        }

        .filter-input:focus, .filter-select:focus {
            border: 2px solid #000;
            box-shadow: 0 0 0 0.2rem rgba(255, 215, 0, 0.25);
            outline: none;
        }

        .btn-search {
            background-color: #ffd700;
            border: 2px solid #ffd700;
            color: #000;
            font-weight: bold;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-search:hover {
            background-color: #000;
            border-color: #000;
            color: #ffd700;
        }

        .btn-clear {
            background-color: #fff;
            border: 2px solid #ffd700;
            color: #000;
            font-weight: bold;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-clear:hover {
            background-color: #ffd700;
            border-color: #ffd700;
            color: #000;
        }

        .filter-active-badge {
            background-color: #ffd700;
            color: #000;
            padding: 8px 15px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 14px;
            display: inline-block;
            margin-bottom: 10px;
        }

        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .custom-table {
            margin-bottom: 0;
        }

        .custom-table thead th {
            background-color: #f8f9fa;
            border: none;
            padding: 15px;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
        }

        .custom-table tbody td {
            padding: 15px;
            border-top: 1px solid #e9ecef;
            vertical-align: middle;
            font-size: 14px;
        }

        .custom-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            white-space: nowrap;
        }

        .status-unpaid {
            background-color: #dc3545;
            color: white;
        }

        .status-paid {
            background-color: #28a745;
            color: white;
        }

        .status-near-due {
            background-color: #ffc107;
            color: #212529;
        }

        .status-terminated {
            background-color: #6c757d;
            color: white;
        }

        .action-btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .btn-mark-paid {
            background-color: #28a745;
            color: white;
        }

        .btn-mark-paid:hover {
            background-color: #218838;
            color: white;
        }

        .btn-mark-paid:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
            opacity: 0.5;
        }

        .date-display {
            color: #6c757d;
        }

        .paid-display {
            color: #28a745;
            font-weight: 600;
        }

        .profile-img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #ffd700;
        }

        .profile-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #ffd700;
            color: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            border: 2px solid #ffd700;
        }

        .terminated-message {
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
        }

        .tenant-card {
            display: none;
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .tenant-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }

        .tenant-name {
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }

        .tenant-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
            font-size: 12px;
        }

        .info-value {
            color: #333;
            font-size: 13px;
            text-align: right;
        }

        .card-action-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid #e9ecef;
        }

        .footer {
            background-color: #343a40;
            color: white;
            padding: 20px;
            text-align: center;
            margin-top: 40px;
        }

        .footer-links {
            margin-top: 10px;
        }

        .footer-links a {
            color: #ffd700;
            text-decoration: none;
            margin: 0 15px;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }

        .success-popup {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: #28a745;
            color: white;
            padding: 20px 25px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(40, 167, 69, 0.4);
            z-index: 9999;
            display: none;
            animation: slideInRight 0.5s ease-out, fadeOut 0.5s ease-out 3.5s;
            min-width: 320px;
            max-width: 450px;
        }

        .success-popup.show {
            display: block;
        }

        .success-popup-content {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .success-popup-icon {
            font-size: 32px;
            flex-shrink: 0;
        }

        .success-popup-text {
            flex: 1;
        }

        .success-popup-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .success-popup-message {
            font-size: 14px;
            opacity: 0.95;
        }

        .success-popup-close {
            position: absolute;
            top: 10px;
            right: 15px;
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.3s;
        }

        .success-popup-close:hover {
            opacity: 1;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
            }
            to {
                opacity: 0;
                transform: translateX(400px);
            }
        }

        @media (max-width: 991px) {
            .custom-table {
                display: none;
            }

            .tenant-card {
                display: block;
            }

            .table-container {
                background: transparent;
                box-shadow: none;
                padding: 0;
            }

            .header-title {
                font-size: 1.2rem;
            }

            .filter-section .row {
                row-gap: 10px;
            }

            .btn-search, .btn-clear {
                width: 100%;
            }

            .success-popup {
                right: 10px;
                left: 10px;
                min-width: auto;
                max-width: none;
            }
        }

        @media (max-width: 768px) {
            .header-bar {
                padding: 12px 0;
                margin-bottom: 15px;
            }

            .header-title {
                font-size: 1.1rem;
            }

            .filter-section {
                padding: 12px;
            }

            .filter-input, .filter-select {
                font-size: 13px;
                padding: 8px 12px;
            }

            .status-badge {
                padding: 5px 10px;
                font-size: 11px;
            }

            .action-btn {
                width: 100%;
                padding: 10px;
                font-size: 13px;
            }

            .tenant-card {
                padding: 12px;
                margin-bottom: 12px;
            }
        }

        @media (max-width: 576px) {
            .container {
                padding-left: 10px;
                padding-right: 10px;
            }

            .back-btn {
                width: 35px;
                height: 35px;
                margin-right: 10px;
            }

            .header-title {
                font-size: 1rem;
            }

            .tenant-name {
                font-size: 15px;
            }

            .info-label {
                font-size: 11px;
            }

            .info-value {
                font-size: 12px;
            }

            .success-popup {
                top: 10px;
                right: 10px;
                left: 10px;
                padding: 15px 20px;
            }

            .success-popup-icon {
                font-size: 28px;
            }

            .success-popup-title {
                font-size: 16px;
            }

            .success-popup-message {
                font-size: 13px;
            }
        }

        @media (min-width: 992px) {
            .tenant-card {
                display: none !important;
            }

            .custom-table {
                display: table !important;
            }
        }
    </style>
</head>
<body>
    <!-- Success Popup -->
    <?php if (!empty($success_message)): ?>
    <div class="success-popup show" id="successPopup">
        <button class="success-popup-close" onclick="closePopup()">&times;</button>
        <div class="success-popup-content">
            <div class="success-popup-icon">✓</div>
            <div class="success-popup-text">
                <div class="success-popup-title">Payment Marked as PAID!</div>
                <div class="success-popup-message">
                    <?php echo htmlspecialchars($success_tenant_name); ?>'s payment has been successfully recorded and email sent.
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="header" style="position:relative;">
        <a href="javascript:history.back()" class="back-btn-circle" title="Go back" aria-label="Go back">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
        </a>
        <h2 class="header-title">List of Rental Dues</h2>
    </div>

    <main>
        <div class="container">
            <div class="filter-section">
                <form method="GET" action="">
                    <input type="hidden" name="house_id" value="<?php echo $house_id; ?>">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-3 col-md-4 col-sm-6">
                            <label class="form-label fw-bold">Select Month</label>
                            <select name="filter_month" class="filter-select">
                                <option value="">All Months</option>
                                <?php foreach ($months as $num => $name): ?>
                                    <option value="<?php echo $num; ?>" <?php echo ($filter_month == $num) ? 'selected' : ''; ?>>
                                        <?php echo $name; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-3 col-md-4 col-sm-6">
                            <label class="form-label fw-bold">Select Year</label>
                            <select name="filter_year" class="filter-select">
                                <option value="">All Years</option>
                                <?php foreach ($years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo ($filter_year == $year) ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <button type="submit" class="btn-search">Search</button>
                        </div>

                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <a href="?house_id=<?php echo $house_id; ?>" class="btn-clear">Clear</a>
                        </div>
                    </div>
                </form>

                <?php if (!empty($filter_month) || !empty($filter_year)): ?>
                    <div class="mt-3">
                        <div class="filter-active-badge">
                            Showing:
                            <?php if (!empty($filter_month)): ?>
                                <?php echo $months[$filter_month]; ?>
                            <?php endif; ?>
                            <?php if (!empty($filter_year)): ?>
                                <?php echo $filter_year; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="table-container">
                <?php if (mysqli_num_rows($users_result) == 0): ?>
                    <div class="p-4 text-center text-muted">
                        No accepted tenants have booked this boarding house yet.
                    </div>
                <?php else: ?>
                    <!-- Desktop Table View -->
                    <table class="table custom-table">
                     <thead>
    <tr>
        <th>Profile</th>
        <th>Renter's Name</th>
        <th>Email</th>
        <th>Contact No.</th>
        <th>Status</th>
        <th>Last Payment Date</th>
<th>Next Due Date</th>
<th>Action</th>
    </tr>
</thead>
                        <tbody>
                            <?php
                            mysqli_data_seek($users_result, 0);
                            while ($user = mysqli_fetch_assoc($users_result)):
                                $payment_info = getPaymentInfo($conn, $house_id, $user['fullName'], $filter_month, $filter_year);
                                $status = $payment_info['status'];
                                $payment_date = $payment_info['payment_date'];
                                $next_due_date = $payment_info['next_due_date'];
                                $is_new = $payment_info['is_new'];
                                $is_terminated = ($user['status'] === 'cancelled_by_owner');
                                // Use the new getRoomPrice function to fetch the specific room price
                                $room_price = getRoomPrice($conn, $house_id, $user['fullName']);
                            ?>
                            <tr>
                                <!-- Added profile image display in table -->
                                <td>
                                    <?php if (!empty($user['profile_img']) && file_exists('../' . $user['profile_img'])): ?>
                                        <img src="../<?php echo htmlspecialchars($user['profile_img']); ?>" alt="Profile" class="profile-img">
                                    <?php else: ?>
                                        <div class="profile-placeholder">
                                            <?php echo strtoupper(substr($user['fullName'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($user['fullName']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['phone']); ?></td>
                                <td>
                                    <?php if ($is_terminated): ?>
                                        <span class="status-badge status-terminated">TERMINATED</span>
                                    <?php elseif ($status == 'paid'): ?>
                                        <span class="status-badge status-paid">PAID</span>
                                    <?php elseif ($status == 'near_due'): ?>
                                        <span class="status-badge status-near-due">NEAR DUE</span>
                                    <?php else: ?>
                                        <span class="status-badge status-unpaid">UNPAID</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($payment_date): ?>
                                        <span class="paid-display"><?php echo date('M j, Y', strtotime($payment_date)); ?></span>
                                    <?php else: ?>
                                        <span class="date-display">---/---/---</span>
                                    <?php endif; ?>
                                </td>
                            <td>
  <!-- <CHANGE> Merged price below the Next Due date -->
  <?php if ($is_terminated): ?>
    <span class="date-display">---/---/---</span>
  <?php elseif ($status == 'paid' && $next_due_date): ?>
    <span class="date-display"><?php echo date('M j, Y', strtotime($next_due_date)); ?></span>
    <br><strong style="color: #ffc107;">₱ <?php echo htmlspecialchars($room_price); ?></strong>
  <?php else: ?>
    <span class="date-display">---/---/---</span>
  <?php endif; ?>
</td>

<td style="text-align: center;">
    <?php if ($is_terminated): ?>
        <span class="terminated-message">TERMINATED</span>
    <?php elseif ($status == 'paid'): ?>
        <?php elseif (empty($filter_month) && empty($filter_year)): ?>
        <form method="POST" class="d-inline tenant-form">
            <input type="hidden" name="tenant_name" value="<?php echo htmlspecialchars($user['fullName']); ?>">
            <input type="hidden" name="tenant_email" value="<?php echo htmlspecialchars($user['email']); ?>">
            <button type="submit" name="mark_paid" class="action-btn btn-mark-paid">
                Mark as paid
            </button>
        </form>
    <?php endif; ?>
</td>
                      </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                    <!-- Mobile Card View -->
                    <?php
                    mysqli_data_seek($users_result, 0);
                    while ($user = mysqli_fetch_assoc($users_result)):
                        $payment_info = getPaymentInfo($conn, $house_id, $user['fullName'], $filter_month, $filter_year);
                        $status = $payment_info['status'];
                        $payment_date = $payment_info['payment_date'];
                        $next_due_date = $payment_info['next_due_date'];
                        $is_new = $payment_info['is_new'];
                        $is_terminated = ($user['status'] === 'cancelled_by_owner');
                        // Use the new getRoomPrice function to fetch the specific room price for mobile view as well
                        $room_price = getRoomPrice($conn, $house_id, $user['fullName']);
                    ?>
                    <div class="tenant-card">
                        <div class="tenant-card-header">
                            <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
                                <!-- Added profile image in mobile card view -->
                                <div>
                                    <?php if (!empty($user['profile_img']) && file_exists('../' . $user['profile_img'])): ?>
                                        <img src="../<?php echo htmlspecialchars($user['profile_img']); ?>" alt="Profile" class="profile-img">
                                    <?php else: ?>
                                        <div class="profile-placeholder">
                                            <?php echo strtoupper(substr($user['fullName'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="tenant-name">
                                        <?php echo htmlspecialchars($user['fullName']); ?>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <?php if ($is_terminated): ?>
                                    <span class="status-badge status-terminated">TERMINATED</span>
                                <?php elseif ($status == 'paid'): ?>
                                    <span class="status-badge status-paid">PAID</span>
                                <?php elseif ($status == 'near_due'): ?>
                                    <span class="status-badge status-near-due">NEAR DUE</span>
                                <?php else: ?>
                                    <span class="status-badge status-unpaid">UNPAID</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tenant-info-row">
                            <span class="info-label">Email</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['email']); ?></span>
                        </div>

                        <div class="tenant-info-row">
                            <span class="info-label">Contact</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['phone']); ?></span>
                        </div>

                        <div class="tenant-info-row">
                            <span class="info-label">Last Payment</span>
                            <span class="info-value">
                                <?php if ($payment_date): ?>
                                    <span class="paid-display"><?php echo date('M j, Y', strtotime($payment_date)); ?></span>
                                <?php else: ?>
                                    <span class="date-display">---/---/---</span>
                                <?php endif; ?>
                            </span>
                        </div>
<div class="tenant-info-row">
    <span class="info-label">Next Due</span>
<span class="info-value">
  <!-- <CHANGE> Merged price below date in mobile view -->
  <?php if ($is_terminated): ?>
    <span class="date-display">---/---/---</span>
  <?php elseif ($status == 'paid' && $next_due_date): ?>
    <span class="date-display"><?php echo date('M j, Y', strtotime($next_due_date)); ?></span>
    <br><strong style="color: #ffc107;">₱ <?php echo htmlspecialchars($room_price); ?></strong>
  <?php else: ?>
    <span class="date-display">---/---/---</span>
  <?php endif; ?>
</span>
</div>


                        <?php if ($is_terminated): ?>
                            <div class="card-action-section">
                                <span class="terminated-message">TERMINATED</span>
                            </div>
                        <?php elseif ($status != 'paid' && empty($filter_month) && empty($filter_year)): ?>
                            <div class="card-action-section">
                                <form method="POST" class="tenant-form">
                                    <input type="hidden" name="tenant_name" value="<?php echo htmlspecialchars($user['fullName']); ?>">
                                    <input type="hidden" name="tenant_email" value="<?php echo htmlspecialchars($user['email']); ?>">
                                    <button type="submit" name="mark_paid" class="action-btn btn-mark-paid">
                                        Mark as paid
                                    </button>
                                </form>
                            </div>
                        <?php elseif (!empty($filter_month) || !empty($filter_year)): ?>
                            <div class="card-action-section">
                                <span class="text-muted">Filtered View</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p style="margin-top: 8px; font-size: 13px;">StayFinder: Boarding Locator and Management System</p>
            <div class="footer-links">
                <a href="../terms.php" target="_blank" rel="noopener">Terms &amp; Conditions</a>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Close popup function
        function closePopup() {
            const popup = document.getElementById('successPopup');
            if (popup) {
                popup.style.display = 'none';
            }
        }

        // Auto-hide popup after 4 seconds
        <?php if (!empty($success_message)): ?>
        setTimeout(() => {
            closePopup();
        }, 4000);
        <?php endif; ?>

        // Form submit confirmation
        document.querySelectorAll('.tenant-form').forEach(form => {
            form.addEventListener('submit', e => {
                const tenant = form.querySelector('input[name="tenant_name"]').value;
                if (!confirm(`Mark ${tenant} as PAID for this month?`)) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
