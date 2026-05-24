<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // CRITICAL: Suppress error output to prevent HTML in JSON responses

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/../vendor/autoload.php';

// ✅ FIXED: Check for owner authentication
if (!isset($_SESSION['owner_logged_in']) || !$_SESSION['owner_logged_in']) {
    echo "<script>
        alert('Please login first to manage your rooms!');
        window.location.href='createboardinghouse.php';
    </script>";
    exit();
}

// Verify owner session exists
if (!isset($_SESSION['owner_id'])) {
    echo "<script>
        alert('Session expired. Please login again.');
        window.location.href='createboardinghouse.php';
    </script>";
    exit();
}

// Connect to database
require_once '../connectiondatabase/main_connection.php';
$house_id = $_GET['house_id'] ?? null;
$room_number = $_GET['room_number'] ?? 1;

// Validate house_id exists
if (!$house_id) {
    echo "<script>
        alert('Invalid house ID!');
        window.location.href='createboardinghouse.php';
    </script>";
    exit();
}

// ✅ IMPORTANT: Verify the owner owns this boarding house
$stmt = $conn->prepare("SELECT * FROM boarding_houses WHERE id = ? AND owner_id = ?");
$stmt->bind_param("ii", $house_id, $_SESSION['owner_id']);
$stmt->execute();
$house = $stmt->get_result()->fetch_assoc();

if (!$house) {
    echo "<script>
        alert('Boarding house not found or you do not have permission to access this house!');
        window.location.href='createboardinghouse.php';
    </script>";
    exit();
}

// Get room information
$stmt = $conn->prepare("SELECT * FROM house_rooms WHERE house_id = ? AND room_number = ?");
$stmt->bind_param("ii", $house_id, $room_number);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();

// Get the actual room_id from database
$stmt = $conn->prepare("SELECT room_id FROM house_rooms WHERE house_id = ? AND room_number = ?");
$stmt->bind_param("ii", $house_id, $room_number);
$stmt->execute();
$room_data = $stmt->get_result()->fetch_assoc();
$room_id_display = $room_data['room_id'] ?? 'N/A';

if (!$room) {
    echo "<script>
        alert('Room not found!');
        window.location.href='owner_dashboard.php?house_id=$house_id';
    </script>";
    exit();
}

// Handle AJAX request to get tenant info
if ($_POST && isset($_POST['action']) && $_POST['action'] == 'get_tenant_info') {
    header('Content-Type: application/json; charset=utf-8');
    ob_clean(); // Clear any buffered output before JSON response
    $bed_number = $_POST['bed_number'];

    $stmt = $conn->prepare("
        SELECT tr.*, r.fullname as tenant_name, r.email as tenant_email, r.phone as tenant_phone
        FROM tenant_requests tr
        LEFT JOIN registerusers r ON tr.email = r.email
        WHERE tr.house_id = ?
        AND tr.room_number = ?
        AND tr.bed_number = ?
        AND tr.status = 'accepted'
        ORDER BY tr.request_date DESC
        LIMIT 1
    ");
    $stmt->bind_param("iii", $house_id, $room_number, $bed_number);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();

    if ($result) {
        echo json_encode([
            'success' => true,
            'tenant' => [
                'name' => $result['full_name'],
                'email' => $result['email'],
                'contact' => $result['phone'],
                'move_in_date' => date('F d, Y', strtotime($result['start_date'])),
                'status' => ucfirst($result['status'])
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No tenant information found']);
    }
    exit();
}

// Handle file upload
if ($_POST && isset($_FILES['bed_image'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_clean(); // Clear buffered output
    
    $bed_number = $_POST['bed_number'];
    $upload_dir = 'uploads/bed_images/';

    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file_extension = pathinfo($_FILES['bed_image']['name'], PATHINFO_EXTENSION);
    $filename = "house_{$house_id}_room_{$room_number}_bed_{$bed_number}." . $file_extension;
    $upload_path = $upload_dir . $filename;

    if (move_uploaded_file($_FILES['bed_image']['tmp_name'], $upload_path)) {
        $stmt = $conn->prepare("INSERT INTO bed_images (house_id, room_number, bed_number, image_path) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE image_path = VALUES(image_path)");
        $stmt->bind_param("iiis", $house_id, $room_number, $bed_number, $upload_path);
        $stmt->execute();

        echo json_encode(['success' => true, 'message' => 'Image uploaded successfully!']);
        exit();
    }
}
// DELETE SPECIFIC BED - FIXED HANDLER
if ($_POST && isset($_POST['delete_specific_bed'])) {
    // 1. Clear any previous HTML output (CRITICAL for JSON)
    while (ob_get_level()) { ob_end_clean(); }
    
    // 2. Set headers to force browser to read as JSON
    header('Content-Type: application/json; charset=utf-8');
    
    // 3. Disable HTML error printing (logs errors instead)
    ini_set('display_errors', 0); 
    error_reporting(E_ALL);

    $response = ['ok' => false, 'msg' => 'Unknown error'];

    try {
        $h_id = intval($_POST['house_id'] ?? 0);
        $r_num = intval($_POST['room_num'] ?? 0);
        $bed_to_delete = intval($_POST['bed_number'] ?? 0);
        
        // Basic Validation
        if ($h_id <= 0 || $r_num <= 0 || $bed_to_delete <= 0) {
            throw new Exception('Invalid parameters provided.');
        }
        
        // Check if room exists
        $check = $conn->prepare("SELECT beds_count FROM house_rooms WHERE house_id = ? AND room_number = ?");
        if (!$check) throw new Exception("Database error: " . $conn->error);
        $check->bind_param("ii", $h_id, $r_num);
        $check->execute();
        $room_info = $check->get_result()->fetch_assoc();
        
        if (!$room_info) throw new Exception('Room not found.');
        if ($bed_to_delete > $room_info['beds_count']) throw new Exception('Bed does not exist.');
        
        // Check if occupied (CRITICAL SAFETY CHECK)
        $occ_check = $conn->prepare("SELECT COUNT(*) as cnt FROM tenant_requests WHERE house_id = ? AND room_number = ? AND bed_number = ? AND status = 'accepted'");
        $occ_check->bind_param("iii", $h_id, $r_num, $bed_to_delete);
        $occ_check->execute();
        $occ_result = $occ_check->get_result()->fetch_assoc();
        
        if ($occ_result && $occ_result['cnt'] > 0) {
            throw new Exception('Cannot delete: Bed is currently occupied.');
        }
        
        // START TRANSACTION
        $conn->begin_transaction();
        
        // Ensure tracking table exists
        $conn->query("CREATE TABLE IF NOT EXISTS deleted_bed_numbers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            house_id INT NOT NULL,
            room_number INT NOT NULL,
            bed_number INT NOT NULL,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_deleted_bed (house_id, room_number, bed_number)
        )");
        
        // 1. Record the deletion (So it doesn't reappear)
        $insert_deleted = $conn->prepare("INSERT IGNORE INTO deleted_bed_numbers (house_id, room_number, bed_number) VALUES (?, ?, ?)");
        if ($insert_deleted) {
            $insert_deleted->bind_param("iii", $h_id, $r_num, $bed_to_delete);
            $insert_deleted->execute();
            $insert_deleted->close();
        }

        // 2. Remove Bed Image
        $del_images = $conn->prepare("DELETE FROM bed_images WHERE house_id = ? AND room_number = ? AND bed_number = ?");
        if ($del_images) {
            $del_images->bind_param("iii", $h_id, $r_num, $bed_to_delete);
            $del_images->execute();
            $del_images->close();
        }
        
        // 3. Remove Bed Prices (SAFE CHECK: Only if table exists)
        // This was the cause of your crash. We check if the table exists first.
        $check_prices = $conn->query("SHOW TABLES LIKE 'bed_prices'");
        if ($check_prices && $check_prices->num_rows > 0) {
            $del_prices = $conn->prepare("DELETE FROM bed_prices WHERE house_id = ? AND room_number = ? AND bed_number = ?");
            if ($del_prices) {
                $del_prices->bind_param("iii", $h_id, $r_num, $bed_to_delete);
                $del_prices->execute();
                $del_prices->close();
            }
        }
        
        // 4. Remove Occupancy Data
        $del_occupancy = $conn->prepare("DELETE FROM bed_occupancy WHERE house_id = ? AND room_number = ? AND bed_number = ?");
        if ($del_occupancy) {
            $del_occupancy->bind_param("iii", $h_id, $r_num, $bed_to_delete);
            $del_occupancy->execute();
            $del_occupancy->close();
        }
        
        // 5. Remove Tenant Requests
        $del_requests = $conn->prepare("DELETE FROM tenant_requests WHERE house_id = ? AND room_number = ? AND bed_number = ?");
        if ($del_requests) {
            $del_requests->bind_param("iii", $h_id, $r_num, $bed_to_delete);
            $del_requests->execute();
            $del_requests->close();
        }
        
        $conn->commit();
        $response = ['ok' => true, 'msg' => 'Bed deleted successfully'];
        
    } catch (Throwable $e) { // 'Throwable' catches fatal errors that 'Exception' misses
        if ($conn->errno) $conn->rollback();
        error_log("Delete Bed Error: " . $e->getMessage());
        $response = ['ok' => false, 'msg' => 'Error: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit();
}

// Handle AJAX requests
if ($_POST && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_clean(); // Clear buffered output to prevent HTML in JSON response
    $action = $_POST['action'];

if ($action == 'add_bed') {
    try {
        $bed_count = (int)$_POST['bed_count'];
        
        if ($bed_count <= 0) {
            echo json_encode(['success' => false, 'error' => 'Please enter a valid number of beds']);
            exit();
        }
        
        // NEW LOGIC: Check for single occupancy and beds_count
        // Get current room type
        $stmt_type = $conn->prepare("SELECT occupancy_type FROM room_occupancy_types WHERE house_id = ? AND room_number = ?");
        $stmt_type->bind_param("ii", $house_id, $room_number);
        $stmt_type->execute();
        $type_result = $stmt_type->get_result()->fetch_assoc();
        $current_type = $type_result['occupancy_type'] ?? 'multi_occupancy'; // Default to multi

        // Get current bed count
        $current_beds = (int)($room['beds_count'] ?? 0);

        if ($current_type == 'single_occupancy' && $current_beds + $bed_count > 1) {
            echo json_encode(['success' => false, 'error' => 'Cannot add more beds. Room is set to Single Occupancy (max 1 bed).']);
            exit();
        }
        // END NEW LOGIC
        
        $stmt = $conn->prepare("UPDATE house_rooms SET beds_count = beds_count + ? WHERE house_id = ? AND room_number = ?");
        $stmt->bind_param("iii", $bed_count, $house_id, $room_number);
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => $bed_count . ' bed(s) added successfully!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}


    if ($action == 'set_room_price') {
        try {
            $price = (float)$_POST['price'];

            $stmt = $conn->prepare("SHOW TABLES LIKE 'room_prices'");
            $stmt->execute();
            if ($stmt->get_result()->num_rows == 0) {
                $create_sql = "CREATE TABLE `room_prices` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `house_id` int(11) NOT NULL,
                    `room_number` int(11) NOT NULL,
                    `price` decimal(10,2) NOT NULL DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT current_timestamp(),
                    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_room_price` (`house_id`, `room_number`),
                    KEY `house_id` (`house_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                $conn->query($create_sql);
            }

            $stmt = $conn->prepare("INSERT INTO room_prices (house_id, room_number, price) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = CURRENT_TIMESTAMP");
            $stmt->bind_param("iid", $house_id, $room_number, $price);
            $stmt->execute();

            echo json_encode(['success' => true, 'message' => "Room $room_number price ₱" . number_format($price, 2) . "/month saved!"]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    if ($action == 'set_room_amenities') {
        try {
            $amenities = $_POST['amenities'];

            $stmt = $conn->prepare("SHOW TABLES LIKE 'room_amenities'");
            $stmt->execute();
            if ($stmt->get_result()->num_rows == 0) {
                $create_sql = "CREATE TABLE `room_amenities` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `house_id` int(11) NOT NULL,
                    `room_number` int(11) NOT NULL,
                    `amenities` text,
                    `created_at` timestamp NULL DEFAULT current_timestamp(),
                    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_room_amenities` (`house_id`, `room_number`),
                    KEY `house_id` (`house_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                $conn->query($create_sql);
            }

            $stmt = $conn->prepare("INSERT INTO room_amenities (house_id, room_number, amenities) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amenities = VALUES(amenities), updated_at = CURRENT_TIMESTAMP");
            $stmt->bind_param("iis", $house_id, $room_number, $amenities);
            $stmt->execute();

            echo json_encode(['success' => true, 'message' => "Room $room_number amenities saved!"]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
    // Handle image removal
if ($_POST && isset($_POST['action']) && $_POST['action'] == 'remove_bed_image') {
    header('Content-Type: application/json; charset=utf-8');
    ob_clean(); // Clear any buffered output before JSON response
    $bed_number = $_POST['bed_number'];
    
    $stmt = $conn->prepare("DELETE FROM bed_images WHERE house_id = ? AND room_number = ? AND bed_number = ?");
    $stmt->bind_param("iii", $house_id, $room_number, $bed_number);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Image removed successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error removing image']);
    }
    exit();
}

    if ($action == 'set_pricing_type') {
        try {
            $pricing_type = $_POST['pricing_type'];

            $stmt = $conn->prepare("SHOW TABLES LIKE 'room_pricing_types'");
            $stmt->execute();
            if ($stmt->get_result()->num_rows == 0) {
                $create_sql = "CREATE TABLE `room_pricing_types` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `house_id` int(11) NOT NULL,
                    `room_number` int(11) NOT NULL,
                    `pricing_type` enum('conditional','fixed') NOT NULL,
                    `created_at` timestamp NULL DEFAULT current_timestamp(),
                    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_room_pricing` (`house_id`, `room_number`),
                    KEY `house_id` (`house_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                $conn->query($create_sql);
            }

            $stmt = $conn->prepare("INSERT INTO room_pricing_types (house_id, room_number, pricing_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE pricing_type = VALUES(pricing_type), updated_at = CURRENT_TIMESTAMP");
            $stmt->bind_param("iis", $house_id, $room_number, $pricing_type);
            $stmt->execute();

            echo json_encode(['success' => true, 'message' => "Room $room_number marked with " . strtoupper($pricing_type) . " pricing!"]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
    
    // NEW AJAX HANDLER FOR ROOM OCCUPANCY TYPE
    if ($action == 'set_occupancy_type') {
        try {
            $occupancy_type = $_POST['occupancy_type'];

            $stmt = $conn->prepare("SHOW TABLES LIKE 'room_occupancy_types'");
            $stmt->execute();
            if ($stmt->get_result()->num_rows == 0) {
                $create_sql = "CREATE TABLE `room_occupancy_types` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `house_id` int(11) NOT NULL,
                    `room_number` int(11) NOT NULL,
                    `occupancy_type` enum('single_occupancy','multi_occupancy') NOT NULL,
                    `created_at` timestamp NULL DEFAULT current_timestamp(),
                    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_room_occupancy` (`house_id`, `room_number`),
                    KEY `house_id` (`house_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                $conn->query($create_sql);
            }

            // If changing to single occupancy and beds_count > 1, prevent or warn (optional: just save it for now as per instructions)
            // The constraint is handled in add_bed and front-end JS

            $stmt = $conn->prepare("INSERT INTO room_occupancy_types (house_id, room_number, occupancy_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE occupancy_type = VALUES(occupancy_type), updated_at = CURRENT_TIMESTAMP");
            $stmt->bind_param("iis", $house_id, $room_number, $occupancy_type);
            $stmt->execute();

            echo json_encode(['success' => true, 'message' => "Room $room_number marked with " . strtoupper(str_replace('_', ' ', $occupancy_type)) . " occupancy!"]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }
}

// Get current room data
$stmt = $conn->prepare("SELECT * FROM house_rooms WHERE house_id = ? AND room_number = ?");
$stmt->bind_param("ii", $house_id, $room_number);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();

// Get bed occupancy
$stmt = $conn->prepare("
    SELECT DISTINCT bed_number
    FROM tenant_requests
    WHERE house_id = ?
    AND room_number = ?
    AND status = 'accepted'
");
$stmt->bind_param("ii", $house_id, $room_number);
$stmt->execute();
$bed_occupancy_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$occupied_beds = [];
foreach ($bed_occupancy_result as $occupancy) {
    $occupied_beds[] = $occupancy['bed_number'];
}

// Get deleted beds from deleted_bed_numbers table
$deleted_beds = [];
$check_deleted_table = $conn->query("SHOW TABLES LIKE 'deleted_bed_numbers'");
if ($check_deleted_table && $check_deleted_table->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT DISTINCT bed_number
        FROM deleted_bed_numbers
        WHERE house_id = ?
        AND room_number = ?
    ");
    $stmt->bind_param("ii", $house_id, $room_number);
    $stmt->execute();
    $deleted_bed_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($deleted_bed_result as $deleted) {
        $deleted_beds[] = $deleted['bed_number'];
    }
}

// Get room price
$room_price = null;
$stmt = $conn->prepare("SELECT price FROM room_prices WHERE house_id = ? AND room_number = ?");
$stmt->bind_param("ii", $house_id, $room_number);
$stmt->execute();
$price_result = $stmt->get_result()->fetch_assoc();
if ($price_result) {
    $room_price = $price_result['price'];
}

// Get room amenities
$room_amenities = '';
$stmt = $conn->prepare("SELECT amenities FROM room_amenities WHERE house_id = ? AND room_number = ?");
$stmt->bind_param("ii", $house_id, $room_number);
$stmt->execute();
$amenities_result = $stmt->get_result()->fetch_assoc();
if ($amenities_result) {
    $room_amenities = $amenities_result['amenities'];
}

// Get pricing type
$pricing_type = null;
$stmt = $conn->prepare("SELECT pricing_type FROM room_pricing_types WHERE house_id = ? AND room_number = ?");
$stmt->bind_param("ii", $house_id, $room_number);
$stmt->execute();
$pricing_result = $stmt->get_result()->fetch_assoc();
if ($pricing_result) {
    $pricing_type = $pricing_result['pricing_type'];
}

// NEW: Get occupancy type
$occupancy_type = null;
$stmt = $conn->prepare("SELECT occupancy_type FROM room_occupancy_types WHERE house_id = ? AND room_number = ?");
$stmt->bind_param("ii", $house_id, $room_number);
$stmt->execute();
$occupancy_result = $stmt->get_result()->fetch_assoc();
if ($occupancy_result) {
    $occupancy_type = $occupancy_result['occupancy_type'];
}

// Get bed images
$bed_images = [];
$stmt = $conn->prepare("SELECT bed_number, image_path FROM bed_images WHERE house_id = ? AND room_number = ?");
$stmt->bind_param("ii", $house_id, $room_number);
$stmt->execute();
$images_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($images_result as $img) {
    $bed_images[$img['bed_number']] = $img['image_path'];
}

// JavaScript variable to check if room setup is complete
$hasPrice = ($room_price !== null && $room_price > 0);
$hasBeds = ($room['beds_count'] > 0);
?>







<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room <?php echo $room_number; ?> Management - <?php echo htmlspecialchars($house['name']); ?></title>
<link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="roomstyle.css">
</head>
<body>
    <div class="container">
        <div class="header-section">
            <button class="back-button" onclick="validateBeforeBack()">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1 class="header-title"><?php echo htmlspecialchars($house['name']); ?></h1>
        </div>

        <div class="room-management-header">
            <div class="room-management-title">
                <i class="fas fa-folder" style="color: #f1c40f;"></i>
                Room Management
            </div>
        </div>

        <div id="message" class="message"></div>

        <div class="room-info-section">
            <h2 class="room-title">Room <?php echo $room_number; ?></h2>
            <div class="room-id">Room ID: <?php echo htmlspecialchars($room_id_display); ?></div>

            <div class="section">
                <h3 class="section-title">Set Room Type</h3>
                <div class="pricing-types">
                    <strong>Occupancy Type</strong>
                    <div class="radio-group">
                        <div class="radio-item">
                            <input type="radio" id="multi_occupancy" name="occupancy_type" value="multi_occupancy" <?php echo ($occupancy_type == 'multi_occupancy' || $occupancy_type === null) ? 'checked' : ''; ?>>
                            <label for="multi_occupancy">Multi Occupancy (Shared Room)</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" id="single_occupancy" name="occupancy_type" value="single_occupancy" <?php echo ($occupancy_type == 'single_occupancy') ? 'checked' : ''; ?>>
                            <label for="single_occupancy">Single Occupancy (Exclusive Room)</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3 class="section-title">Set Bed Numbers</h3>

                <div class="occupancy-legend">
                    <div class="legend-item">
                        <div class="legend-color legend-available"></div>
                        <span>Available Bed</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color legend-occupied"></div>
                        <span>Occupied Bed</span>
                    </div>
                </div>

                                <div class="bed-buttons-container" id="bedButtonsContainer">
                                    <?php for ($i = 1; $i <= $room['beds_count']; $i++): ?>
                                        <?php if (in_array($i, $deleted_beds)) continue; ?>
                                        <div 
                                            class="bed-button <?php echo in_array($i, $occupied_beds) ? 'occupied' : ''; ?>"
                                            data-bed="<?php echo $i; ?>"
                                            title="Tap to upload or drag & drop an image here">
                                            Bed <?php echo $i; ?>
                                            <?php if (in_array($i, $occupied_beds)): ?>
                                                <i class="fas fa-user" style="margin-left:5px;"></i>
                                            <?php endif; ?>
                                            <input type="file" accept="image/*" id="imageUpload<?php echo $i; ?>" style="display:none" onchange="uploadBedImage(<?php echo $i; ?>, this)" multiple>
                                        </div>
                                    <?php endfor; ?>
                    
                    <strong style="margin-left: 20px;">Action:</strong>

        <button class="add-bed-btn" onclick="addBed()">
    <i class="fas fa-plus"></i> Add Beds
</button>
                    
<button class="delete-bed-btn" onclick="deleteBed()" <?php echo ($room['beds_count'] <= 0) ? 'disabled' : ''; ?>>
                        <i class="fas fa-minus"></i> Delete Bed
                    </button>
                </div>
            </div>

<div class="section">
    <h3 class="section-title">Bed Images Management</h3>
    
    <div class="modal" id="bedImageModal">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">
                <i class="fas fa-images me-2"></i>Upload Bed Image
            </h5>
            <button type="button" class="btn-close" onclick="closeBedImageModal()">×</button>
        </div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label fw-bold">Select Bed Number:</label>
                <select id="bedNumberSelect" class="form-select">
                    <?php for ($i = 1; $i <= $room['beds_count']; $i++): ?>
                        <option value="<?php echo $i; ?>">Bed <?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
                <div class="mb-3">
                <label class="form-label fw-bold">Choose Image:</label>
                <div class="upload-area" id="modalUploadArea" onclick="document.getElementById('bedImageInput').click()">
                    <i class="fas fa-camera fa-3x mb-2" style="color: #f1c40f;"></i>
                    <p id="modalUploadHint">Click to select image (or drag & drop)</p>
                </div>
                <input type="file" id="bedImageInput" accept="image/*" style="display: none;" onchange="previewBedImage(this)" multiple>

                <div id="imagePreviewContainer">
                    <img id="imagePreview">
                    <div id="imageCount" style="font-size:12px;color:#666;margin-top:6px;"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeBedImageModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="uploadSelectedBedImage()">
                <i class="fas fa-upload me-2"></i>Upload Image
            </button>
        </div>
    </div>
</div>
    
    <button class="add-bed-btn" onclick="openBedImageModal()">
        <i class="fas fa-images"></i> Add/Manage Bed Images
    </button>
    
    <div class="current-bed-images" id="currentBedImages">
        <?php if (!empty($bed_images)): ?>
            <div class="bed-images-grid" style="margin-top: 20px;">
                <?php foreach ($bed_images as $bed_num => $image_path): ?>
                    <div class="bed-image-card">
                        <img src="<?php echo htmlspecialchars($image_path); ?>" alt="Bed <?php echo $bed_num; ?>">
                        <div class="bed-image-label">Bed <?php echo $bed_num; ?></div>
                        <button class="remove-image-btn" onclick="removeBedImage(<?php echo $bed_num; ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted" style="margin-top: 15px;">No bed images uploaded yet.</p>
        <?php endif; ?>
    </div>
</div>

            <div class="section pricing-section">
                <h3 class="section-title">Set Room Pricing</h3>
                <div class="pricing-types">
                    <strong>Pricing Types</strong>
                    <div class="radio-group">
                        <div class="radio-item">
                            <input type="radio" id="conditional" name="pricing_type" value="conditional" <?php echo ($pricing_type == 'conditional') ? 'checked' : ''; ?>>
                           <label for="conditional">Per Head (Price can be divided for shared rooms)</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" id="fixed" name="pricing_type" value="fixed" <?php echo ($pricing_type == 'fixed') ? 'checked' : ''; ?>>
                         <label for="fixed">Per Room (The price is fixed, even for shared rooms)</label>
                        </div>
                    </div>
                </div>

                <div>
                    <strong>Room Price</strong>
                    <div class="price-input-container" style="display: flex; align-items: center;">
                        <span style="font-weight: bold; margin-right: 5px;">₱</span>
                        <input type="number" class="price-input" id="roomPrice" placeholder="Input room price" value="<?php echo ($room_price !== null && $room_price !== '') ? htmlspecialchars($room_price) : ''; ?>" min="0" step="0.01">
                    </div>
                </div>
            </div>

            <div class="section amenities-section">
                <h3 class="section-title">Set Room Amenities</h3>
                <div class="amenities-grid">
                    <div class="amenity-item">
                        <input type="checkbox" id="comfort_room" name="amenities[]" value="Comfort Room">
                        <label for="comfort_room">Comfort Room</label>
                    </div>
                    <div class="amenity-item">
                        <input type="checkbox" id="closet" name="amenities[]" value="Closet">
                        <label for="closet">Closet</label>
                    </div>
                    <div class="amenity-item">
                        <input type="checkbox" id="bed_frame" name="amenities[]" value="Bed Frame">
                        <label for="bed_frame">Bed Frame</label>
                    </div>
                </div>
                <button class="add-amenity-btn" onclick="addCustomAmenity()">
                    <i class="fas fa-plus"></i> Add other amenities
                </button>
            </div>
        </div>

        <div class="action-buttons">
            <button class="action-btn apply-btn" onclick="applySettings()">Apply</button>
            <button class="action-btn save-btn" onclick="saveSettings()">Save</button>
        </div>

    <!-- Footer: black background, sits next to the sidebar, includes Terms link -->
    <footer class="footer" style="background-color:#111 !important; background-image: none !important; color:#fff !important; padding:10px 0; text-align:center; width:100vw; margin-left:calc(-50vw + 50%); margin-top:0; transition:all 0.3s ease; position:relative; z-index:900;">
        <div class="container-fluid" style="width:100%;padding-left:15px;padding-right:15px;">
            <div class="row">
                <div class="col-12">
                    <p class="mb-0" style="font-size:13px;">
                        StayFinder: Boarding Locator and Management System
                    </p>
                    <p class="mt-1 mb-0" style="font-size:13px;">
                        <a href="../terms.php" style="color:#FFD700;text-decoration:none;font-weight:700;">Terms &amp; Conditions</a>
                    </p>
                </div>
            </div>
        </div>
    </footer>
    <style>
        /* On small screens footer should be normal flow */
        @media (max-width: 992px) {
            .footer { width: 100%; margin-left: 0 !important; }
        }
        /* Bed button dragover visual */
        .bed-button.dragover {
            outline: 3px dashed #ffd700;
            background: rgba(255, 215, 0, 0.08);
            transition: background 0.15s ease, outline 0.15s ease;
        }
        .bed-button { cursor: pointer; user-select: none; }
    </style>
    </div>




    <script>
        const houseId = <?php echo $house_id; ?>;
        const roomNumber = <?php echo $room_number; ?>;
        const bedsCount = <?php echo (int)$room['beds_count']; ?>;
        const deletedBeds = <?php echo json_encode($deleted_beds); ?> || [];

        console.log('roommanagement script loaded', 'houseId=', houseId, 'roomNumber=', roomNumber);


// Delete bed using simple prompt (same method as owner_dashboard.php)
function deleteBed() {
    // Get all available beds from the page
    const bedButtons = document.querySelectorAll('.bed-button');
    const availableBeds = [];
    
    bedButtons.forEach(btn => {
        const text = btn.textContent.trim();
        const match = text.match(/Bed\s*(\d+)/);
        if (match) {
            availableBeds.push(parseInt(match[1]));
        }
    });
    
    if (availableBeds.length === 0) {
        showAlertNotification('❌ No beds to delete!');
        return;
    }
    
    const availableBedsStr = availableBeds.join(', ');
    const bedNumber = prompt(`Enter bed number to delete:\n\nAvailable beds: ${availableBedsStr}`);
    if (!bedNumber) return;

    const parsedBedNumber = parseInt(bedNumber);
    
    // Validate bed number exists
    if (!availableBeds.includes(parsedBedNumber)) {
        showAlertNotification(`❌ Bed ${parsedBedNumber} does not exist!\n\nAvailable beds: ${availableBedsStr}`);
        return;
    }
    
    if (isNaN(parsedBedNumber) || parsedBedNumber <= 0) {
        showAlertNotification('❌ Please enter a valid bed number!');
        return;
    }

    // Check if bed is occupied
    const occupiedBeds = <?php echo json_encode($occupied_beds); ?>.map(b => parseInt(b));
    const isOccupied = occupiedBeds.includes(parsedBedNumber);

    if (isOccupied) {
        showAlertNotification(`❌ Bed ${parsedBedNumber} is occupied. Cannot delete!`);
        return;
    }

    if (!confirm(`⚠️ Delete Bed ${parsedBedNumber}?\n\nThis action cannot be undone!`)) return;

    try {
        const payload = new URLSearchParams();
        payload.append('delete_specific_bed', '1');
        payload.append('house_id', houseId);
        payload.append('room_num', roomNumber);
        payload.append('bed_number', parsedBedNumber);
        
        console.log('🔴 Sending delete request:', payload.toString());

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        })
        .then(response => {
            console.log('🟡 Response status:', response.status);
            console.log('🟡 Response headers:', response.headers);
            
            // Check if response is OK
            if (!response.ok) {
                console.error('Response not OK:', response.status, response.statusText);
            }
            
            // Get response text first to debug
            return response.text().then(text => {
                console.log('🟡 Raw response body:', text);
                
                // Try to parse as JSON
                try {
                    const json = JSON.parse(text);
                    return json;
                } catch (e) {
                    console.error('❌ JSON parse failed:', e.message);
                    console.error('❌ Response was:', text);
                    throw new Error('Invalid JSON response from server: ' + text.substring(0, 100));
                }
            });
        })
        .then(result => {
            console.log('🟢 Server response:', result);
            if (result && result.ok) {
                showPopupNotification(`✅ ${result.msg}`);
                setTimeout(() => window.location.reload(), 1000);
            } else if (result && result.msg) {
                showAlertNotification(`❌ Error: ${result.msg}`);
            } else {
                showAlertNotification('❌ Unknown error from server');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlertNotification(`❌ Error: ${error.message}`);
        });
    } catch (error) {
        showAlertNotification(`❌ Error: ${error.message}`);
    }
}

        function validateBeforeBack() {
            if (!validateAllFields()) {
                return false;
            }
            window.location.href = 'owner_dashboard.php?house_id=<?php echo $house_id; ?>';
        }

        function showMessage(text, type = 'success') {
            const message = document.getElementById('message');
            message.textContent = text;
            message.className = `message ${type}`;
            message.style.display = 'block';
            setTimeout(() => {
                message.style.display = 'none';
            }, 3000);
        }

        function showPopupNotification(text) {
            const popup = document.createElement('div');
            popup.className = 'popup-notification';
            popup.innerHTML = `<i class="fas fa-check-circle popup-icon"></i><span>${text}</span>`;
            document.body.appendChild(popup);

            setTimeout(() => {
                popup.classList.add('hide');
                setTimeout(() => {
                    popup.remove();
                }, 400);
            }, 2500);
        }

        function showAlertNotification(text) {
            const popup = document.createElement('div');
            popup.className = 'alert-notification';
            popup.innerHTML = `<i class="fas fa-exclamation-circle alert-icon"></i><span>${text}</span>`;
            document.body.appendChild(popup);

            setTimeout(() => {
                popup.style.animation = 'slideUp 0.4s ease-out forwards';
                setTimeout(() => {
                    popup.remove();
                }, 400);
            }, 3000);
        }

        function validateAllFields() {
            // Check beds count
            const bedsCount = <?php echo $room['beds_count']; ?>;
            if (bedsCount <= 0) {
                showAlertNotification('❌ Please add at least one bed!');
                return false;
            }

            // Check room price
            const price = document.getElementById('roomPrice').value.trim();
            if (price === '' || price === '0' || parseFloat(price) <= 0) {
                showAlertNotification('❌ Please set a valid room price!');
                return false;
            }

            // Check occupancy type is selected
            const occupancyType = document.querySelector('input[name="occupancy_type"]:checked');
            if (!occupancyType) {
                showAlertNotification('❌ Please select occupancy type (Single or Multi)!');
                return false;
            }

            // Check pricing type is selected
            const pricingType = document.querySelector('input[name="pricing_type"]:checked');
            if (!pricingType) {
                showAlertNotification('❌ Please select pricing type (Per Head or Per Room)!');
                return false;
            }

            // Check at least one amenity is selected
            const selectedAmenities = document.querySelectorAll('input[name="amenities[]"]:checked');
            if (selectedAmenities.length === 0) {
                showAlertNotification('❌ Please select at least one amenity!');
                return false;
            }

            return true;
        }

        function validatePrice() {
            return validateAllFields();
        }

        function triggerImageUpload(bedNumber) {
            const input = document.getElementById('imageUpload' + bedNumber);
            if (input) input.click();
        }

        async function uploadBedImage(bedNumber, inputOrEvent) {
            // Accept either a file input element, an event (drop), or a File/FileList
            try {
                // Handle drop event with DataTransfer
                if (inputOrEvent instanceof Event && inputOrEvent.dataTransfer) {
                    const files = inputOrEvent.dataTransfer.files;
                    if (files && files.length > 0) {
                        if (files.length === 1) {
                            await uploadBedImageFile(bedNumber, files[0]);
                        } else {
                            await uploadMultipleFiles(bedNumber, files);
                        }
                    } else {
                        showAlertNotification('⚠️ No files to upload');
                    }
                    return;
                }

                // Handle file input element
                if (inputOrEvent instanceof HTMLInputElement) {
                    const files = inputOrEvent.files;
                    if (!files || files.length === 0) {
                        showAlertNotification('⚠️ No files selected');
                        return;
                    }
                    if (files.length === 1) {
                        await uploadBedImageFile(bedNumber, files[0]);
                    } else {
                        await uploadMultipleFiles(bedNumber, files);
                    }
                    return;
                }

                // Single File object
                if (inputOrEvent instanceof File) {
                    await uploadBedImageFile(bedNumber, inputOrEvent);
                    return;
                }

                showAlertNotification('⚠️ Unsupported upload source');
            } catch (err) {
                console.error('uploadBedImage error', err);
                showAlertNotification('❌ Error uploading image');
            }
        }

        // Upload a single file to a specific bed; returns boolean success
        async function uploadBedImageFile(bedNumber, file) {
            const formData = new FormData();
            formData.append('bed_image', file);
            formData.append('bed_number', bedNumber);

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                if (result.success) {
                    return { success: true, message: result.message };
                } else {
                    return { success: false, message: result.message || 'Image upload failed' };
                }
            } catch (error) {
                console.error('Upload error:', error);
                return { success: false, message: error.message };
            }
        }

        // Upload multiple files, assigning them to sequential bed numbers starting at startBed
        async function uploadMultipleFiles(startBed, files) {
            // Build list of candidate bed numbers (skip deletedBeds)
            const candidates = [];
            for (let b = startBed; b <= bedsCount; b++) {
                if (!deletedBeds.includes(b)) candidates.push(b);
            }

            if (files.length > candidates.length) {
                showAlertNotification(`⚠️ Not enough available beds from Bed ${startBed} to assign ${files.length} files.`);
                return;
            }

            const results = [];
            for (let i = 0; i < files.length; i++) {
                const bedNum = candidates[i];
                const res = await uploadBedImageFile(bedNum, files[i]);
                results.push({ bed: bedNum, res });
            }

            // Summarize results
            const successCount = results.filter(r => r.res.success).length;
            const failItems = results.filter(r => !r.res.success);

            if (successCount > 0) {
                showPopupNotification(`✅ ${successCount} image(s) uploaded` + (failItems.length ? `, ${failItems.length} failed` : ''));
            }
            if (failItems.length > 0) {
                console.warn('Some uploads failed', failItems);
                showAlertNotification('❌ Some uploads failed (check console)');
            }

            // Reload once to reflect updates
            setTimeout(() => location.reload(), 900);
        }

function addBed() {
    const occupancyType = document.querySelector('input[name="occupancy_type"]:checked').value;
    const currentBeds = parseInt(<?php echo $room['beds_count']; ?>);
    
    if (occupancyType === 'single_occupancy' && currentBeds >= 1) {
        showAlertNotification('❌ Room is set to Single Occupancy. You can only add one bed.');
        return;
    }
    
    // Create modal for bed selection
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.style.display = 'flex';
    
    let bedOptions = '';
    // If single occupancy and no beds, restrict to 1
    if (occupancyType === 'single_occupancy' && currentBeds === 0) {
        bedOptions = '<option value="1">1 Bed</option>';
    } else {
        // Multi-occupancy: show 1 to 10 options
        for(let i = 1; i <= 10; i++) {
            bedOptions += `<option value="${i}">${i} Bed${i > 1 ? 's' : ''}</option>`;
        }
    }
    
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <i class="fas fa-bed warning-icon"></i>
                <span>Add Beds</span>
            </div>
            <div class="modal-body">
                <p>How many beds do you want to add?</p>
                <select id="bedCountSelect" class="price-input" style="width: 100%; margin-top: 10px;">
                    ${bedOptions}
                </select>
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-cancel" onclick="closeAddBedModal()">Cancel</button>
                <button class="modal-btn modal-btn-confirm" onclick="confirmAddBeds()">Add Beds</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

function closeAddBedModal() {
    const modal = document.querySelector('.modal');
    if (modal) {
        modal.remove();
    }
}

async function confirmAddBeds() {
    const select = document.getElementById('bedCountSelect');
    const count = parseInt(select.value);
    
    if (isNaN(count) || count <= 0) {
        showAlertNotification('❌ Please select a valid number of beds!');
        return;
    }
    
    try {
        const response = await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=add_bed&bed_count=${count}`
        });
        const result = await response.json();

        if (result.success) {
            showPopupNotification(`✅ ${result.message}`);
            closeAddBedModal();
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlertNotification(`❌ ${result.error}`);
        }
    } catch (error) {
        showAlertNotification('❌ Error adding beds');
    }
}
        
        function addCustomAmenity() {
            const amenityName = prompt('Enter amenity name:');
            if (amenityName) {
                const amenitiesGrid = document.querySelector('.amenities-grid');
                const newAmenity = document.createElement('div');
                newAmenity.className = 'amenity-item';
                newAmenity.innerHTML = `
                    <input type="checkbox" id="${amenityName.toLowerCase().replace(/\s+/g, '_')}" name="amenities[]" value="${amenityName}" checked>
                    <label for="${amenityName.toLowerCase().replace(/\s+/g, '_')}">${amenityName}</label>
                `;
                amenitiesGrid.appendChild(newAmenity);
            }
        }

        async function applySettings() {
            if (!validateAllFields()) {
                return;
            }
            await saveSettings();
        }

        async function saveSettings() {
            if (!validateAllFields()) {
                return;
            }

            // Save Occupancy Type
            const occupancyType = document.querySelector('input[name="occupancy_type"]:checked');
            if (occupancyType) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=set_occupancy_type&occupancy_type=${occupancyType.value}`
                    });
                    const result = await response.json();
                    if (!result.success) {
                        showMessage('Error saving occupancy type', 'error');
                        return;
                    }
                } catch (error) {
                    showMessage('Error saving occupancy type', 'error');
                    return;
                }
            }

            // Save Pricing Type
            const pricingType = document.querySelector('input[name="pricing_type"]:checked');
            if (pricingType) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=set_pricing_type&pricing_type=${pricingType.value}`
                    });
                    const result = await response.json();
                    if (!result.success) {
                        showMessage('Error saving pricing type', 'error');
                        return;
                    }
                } catch (error) {
                    showMessage('Error saving pricing type', 'error');
                    return;
                }
            }

            // Save Room Price
            const price = document.getElementById('roomPrice').value;
            if (price && parseFloat(price) > 0) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=set_room_price&price=${price}`
                    });
                    const result = await response.json();
                    if (!result.success) {
                        showMessage('Error saving price', 'error');
                        return;
                    }
                } catch (error) {
                    showMessage('Error saving price', 'error');
                    return;
                }
            }

            // Save Amenities
            const selectedAmenities = [];
            document.querySelectorAll('input[name="amenities[]"]:checked').forEach(checkbox => {
                selectedAmenities.push(checkbox.value);
            });

            if (selectedAmenities.length > 0) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=set_room_amenities&amenities=${encodeURIComponent(selectedAmenities.join(', '))}`
                    });
                    const result = await response.json();
                    if (!result.success) {
                        showMessage('Error saving amenities', 'error');
                        return;
                    }
                } catch (error) {
                    showMessage('Error saving amenities', 'error');
                    return;
                }
            }

            showPopupNotification('✅ All required settings saved successfully!');
            setTimeout(() => location.reload(), 1500);
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Restore occupancy type from PHP value
            const occupancyTypeValue = '<?php echo $occupancy_type ?? 'multi_occupancy'; ?>';
            if (occupancyTypeValue) {
                const occupancyRadio = document.querySelector(`input[name="occupancy_type"][value="${occupancyTypeValue}"]`);
                if (occupancyRadio) {
                    occupancyRadio.checked = true;
                }
            }

            // Restore pricing type from PHP value
            const pricingTypeValue = '<?php echo $pricing_type ?? ''; ?>';
            if (pricingTypeValue) {
                const pricingRadio = document.querySelector(`input[name="pricing_type"][value="${pricingTypeValue}"]`);
                if (pricingRadio) {
                    pricingRadio.checked = true;
                }
            }

            // Restore amenities
            const existingAmenities = '<?php echo $room_amenities; ?>';
            if (existingAmenities) {
                const amenitiesList = existingAmenities.split(', ');
                amenitiesList.forEach(amenity => {
                    const checkbox = document.querySelector(`input[value="${amenity}"]`);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
            }

            // Attach click and drag/drop handlers to bed boxes for immediate upload
            const bedBoxes = document.querySelectorAll('.bed-button');
            bedBoxes.forEach(box => {
                const bedNum = box.dataset.bed ? parseInt(box.dataset.bed) : null;
                if (!bedNum) return;

                // Click/tap: open native file picker
                box.addEventListener('click', (ev) => {
                    // prevent accidental triggers when clicking child buttons (e.g., trash)
                    const target = ev.target;
                    if (target && (target.tagName === 'BUTTON' || target.closest('button'))) return;
                    triggerImageUpload(bedNum);
                });

                // Drag & drop handlers
                box.addEventListener('dragover', (e) => { e.preventDefault(); box.classList.add('dragover'); });
                box.addEventListener('dragenter', (e) => { e.preventDefault(); box.classList.add('dragover'); });
                box.addEventListener('dragleave', (e) => { box.classList.remove('dragover'); });
                box.addEventListener('drop', (e) => {
                    e.preventDefault();
                    box.classList.remove('dragover');
                    const files = e.dataTransfer && e.dataTransfer.files;
                    if (files && files.length > 0) {
                        uploadBedImage(bedNum, e);
                    }
                });
            });

            // Modal upload area drag/drop handlers
            const modalUploadArea = document.getElementById('modalUploadArea');
            const modalBedSelect = document.getElementById('bedNumberSelect');
            if (modalUploadArea) {
                modalUploadArea.addEventListener('dragover', (e) => { e.preventDefault(); modalUploadArea.classList.add('dragover'); });
                modalUploadArea.addEventListener('dragenter', (e) => { e.preventDefault(); modalUploadArea.classList.add('dragover'); });
                modalUploadArea.addEventListener('dragleave', (e) => { modalUploadArea.classList.remove('dragover'); });
                modalUploadArea.addEventListener('drop', (e) => {
                    e.preventDefault();
                    modalUploadArea.classList.remove('dragover');
                    const files = e.dataTransfer && e.dataTransfer.files;
                    if (!files || files.length === 0) return;
                    const bedNum = modalBedSelect ? parseInt(modalBedSelect.value, 10) : 1;
                    // If multiple files, upload sequentially starting from selected bed
                    if (files.length > 1) {
                        uploadMultipleFiles(bedNum, files);
                    } else {
                        uploadBedImage(bedNum, files[0]);
                    }
                });
            }
        });
        // Open bed image modal
function openBedImageModal() {
    const modal = document.getElementById('bedImageModal');
    
    // Reset form first
    document.getElementById('bedImageInput').value = '';
    document.getElementById('imagePreviewContainer').classList.remove('show');
    
    // Show modal using class toggle for better performance
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

// Close bed image modal
function closeBedImageModal() {
    const modal = document.getElementById('bedImageModal');
    modal.classList.remove('show');
    document.body.style.overflow = '';
}

// Preview selected image - OPTIMIZED to prevent glitching
function previewBedImage(input) {
    const previewImg = document.getElementById('imagePreview');
    const previewContainer = document.getElementById('imagePreviewContainer');
    const imageCount = document.getElementById('imageCount');

    if (!input || !input.files || input.files.length === 0) {
        previewImg.src = '';
        previewContainer.classList.remove('show');
        if (imageCount) imageCount.textContent = '';
        return;
    }

    // Show first image preview and count of selected files
    const file = input.files[0];
    const reader = new FileReader();
    reader.onload = function(e) {
        previewImg.src = e.target.result;
        previewContainer.classList.add('show');
        if (imageCount) imageCount.textContent = input.files.length > 1 ? `${input.files.length} files selected` : '';
    };
    reader.readAsDataURL(file);
}

// Upload the selected bed image
async function uploadSelectedBedImage() {
    const bedNumber = parseInt(document.getElementById('bedNumberSelect').value, 10);
    const fileInput = document.getElementById('bedImageInput');

    if (!fileInput.files || fileInput.files.length === 0) {
        showAlertNotification('⚠️ Please select an image first!');
        return;
    }

    // If multiple files selected, use uploadMultipleFiles starting at selected bed
    if (fileInput.files.length > 1) {
        await uploadMultipleFiles(bedNumber, fileInput.files);
        closeBedImageModal();
        return;
    }

    // Single file flow
    const res = await uploadBedImageFile(bedNumber, fileInput.files[0]);
    if (res.success) {
        showPopupNotification('✅ ' + res.message);
        closeBedImageModal();
        setTimeout(() => location.reload(), 900);
    } else {
        showAlertNotification('❌ ' + (res.message || 'Error uploading image'));
    }
}

// Close modal when clicking outside of it
document.addEventListener('click', function(event) {
    const modal = document.getElementById('bedImageModal');
    if (modal && event.target === modal) {
        closeBedImageModal();
    }
});

async function removeBedImage(bedNumber) {
    if (!confirm(`Remove image for Bed ${bedNumber}?`)) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'remove_bed_image');
        formData.append('bed_number', bedNumber);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showPopupNotification('✅ Image removed successfully');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlertNotification('❌ Error removing image');
        }
    } catch (error) {
        showAlertNotification('❌ Error removing image');
        console.error('Remove error:', error);
    }
}
    </script>
</body>
</html>