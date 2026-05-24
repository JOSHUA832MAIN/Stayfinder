<?php
session_start();
ob_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '/home/u325653807/domains/stayfinder.online/public_html/vendor/autoload.php';

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
} else {
    die("❌ .env file not found at: $envPath");
}

$conn = new mysqli(
    $_ENV['DB_HOST'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    $_ENV['DB_NAME']
);

if ($conn->connect_error) {
    die("❌ Database connection failed: " . $conn->connect_error);
}

// --- START: Status Message Handling for PRG ---
// Check for status in session, which is set after a redirect
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
// --- END: Status Message Handling for PRG ---

if (isset($_GET['ajax']) && $_GET['ajax'] === 'update') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    ob_clean();

    $response = [
        'success' => true,
        'stats' => [],
        'house_stats' => [],
        'owners' => [],
        'houses' => []
    ];

    $stats_query = "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN user_status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN user_status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN user_status = 'denied' THEN 1 ELSE 0 END) as denied
        FROM ownerregister";
    $stats_result = $conn->query($stats_query);
    $response['stats'] = $stats_result->fetch_assoc();

    $house_stats_query = "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as declined
        FROM boarding_houses";
    $house_stats_result = $conn->query($house_stats_query);
    $response['house_stats'] = $house_stats_result->fetch_assoc();

    $owners_query = "SELECT user_id, user_fullname, user_contact, user_email, user_profile, user_status, created_at
                     FROM ownerregister ORDER BY created_at DESC LIMIT 100";
    $owners_result = $conn->query($owners_query);
    while ($row = $owners_result->fetch_assoc()) {
        // Normalize user_profile so AJAX consumers get a usable URL/path
        $profile = $row['user_profile'] ?? '';
        $normalized = '';
        if (!empty($profile)) {
            // If stored path starts with uploads/ it's created by owneregister and resides under ../owneregister/
            if (strpos($profile, 'uploads/') === 0) {
                $candidate = '../owneregister/' . $profile;
                if (file_exists($candidate)) {
                    $normalized = $candidate;
                } elseif (file_exists('../' . $profile)) {
                    $normalized = '../' . $profile;
                } else {
                    $normalized = $profile; // fallback, browser may still resolve it
                }
            } else {
                // Try sensible relative locations from admin folder
                if (file_exists('../' . $profile)) {
                    $normalized = '../' . $profile;
                } elseif (file_exists($profile)) {
                    $normalized = $profile;
                } elseif (file_exists('../uploads/' . $profile)) {
                    $normalized = '../uploads/' . $profile;
                } else {
                    $normalized = $profile;
                }
            }
        }

        $row['user_profile'] = $normalized;
        $response['owners'][] = $row;
    }

    $houses_query = "SELECT
        bh.id, bh.name, bh.status, bh.created_at, bh.images, bh.panorama_url, bh.description,
        bh.business_permit_number, bh.business_permit, bh.full_location, bh.owner_id, bh.price,
        o.user_fullname,
        o.user_email,
        o.user_contact
        FROM boarding_houses bh
        LEFT JOIN ownerregister o ON bh.owner_id = o.user_id
        ORDER BY bh.created_at DESC LIMIT 50";
    $houses_result = $conn->query($houses_query);
    while ($row = $houses_result->fetch_assoc()) {
        $response['houses'][] = $row;
    }
    
    // Fetch notifications for the AJAX update
    $notif_query = "SELECT id, sender, message, created_at FROM global_notifications ORDER BY created_at DESC LIMIT 50";
    $notif_result = $conn->query($notif_query);
    $response['global_notifications'] = [];
    if ($notif_result) {
        while ($row = $notif_result->fetch_assoc()) {
            $response['global_notifications'][] = $row;
        }
    }


    echo json_encode($response);
    ob_end_flush();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $user_id = intval($_POST['user_id']);
    $new_status = $_POST['new_status'];

    $userQuery = $conn->prepare("SELECT user_fullname, user_email FROM ownerregister WHERE user_id = ?");
    $userQuery->bind_param("i", $user_id);
    $userQuery->execute();
    $userResult = $userQuery->get_result();
    $userData = $userResult->fetch_assoc();
    $userQuery->close();

    $stmt = $conn->prepare("UPDATE ownerregister SET user_status = ? WHERE user_id = ?");
    $stmt->bind_param("si", $new_status, $user_id);

    if ($stmt->execute()) {
        // Use Session for PRG status
        $_SESSION['success_message'] = "✅ Owner status updated successfully!";

        if ($userData) {
            $recipientEmail = $userData['user_email'];
            $recipientName  = $userData['user_fullname'];

            if ($new_status === "approved") {
                $subject = "StayFinder Application Approved";
                $body = "
                    Dear {$recipientName},<br><br>
                    🎉 Congratulations! Your boarding house application has been <b>APPROVED</b>.<br>
                    You may now login and manage your boarding house on StayFinder.<br><br>
                    Regards,<br><b>StayFinder Admin</b>
                ";
            } elseif ($new_status === "denied") {
                $subject = "StayFinder Application Denied";
                $body = "
                    Dear {$recipientName},<br><br>
                    We regret to inform you that your application has been <b>DENIED</b>.<br>
                    For details, please contact our support team.<br><br>
                    Regards,<br><b>StayFinder Admin</b>
                ";
            } else {
                $subject = "StayFinder Application Update";
                $body = "Hello {$recipientName}, your application status is now: <b>{$new_status}</b>";
            }

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $_ENV['SMTP_HOST'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $_ENV['SMTP_USERNAME'];
                $mail->Password   = $_ENV['SMTP_PASSWORD'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = $_ENV['SMTP_PORT'];

                $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME']);
                $mail->addAddress($recipientEmail, $recipientName);

                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $body;

                $mail->send();
                $_SESSION['success_message'] .= " 📧 Email sent to {$recipientEmail}!";
            } catch (Exception $e) {
                $_SESSION['error_message'] = "⚠️ Email failed: {$mail->ErrorInfo}";
            }
        }
    } else {
        $_SESSION['error_message'] = "❌ Error updating owner status!";
    }
    $stmt->close();
    header('Location: dashadmin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_house_status'])) {
    $house_id = intval($_POST['house_id']);
    $new_status = $_POST['new_status'];

    $houseQuery = $conn->prepare("
        SELECT bh.name, o.user_fullname, o.user_email
        FROM boarding_houses bh
        LEFT JOIN ownerregister o ON bh.owner_id = o.user_id
        WHERE bh.id = ?
    ");
    $houseQuery->bind_param("i", $house_id);
    $houseQuery->execute();
    $houseResult = $houseQuery->get_result();
    $houseData = $houseResult->fetch_assoc();
    $houseQuery->close();

    $stmt = $conn->prepare("UPDATE boarding_houses SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $house_id);

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "✅ Boarding house status updated successfully!";

        if ($houseData && !empty($houseData['user_email'])) {
            $recipientEmail = $houseData['user_email'];
            $recipientName  = $houseData['user_fullname'];
            $houseName      = $houseData['name'];

            if ($new_status === "approved") {
                $subject = "Your Boarding House Has Been Approved!";
                $body = "
                    Dear {$recipientName},<br><br>
                    🎉 Great news! Your boarding house <b>{$houseName}</b> has been <b>APPROVED</b> on StayFinder.<br>
                    Your property is now live and visible to potential tenants.<br>
                    You can now manage your rooms, pricing, and bookings from your dashboard.<br><br>
                    Regards,<br><b>StayFinder Admin</b>
                ";
            } elseif ($new_status === "declined") {
                $subject = "Your Boarding House Application Has Been Declined";
                $body = "
                    Dear {$recipientName},<br><br>
                    We regret to inform you that your boarding house <b>{$houseName}</b> application has been <b>DECLINED</b>.<br>
                    For more information or to appeal this decision, please contact our support team.<br><br>
                    Regards,<br><b>StayFinder Admin</b>
                ";
            } else {
                $subject = "Boarding House Status Update";
                $body = "Hello {$recipientName}, your boarding house <b>{$houseName}</b> status is now: <b>{$new_status}</b>";
            }

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $_ENV['SMTP_HOST'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $_ENV['SMTP_USERNAME'];
                $mail->Password   = $_ENV['SMTP_PASSWORD'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = $_ENV['SMTP_PORT'];

                $mail->setFrom($_ENV['SMTP_FROM_EMAIL'], $_ENV['SMTP_FROM_NAME']);
                $mail->addAddress($recipientEmail, $recipientName);

                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $body;

                $mail->send();
                $_SESSION['success_message'] .= " 📧 Email sent to {$recipientEmail}!";
            } catch (Exception $e) {
                $_SESSION['error_message'] = "⚠️ Email failed: {$mail->ErrorInfo}";
            }
        }
    } else {
        $_SESSION['error_message'] = "❌ Error updating boarding house status!";
    }
    $stmt->close();
    header('Location: dashadmin.php');
    exit;
}

// (Previously had inline permit-edit handler; removed to keep dashboard read-only)

// --- START: Logic for Global Notification Message Submission (MODIFIED) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_global_message'])) {
    $message = trim($_POST['update_message']);
    if (!empty($message)) {
        // 'DEV' is the default sender, message is the only required field
        $stmt = $conn->prepare("INSERT INTO global_notifications (message) VALUES (?)");
        $stmt->bind_param("s", $message);

        if ($stmt->execute()) {
            // **CORRECTION:** Set message in SESSION and REDIRECT
            $_SESSION['success_message'] = "✅ Global update message sent successfully!";
        } else {
            // **CORRECTION:** Set error message in SESSION and REDIRECT
            $_SESSION['error_message'] = "❌ Error sending global message: " . $conn->error;
        }
        $stmt->close();

        // **POST-REDIRECT-GET (PRG) PATTERN IMPLEMENTATION**
        header('Location: dashadmin.php');
        exit;
    } else {
        $_SESSION['error_message'] = "⚠️ Message cannot be empty!";
        header('Location: dashadmin.php');
        exit;
    }
}
// --- END: Logic for Global Notification Message Submission ---

// --- START: Fetch Global Notifications for history view ---
$global_notifications = [];
// Fetch the latest 50 notifications, ordered by newest first
$notif_query = "SELECT id, sender, message, created_at FROM global_notifications ORDER BY created_at DESC LIMIT 50";
$notif_result = $conn->query($notif_query);
if ($notif_result) {
    while ($row = $notif_result->fetch_assoc()) {
        $global_notifications[] = $row;
    }
}
// --- END: Fetch Global Notifications ---

$query = "SELECT * FROM ownerregister ORDER BY created_at DESC";
$result = $conn->query($query);

$stats_query = "SELECT
    COUNT(*) as total,
    SUM(CASE WHEN user_status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN user_status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN user_status = 'denied' THEN 1 ELSE 0 END) as denied
    FROM ownerregister";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

$house_stats_query = "SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as declined
    FROM boarding_houses";
$house_stats_result = $conn->query($house_stats_query);
$house_stats = $house_stats_result->fetch_assoc();

$houses_query = "SELECT
    bh.*,
    o.user_fullname,
    o.user_email,
    o.user_contact,
    o.user_address
    FROM boarding_houses bh
    LEFT JOIN ownerregister o ON bh.owner_id = o.user_id
    ORDER BY bh.created_at DESC";
$houses_result = $conn->query($houses_query);
$boarding_houses = [];
if ($houses_result && $houses_result->num_rows > 0) {
    while ($row = $houses_result->fetch_assoc()) {
        $boarding_houses[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - StayFinder</title>
<link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #FDD835;
            --primary-dark: #F9A825;
            --primary-light: #FFF176;
            --secondary-color: #FFEB3B;
            --accent-color: #FDD835;
        }

        body { background-color: #f8f9fa; }
        .navbar { box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        .stats-card { transition: transform 0.2s; }
        .stats-card:hover { transform: translateY(-5px); }
        .profile-img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #dee2e6;
        }
        .table th { background-color: #FDD835; color: #000; font-weight: 600; }
        .badge { font-size: 0.75em; }
        .btn-group .btn { margin: 0 2px; }
        .bg-primary-custom {
            background-color: var(--primary-color) !important;
        }
        .btn-primary-custom {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: #000;
            font-weight: 600;
        }
        .btn-primary-custom:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
            color: #000;
        }
        .nav-tabs .nav-link {
            color: #333;
            border: none;
            border-bottom: 3px solid transparent;
        }
        .nav-tabs .nav-link.active {
            background-color: var(--primary-color);
            color: #000;
            border-bottom: 3px solid var(--primary-dark);
        }
        .house-card-details {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .new-badge {
            display: inline-block;
            background: linear-gradient(135deg, #ff6b6b, #ff8787);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7em;
            font-weight: 700;
            margin-left: 8px;
            animation: pulse 1.5s ease-in-out infinite;
            box-shadow: 0 2px 8px rgba(255, 107, 107, 0.4);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .image-gallery-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin: 15px 0;
        }

        .gallery-section {
            margin-bottom: 25px;
        }

        .gallery-section-title {
            font-size: 14px;
            font-weight: 700;
            color: #333;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .gallery-badge {
            display: inline-block;
            background: #FDD835;
            color: #000;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .panorama-badge {
            display: inline-block;
            background: linear-gradient(135deg, #8B4FBF, #5B3FA0);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
        }

        .image-card {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .image-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(253, 216, 53, 0.3);
            border-color: #FDD835;
        }

        .image-card img {
            width: 100%;
            height: 140px;
            object-fit: cover;
            display: block;
        }

        .image-label {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
            color: white;
            padding: 8px 10px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }

        .no-images-placeholder {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 150px;
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            color: #6c757d;
        }

        .no-images-placeholder i {
            font-size: 36px;
            margin-right: 12px;
            opacity: 0.5;
        }

        .image-count-badge {
            display: inline-block;
            background: #FDD835;
            color: #000;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 8px;
        }

        /* Fullscreen modal / lightbox styles */
        .lightbox-modal {
            position: fixed;
            z-index: 2000;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: transparent; /* NO BLACK BACKGROUND */
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .lightbox-content {
            max-width: 100vw;
            width: 100%;
            max-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            background: white; /* WHITE BACKGROUND TO FILL SCREEN */
            padding: 0;
            border-radius: 0;
            box-shadow: none;
        }

        .lightbox-image {
            max-width: 100%;
            max-height: 100vh;
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 0;
            box-shadow: none;
            background: white;
            image-rendering: crisp-edges; /* HD quality */
        }

        .lightbox-close, .lightbox-prev, .lightbox-next, .lightbox-opennew {
            position: absolute;
            background: rgba(0,0,0,0.6);
            border: none;
            color: #fff;
            padding: 10px 14px;
            border-radius: 4px;
            cursor: pointer;
            backdrop-filter: blur(4px);
            font-size: 16px;
            font-weight: bold;
            transition: all 0.2s ease;
        }

        .lightbox-close:hover, .lightbox-prev:hover, .lightbox-next:hover, .lightbox-opennew:hover {
            background: rgba(0,0,0,0.8);
        }

        .lightbox-close { top: 12px; right: 12px; z-index: 10; }
        .lightbox-prev { left: 8px; top: 50%; transform: translateY(-50%); z-index: 10; }
        .lightbox-next { right: 8px; top: 50%; transform: translateY(-50%); z-index: 10; }
        .lightbox-opennew { left: 12px; top: 12px; z-index: 10; }

        .lightbox-caption {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            bottom: 12px;
            color: #fff;
            font-weight: 600;
            background: rgba(0,0,0,0.7);
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 13px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            max-width: 90%;
            text-align: center;
            z-index: 10;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg" style="background-color: var(--primary-color);">
    <div class="container-fluid">
        <a class="navbar-brand" href="#" style="color: #000; font-weight: 700;">
            <i class="fas fa-tachometer-alt me-2"></i>
            StayFinder Admin
        </a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="adminlog.php" style="color: #000; font-weight: 600;">
                <i class="fas fa-sign-out-alt me-1"></i>
                Logout
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid py-4">

    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fas fa-users-cog me-2"></i>Admin Management Dashboard</h2>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="owners-tab" data-bs-toggle="tab" data-bs-target="#owners-content" type="button" role="tab">
                <i class="fas fa-users me-2"></i>Owner Applications
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="houses-tab" data-bs-toggle="tab" data-bs-target="#houses-content" type="button" role="tab">
                <i class="fas fa-home me-2"></i>Boarding House Approvals
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications-content" type="button" role="tab">
                <i class="fas fa-history me-2"></i>Global Updates History
            </button>
        </li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="owners-content" role="tabpanel">
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stats-card border-0 shadow-sm">
                        <div class="card-body text-center">
                            <div class="mb-2" style="color: var(--primary-color);">
                                <i class="fas fa-users fa-2x"></i>
                            </div>
                            <h3 class="mb-1" id="stat-total"><?= $stats['total'] ?></h3>
                            <p class="text-muted mb-0">Total Applications</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card border-0 shadow-sm">
                        <div class="card-body text-center">
                            <div class="mb-2" style="color: var(--accent-color);">
                                <i class="fas fa-clock fa-2x"></i>
                            </div>
                            <h3 class="mb-1" id="stat-pending"><?= $stats['pending'] ?></h3>
                            <p class="text-muted mb-0">Pending</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card border-0 shadow-sm">
                        <div class="card-body text-center">
                            <div class="text-success mb-2">
                                <i class="fas fa-check-circle fa-2x"></i>
                            </div>
                            <h3 class="mb-1" id="stat-approved"><?= $stats['approved'] ?></h3>
                            <p class="text-muted mb-0">Approved</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card border-0 shadow-sm">
                        <div class="card-body text-center">
                            <div class="text-danger mb-2">
                                <i class="fas fa-times-circle fa-2x"></i>
                            </div>
                            <h3 class="mb-1" id="stat-denied"><?= $stats['denied'] ?></h3>
                            <p class="text-muted mb-0">Denied</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Owner Applications
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Profile</th>
                                    <th>Full Name</th>
                                    <th>Contact</th>
                                    <th>Email</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody id="owners-tbody">
                                <?php if ($result && $result->num_rows > 0): ?>
                                    <?php while ($row = $result->fetch_assoc()):
                                        $created_time = strtotime($row['created_at']);
                                        $is_new = (time() - $created_time) < 300;
                                    ?>
                                    <tr data-created="<?= $created_time ?>">
                                        <td><strong>#<?= $row['user_id'] ?></strong></td>
                                        <td>
                                            <?php
                                            $profilePath = '';
                                            if (!empty($row['user_profile'])) {
                                                $dbProfile = $row['user_profile'];

                                                // If value starts with uploads/ it's likely stored by owneregister
                                                if (strpos($dbProfile, 'uploads/') === 0) {
                                                    $candidates = [
                                                        '../owneregister/' . $dbProfile,
                                                        '../' . $dbProfile,
                                                        $dbProfile
                                                    ];
                                                } else {
                                                    $candidates = [
                                                        '../' . $dbProfile,
                                                        $dbProfile,
                                                        '../uploads/' . $dbProfile,
                                                        'uploads/' . $dbProfile
                                                    ];
                                                }

                                                foreach ($candidates as $p) {
                                                    if (file_exists($p)) {
                                                        $profilePath = $p;
                                                        break;
                                                    }
                                                }
                                            }

                                            if (!empty($profilePath)): 
                                            ?>
                                                   <img src="<?= htmlspecialchars($profilePath) ?>"
                                                       class="profile-img" alt="Profile"
                                                       onerror="this.onerror=null; this.src='../img/default.jpg';">
                                            <?php else: ?>
                                                <div class="profile-img bg-secondary d-flex align-items-center justify-content-center text-white">
                                                    <i class="fas fa-user"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($row['user_fullname']) ?></strong>
                                            <?php if ($is_new): ?>
                                                <span class="new-badge">NEW</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <i class="fas fa-phone text-muted me-1"></i>
                                            <?= htmlspecialchars($row['user_contact']) ?>
                                        </td>
                                        <td>
                                            <i class="fas fa-envelope text-muted me-1"></i>
                                            <?= htmlspecialchars($row['user_email']) ?>
                                        </td>
                                        <td>
                                            <small><?= date('M j, Y', strtotime($row['created_at'])) ?></small>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <?php if ($row['user_status'] !== 'approved'): ?>
                                                <form method="post" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?= $row['user_id'] ?>">
                                                    <input type="hidden" name="new_status" value="approved">
                                                    <input type="hidden" name="update_status" value="1">
                                                    <button type="submit" class="btn btn-success btn-sm"
                                                            onclick="return confirm('Approve this application?')">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5">
                                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No applications found</h5>
                                            <p class="text-muted">Owner registrations will appear here</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="houses-content" role="tabpanel">
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stats-card border-0 shadow-sm">
                        <div class="card-body text-center">
                            <div class="mb-2" style="color: var(--primary-color);">
                                <i class="fas fa-home fa-2x"></i>
                            </div>
                            <h3 class="mb-1" id="house-stat-total"><?= $house_stats['total'] ?></h3>
                            <p class="text-muted mb-0">Total Houses</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card border-0 shadow-sm">
                        <div class="card-body text-center">
                            <div class="mb-2" style="color: var(--accent-color);">
                                <i class="fas fa-clock fa-2x"></i>
                            </div>
                            <h3 class="mb-1" id="house-stat-pending"><?= $house_stats['pending'] ?></h3>
                            <p class="text-muted mb-0">Pending</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card border-0 shadow-sm">
                        <div class="card-body text-center">
                            <div class="text-success mb-2">
                                <i class="fas fa-check-circle fa-2x"></i>
                            </div>
                            <h3 class="mb-1" id="house-stat-approved"><?= $house_stats['approved'] ?></h3>
                            <p class="text-muted mb-0">Approved</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card border-0 shadow-sm">
                        <div class="card-body text-center">
                            <div class="text-danger mb-2">
                                <i class="fas fa-times-circle fa-2x"></i>
                            </div>
                            <h3 class="mb-1" id="house-stat-declined"><?= $house_stats['declined'] ?></h3>
                            <p class="text-muted mb-0">Declined</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        Boarding House Registrations
                    </h5>
                </div>
                <div class="card-body" id="houses-list">
                    <?php if (!empty($boarding_houses)): ?>
                        <?php foreach ($boarding_houses as $house):
                            $house_created_time = strtotime($house['created_at']);
                            $house_is_new = (time() - $house_created_time) < 300;

                            $images = !empty($house['images']) ? array_filter(array_map('trim', explode(',', $house['images']))) : [];
                            $property_labels = ['Front Image', 'Back Image', 'Side Image'];

                            $panoramas = !empty($house['panorama_url']) ? array_filter(array_map('trim', explode(',', $house['panorama_url']))) : [];
                        ?>
                            <div class="house-card-details" data-created="<?= $house_created_time ?>">
                                <div class="row">
                                    <div class="col-md-8">
                                        <h5 class="mb-3">
                                            <i class="fas fa-home me-2"></i>
                                            <?= htmlspecialchars($house['name']) ?>
                                            <span class="badge bg-primary">ID: <?= $house['id'] ?></span>
                                            <?php if ($house_is_new): ?>
                                                <span class="new-badge">NEW</span>
                                            <?php endif; ?>
                                        </h5>

                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <h6 class="text-muted">Owner Information</h6>
                                                <p class="mb-1"><strong><?= htmlspecialchars($house['user_fullname']) ?></strong></p>
                                                <p class="mb-1"><i class="fas fa-envelope me-2"></i><?= htmlspecialchars($house['user_email']) ?></p>
                                                <p class="mb-1"><i class="fas fa-phone me-2"></i><?= htmlspecialchars($house['user_contact']) ?></p>
                                                <p class="mb-0"><i class="fas fa-map-marker-alt me-2"></i><?= htmlspecialchars($house['full_location'] ?? 'Not specified') ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="text-muted">Boarding House Details</h6>
                                                <p class="mb-1"><strong>Permit Number:</strong> <?= htmlspecialchars($house['business_permit_number'] ?? 'Not provided') ?></p>
                                                <p class="mb-0"><strong>Status:</strong>
                                                    <?php
                                                    $badge_class = $house['status'] === 'approved' ? 'bg-success' :
                                                                   ($house['status'] === 'declined' ? 'bg-danger' : 'bg-warning text-dark');
                                                    ?>
                                                    <span class="badge <?= $badge_class ?>"><?= ucfirst($house['status']) ?></span>
                                                </p>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <h6 class="text-muted">Description</h6>
                                            <p class="small"><?= htmlspecialchars(substr($house['description'], 0, 200)) ?>...</p>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <h6 class="text-muted">Business Permit</h6>
                                        <?php if (!empty($house['business_permit'])): ?>
                                            <a href="../<?= htmlspecialchars($house['business_permit']) ?>" class="btn btn-sm btn-outline-primary w-100 mb-3 view-permit" data-file="../<?= htmlspecialchars($house['business_permit']) ?>">
                                                <i class="fas fa-file-pdf me-2"></i>View Permit
                                            </a>
                                        <?php else: ?>
                                            <div class="alert alert-warning alert-sm mb-3">
                                                <small>No permit uploaded</small>
                                            </div>
                                        <?php endif; ?>

                                        <div class="btn-group w-100" role="group">
                                                            <?php if ($house['status'] === 'pending'): ?>
                                                                <form method="post" style="display: inline; width: 50%;">
                                                                    <input type="hidden" name="house_id" value="<?= $house['id'] ?>">
                                                                    <input type="hidden" name="new_status" value="approved">
                                                                    <input type="hidden" name="update_house_status" value="1">
                                                                    <button type="submit" class="btn btn-success btn-sm w-100" onclick="return confirm('Approve this boarding house?')">
                                                                        <i class="fas fa-check me-1"></i>Approve
                                                                    </button>
                                                                </form>
                                                                <form method="post" style="display: inline; width: 50%;">
                                                                    <input type="hidden" name="house_id" value="<?= $house['id'] ?>">
                                                                    <input type="hidden" name="new_status" value="declined">
                                                                    <input type="hidden" name="update_house_status" value="1">
                                                                    <button type="submit" class="btn btn-danger btn-sm w-100" onclick="return confirm('Decline this boarding house?')">
                                                                        <i class="fas fa-times me-1"></i>Decline
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                    </div>
                                </div>

                                <hr>
                                <div class="image-gallery-container">
                                    <div class="gallery-section">
                                        <div class="gallery-section-title">
                                            <i class="fas fa-images"></i>
                                            <span>Property Images</span>
                                            <span class="image-count-badge"><?= count($images) ?> Images</span>
                                        </div>

                                        <?php if (!empty($images)): ?>
                                            <div class="image-grid">
                                                <?php foreach ($images as $index => $image): ?>
                                                    <div class="image-card">
                                                        <img loading="lazy" src="../<?= htmlspecialchars(trim($image)) ?>"
                                                             alt="<?= $property_labels[$index] ?? 'Property Image' ?>"
                                                             onerror="this.src='../img/default.jpg'">
                                                        <div class="image-label">
                                                            <?= $property_labels[$index] ?? 'Image ' . ($index + 1) ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="no-images-placeholder">
                                                <i class="fas fa-image"></i>
                                                <span>No property images available</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="gallery-section">
                                        <div class="gallery-section-title">
                                            <i class="fas fa-panorama"></i>
                                            <span>360° Panorama Images</span>
                                            <span class="panorama-badge"><?= count($panoramas) ?> Panoramas</span>
                                        </div>

                                        <?php if (!empty($panoramas)): ?>
                                            <div class="image-grid">
                                                <?php foreach ($panoramas as $pano_index => $panorama): ?>
                                                    <div class="image-card">
                                                        <img loading="lazy" src="../<?= htmlspecialchars(trim($panorama)) ?>"
                                                             alt="360° Panorama <?= $pano_index + 1 ?>"
                                                             onerror="this.src='../img/default.jpg'">
                                                        <div class="image-label">
                                                            360° Panorama <?= $pano_index + 1 ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="no-images-placeholder">
                                                <i class="fas fa-panorama"></i>
                                                <span>No panorama images available</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <hr>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No boarding houses found</h5>
                            <p class="text-muted">Boarding house registrations will appear here</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="tab-pane fade" id="notifications-content" role="tabpanel">
            
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-primary-custom text-dark">
                    <h5 class="mb-0"><i class="fas fa-bullhorn me-2"></i>Send Global Update Message</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="update_message" class="form-label">Update Message (Visible in notif.php to all users)</label>
                            <textarea class="form-control" id="update_message" name="update_message" rows="3" required placeholder="Enter the message for all users (e.g., 'Maintenance downtime tonight')."></textarea>
                        </div>
                        <input type="hidden" name="send_global_message" value="1">
                        <button type="submit" class="btn btn-primary-custom" onclick="return confirm('Are you sure you want to send this global notification to ALL users?');">
                            <i class="fas fa-save me-1"></i> Save and Notify
                        </button>
                    </form>
                </div>
            </div>
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        <i class="fas fa-history me-2"></i>
                        Previous Global Updates (Last 50)
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Sender</th>
                                    <th>Message Snippet</th>
                                    <th>Date Sent</th>
                                </tr>
                            </thead>
                            <tbody id="notifications-tbody">
                                <?php if (!empty($global_notifications)): ?>
                                    <?php foreach ($global_notifications as $notif): ?>
                                        <tr>
                                            <td><?= $notif['id'] ?></td>
                                            <td><strong><?= htmlspecialchars($notif['sender']) ?></strong></td>
                                            <td>
                                                <p class="mb-0 small text-truncate" style="max-width: 400px;">
                                                    <?= htmlspecialchars($notif['message']) ?>
                                                </p>
                                            </td>
                                            <td><small><?= date('M j, Y h:i A', strtotime($notif['created_at'])) ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4">
                                            <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                            <p class="text-muted mb-0">No past global messages found.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>

<!-- Fullscreen image lightbox modal -->
<div id="lightboxModal" class="lightbox-modal" aria-hidden="true">
    <div class="lightbox-content">
        <button class="lightbox-opennew" title="Open in new tab" style="display:none;">Open</button>
        <button class="lightbox-close" title="Close">&times;</button>
        <button class="lightbox-prev" title="Previous" style="display:none;">&#10094;</button>
        <img class="lightbox-image" src="" alt="" style="display:block;">
        <iframe class="lightbox-iframe" src="" style="display:none; width:100%; height:95vh; border:0; background:#fff;" title="Document preview"></iframe>
        <button class="lightbox-next" title="Next" style="display:none;">&#10095;</button>
        <div class="lightbox-caption"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
let updateInterval;
let isUpdating = false;

function isNew(createdTimestamp) {
    const now = Math.floor(Date.now() / 1000);
    return (now - createdTimestamp) < 300;
}

function updateNewBadges() {
    document.querySelectorAll('[data-created]').forEach(element => {
        const createdTime = parseInt(element.getAttribute('data-created'));
        const badge = element.querySelector('.new-badge');

        if (!isNew(createdTime) && badge) {
            badge.remove();
        }
    });
}

async function fetchUpdates() {
    if (isUpdating) return;

    try {
        isUpdating = true;

        const response = await fetch('?ajax=update&t=' + Date.now());
        const data = await response.json();

        if (data.success) {
            updateStats(data.stats, data.house_stats);
            updateOwnersList(data.owners);
            updateHousesList(data.houses);
            updateNotificationsHistory(data.global_notifications);
        }

        updateNewBadges();

    } catch (error) {
        console.error('Update failed:', error);
    } finally {
        isUpdating = false;
    }
}

function updateNotificationsHistory(notifications) {
    const tbody = document.getElementById('notifications-tbody');
    if (!tbody) return;

    tbody.innerHTML = notifications.length > 0 ? notifications.map(notif => {
        const date = new Date(notif.created_at);
        const formattedDate = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });

        return `
            <tr>
                <td>${notif.id}</td>
                <td><strong>${escapeHtml(notif.sender)}</strong></td>
                <td>
                    <p class="mb-0 small text-truncate" style="max-width: 400px;">
                        ${escapeHtml(notif.message)}
                    </p>
                </td>
                <td><small>${formattedDate}</small></td>
            </tr>
        `;
    }).join('') : `
        <tr>
            <td colspan="4" class="text-center py-4">
                <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                <p class="text-muted mb-0">No past global messages found.</p>
            </td>
        </tr>
    `;
}

function updateStats(stats, houseStats) {
    animateNumber('stat-total', stats.total);
    animateNumber('stat-pending', stats.pending);
    animateNumber('stat-approved', stats.approved);
    animateNumber('stat-denied', stats.denied);

    animateNumber('house-stat-total', houseStats.total);
    animateNumber('house-stat-pending', houseStats.pending);
    animateNumber('house-stat-approved', houseStats.approved);
    animateNumber('house-stat-declined', houseStats.declined);
}

function animateNumber(elementId, newValue) {
    const element = document.getElementById(elementId);
    if (!element) return;

    const currentValue = parseInt(element.textContent) || 0;
    if (currentValue !== newValue) {
        element.style.transition = 'all 0.3s ease';
        element.style.transform = 'scale(1.1)';
        element.textContent = newValue;

        setTimeout(() => {
            element.style.transform = 'scale(1)';
        }, 300);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function formatDate(dateString) {
    const date = new Date(dateString);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return `${months[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
}

function updateOwnersList(owners) {
    const tbody = document.getElementById('owners-tbody');
    if (!tbody) return;

    tbody.innerHTML = owners.length > 0 ? owners.map(owner => {
        const createdTime = Math.floor(new Date(owner.created_at).getTime() / 1000);
        const showNewBadge = isNew(createdTime);

        let profileImgHtml = `
            <div class="profile-img bg-secondary d-flex align-items-center justify-content-center text-white">
                <i class="fas fa-user"></i>
            </div>
        `;

        if (owner.user_profile) {
            profileImgHtml = `
                <img src="${escapeHtml(owner.user_profile)}"
                     class="profile-img" alt="Profile"
                     onerror="this.onerror=null; this.src='../img/default.jpg';">
            `;
        }

        return `
            <tr data-created="${createdTime}">
                <td><strong>#${owner.user_id}</strong></td>
                <td>${profileImgHtml}</td>
                <td>
                    <strong>${escapeHtml(owner.user_fullname)}</strong>
                    ${showNewBadge ? '<span class="new-badge">NEW</span>' : ''}
                </td>
                <td>
                    <i class="fas fa-phone text-muted me-1"></i>
                    ${escapeHtml(owner.user_contact)}
                </td>
                <td>
                    <i class="fas fa-envelope text-muted me-1"></i>
                    ${escapeHtml(owner.user_email)}
                </td>
                <td>
                    <small>${formatDate(owner.created_at)}</small>
                </td>
                <td>
                    <div class="btn-group" role="group">
                        ${owner.user_status !== 'approved' ? `
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="user_id" value="${owner.user_id}">
                                <input type="hidden" name="new_status" value="approved">
                                <input type="hidden" name="update_status" value="1">
                                <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Approve this application?')">
                                    <i class="fas fa-check"></i>
                                </button>
                            </form>
                        ` : ''}
                    </div>
                </td>
            </tr>
        `;
    }).join('') : `
        <tr>
            <td colspan="7" class="text-center py-5">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No applications found</h5>
                <p class="text-muted">Owner registrations will appear here</p>
            </td>
        </tr>
    `;
}

function updateHousesList(houses) {
    const housesList = document.getElementById('houses-list');
    if (!housesList) return;

    housesList.innerHTML = houses.length > 0 ? houses.map(house => {
        const house_created_time = Math.floor(new Date(house.created_at).getTime() / 1000);
        const house_is_new = isNew(house_created_time);

        const images = house.images ? house.images.split(',').map(img => img.trim()) : [];
        const panoramas = house.panorama_url ? house.panorama_url.split(',').map(pano => pano.trim()) : [];
        const property_labels = ['Front Image', 'Back Image', 'Side Image'];
        const badge_class = house.status === 'approved' ? 'bg-success' :
                           (house.status === 'declined' ? 'bg-danger' : 'bg-warning text-dark');

        return `
            <div class="house-card-details" data-created="${house_created_time}">
                <div class="row">
                    <div class="col-md-8">
                        <h5 class="mb-3">
                            <i class="fas fa-home me-2"></i>
                            ${escapeHtml(house.name)}
                            <span class="badge bg-primary">ID: ${house.id}</span>
                            ${house_is_new ? '<span class="new-badge">NEW</span>' : ''}
                        </h5>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-muted">Owner Information</h6>
                                <p class="mb-1"><strong>${escapeHtml(house.user_fullname)}</strong></p>
                                <p class="mb-1"><i class="fas fa-envelope me-2"></i>${escapeHtml(house.user_email)}</p>
                                <p class="mb-1"><i class="fas fa-phone me-2"></i>${escapeHtml(house.user_contact)}</p>
                                <p class="mb-0"><i class="fas fa-map-marker-alt me-2"></i>${escapeHtml(house.full_location || 'Not specified')}</p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted">Boarding House Details</h6>
                                <p class="mb-1"><strong>Permit Number:</strong> ${escapeHtml(house.business_permit_number || 'Not provided')}</p>
                                <p class="mb-0"><strong>Status:</strong>
                                    <span class="badge ${badge_class}">${capitalize(house.status)}</span>
                                </p>
                            </div>
                        </div>

                        <div class="mb-3">
                            <h6 class="text-muted">Description</h6>
                            <p class="small">${escapeHtml(house.description.substring(0, 200))}...</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <h6 class="text-muted mb-2">Business Permit</h6>
                        ${house.business_permit ? `
                            <a href="../${escapeHtml(house.business_permit)}" class="btn btn-sm btn-outline-primary w-100 mb-3 view-permit" data-file="../${escapeHtml(house.business_permit)}">
                                <i class="fas fa-file-pdf me-2"></i>View Permit
                            </a>
                        ` : `
                            <div class="alert alert-warning alert-sm mb-3">
                                <small>No permit uploaded</small>
                            </div>
                        `}

                        <div class="btn-group w-100" role="group">
                                ${house.status === 'pending' ? `
                                    <form method="post" style="display: inline; width: 50%;">
                                        <input type="hidden" name="house_id" value="${house.id}">
                                        <input type="hidden" name="new_status" value="approved">
                                        <input type="hidden" name="update_house_status" value="1">
                                        <button type="submit" class="btn btn-success btn-sm w-100" onclick="return confirm('Approve this boarding house?')">
                                            <i class="fas fa-check me-1"></i>Approve
                                        </button>
                                    </form>
                                    <form method="post" style="display: inline; width: 50%;">
                                        <input type="hidden" name="house_id" value="${house.id}">
                                        <input type="hidden" name="new_status" value="declined">
                                        <input type="hidden" name="update_house_status" value="1">
                                        <button type="submit" class="btn btn-danger btn-sm w-100" onclick="return confirm('Decline this boarding house?')">
                                            <i class="fas fa-times me-1"></i>Decline
                                        </button>
                                    </form>
                                ` : ''}
                        </div>
                    </div>
                </div>

                <hr>
                <div class="image-gallery-container">
                    <div class="gallery-section">
                        <div class="gallery-section-title">
                            <i class="fas fa-images"></i>
                            <span>Property Images</span>
                            <span class="image-count-badge">${images.length} Images</span>
                        </div>

                        ${images.length > 0 ? `
                            <div class="image-grid">
                                ${images.map((img, idx) => `
                                    <div class="image-card">
                                        <img src="../${escapeHtml(img)}"
                                             alt="${property_labels[idx] || 'Property Image'}"
                                             onerror="this.src='../img/default.jpg'">
                                        <div class="image-label">
                                            ${property_labels[idx] || 'Image ' + (idx + 1)}
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        ` : `
                            <div class="no-images-placeholder">
                                <i class="fas fa-image"></i>
                                <span>No property images available</span>
                            </div>
                        `}
                    </div>

                    <div class="gallery-section">
                        <div class="gallery-section-title">
                            <i class="fas fa-panorama"></i>
                            <span>360° Panorama Images</span>
                            <span class="panorama-badge">${panoramas.length} Panoramas</span>
                        </div>

                        ${panoramas.length > 0 ? `
                            <div class="image-grid">
                                ${panoramas.map((pano, idx) => `
                                    <div class="image-card">
                                        <img src="../${escapeHtml(pano)}"
                                             alt="360° Panorama ${idx + 1}"
                                             onerror="this.src='../img/default.jpg'">
                                        <div class="image-label">
                                            360° Panorama ${idx + 1}
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        ` : `
                            <div class="no-images-placeholder">
                                <i class="fas fa-panorama"></i>
                                <span>No panorama images available</span>
                            </div>
                        `}
                    </div>
                </div>

                <hr>
            </div>
        `;
    }).join('') : `
        <div class="text-center py-5">
            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">No boarding houses found</h5>
            <p class="text-muted">Boarding house registrations will appear here</p>
        </div>
    `;
}

updateInterval = setInterval(fetchUpdates, 15000);

setInterval(updateNewBadges, 2000);

setTimeout(fetchUpdates, 5000);

document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        clearInterval(updateInterval);
    } else {
        updateInterval = setInterval(fetchUpdates, 10000);
        fetchUpdates();
    }
});

console.log('Admin Dashboard Loaded Successfully');
// --- Lightbox / Fullscreen Image Viewer ---
(function() {
    const modal = document.getElementById('lightboxModal');
    const modalImg = modal ? modal.querySelector('.lightbox-image') : null;
    const caption = modal ? modal.querySelector('.lightbox-caption') : null;
    const btnClose = modal ? modal.querySelector('.lightbox-close') : null;
    const btnPrev = modal ? modal.querySelector('.lightbox-prev') : null;
    const btnNext = modal ? modal.querySelector('.lightbox-next') : null;
    const btnOpenNew = modal ? modal.querySelector('.lightbox-opennew') : null;

    let currentGallery = [];
    let currentIndex = 0;

    function openLightbox(gallery, index) {
        if (!modal || !modalImg) return;
        currentGallery = gallery || [];
        currentIndex = index || 0;
        showImage(currentIndex);
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        // Try to request fullscreen for immersive view (may be blocked)
        try { if (modal.requestFullscreen) modal.requestFullscreen(); } catch(e) {}
    }

    // Open arbitrary file (image or PDF) in the lightbox modal
    function openFileInLightbox(url, label) {
        if (!modal) return;
        const src = url;
        const ext = (src.split('.').pop() || '').toLowerCase();
        const iframe = modal.querySelector('.lightbox-iframe');
        const img = modal.querySelector('.lightbox-image');
        const captionEl = modal.querySelector('.lightbox-caption');

        // reset
        if (iframe) { iframe.style.display = 'none'; iframe.src = ''; }
        if (img) { img.style.display = 'none'; img.src = ''; }

        if (['pdf'].includes(ext)) {
            if (iframe) {
                // Use safe browsing path; allow browser to render PDF
                iframe.src = src;
                iframe.style.display = 'block';
            }
            captionEl.textContent = label || 'Document Preview';
        } else {
            if (img) {
                img.src = src;
                img.style.display = 'block';
            }
            captionEl.textContent = label || 'Image Preview';
        }

        if (btnOpenNew) btnOpenNew.dataset.src = src;

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        try { if (modal.requestFullscreen) modal.requestFullscreen(); } catch(e) {}
    }

    function closeLightbox() {
        if (!modal) return;
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        try { if (document.fullscreenElement) document.exitFullscreen(); } catch(e) {}
    }

    function showImage(idx) {
        const item = currentGallery[idx];
        if (!item) return;
        const iframe = modal.querySelector('.lightbox-iframe');
        if (iframe) { iframe.style.display = 'none'; iframe.src = ''; }
        modalImg.src = item.src;
        modalImg.alt = item.alt || '';
        caption.textContent = `${item.label || item.alt || 'Image'} — ${idx + 1} of ${currentGallery.length}`;
        if (btnOpenNew) btnOpenNew.dataset.src = item.src;
    }

    function nextImage() {
        if (!currentGallery.length) return;
        currentIndex = (currentIndex + 1) % currentGallery.length;
        showImage(currentIndex);
    }

    function prevImage() {
        if (!currentGallery.length) return;
        currentIndex = (currentIndex - 1 + currentGallery.length) % currentGallery.length;
        showImage(currentIndex);
    }

    // Delegate clicks on any image-card image
    document.addEventListener('click', function(e) {
        const img = e.target.closest('.image-card img');
        if (!img) return;
        // find images within the same gallery container (image-gallery-container)
        const galleryContainer = img.closest('.image-gallery-container');
        const imgs = galleryContainer ? Array.from(galleryContainer.querySelectorAll('img')) : [img];
        const gallery = imgs.map(i => ({ src: i.getAttribute('src'), alt: i.getAttribute('alt'), label: i.closest('.image-card')?.querySelector('.image-label')?.textContent?.trim() }));
        const index = imgs.indexOf(img);
        openLightbox(gallery, index);
    });

    // Delegate clicks on permit/view links (open images or PDFs in modal)
    document.addEventListener('click', function(e) {
        const link = e.target.closest('.view-permit');
        if (!link) return;
        e.preventDefault();
        const file = link.dataset.file || link.getAttribute('href');
        const label = link.dataset.label || (link.textContent || '').trim();
        if (file) openFileInLightbox(file, label);
    });

    // Controls
    if (btnClose) btnClose.addEventListener('click', closeLightbox);
    if (btnNext) btnNext.addEventListener('click', nextImage);
    if (btnPrev) btnPrev.addEventListener('click', prevImage);
    if (btnOpenNew) btnOpenNew.addEventListener('click', function(){
        const src = this.dataset.src;
        if (src) window.open(src, '_blank');
    });

    // Click outside image closes
    if (modal) modal.addEventListener('click', function(e){
        if (e.target === modal) closeLightbox();
    });

    // Keyboard navigation
    document.addEventListener('keydown', function(e){
        if (modal && modal.style.display === 'flex') {
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowRight') nextImage();
            if (e.key === 'ArrowLeft') prevImage();
        }
    });

    // (Inline edit UI removed; dashboard is read-only for permit numbers)

    // Expose for debugging (optional)
    window._adminLightbox = { open: openLightbox, close: closeLightbox };
})();
</script>

</body>
</html>