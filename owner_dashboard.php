<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/../vendor/autoload.php';

$envPath = dirname(__DIR__, 2) . '/.env';

if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
} else {
    die("âŒ Missing .env file at: " . $envPath);
}

require_once '../connectiondatabase/main_connection.php';
$house_id = $_GET['house_id'] ?? $_SESSION['house_owner_id'];
$stmt = $conn->prepare("SELECT * FROM boarding_houses WHERE id = ?");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$house = $stmt->get_result()->fetch_assoc();
if (!$house) {
    echo "<script>alert('Boarding house not found!'); window.location.href='createboardinghouse.php';</script>";
    exit();
}

function generateRoomID($conn, $house_id) {
    do {
        $random_letters = '';
        for ($i = 0; $i < 3; $i++) {
            $random_letters .= chr(rand(65, 90));
        }

        $random_number = rand(1000, 9999);
        $room_id = $random_letters . $random_number;

        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM house_rooms WHERE room_id = ?");
        $stmt->bind_param("s", $room_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

    } while ($result['count'] > 0);

    return $room_id;
}

$check_column = $conn->query("SHOW COLUMNS FROM house_rooms LIKE 'room_id'");
if ($check_column->num_rows == 0) {
    $conn->query("ALTER TABLE house_rooms ADD COLUMN room_id VARCHAR(20) DEFAULT NULL AFTER room_number");

    $stmt = $conn->prepare("SELECT id, house_id, room_number FROM house_rooms WHERE room_id IS NULL");
    $stmt->execute();
    $existing_rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($existing_rooms as $room) {
        $room_id = generateRoomID($room['house_id'], $room['room_number']);
        $update_stmt = $conn->prepare("UPDATE house_rooms SET room_id = ? WHERE id = ?");
        $update_stmt->bind_param("si", $room_id, $room['id']);
        $update_stmt->execute();
    }
}

$panorama_images = [];
if (!empty($house['panorama_url'])) {
    $panorama_paths = explode(',', $house['panorama_url']);
    foreach ($panorama_paths as $path) {
        $clean_path = trim($path);
        if (!empty($clean_path)) {
            $panorama_images[] = $clean_path;
        }
    }
}

$room_images = [];
if (!empty($house['room_images'])) {
    $room_paths = explode(',', $house['room_images']);
    foreach ($room_paths as $path) {
        $clean_path = trim($path);
        if (!empty($clean_path)) {
            $room_images[] = $clean_path;
        }
    }
}

if ($_POST && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action == 'handle_tenant_request') {
        try {
            $request_id = $_POST['request_id'];
            $decision   = $_POST['decision'];

            $stmt = $conn->prepare("SELECT * FROM tenant_requests WHERE id = ?");
            $stmt->bind_param("i", $request_id);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();

            if (!$request) {
                echo json_encode(['success' => false, 'error' => 'Request not found']);
                exit;
            }

            $stmt = $conn->prepare("SELECT name, price, owner_email FROM boarding_houses WHERE id = ?");
            $stmt->bind_param("i", $request['house_id']);
            $stmt->execute();
            $house_result = $stmt->get_result()->fetch_assoc();

            $room_price = null;
            $stmt = $conn->prepare("SELECT price FROM room_prices WHERE house_id = ? AND room_number = ?");
            $stmt->bind_param("ii", $request['house_id'], $request['room_number']);
            $stmt->execute();
            $price_result = $stmt->get_result()->fetch_assoc();
            if ($price_result) {
                $room_price = $price_result['price'];
            }

            $stmt = $conn->prepare("UPDATE tenant_requests SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $decision, $request_id);
            $stmt->execute();

            if ($decision == 'accepted') {
                $stmt = $conn->prepare("INSERT INTO bed_occupancy
                    (house_id, room_number, bed_number, is_occupied, tenant_name)
                    VALUES (?, ?, ?, 1, ?)");
                $stmt->bind_param("iiis", $request['house_id'], $request['room_number'], $request['bed_number'], $request['full_name']);
                $stmt->execute();

                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = $env['SMTP_HOST'];
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $env['SMTP_USERNAME'];
                    $mail->Password   = $env['SMTP_PASSWORD'];
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = $env['SMTP_PORT'];
                    $mail->setFrom($env['SMTP_FROM_EMAIL'], $env['SMTP_FROM_NAME']);
                    $mail->addAddress($request['email'], $request['full_name']);

                    $mail->isHTML(true);
                    $mail->Subject = 'Booking Confirmation - Your Reservation Has Been Approved!';

                    $price_display = $room_price ? "₱" . number_format($room_price, 2) . "/MONTH" : ($house_result['price'] ? $house_result['price'] . " PESOS/MONTH" : "Contact Owner for Price");

                    $mail->Body = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9f9f9;'>
                        <div style='background-color: #27ae60; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0;'>
                            <h1 style='margin: 0; font-size: 24px;'>ðŸŽ‰ Booking Confirmed!</h1>
                        </div>
                        <div style='background-color: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                            <p style='font-size: 18px; color: #2c3e50; margin-bottom: 20px;'>Dear <strong>{$request['full_name']}</strong>,</p>

                            <p style='font-size: 16px; color: #34495e; line-height: 1.6;'>
                                Great news! Your booking has been <strong style='color: #27ae60;'>CONFIRMED</strong>.
                                Here are your reservation details:
                            </p>

                            <div style='background-color: #ecf0f1; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                                <h3 style='color: #2c3e50; margin-top: 0;'>ðŸ“‹ Booking Details</h3>
                                <table style='width: 100%; border-collapse: collapse;'>
                                    <tr>
                                        <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Boarding House:</td>
                                        <td style='padding: 8px 0; color: #2c3e50;'>{$house_result['name']}</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Room Number:</td>
                                        <td style='padding: 8px 0; color: #2c3e50;'>Room {$request['room_number']}</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Bed Number:</td>
                                        <td style='padding: 8px 0; color: #2c3e50;'>Bed {$request['bed_number']}</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Price:</td>
                                        <td style='padding: 8px 0; color: #27ae60; font-weight: bold; font-size: 18px;'>{$price_display}</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Start Date:</td>
                                        <td style='padding: 8px 0; color: #2c3e50;'>{$request['start_date']}</td>
                                    </tr>
                                </table>
                            </div>

                            <div style='background-color: #3498db; color: white; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                                <h4 style='margin: 0 0 10px 0;'>ðŸ“ž Next Steps:</h4>
                                <p style='margin: 0; line-height: 1.5;'>
                                    Please contact the boarding house owner for move-in arrangements and payment details.
                                    Your reservation is secured!
                                </p>
                            </div>

                            <p style='color: #7f8c8d; font-size: 14px; text-align: center; margin-top: 30px;'>
                                Thank you for choosing StayFinder!<br>
                                <em>This is an automated confirmation email.</em>
                            </p>
                        </div>
                    </div>";

                    $mail->AltBody = "Your booking has been confirmed!
                    Boarding House: {$house_result['name']}
                    Room Number: {$request['room_number']}
                    Bed Number: {$request['bed_number']}
                    Price: {$price_display}
                    Start Date: {$request['start_date']}

                    Thank you for choosing StayFinder!";

                    $mail->send();
                } catch (Exception $e) {
                    error_log("Email sending failed: " . $mail->ErrorInfo);
                }
            }
        elseif ($decision == 'declined') {
                $stmt = $conn->prepare("DELETE FROM bed_occupancy
                    WHERE house_id = ? AND room_number = ? AND bed_number = ?");
                $stmt->bind_param("iii", $request['house_id'], $request['room_number'], $request['bed_number']);
                $stmt->execute();

                $slot_pattern = "Room " . $request['room_number'] . ", Bed " . $request['bed_number'];
                $stmt = $conn->prepare("UPDATE yourbook
                    SET status = 'declined'
                    WHERE boardingHouse = (SELECT name FROM boarding_houses WHERE id = ?)
                    AND availableSlots = ?");
                $stmt->bind_param("is", $request['house_id'], $slot_pattern);
                $stmt->execute();


                $slot_pattern = "Room " . $request['room_number'] . ", Bed " . $request['bed_number'];
$stmt = $conn->prepare("UPDATE yourbook
    SET status = 'declined'
    WHERE boardingHouse = (SELECT name FROM boarding_houses WHERE id = ?)
    AND availableSlots = ?");
                $stmt->bind_param("is", $request['house_id'], $slot_pattern);
                $stmt->execute();

                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = $env['SMTP_HOST'];
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $env['SMTP_USERNAME'];
                    $mail->Password   = $env['SMTP_PASSWORD'];
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = $env['SMTP_PORT'];

                    $mail->setFrom($env['SMTP_FROM_EMAIL'], $env['SMTP_FROM_NAME']);
                    $mail->addAddress($request['email'], $request['full_name']);

                    $mail->isHTML(true);
                    $mail->Subject = 'âŒ Booking Request Declined - ' . $house_result['name'];

                    $mail->Body = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9f9f9;'>
                        <div style='background-color: #e74c3c; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0;'>
                            <h1 style='margin: 0; font-size: 24px;'>âŒ Booking Request Declined</h1>
                        </div>
                        <div style='background-color: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                            <p style='font-size: 18px; color: #2c3e50; margin-bottom: 20px;'>Dear <strong>{$request['full_name']}</strong>,</p>

                            <p style='font-size: 16px; color: #34495e; line-height: 1.6;'>
                                Unfortunately, your booking request for <strong>{$house_result['name']}</strong> has been <strong style='color: #e74c3c;'>DECLINED</strong> by the boarding house owner.
                            </p>

                            <div style='background-color: #ecf0f1; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                                <h3 style='color: #2c3e50; margin-top: 0;'>ðŸ“‹ Booking Information</h3>
                                <table style='width: 100%; border-collapse: collapse;'>
                                    <tr>
                                        <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Boarding House:</td>
                                        <td style='padding: 8px 0; color: #2c3e50;'>{$house_result['name']}</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Room Number:</td>
                                        <td style='padding: 8px 0; color: #2c3e50;'>Room {$request['room_number']}</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Bed Number:</td>
                                        <td style='padding: 8px 0; color: #2c3e50;'>Bed {$request['bed_number']}</td>
                                    </tr>
                                    <tr>
                                        <td style='padding: 8px 0; color: #7f8c8d; font-weight: bold;'>Requested Start Date:</td>
                                        <td style='padding: 8px 0; color: #2c3e50;'>{$request['start_date']}</td>
                                    </tr>
                                </table>
                            </div>

                            <div style='background-color: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                                <h4 style='margin: 0 0 10px 0;'>ðŸ’¡ What Now?</h4>
                                <p style='margin: 0; line-height: 1.5;'>
                                    Don't worry! You can browse other boarding houses on StayFinder or contact the owner directly to ask about alternative rooms or dates that might be available.
                                </p>
                            </div>

                            <p style='color: #7f8c8d; font-size: 14px; text-align: center; margin-top: 30px;'>
                                Thank you for using StayFinder!<br>
                                <em>This is an automated notification email.</em>
                            </p>
                        </div>
                    </div>";

                    $mail->AltBody = "Your booking request has been declined.

                    Boarding House: {$house_result['name']}
                    Room Number: {$request['room_number']}
                    Bed Number: {$request['bed_number']}
                    Requested Start Date: {$request['start_date']}

                    Please browse other available boarding houses or contact the owner directly.

                    Thank you for using StayFinder!";

                    $mail->send();
                } catch (Exception $e) {
                    error_log("Decline email sending failed: " . $mail->ErrorInfo);
                }

                $stmt = $conn->prepare("SELECT name FROM boarding_houses WHERE id = ?");
                $stmt->bind_param("i", $request['house_id']);
                $stmt->execute();
                $house_result = $stmt->get_result()->fetch_assoc();

                if ($house_result) {
                    $stmt = $conn->prepare("DELETE FROM bed_occupancy WHERE house_id = ?");
                    $stmt->bind_param("i", $request['house_id']);
                    $stmt->execute();

                    $stmt = $conn->prepare("SELECT * FROM yourbook WHERE boardingHouse = ? AND (status IS NULL OR status = 'active')");
                    $stmt->bind_param("s", $house_result['name']);
                    $stmt->execute();
                    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                    foreach ($bookings as $booking) {
                        if (preg_match('/Room (\d+), Bed (\d+)/', $booking['availableSlots'], $matches)) {
                            $stmt = $conn->prepare("INSERT IGNORE INTO bed_occupancy
                                (house_id, room_number, bed_number, is_occupied, tenant_name, booking_id)
                                VALUES (?, ?, ?, 1, ?, ?)");
                            $stmt->bind_param("iiisi", $request['house_id'], $matches[1], $matches[2], $booking['fullName'], $booking['id']);
                            $stmt->execute();
                        }
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'message' => $decision == 'accepted'
                    ? 'Tenant request accepted! Confirmation email sent to tenant.'
                    : 'Tenant request declined! Decline email sent to tenant.',
                'decision' => $decision
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
if ($action == 'get_tenant_requests') {
    try {
        // Ã¢Å“â€¦ FETCH tenant requests WITH profile images
        $stmt = $conn->prepare("
            SELECT tr.*, ru.profile_img 
            FROM tenant_requests tr
            LEFT JOIN registerusers ru ON tr.email = ru.email
            WHERE tr.house_id = ? AND tr.status = 'pending'
            ORDER BY tr.request_date DESC
        ");
        $stmt->bind_param("i", $house_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $requests = $result->fetch_all(MYSQLI_ASSOC);

        echo json_encode(['success' => true, 'requests' => $requests]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
    if ($action == 'save_map_location') {
        try {
            $lat = $_POST['lat'];
            $lng = $_POST['lng'];
            $stmt = $conn->prepare("UPDATE boarding_houses SET map_lat = ?, map_lng = ? WHERE id = ?");
            $stmt->bind_param("ddi", $lat, $lng, $house_id);
            $success = $stmt->execute();
            echo json_encode(['success' => $success]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    if ($action == 'save_description') {
        try {
            $description = isset($_POST['description']) ? $_POST['description'] : '';
            $stmt = $conn->prepare("UPDATE boarding_houses SET description = ? WHERE id = ?");
            $stmt->bind_param("si", $description, $house_id);
            $success = $stmt->execute();
            echo json_encode(['success' => (bool)$success]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    if ($action == 'get_rooms') {
        try {
            $stmt = $conn->prepare("
    SELECT
        hr.room_number,
        hr.room_id,
        hr.beds_count,
        hr.room_type,
        COUNT(DISTINCT CASE WHEN tr.status = 'accepted' THEN tr.bed_number ELSE NULL END) AS occupied_beds,
        rp.price
    FROM house_rooms hr
    LEFT JOIN tenant_requests tr ON hr.house_id = tr.house_id 
        AND hr.room_number = tr.room_number 
        AND tr.status = 'accepted'
    LEFT JOIN room_prices rp ON hr.house_id = rp.house_id AND hr.room_number = rp.room_number
    WHERE hr.house_id = ?
    GROUP BY hr.room_number, hr.room_id, hr.beds_count, hr.room_type, rp.price
    ORDER BY hr.room_number
");

            $stmt->bind_param("i", $house_id);
            $stmt->execute();
            $rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            echo json_encode($rooms);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit();
    }

    if ($action == 'add_room') {
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("SELECT MAX(room_number) as max_room FROM house_rooms WHERE house_id = ?");
            $stmt->bind_param("i", $house_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $next_room = ($result['max_room'] ?? 0) + 1;

            $room_id = generateRoomID($conn, $house_id);
            $stmt = $conn->prepare("INSERT INTO house_rooms (house_id, room_number, room_id, beds_count, room_type) VALUES (?, ?, ?, 0, 'regular')");
            $stmt->bind_param("iis", $house_id, $next_room, $room_id);
            $stmt->execute();

            $conn->commit();
            echo json_encode(['success' => true, 'message' => "Room $next_room added successfully with ID: $room_id!"]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
    if ($action == 'check_room_occupancy') {
    try {
        $room = (int)$_POST['room_number'];
        
        if ($room <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid room number']);
            exit();
        }
        
        // Check if room has any occupied beds
        $stmt = $conn->prepare("
            SELECT COUNT(*) as occupied_beds 
            FROM tenant_requests 
            WHERE house_id = ? 
            AND room_number = ? 
            AND status = 'accepted'
        ");
        $stmt->bind_param("ii", $house_id, $room);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        $has_occupants = ($result['occupied_beds'] > 0);
        
        echo json_encode([
            'success' => true,
            'has_occupants' => $has_occupants,
            'occupied_beds' => $result['occupied_beds']
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

    if ($action == 'remove_room') {
        try {
            $room = (int)$_POST['room_number'];

            if ($room <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid room number']);
                exit();
            }
            $conn->begin_transaction();

            $stmt = $conn->prepare("DELETE FROM yourbook WHERE boardingHouse = ? AND availableSlots LIKE ?");
            $like_pattern = "%Room $room,%";
            $stmt->bind_param("ss", $house['name'], $like_pattern);
            $stmt->execute();
            $stmt = $conn->prepare("DELETE FROM bed_occupancy WHERE house_id = ? AND room_number = ?");
            $stmt->bind_param("ii", $house_id, $room);
            $stmt->execute();
            $stmt = $conn->prepare("SHOW TABLES LIKE 'room_prices'");
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt = $conn->prepare("DELETE FROM room_prices WHERE house_id = ? AND room_number = ?");
                $stmt->bind_param("ii", $house_id, $room);
                $stmt->execute();
            }
            $stmt = $conn->prepare("SHOW TABLES LIKE 'room_amenities'");
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt = $conn->prepare("DELETE FROM room_amenities WHERE house_id = ? AND room_number = ?");
                $stmt->bind_param("ii", $house_id, $room);
                $stmt->execute();
            }
            $stmt = $conn->prepare("DELETE FROM house_rooms WHERE house_id = ? AND room_number = ?");
            $stmt->bind_param("ii", $house_id, $room);
            $stmt->execute();
            $conn->commit();
            echo json_encode(['success' => true, 'message' => "Room $room removed successfully!"]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
    if ($action == 'get_room_prices') {
        try {
            $stmt = $conn->prepare("SHOW TABLES LIKE 'room_prices'");
            $stmt->execute();
            if ($stmt->get_result()->num_rows == 0) {
                echo json_encode([]);
                exit();
            }

            $stmt = $conn->prepare("SELECT * FROM room_prices WHERE house_id = ?");
            $stmt->bind_param("i", $house_id);
            $stmt->execute();
            echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        } catch (Exception $e) {
            echo json_encode([]);
        }
        exit();
    }

    if ($action == 'set_room_price') {
        try {
            $room = (int)$_POST['room_number'];
            $price = (float)$_POST['price'];

            $stmt = $conn->prepare("SHOW TABLES LIKE 'room_prices'");
            $stmt->execute();
            if ($stmt->get_result()->num_rows == 0) {
                $create_sql = "CREATE TABLE `room_prices` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `house_id` int(11) NOT NULL,
                    `room_number` int(11) NOT NULL,
                    `price` decimal(10,2) NOT NULL DEFAULT 0.00,
                    `created_at` timestamp NULL DEFAULT current_timestamp(),
                    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_room_price` (`house_id`, `room_number`),
                    KEY `house_id` (`house_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                $conn->query($create_sql);
            }

            $stmt = $conn->prepare("INSERT INTO room_prices (house_id, room_number, price) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = CURRENT_TIMESTAMP");
            $stmt->bind_param("iid", $house_id, $room, $price);
            $stmt->execute();

            echo json_encode([
                'success' => true,
                'message' => "✅ Room $room price ₱" . number_format($price, 2) . "/month saved to database!"
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    if ($action == 'remove_room_price') {
        try {
            $room = (int)$_POST['room_number'];

            $stmt = $conn->prepare("SHOW TABLES LIKE 'room_prices'");
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt = $conn->prepare("DELETE FROM room_prices WHERE house_id = ? AND room_number = ?");
                $stmt->bind_param("ii", $house_id, $room);
                $stmt->execute();
            }

            echo json_encode(['success' => true, 'message' => "âœ… Room price removed from database!"]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    if ($action == 'get_room_amenities') {
        try {
            $stmt = $conn->prepare("SHOW TABLES LIKE 'room_amenities'");
            $stmt->execute();
            if ($stmt->get_result()->num_rows == 0) {
                echo json_encode([]);
                exit();
            }

            $stmt = $conn->prepare("SELECT * FROM room_amenities WHERE house_id = ?");
            $stmt->bind_param("i", $house_id);
            $stmt->execute();
            echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        } catch (Exception $e) {
            echo json_encode([]);
        }
        exit();
    }

    if ($action == 'set_room_amenities') {
        try {
            $room = (int)$_POST['room_number'];
            $amenities = $_POST['amenities'] ?? '';

            $stmt = $conn->prepare("SHOW TABLES LIKE 'room_amenities'");
            $stmt->execute();
            if ($stmt->get_result()->num_rows == 0) {
                $create_sql = "CREATE TABLE `room_amenities` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `house_id` int(11) NOT NULL,
                    `room_number` int(11) NOT NULL,
                    `amenities` text DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT current_timestamp(),
                    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_room_amenities` (`house_id`, `room_number`),
                    KEY `house_id` (`house_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                $conn->query($create_sql);
            }

            $stmt = $conn->prepare("INSERT INTO room_amenities (house_id, room_number, amenities) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amenities = VALUES(amenities), updated_at = CURRENT_TIMESTAMP");
            $stmt->bind_param("iis", $house_id, $room, $amenities);
            $stmt->execute();

            echo json_encode([
                'success' => true,
                'message' => "âœ… Room $room amenities saved successfully!"
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
}
function getRoomPrice($conn, $house_id, $room_number) {
    $stmt = $conn->prepare("SELECT price FROM room_prices WHERE house_id = ? AND room_number = ?");
    $stmt->bind_param("ii", $house_id, $room_number);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ? $result['price'] : null;
}
$images = array_filter(explode(',', $house['images']));
$imageBasePath = '../';
$firstLetter = strtoupper(substr($house['name'] ?? 'A', 0, 1));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard - <?php echo htmlspecialchars($house['name']); ?></title>
  <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
   <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($env['GOOGLE_MAPS_API_KEY'] ?? ''); ?>&libraries=places&callback=initMap" async defer></script>
    <script src="https://aframe.io/releases/1.4.0/aframe.min.js"></script>
<link rel="stylesheet" href="style.css">

<script>
    // Initialize PHP variables for JavaScript
    window.phpVars = {
        houseId: <?php echo intval($house_id); ?>,
        hasMapLocation: <?php echo !empty($house['map_lat']) && !empty($house['map_lng']) ? 'true' : 'false'; ?>,
        mapLat: <?php echo !empty($house['map_lat']) ? floatval($house['map_lat']) : 'null'; ?>,
        mapLng: <?php echo !empty($house['map_lng']) ? floatval($house['map_lng']) : 'null'; ?>,
        panoramaImages: <?php echo json_encode($panorama_images); ?>,
        imageBasePath: '<?php echo htmlspecialchars($imageBasePath); ?>',
        houseImages: <?php echo json_encode($images); ?>
    };
    console.log('PHP Variables initialized:', window.phpVars);
</script>

</head>
<body>

<div class="sidebar" id="sidebar" style="left: -280px; position: fixed; top: 0; width: 280px; height: 100vh; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15); z-index: 1000; overflow-y: auto; border-right: 2px solid #ffd700; transition: left 0.3s ease;">
    <div class="sidebar-header" style="padding: 1.5rem 0; border-bottom: none;">
    </div>
    <div class="sidebar-menu" style="padding: 2rem 0;">
         <a href="#" onclick="openTenantRequestsModal(); showPendingRequests();" class="sidebar-item" style="padding: 1rem 1.5rem; margin: 0.5rem 0; display: flex; align-items: center; border-left: 4px solid transparent; transition: all 0.3s ease;">
            <i class="fas fa-clock" style="color: #ff9800; font-size: 1.5rem; margin-right: 15px; min-width: 30px;"></i>
            <span style="font-size: 1rem; font-weight: 600; color: #1f2937;">Pending Renters</span>
        </a>
        <a href="view_bookings.php?house_id=<?php echo $house_id; ?>" class="sidebar-item" style="padding: 1rem 1.5rem; margin: 0.5rem 0; display: flex; align-items: center; border-left: 4px solid transparent; transition: all 0.3s ease;">
            <i class="fas fa-users" style="color: #2196F3; font-size: 1.5rem; margin-right: 15px; min-width: 30px;"></i>
            <span style="font-size: 1rem; font-weight: 600; color: #1f2937;">List of Renters</span>
        </a>
        <a href="payment-history.php?house_id=<?php echo $house_id; ?>" class="sidebar-item" style="padding: 1rem 1.5rem; margin: 0.5rem 0; display: flex; align-items: center; border-left: 4px solid transparent; transition: all 0.3s ease;">
            <i class="fas fa-hand-holding-usd" style="color: #4CAF50; font-size: 1.5rem; margin-right: 15px; min-width: 30px;"></i>
            <span style="font-size: 1rem; font-weight: 600; color: #1f2937;">Payments Due</span>
        </a>
       
        <a href="list_payment_history.php?house_id=<?php echo $house_id; ?>" class="sidebar-item" style="padding: 1rem 1.5rem; margin: 0.5rem 0; display: flex; align-items: center; border-left: 4px solid transparent; transition: all 0.3s ease;">
            <i class="fas fa-history" style="color: #9C27B0; font-size: 1.5rem; margin-right: 15px; min-width: 30px;"></i>
            <span style="font-size: 1rem; font-weight: 600; color: #1f2937;">Payment History</span>
        </a>
     <div style="margin-top: auto; padding: 2rem 1.5rem; border-top: 2px solid #e5e7eb;">
    <button class="logout-btn" onclick="if(confirm('Go back to My Boarding Houses?')) window.location.href='createboardinghouse.php'" style="font-size: 1rem; padding: 12px 20px; font-weight: 600; display: flex; align-items: center; justify-content: center; width: 100%; margin: 0 auto;">
        <i class="fas fa-arrow-left" style="color: #fff; font-size: 1.2rem; margin-right: 10px;"></i>
        <span>Back to My Houses</span>
    </button>
</div>
    </div>
</div>
<button class="mobile-menu-toggle" id="mobileMenuToggle" style="display: none; position: fixed; top: 40px; left: 20px; width: 50px; height: 50px; background: linear-gradient(135deg, #ffd700, #ffed4e); border: none; border-radius: 10px; z-index: 1001; cursor: pointer; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
    <i class="fas fa-bars" style="font-size: 1.5rem; color: #1f2937; margin: -2px 0 0 -2px;"></i>
</button>
<!-- Mobile Overlay -->
<div class="mobile-overlay" id="mobileOverlay" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999;"></div>

<header class="main-header" id="mainHeader" style="margin-left: 280px; width: calc(100% - 280px); position: fixed; top: 0; right: 0; z-index: 999; padding: 1rem 0; transition: all 0.3s ease; background: linear-gradient(135deg, #ffd700 0%, #ffed4e 100%); box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        <div class="container-fluid">
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 0 1.5rem; gap: 1rem;">
                <div style="flex: 1; min-width: 0;">
                    <h1 style="font-size: 1.8rem; font-weight: 700; margin: 0; color: #000; word-break: break-word; overflow: hidden; text-overflow: ellipsis;"><?php echo strtoupper(htmlspecialchars($house['name'])); ?></h1>
                </div>
                <div style="text-align: right; white-space: nowrap;">
                </div>
            </div>
        </div>
    </header>
    
    <style>
        @media (max-width: 992px) {
            #mainHeader {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 1rem 0 !important;
            }
            
            #mainHeader h1 {
                font-size: 1.5rem !important;
            }
            
            #mainHeader .subtitle {
                font-size: 0.8rem !important;
            }
        }
        
        @media (max-width: 768px) {
            #mainHeader {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0.75rem 0 !important;
            }
            
            #mainHeader h1 {
                font-size: 1.2rem !important;
            }
            
            #mainHeader .subtitle {
                font-size: 0.7rem !important;
                display: none;
            }
            
            #mainHeader .container-fluid {
                padding: 0 1rem !important;
            }
        }
        
        @media (max-width: 576px) {
            #mainHeader {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0.5rem 0 !important;
            }
            
            #mainHeader h1 {
                font-size: 1rem !important;
            }
            
            #mainHeader .container-fluid {
                padding: 0 0.75rem !important;
            }
        }
    </style>

<div class="main-content" id="mainContent" style="margin-left: 280px; margin-top: 150px; transition: all 0.3s ease;">
        <div class="container-fluid">
            <?php
            // Calculate total paid payments and estimate total earnings based on recorded payments
            // We rely on `payment_history` for actual paid records. For amount per payment we attempt
            // to lookup the tenant's accepted `tenant_requests` to get the `room_number` then `room_prices`.
            $house_total_earnings = 0.00;
            $house_total_bookings = 0;

            $earnings_sql = "
                SELECT
                    COUNT(ph.id) AS total_paid,
                    SUM(COALESCE(rp.price, 0)) AS total_earnings
                FROM payment_history ph
                LEFT JOIN tenant_requests tr ON ph.tenant_name = tr.full_name AND tr.house_id = ph.house_id AND tr.status = 'accepted'
                LEFT JOIN room_prices rp ON tr.house_id = rp.house_id AND tr.room_number = rp.room_number
                WHERE ph.house_id = ? AND ph.status = 'paid'
            ";

            if ($stmt_e = $conn->prepare($earnings_sql)) {
                $stmt_e->bind_param("i", $house_id);
                $stmt_e->execute();
                $res_e = $stmt_e->get_result()->fetch_assoc();
                $house_total_earnings = (float)($res_e['total_earnings'] ?? 0);
                $house_total_bookings = (int)($res_e['total_paid'] ?? 0);
                $stmt_e->close();
            }
            ?>

            <div class="row mb-3">
                <div class="col-12">
                    <div class="card mb-3">
                        <div class="card-body d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0">Total Earnings Overview</h5>
                                <p class="mb-0 text-muted" style="font-size:0.9rem;">Total revenue from paid/active bookings for this boarding house</p>
                            </div>
                            <div style="text-align: right;">
                                <button class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#earningsBreakdownModal" style="background:transparent; border: none; padding: 0;">
                                    <div style="font-size:1.5rem; font-weight:700; color:#1f2937;">₱<?php echo number_format((float)$house_total_earnings,2); ?></div>
                                    <div style="font-size:0.9rem; color: rgba(0,0,0,0.6);"><?php echo intval($house_total_bookings); ?> paid tenant<?php echo intval($house_total_bookings) !== 1 ? 's' : ''; ?></div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-5">
                <div class="col-12">
                    <div class="card shadow-lg">
                        <div class="card-header">
                            <h3 class="mb-0">
                                <i class="fas fa-images text-warning me-2"></i>
                                House Images
                            </h3>
                        </div>
                        <div class="card-body p-0">
                            <div id="houseCarousel" class="carousel slide" data-bs-ride="carousel">
                                <div class="carousel-inner">
                                    <?php foreach ($images as $index => $img): ?>
                                        <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                            <img src="<?php echo $imageBasePath . htmlspecialchars($img); ?>"
                                                 class="d-block w-100"
                                                 alt="House Image <?php echo $index + 1; ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if (count($images) > 1): ?>
                                    <button class="carousel-control-prev" type="button" data-bs-target="#houseCarousel" data-bs-slide="prev" style="width: auto; left: 15px; background: none; border: none;">
                                        <span style="background-color: #ffd700; border-radius: 50%; width: 45px; height: 45px; display: inline-flex; align-items: center; justify-content: center; font-size: 24px; color: #1f2937;">
                                            <i class="fas fa-chevron-left"></i>
                                        </span>
                                    </button>
                                    <button class="carousel-control-next" type="button" data-bs-target="#houseCarousel" data-bs-slide="next" style="width: auto; right: 15px; background: none; border: none;">
                                        <span style="background-color: #ffd700; border-radius: 50%; width: 45px; height: 45px; display: inline-flex; align-items: center; justify-content: center; font-size: 24px; color: #1f2937;">
                                            <i class="fas fa-chevron-right"></i>
                                        </span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-5">
                <div class="col-12">
                    <div class="d-flex flex-wrap justify-content-center gap-3">
                        <?php foreach ($images as $index => $img): ?>
                            <img src="<?php echo $imageBasePath . htmlspecialchars($img); ?>"
                                 class="img-thumbnail"
                                 alt="Thumbnail <?php echo $index + 1; ?>"
                                 style="width: 100px; height: 75px; object-fit: cover; cursor: pointer;"
                                 onclick="setMainImage(<?php echo $index; ?>)">
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="row mb-5">
                <div class="col-12">
                    <div class="card bg-warning text-dark shadow-lg">
                        <div class="card-body text-center">
                            <h3 class="card-title">
                                <i class="fas fa-globe me-2"></i> 360° Panorama Views
                            </h3>

                            <?php if (!empty($panorama_images)): ?>
                                <div class="alert alert-light text-dark" role="alert">
                                    <strong><?php echo count($panorama_images); ?> Panoramic Views Available</strong><br>
                                    Experience immersive 360Â° views of this boarding house
                                </div>

                                <div class="d-flex justify-content-center flex-wrap gap-3 mb-4">
                                    <?php foreach ($panorama_images as $index => $panorama_path): ?>
                                        <div class="panorama-preview border border-light rounded overflow-hidden"
                                             style="width: 150px; height: 100px; cursor: pointer;"
                                             onclick="open360Viewer(<?php echo $index; ?>)">
                                            <img src="<?php echo $imageBasePath . htmlspecialchars($panorama_path); ?>"
                                                 alt="360Â° View <?php echo $index + 1; ?>"
                                                 class="w-100 h-100"
                                                 style="object-fit: cover;"
                                                 onerror="this.src='../img/default.jpg'">
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <button class="btn btn-light btn-lg" onclick="open360Viewer(0)">
                                    <i class="fas fa-play-circle me-2"></i> Launch 360Â° Virtual Tour
                                </button>

                            <?php else: ?>
                                <div class="alert alert-light text-dark" role="alert">
                                    <i class="fas fa-info-circle me-2"></i> No 360Â° panorama images uploaded yet<br>
                                    <small>Upload equirectangular panorama images when creating/editing your boarding house to enable virtual tours</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-5">
                <div class="col-12">
                    <div class="card shadow-lg">
                        <div class="card-header">
                            <h3 class="mb-0">
                                <i class="fas fa-home text-warning me-2"></i>
                                Room Management
                            </h3>
                        </div>
                        <div class="card-body">

                            <div class="row" id="roomsGrid">
                            </div>


                            <div class="text-center mt-4">
                                <div class="btn-group gap-3" role="group">
                                    <button id="addRoomBtn" class="btn btn-success btn-lg" onclick="addRoom()">
                                        <i class="fas fa-plus me-2"></i>Add New Room
                                    </button>
                                    <button id="removeRoomBtn" class="btn btn-danger btn-lg" onclick="removeRoom()">
                                        <i class="fas fa-minus me-2"></i>Remove Room
                                    </button>
                                </div>
                            </div>


                            <div id="msgBox" class="alert mt-4" style="display: none;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-5">
                <div class="col-12">
                    <div class="card shadow-lg">
                        <div class="card-header">
                            <h3 class="mb-0">
                                <i class="fas fa-map-marker-alt text-danger me-2"></i>
                                Boarding House Location
                            </h3>
                        </div>
                        <div class="card-body">
                            <div id="ownerMap" style="height: 400px; border-radius: 8px;"></div>
                              <div class="mt-3 text-center">
        <button id="saveLocationBtn" class="btn btn-success me-2" onclick="saveMapLocation()" style="display: none;">
            <i class="fas fa-save me-2"></i>Save Location
        </button>
        <button id="clearLocationBtn" class="btn btn-danger" onclick="clearMapLocation()" style="display: none;">
            <i class="fas fa-trash me-2"></i>Clear Location
        </button>
    </div>
                            <div id="mapMsg" class="alert mt-3" style="display: none;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mb-5">
                <div class="col-12">
                    <div class="card shadow-lg">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <h3 class="mb-0">
                                <i class="fas fa-info-circle text-info me-2"></i>
                                About This Boarding House
                            </h3>
                            <div>
                                <button id="editAboutBtn" class="btn btn-sm" title="Edit About" style="background:#FFFF00;color:#000;border:1px solid #e6b800;">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="aboutView">
                                <p class="card-text lead" id="aboutText">
                                    <?php echo nl2br(htmlspecialchars(preg_replace('/\[OWNER:[^\]]+\]/', '', $house['description'] ?? 'This comfortable boarding house offers a convenient and affordable living space for students and young professionals.'))); ?>
                                </p>
                            </div>

                            <div id="aboutEdit" style="display:none;">
                                <textarea id="aboutTextarea" class="form-control" rows="6"><?php echo htmlspecialchars(preg_replace('/\[OWNER:[^\]]+\]/', '', $house['description'] ?? 'This comfortable boarding house offers a convenient and affordable living space for students and young professionals.')); ?></textarea>
                                <div class="mt-2">
                                    <button id="saveAboutBtn" class="btn btn-sm" style="background:#FFFF00;color:#000;border:1px solid #e6b800;"><i class="fas fa-save me-1"></i> Save</button>
                                    <button id="cancelAboutBtn" class="btn btn-secondary btn-sm">Cancel</button>
                                </div>
                            </div>
                            <script>
                                (function(){
                                    const editBtn = document.getElementById('editAboutBtn');
                                    const aboutView = document.getElementById('aboutView');
                                    const aboutEdit = document.getElementById('aboutEdit');
                                    const aboutTextarea = document.getElementById('aboutTextarea');
                                    const aboutText = document.getElementById('aboutText');
                                    const saveBtn = document.getElementById('saveAboutBtn');
                                    const cancelBtn = document.getElementById('cancelAboutBtn');

                                    function toggleEdit(on){
                                        if(on){
                                            aboutView.style.display = 'none';
                                            aboutEdit.style.display = 'block';
                                            editBtn.disabled = true;
                                        } else {
                                            aboutView.style.display = 'block';
                                            aboutEdit.style.display = 'none';
                                            editBtn.disabled = false;
                                        }
                                    }

                                    editBtn.addEventListener('click', function(){
                                        toggleEdit(true);
                                    });

                                    cancelBtn.addEventListener('click', function(){
                                        aboutTextarea.value = aboutText.innerText.replace(/\n/g, '\n');
                                        toggleEdit(false);
                                    });

                                    saveBtn.addEventListener('click', function(){
                                        const desc = aboutTextarea.value;
                                        const fd = new FormData();
                                        fd.append('action','save_description');
                                        fd.append('description', desc);
                                        fetch(window.location.pathname + window.location.search, {
                                            method: 'POST',
                                            body: fd
                                        }).then(r=>r.json()).then(data=>{
                                            if(data && data.success){
                                                aboutText.innerHTML = desc.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
                                                toggleEdit(false);
                                            } else {
                                                alert('Save failed: ' + (data.error || 'Unknown error'));
                                            }
                                        }).catch(err=>{
                                            alert('Error saving description: ' + err.message);
                                        });
                                    });
                                })();
                            </script>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mb-5">
                <div class="col-12">
                    <div class="card shadow-lg">
                        <div class="card-header">
                            <h3 class="mb-0">
                                <i class="fas fa-address-book text-primary me-2"></i>
                                 Your Contact Information
                            </h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($house['owner_email']) || !empty($house['owner_phone'])): ?>
                                <div class="mt-3">
                                    <?php if (!empty($house['owner_email'])): ?>
                                        <p class="mb-2">
                                            <i class="fas fa-envelope text-primary me-2"></i>
                                            <strong>Email:</strong>
                                            <a href="mailto:<?= htmlspecialchars($house['owner_email']) ?>" class="text-decoration-none text-primary">
                                                <?= htmlspecialchars($house['owner_email']) ?>
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                    <?php if (!empty($house['owner_phone'])): ?>
                                        <p class="mb-0">
                                            <i class="fas fa-phone text-success me-2"></i>
                                            <strong>Phone:</strong>
                                            <a href="tel:<?= htmlspecialchars($house['owner_phone']) ?>" class="text-decoration-none text-success">
                                                <?= htmlspecialchars($house['owner_phone']) ?>
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted fst-italic mb-0">Owner contact details not available.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

                <div class="row mb-5">
                    <div class="col-12">
                        <div class="card shadow-lg">
                            <div class="card-header">
                                <h3 class="mb-0">
                                    <i class="fas fa-file-contract text-secondary me-2"></i>
                                    Business Permit
                                </h3>
                            </div>
                            <div class="card-body">

                                <?php if (!empty($house['business_permit'])): ?>
                                    <a href="../<?php echo htmlspecialchars($house['business_permit']); ?>" target="_blank" class="btn btn-outline-primary">
                                        <i class="fas fa-file-pdf me-2"></i> View Uploaded Permit
                                    </a>
                                <?php else: ?>
                                    <div class="alert alert-warning d-inline-block" role="alert">
                                        <small>No permit file uploaded.</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            

        </div>
    </div>

    <div class="panorama-viewer-modal" id="panoramaViewer">
        <div class="panorama-viewer-content">
            <div class="panorama-loading" id="panoramaLoading">
                <i class="fas fa-spinner fa-spin me-2"></i>
                Loading 360Â° View...
            </div>

            <div class="panorama-controls">
                <button onclick="close360Viewer()">
                    <i class="fas fa-times me-2"></i> Close
                </button>
                <button onclick="resetView()">
                    <i class="fas fa-home me-2"></i> Reset View
                </button>
                <button onclick="zoomIn()">
                    <i class="fas fa-search-plus me-2"></i> Zoom In
                </button>
                <button onclick="zoomOut()">
                    <i class="fas fa-search-minus me-2"></i> Zoom Out
                </button>
                <button id="fullscreenBtn" onclick="toggleFullscreen()">
                    <i class="fas fa-expand me-2"></i> <span id="fullscreenText">Fullscreen</span>
                </button>
            </div>

            <a-scene id="panoramaScene" embedded style="height: 100%; width: 100%;"
                     vr-mode-ui="enabled: false"
                     cursor="rayOrigin: mouse"
                     background="color: #000">
                <a-sky id="panoramaSky"
                       src="/placeholder.svg"
                       rotation="0 0 0"
                       geometry="primitive: sphere; radius: 100; segmentsWidth: 64; segmentsHeight: 32"
                       material="side: back; shader: standard; roughness: 1; metalness: 0"
                       scale="-1 1 1">
                </a-sky>
             <a-camera id="panoramaCamera"
          look-controls="enabled: true; touchEnabled: true; mouseEnabled: true; pointerLockEnabled: false; magicWindowTrackingEnabled: false; reverseMouseDrag: false"
          wasd-controls="enabled: false"
          position="0 1.6 0" 
          fov="80">
    <a-cursor color="white" opacity="0.5" scale="0.5 0.5 0.5"></a-cursor>
</a-camera>
            </a-scene>

            <div class="panorama-navigation" id="panoramaNavigation">
            </div>

            <div class="panorama-instructions" id="panoramaInstructions">
                <p><i class="fas fa-hand-pointer me-2"></i> Drag to look around â€¢ Pinch to zoom</p>
            </div>
        </div>
    </div>

    <div class="modal fade" id="tenantRequestsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <ul class="nav nav-tabs w-100" id="tenantTabs" role="tablist">
                        <li class="nav-item flex-fill" role="presentation">
                            <button class="nav-link active w-100" id="pending-tab" data-bs-toggle="tab"
                                    data-bs-target="#pending" type="button" role="tab">
                                <i class="fas fa-clock me-2"></i>Pending Requests
                            </button>
                        </li>

                    </ul>
                </div>
                <div class="modal-body">
                    <div class="tab-content" id="tenantTabsContent">
                        <div class="tab-pane fade show active" id="pending" role="tabpanel">
                            <div id="tenantRequestsList" style="max-height: 400px; overflow-y: auto;">
                            </div>
                        </div>

                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="roomPriceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-dollar-sign text-success me-2"></i> Set Room Price
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Set monthly price for <strong id="roomLocation">Room X</strong></p>
                    <div class="mb-3">
                        <label for="roomPrice" class="form-label">Monthly Price (₱):</label>
                        <input type="number" class="form-control" id="roomPrice"
                               placeholder="Enter room price (1000, 1500, 2000...)" min="0" step="0.01">
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i> This price applies to all beds in this room
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" onclick="saveRoomPrice()">
                        <i class="fas fa-save me-2"></i> Save Price
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="amenitiesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-bed text-info me-2"></i> Add Room Amenities
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-center">Enter amenities for <strong id="amenitiesRoomTitle">Room X</strong></p>

                    <div class="alert alert-info">
                        <strong><i class="fas fa-lightbulb me-2"></i> Example:</strong>
                        Air Conditioning, WiFi, Private Bathroom, Study Table, Closet, TV, Mini Fridge
                    </div>

                    <div class="mb-3">
                        <label for="amenitiesText" class="form-label">Room Amenities:</label>
                        <textarea id="amenitiesText" class="form-control" rows="5"
                                  placeholder="Enter amenities separated by commas (e.g., Air Conditioning, WiFi, Private Bathroom, Study Table, Closet, TV, Mini Fridge, Good Lighting, Power Outlets, Window View)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" onclick="saveRoomAmenities()">
                        <i class="fas fa-save me-2"></i> Save Amenities
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="tenantModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-times text-danger me-2"></i> Remove Tenant
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Remove <strong id="tenantName"></strong> from <strong id="tenantLocation"></strong>?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="confirmRemoveTenant()">
                        Yes, Remove
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>
    <?php
    // Prepare payments breakdown for modal (paid records only)
    $payments = [];
    $payments_sql = "
        SELECT ph.id, ph.tenant_name, ph.payment_date, ph.next_due_date,
               COALESCE(rp.price, 0) AS amount,
               tr.room_number,
               COALESCE(ru.email, '') AS tenant_email
        FROM payment_history ph
        LEFT JOIN tenant_requests tr ON ph.tenant_name = tr.full_name AND tr.house_id = ph.house_id AND tr.status = 'accepted'
        LEFT JOIN room_prices rp ON tr.house_id = rp.house_id AND tr.room_number = rp.room_number
        LEFT JOIN registerusers ru ON (ru.fullname = ph.tenant_name OR ru.email = ph.tenant_name)
        WHERE ph.house_id = ? AND ph.status = 'paid'
        ORDER BY ph.payment_date DESC
    ";
    if ($stmt_p = $conn->prepare($payments_sql)) {
        $stmt_p->bind_param("i", $house_id);
        $stmt_p->execute();
        $res_p = $stmt_p->get_result();
        while ($row = $res_p->fetch_assoc()) {
            $payments[] = $row;
        }
        $stmt_p->close();
    }
    ?>

    <!-- Earnings Breakdown Modal -->
    <div class="modal fade" id="earningsBreakdownModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-list me-2"></i> Earnings Breakdown (Paid)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($payments)): ?>
                        <div class="alert alert-secondary">No paid payments recorded yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Tenant</th>
                                        <th>Room</th>
                                        <th>Amount (₱)</th>
                                        <th>Payment Date</th>
                                        <th>Next Due</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $p): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($p['tenant_name']); ?></td>
                                            <td><?php echo !empty($p['room_number']) ? 'Room ' . htmlspecialchars($p['room_number']) : '—'; ?></td>
                                            <td><?php echo number_format((float)$p['amount'], 2); ?></td>
                                            <td><?php echo !empty($p['payment_date']) ? date('M d, Y', strtotime($p['payment_date'])) : '—'; ?></td>
                                            <td><?php echo !empty($p['next_due_date']) ? date('M d, Y', strtotime($p['next_due_date'])) : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <script src="owner_dashboard.js"></script>

    <!-- Full-Width Footer (next to sidebar, doesn't cover it) -->
    <footer class="footer" style="background-color:#111;color:#fff;padding:10px 0;text-align:center;margin-left:280px;margin-top:30px;transition:all 0.3s ease;">
        <div class="container-fluid" style="width:100%;padding-left:15px;padding-right:15px;">
            <div class="row">
                <div class="col-12">
                    <p class="mb-0" style="font-size:13px;">
                        StayFinder: Boarding Locator and Management System
                    </p>
                    <p class="mt-1 mb-0" style="font-size:13px;">
                        <a href="../terms.php" class="text-warning text-decoration-none fw-bold">Terms &amp; Conditions</a>
                    </p>
                </div>
            </div>
        </div>
    </footer>
    <style>
        @media (max-width: 992px) {
            .footer {
                margin-left: 0 !important;
            }
        }
    </style>

</body>
</html>
