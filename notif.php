<?php
session_start();
ob_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$envPath = dirname(__DIR__) . '/.env';

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

// Ensure user is logged in
if (empty($_SESSION['email'])) {
    echo "<script>alert('Please login to view notifications.'); window.location.href='auseregisterlogform/loginseeker.php';</script>";
    exit;
}

$userEmail = trim($_SESSION['email']);
$notifications = [];

// Database Connection
$conn = @new mysqli(
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASS'] ?? '',
    $_ENV['DB_NAME'] ?? ''
);

if ($conn->connect_error) {
    // Fall back to a minimal view when DB is unreachable
    $notifications = [];
} else {
    // Create read-tracking table if not exists
    $createReads = "CREATE TABLE IF NOT EXISTS user_notification_reads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(255) NOT NULL,
        notif_type VARCHAR(100) NOT NULL,
        ref_id VARCHAR(255) NOT NULL,
        read_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_user_notif (user_email, notif_type, ref_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($createReads);

    // Handle AJAX endpoints: fetch (JSON) or mark_read
    if (isset($_GET['action']) && $_GET['action'] === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $inputType = $_POST['type'] ?? '';
        $inputRef = $_POST['ref'] ?? '';
        if ($inputType && $inputRef) {
            $stmt = $conn->prepare("INSERT INTO user_notification_reads (user_email, notif_type, ref_id, read_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE read_at = NOW()");
            if ($stmt) {
                $stmt->bind_param('sss', $userEmail, $inputType, $inputRef);
                $stmt->execute();
                $stmt->close();
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                $conn->close();
                exit;
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => false]);
        $conn->close();
        exit;
    }

    // Build notifications for the logged-in user
    // 1) Get user's full name (if available)
    $userFull = null;
    $stmt = $conn->prepare("SELECT fullname FROM registerusers WHERE TRIM(email) = TRIM(?) LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $userEmail);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $userFull = $row['fullname'];
        }
        $stmt->close();
    }

    // 2) Fetch recent payment events for this tenant (paid or near_due)
    if ($userFull) {
        $pstmt = $conn->prepare("SELECT ph.id, ph.house_id, ph.tenant_name, ph.status, ph.payment_date, ph.payment_month, ph.next_due_date, bh.name as house_name, ph.created_at
            FROM payment_history ph
            LEFT JOIN boarding_houses bh ON bh.id = ph.house_id
            WHERE TRIM(ph.tenant_name) = TRIM(?)
            ORDER BY ph.created_at DESC
            LIMIT 50");
        if ($pstmt) {
            $pstmt->bind_param('s', $userFull);
            $pstmt->execute();
            $pres = $pstmt->get_result();
            while ($prow = $pres->fetch_assoc()) {
                $type = ($prow['status'] === 'paid') ? 'payment_paid' : (($prow['status'] === 'near_due') ? 'payment_near_due' : null);
                if ($type) {
                    $ref = 'payment_' . $prow['id'];
                    $msg = '';
                    if ($prow['status'] === 'paid') {
                        $when = $prow['payment_date'] ? date('M j, Y', strtotime($prow['payment_date'])) : ($prow['payment_month'] ?? '');
                        $msg = "Payment received for " . ($prow['house_name'] ?? 'your booking') . " on " . $when . ". Thank you.";
                    } else {
                        $msg = "Payment near due for " . ($prow['house_name'] ?? 'your booking') . ". Due: " . ($prow['next_due_date'] ?? 'soon') . ". Please pay to avoid interruption.";
                    }
                    $notifications[] = [
                        'type' => $type,
                        'ref' => $ref,
                        'message' => $msg,
                        'created_at' => $prow['created_at'] ?? date('Y-m-d H:i:s')
                    ];
                }
            }
            $pstmt->close();
        }
    }

    // 3) Booking acceptances from tenant_requests
    // Some installations don't have a created_at column on tenant_requests; order by id instead to avoid errors
    $rstmt = $conn->prepare("SELECT tr.id, tr.house_id, tr.status, bh.name as house_name FROM tenant_requests tr LEFT JOIN boarding_houses bh ON bh.id = tr.house_id WHERE TRIM(tr.email) = TRIM(?) AND tr.status = 'accepted' ORDER BY tr.id DESC LIMIT 50");
    if ($rstmt) {
        $rstmt->bind_param('s', $userEmail);
        $rstmt->execute();
        $rres = $rstmt->get_result();
        while ($rrow = $rres->fetch_assoc()) {
            $ref = 'request_' . $rrow['id'];
            $msg = "Your booking request for " . ($rrow['house_name'] ?? 'a house') . " has been accepted.\nCheck your bookings to proceed.";
            $notifications[] = [
                'type' => 'booking_accepted',
                'ref' => $ref,
                'message' => $msg,
                // tenant_requests may not have a timestamp column across all installs — fall back to now
                'created_at' => $rrow['created_at'] ?? date('Y-m-d H:i:s')
            ];
        }
        $rstmt->close();
    }

    // 4) Booking terminations for the user's yourbook entries
    // Some installs don't have a created_at column on yourbook; select existing columns and order by id
    $bstmt = $conn->prepare("SELECT y.id, y.boardingHouse, y.status, bh.id as house_id, bh.name as house_name FROM yourbook y LEFT JOIN boarding_houses bh ON bh.name = y.boardingHouse WHERE TRIM(y.email) = TRIM(?) ORDER BY y.id DESC LIMIT 100");
    if ($bstmt) {
        $bstmt->bind_param('s', $userEmail);
        $bstmt->execute();
        $bres = $bstmt->get_result();
        while ($brow = $bres->fetch_assoc()) {
            if (($brow['status'] ?? '') === 'cancelled_by_owner') {
                $ref = 'booking_' . $brow['id'];
                $msg = "Your booking for " . ($brow['boardingHouse'] ?? 'a house') . " was cancelled by the owner.\nPlease contact the owner or make a new booking.";
                $notifications[] = [
                    'type' => 'booking_terminated',
                    'ref' => $ref,
                    'message' => $msg,
                    // yourbook may not have a timestamp column across installs — use now
                    'created_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        $bstmt->close();
    }

    // 5) Global messages from developers (global_notifications)
    $gresult = $conn->query("SELECT id, sender, message, created_at FROM global_notifications ORDER BY created_at DESC LIMIT 50");
    if ($gresult) {
        while ($grow = $gresult->fetch_assoc()) {
            $ref = 'global_' . ($grow['id'] ?? '');
            $sender = $grow['sender'] ?? 'DEV';
            $msg = ($grow['message'] ?? '') . "\n\n-- " . $sender;
            $notifications[] = [
                'type' => 'global_message',
                'ref' => $ref,
                'message' => $msg,
                'created_at' => $grow['created_at'] ?? date('Y-m-d H:i:s')
            ];
        }
        $gresult->close();
    }

    // Sort notifications by created_at desc
    usort($notifications, function($a, $b) {
        return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
    });

    // Enrich notifications with read/unread status
    foreach ($notifications as &$n) {
        $t = $conn->real_escape_string($n['type']);
        $r = $conn->real_escape_string($n['ref']);
        $check = $conn->query("SELECT read_at FROM user_notification_reads WHERE user_email = '" . $conn->real_escape_string($userEmail) . "' AND notif_type = '" . $t . "' AND ref_id = '" . $r . "' LIMIT 1");
        if ($check && $check->num_rows > 0) {
            $row = $check->fetch_assoc();
            $n['read_at'] = $row['read_at'];
        } else {
            $n['read_at'] = null;
        }
        if ($check) $check->close();
    }

    // Close connection (we'll reopen for mark_read AJAX when needed)
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
      <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - StayFinder</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .header {
            background-color: white;
            padding: 15px 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        /* Custom Back Button Styling */
        .back-button {
            background-color: transparent;
            border: none;
            padding: 0;
            cursor: pointer;
            outline: none;
            transition: opacity 0.2s;
        }
        .back-button:hover {
            opacity: 0.8;
        }
        /* Yellow Circle Container */
        .back-icon-container {
            width: 35px; 
            height: 35px;
            border-radius: 50%;
            background-color: #FDD835; /* StayFinder Primary Color */
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }
        /* Black < Icon */
        .back-icon-container i {
            color: black; 
            font-size: 16px;
            font-weight: bold;
        }
        .content {
            padding: 20px;
        }
        
        /* START: Styles for Notifications */
        .notification-list {
            list-style: none;
            padding: 0;
            max-width: 600px;
            margin: 20px auto;
        }
        .notification-item {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        .notification-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }
        .notification-item.unread {
            border-left: 6px solid #FFD700;
            background-color: #fffef7;
            cursor: pointer;
        }
        .notification-item.read {
            opacity: 0.98;
        }
        .notification-header {
            font-weight: bold;
            color: #d9534f; /* A strong color for the DEV header */
            margin-bottom: 5px;
            border-bottom: 2px solid #eee;
            padding-bottom: 5px;
            font-size: 1.2em;
        }
        .notification-message {
            margin: 0;
            color: #333;
            line-height: 1.5;
            white-space: pre-wrap; /* Respect newlines in the message */
        }
        .notification-date {
            font-size: 0.75em;
            color: #999;
            display: block;
            margin-top: 10px;
            text-align: right;
            border-top: 1px dashed #eee;
            padding-top: 5px;
        }
        .text-center { text-align: center; }
        .text-muted { color: #6c757d; }
        /* END: Styles for Notifications */
    </style>
</head>
<body>
    <div class="header">
        <button class="back-button" onclick="goBack()" aria-label="Go back">
            <div class="back-icon-container" aria-hidden="true">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
            </div>
        </button>
        <h2 style="margin-left: 15px; font-size: 1.5em; color: #333;">System Notifications</h2>
    </div>

    <div class="content">
        <h1 class="text-center" style="color: #333;">Notifications</h1>
        <p class="text-center text-muted">Tap a notification to mark it as read. You will keep new alerts until you open them.</p>
        <!-- Add margin-bottom to push content above sticky footer -->
        <div style="margin-bottom: 80px;"></div>
        <ul class="notification-list">
            <?php if (count($notifications) > 0): ?>
                <?php foreach ($notifications as $notif): ?>
                    <?php
                        $isUnread = empty($notif['read_at']);
                        $type = $notif['type'] ?? 'info';
                        $icon = 'fas fa-info-circle';
                        $title = 'Notice';
                        $color = '#333';
                        if ($type === 'payment_paid') { $icon = 'fas fa-check-circle'; $title = 'Payment Received'; $color = '#198754'; }
                        if ($type === 'payment_near_due') { $icon = 'fas fa-exclamation-triangle'; $title = 'Payment Near Due'; $color = '#d39e00'; }
                        if ($type === 'booking_accepted') { $icon = 'fas fa-calendar-check'; $title = 'Booking Accepted'; $color = '#0d6efd'; }
                        if ($type === 'booking_terminated') { $icon = 'fas fa-ban'; $title = 'Booking Cancelled'; $color = '#dc3545'; }
                        if ($type === 'global_message') { $icon = 'fas fa-bullhorn'; $title = 'Developer Update'; $color = '#6f42c1'; }
                    ?>
                    <li class="notification-item <?php echo $isUnread ? 'unread' : 'read'; ?>" data-type="<?= htmlspecialchars($notif['type']) ?>" data-ref="<?= htmlspecialchars($notif['ref']) ?>" onclick="markRead(this)">
                        <div class="notification-header" style="color: <?= htmlspecialchars($color) ?>; display:flex; align-items:center; gap:10px;">
                            <i class="<?= $icon ?>" style="font-size:1.1em;"></i>
                            <span style="font-weight:800;"><?= htmlspecialchars($title) ?></span>
                        </div>
                        <p class="notification-message">
                            <?= nl2br(htmlspecialchars($notif['message'])) ?>
                        </p>
                        <span class="notification-date">
                            <i class="fas fa-clock"></i>
                            <?= date('M j, Y g:i A', strtotime($notif['created_at'])) ?>
                            <?php if ($isUnread): ?>
                                <strong style="color:#FFD700; margin-left:8px;">NEW</strong>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <li class="notification-item text-center">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No new notifications.</h5>
                    <p>Everything looks clear for now.</p>
                </li>
            <?php endif; ?>
        </ul>
        
        <p class="text-center text-muted mt-4">Thank you for being part of StayFinder.</p>
    </div>
    
    <script>
        function goBack() {
            window.history.back();
        }

        async function markRead(el) {
            if (!el || !el.dataset) return;
            const type = el.dataset.type;
            const ref = el.dataset.ref;
            if (!type || !ref) return;

            // If already read, do nothing
            if (!el.classList.contains('unread')) return;

            try {
                const body = `type=${encodeURIComponent(type)}&ref=${encodeURIComponent(ref)}`;
                const res = await fetch('notif.php?action=mark_read', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body
                });
                const data = await res.json();
                if (data && data.success) {
                    el.classList.remove('unread');
                    el.classList.add('read');
                    // remove NEW label if present
                    const strong = el.querySelector('strong');
                    if (strong) strong.remove();
                } else {
                    console.warn('Could not mark notification read');
                }
            } catch (e) {
                console.error('Request failed', e);
            }
        }
    </script>
        <footer class="footer" style="background-color:#111;color:#fff;padding:10px 0;text-align:center;position:fixed;left:0;bottom:0;width:100%;z-index:9999;">
            <div class="container">
                <div class="row">
                    <div class="col-12">
                        <p class="mb-0" style="font-size:13px;">
                            StayFinder: Boarding Locator and Management System
                        </p>
                        <p class="mt-1 mb-0" style="font-size:13px;">
                            <a href="terms.php" style="color:#FFD700 !important;" class="text-decoration-none fw-bold">Terms &amp; Conditions</a>
                        </p>
                    </div>
                </div>
            </div>
        </footer>
</body>
</html>