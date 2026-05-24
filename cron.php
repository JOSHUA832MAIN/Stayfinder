<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/cron_php_errors.log');

// ✅ Load .env file manually (Hostinger shared hosting)
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
} else {
    die("❌ .env file not found at: $envFile");
}

// ✅ Load database connection
require_once __DIR__ . '/connectiondatabase/main_connection.php';

// ✅ Load PHPMailer
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * ✅ Send notification email to boarding house owner
 * All credentials loaded from .env — nothing hardcoded
 */
function sendOwnerNotification(
    $ownerEmail,
    $ownerName,
    $tenantName,
    $tenantEmail,
    $houseName,
    $roomDetails,
    $startDate
) {
    $mail = new PHPMailer(true);

    try {
        // ✅ SMTP settings — all from .env
        $mail->isSMTP();
        $mail->Host       = getenv('MAIL_HOST')     ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('MAIL_USERNAME');
        $mail->Password   = getenv('MAIL_PASSWORD');
        $mail->SMTPSecure = getenv('MAIL_ENCRYPTION') ?: 'tls';
        $mail->Port       = (int)(getenv('MAIL_PORT') ?: 587);
        $mail->SMTPDebug  = 0;

        // ✅ Sender and recipient
        $mail->setFrom(getenv('MAIL_USERNAME'), getenv('MAIL_FROM_NAME') ?: 'StayFinder System');
        $mail->addAddress($ownerEmail, $ownerName);

        // ✅ Email content
        $mail->isHTML(true);
        $mail->Subject = '🏠 NEW TENANT REQUEST - ' . $houseName;
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <h2 style='color: #2563eb; text-align: center;'>🏠 New Tenant Request</h2>

                <div style='background: #f8fafc; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                    <h3 style='color: #1e40af; margin-top: 0;'>Boarding House: $houseName</h3>
                    <p><strong>📍 Room Details:</strong> $roomDetails</p>
                </div>

                <div style='background: #ecfdf5; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                    <h3 style='color: #059669; margin-top: 0;'>👤 Tenant Information</h3>
                    <p><strong>Name:</strong> $tenantName</p>
                    <p><strong>Email:</strong> $tenantEmail</p>
                    <p><strong>Start Date:</strong> $startDate</p>
                </div>

                <div style='text-align: center; margin: 30px 0;'>
                    <p style='color: #dc2626; font-weight: bold;'>⚠️ ACTION REQUIRED</p>
                    <p>Please log in to your dashboard to accept or decline this request.</p>
                </div>

                <div style='background: #f1f5f9; padding: 15px; border-radius: 8px; text-align: center;'>
                    <p style='margin: 0; color: #64748b; font-size: 14px;'>
                        This is an automated message from StayFinder System
                    </p>
                </div>
            </div>
        ";

        $mail->send();

        file_put_contents(
            __DIR__ . "/cron_log.txt",
            "[SUCCESS] Email sent to $ownerEmail ($ownerName) | Tenant: $tenantName | House: $houseName at " . date("Y-m-d H:i:s") . "\n",
            FILE_APPEND
        );

        return true;

    } catch (Exception $e) {
        file_put_contents(
            __DIR__ . "/cron_errors.txt",
            "[ERROR] Owner: $ownerEmail | Tenant: $tenantName | Error: {$mail->ErrorInfo} at " . date("Y-m-d H:i:s") . "\n",
            FILE_APPEND
        );
        return false;
    }
}

/**
 * ✅ Check pending bookings and notify owners
 */
function checkAndNotifyNewBookings($conn) {
    // ✅ Uses prepared statement — safe from SQL injection
    $sql = "SELECT 
                tr.id, 
                tr.full_name, 
                tr.email        AS tenant_email,
                tr.start_date, 
                tr.room_number,
                tr.bed_number,
                bh.name         AS house_name,
                o.user_fullname AS owner_name, 
                o.user_email    AS owner_email
            FROM tenant_requests tr
            JOIN boarding_houses bh ON tr.house_id  = bh.id
            JOIN ownerregister   o  ON bh.owner_id  = o.user_id
            WHERE tr.status     = 'pending' 
              AND tr.email_sent = 0
            ORDER BY tr.request_date ASC";

    $result = $conn->query($sql);

    if (!$result) {
        file_put_contents(
            __DIR__ . "/cron_errors.txt",
            "[SQL ERROR] " . $conn->error . " at " . date("Y-m-d H:i:s") . "\n",
            FILE_APPEND
        );
        return;
    }

    $totalFound = $result->num_rows;

    file_put_contents(
        __DIR__ . "/cron_log.txt",
        "[INFO] Found $totalFound new pending bookings to process at " . date("Y-m-d H:i:s") . "\n",
        FILE_APPEND
    );

    if ($totalFound === 0) {
        file_put_contents(
            __DIR__ . "/cron_log.txt",
            "[INFO] No new pending bookings found at " . date("Y-m-d H:i:s") . "\n",
            FILE_APPEND
        );
        return;
    }

    while ($row = $result->fetch_assoc()) {
        $ownerEmail  = $row['owner_email'];
        $ownerName   = $row['owner_name'];
        $tenantName  = $row['full_name'];
        $tenantEmail = $row['tenant_email'];
        $houseName   = $row['house_name'];
        $roomDetails = "Room {$row['room_number']}, Bed {$row['bed_number']}";
        $startDate   = $row['start_date'];

        file_put_contents(
            __DIR__ . "/cron_log.txt",
            "[DEBUG] Processing booking ID {$row['id']} — Sending to $ownerEmail for tenant $tenantName at " . date("Y-m-d H:i:s") . "\n",
            FILE_APPEND
        );

        $emailSent = sendOwnerNotification(
            $ownerEmail,
            $ownerName,
            $tenantName,
            $tenantEmail,
            $houseName,
            $roomDetails,
            $startDate
        );

        if ($emailSent) {
            // ✅ Prepared statement — safe from SQL injection
            $update = $conn->prepare("UPDATE tenant_requests SET email_sent = 1 WHERE id = ?");
            $update->bind_param("i", $row['id']);

            if ($update->execute()) {
                file_put_contents(
                    __DIR__ . "/cron_log.txt",
                    "[UPDATE] Marked booking ID {$row['id']} as notified at " . date("Y-m-d H:i:s") . "\n",
                    FILE_APPEND
                );
            } else {
                file_put_contents(
                    __DIR__ . "/cron_errors.txt",
                    "[UPDATE ERROR] Failed to mark booking ID {$row['id']}: " . $conn->error . " at " . date("Y-m-d H:i:s") . "\n",
                    FILE_APPEND
                );
            }

            $update->close();

        } else {
            file_put_contents(
                __DIR__ . "/cron_errors.txt",
                "[EMAIL FAILED] Could not send email for booking ID {$row['id']} at " . date("Y-m-d H:i:s") . "\n",
                FILE_APPEND
            );
        }
    }
}

// ✅ Run cron job
try {
    if (!$conn) {
        throw new Exception("Database connection failed");
    }

    file_put_contents(
        __DIR__ . "/cron_log.txt",
        "[CRON START] Starting cron job at " . date("Y-m-d H:i:s") . "\n",
        FILE_APPEND
    );

    checkAndNotifyNewBookings($conn);

    file_put_contents(
        __DIR__ . "/cron_log.txt",
        "[CRON END] Finished cron job at " . date("Y-m-d H:i:s") . "\n\n",
        FILE_APPEND
    );

    echo "✅ Booking notifications processed successfully at " . date("Y-m-d H:i:s");

} catch (Exception $e) {
    $errorMsg = "[CRON FATAL ERROR] " . $e->getMessage() . " at " . date("Y-m-d H:i:s") . "\n";
    file_put_contents(__DIR__ . "/cron_errors.txt", $errorMsg, FILE_APPEND);
    echo "❌ Cron job failed: " . $e->getMessage();
}
?>