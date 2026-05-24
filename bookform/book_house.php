<?php
session_start();
// ✅ CONNECT TO MAIN CONNECTION FILE
require_once '../connectiondatabase/main_connection.php';

$house_id = isset($_GET['house_id']) ? (int)$_GET['house_id'] : 0;
if ($house_id <= 0) {
    header("Location: ../dashboard.php");
    exit();
}

$is_guest_mode = isset($_GET['guest']) && $_GET['guest'] == '1';
$guest_param = $is_guest_mode ? '?guest=1' : '';

$stmt = $conn->prepare("SELECT * FROM boarding_houses WHERE id = ?");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$house = $stmt->get_result()->fetch_assoc();

if (!$house) {
    header("Location: ../dashboard.php");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM boarding_houses WHERE id = ?");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$house = $stmt->get_result()->fetch_assoc();

if (!$house) {
    header("Location: ../dashboard.php");
    exit();
}

// Create ratings table if it doesn't exist
$create_ratings_table = "
CREATE TABLE IF NOT EXISTS house_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    house_id INT NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_rating (house_id, user_email),
    FOREIGN KEY (house_id) REFERENCES boarding_houses(id) ON DELETE CASCADE
)";
$conn->query($create_ratings_table);

$create_comments_table = "
CREATE TABLE IF NOT EXISTS house_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    house_id INT NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    user_name VARCHAR(255) NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (house_id) REFERENCES boarding_houses(id) ON DELETE CASCADE
)";
$conn->query($create_comments_table);

// Get current user's existing rating for this house
$user_email = $_SESSION['email'] ?? '';
if (!empty($user_email)) {
    $stmt = $conn->prepare("SELECT rating FROM house_ratings WHERE house_id = ? AND user_email = ?");
    $stmt->bind_param("is", $house_id, $user_email);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_user_rating = $result->fetch_assoc();
    $existing_rating = $current_user_rating ? $current_user_rating['rating'] : 0;
} else {
    $existing_rating = 0;
}

// Get average rating and count for this house
$stmt = $conn->prepare("SELECT AVG(rating) as avg_rating, COUNT(rating) as total_ratings FROM house_ratings WHERE house_id = ?");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$result = $stmt->get_result();
$rating_stats = $result->fetch_assoc();
$avg_rating = round($rating_stats['avg_rating'], 1);
$total_ratings = $rating_stats['total_ratings'];

$stmt = $conn->prepare("SELECT * FROM house_comments WHERE house_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$comments_result = $stmt->get_result();
$comments = $comments_result->fetch_all(MYSQLI_ASSOC);

// Handle AJAX requests for real-time data
if ($_POST && isset($_POST['action']) && $_POST['action'] == 'get_rooms') {
    header('Content-Type: application/json');
    
    // Get rooms
    $stmt = $conn->prepare("SELECT * FROM house_rooms WHERE house_id = ? ORDER BY room_number");
    $stmt->bind_param("i", $house_id);
    $stmt->execute();
    $rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt = $conn->prepare("
        SELECT DISTINCT room_number, bed_number 
        FROM tenant_requests 
        WHERE house_id = ? 
        AND status = 'accepted'
    ");
    $stmt->bind_param("i", $house_id);
    $stmt->execute();
    $beds = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get room prices
    $stmt = $conn->prepare("SELECT room_number, price FROM room_prices WHERE house_id = ?");
    $stmt->bind_param("i", $house_id);
    $stmt->execute();
    $prices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

 
    $stmt = $conn->prepare("SELECT room_number, amenities FROM room_amenities WHERE house_id = ?");
    $stmt->bind_param("i", $house_id);
    $stmt->execute();
    $amenities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt = $conn->prepare("SELECT room_number, pricing_type FROM room_pricing_types WHERE house_id = ?");
    $stmt->bind_param("i", $house_id);
    $stmt->execute();
    $pricing_types = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt = $conn->prepare("SELECT room_number, bed_number, image_path FROM bed_images WHERE house_id = ?");
    $stmt->bind_param("i", $house_id);
    $stmt->execute();
    $bed_images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt = $conn->prepare("SELECT room_number, occupancy_type FROM room_occupancy_types WHERE house_id = ?");
    $stmt->bind_param("i", $house_id);
    $stmt->execute();
    $occupancy_types = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['rooms' => $rooms, 'beds' => $beds, 'prices' => $prices, 'amenities' => $amenities, 'pricing_types' => $pricing_types, 'bed_images' => $bed_images, 'occupancy_types' => $occupancy_types]);
    exit();
}

// Handle AJAX requests for rating save
if ($_POST && isset($_POST['action']) && $_POST['action'] == 'save_rating') {
    header('Content-Type: application/json');
    
    // Ensure we have the user's email from session
    $user_email = $_SESSION['email'] ?? '';
    if (empty($user_email)) {
        echo json_encode(['success' => false, 'message' => 'Please login to rate this boarding house']);
        exit();
    }

    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : -1;

    if ($rating >= 1 && $rating <= 5) {
        // Insert or update rating
        $insertSql = "INSERT INTO house_ratings (house_id, user_email, rating) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating)";
        $stmt = $conn->prepare($insertSql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("isi", $house_id, $user_email, $rating);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
            exit();
        }

        // Get updated statistics
        $stmt = $conn->prepare("SELECT AVG(rating) as avg_rating, COUNT(rating) as total_ratings FROM house_ratings WHERE house_id = ?");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("i", $house_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();

        echo json_encode([
            'success' => true,
            'message' => 'Rating saved successfully!',
            'avg_rating' => $stats['avg_rating'] !== null ? round($stats['avg_rating'], 1) : 0,
            'total_ratings' => $stats['total_ratings'],
            'user_rating' => $rating
        ]);
    } elseif ($rating === 0) {
        // Clear rating
        $delSql = "DELETE FROM house_ratings WHERE house_id = ? AND user_email = ?";
        $stmt = $conn->prepare($delSql);
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("is", $house_id, $user_email);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
            exit();
        }

        // Get updated statistics
        $stmt = $conn->prepare("SELECT AVG(rating) as avg_rating, COUNT(rating) as total_ratings FROM house_ratings WHERE house_id = ?");
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
            exit();
        }
        $stmt->bind_param("i", $house_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();

        echo json_encode([
            'success' => true,
            'message' => 'Rating cleared successfully!',
            'avg_rating' => $stats['avg_rating'] ? round($stats['avg_rating'], 1) : 0,
            'total_ratings' => $stats['total_ratings'],
            'user_rating' => 0
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid rating value']);
    }
    exit();
}

if ($_POST && isset($_POST['action']) && $_POST['action'] == 'submit_comment') {
    header('Content-Type: application/json');
    
    if (empty($_SESSION['email'])) {
        echo json_encode(['success' => false, 'message' => 'Please login to post a comment']);
        exit();
    }
    
    $comment = trim($_POST['comment']);
    
    if (empty($comment)) {
        echo json_encode(['success' => false, 'message' => 'Comment cannot be empty']);
        exit();
    }
    
    if (strlen($comment) > 1000) {
        echo json_encode(['success' => false, 'message' => 'Comment is too long (max 1000 characters)']);
        exit();
    }
    
    try {
        // Get user's full name from registerusers table
        $user_stmt = $conn->prepare("SELECT fullname FROM registerusers WHERE email = ?");
        $user_stmt->bind_param("s", $user_email);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user_data = $user_result->fetch_assoc();
        $user_name = $user_data ? $user_data['fullname'] : 'Anonymous';
        
        $stmt = $conn->prepare("INSERT INTO house_comments (house_id, user_email, user_name, comment) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $house_id, $user_email, $user_name, $comment);
        $stmt->execute();
        
        // Get the newly inserted comment
        $comment_id = mysqli_insert_id($conn);
        $stmt = $conn->prepare("SELECT * FROM house_comments WHERE id = ?");
        $stmt->bind_param("i", $comment_id);
        $stmt->execute();
        $new_comment = $stmt->get_result()->fetch_assoc();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Comment posted successfully!',
            'comment' => $new_comment
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error posting comment: ' . $e->getMessage()]);
    }
    exit();
}

if ($_POST && isset($_POST['action']) && $_POST['action'] == 'get_comments') {
    header('Content-Type: application/json');
    
    $stmt = $conn->prepare("SELECT * FROM house_comments WHERE house_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $house_id);
    $stmt->execute();
    $comments_result = $stmt->get_result();
    $comments = $comments_result->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode(['success' => true, 'comments' => $comments]);
    exit();
}

function isRoomFaculty($roomId) {
    global $conn;
    
    // First check if room_type column exists
    $check_column = $conn->query("SHOW COLUMNS FROM house_rooms LIKE 'room_type'");
    if ($check_column->num_rows == 0) {
        return false;
    }
    
    $stmt = $conn->prepare("SELECT room_type FROM house_rooms WHERE room_number = ?");
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['room_type'] === 'faculty';
    }
    return false;
}

// Get initial room data
$check_room_type_column = $conn->query("SHOW COLUMNS FROM house_rooms LIKE 'room_type'");
$has_room_type = $check_room_type_column->num_rows > 0;

if ($has_room_type) {
    $rooms_query = "SELECT room_number as id, beds_count as beds, room_type FROM house_rooms WHERE house_id = ? ORDER BY room_number";
} else {
    $rooms_query = "SELECT room_number as id, beds_count as beds, 'regular' as room_type FROM house_rooms WHERE house_id = ? ORDER BY room_number";
}

$rooms_stmt = $conn->prepare($rooms_query);
if (!$rooms_stmt) {
    die("Prepare failed: " . $conn->error);
}
$rooms_stmt->bind_param("i", $house_id);
$rooms_stmt->execute();
$rooms_result = $rooms_stmt->get_result();

$rooms = [];
while ($room = $rooms_result->fetch_assoc()) {
    $rooms[] = $room;
}

$stmt = $conn->prepare("
    SELECT DISTINCT room_number, bed_number 
    FROM tenant_requests 
    WHERE house_id = ? 
    AND status = 'accepted'
");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$occupiedBeds = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
// Get initial room prices
$stmt = $conn->prepare("SELECT room_number, price FROM room_prices WHERE house_id = ?");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$roomPrices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
// Get initial room amenities
$stmt = $conn->prepare("SELECT room_number, amenities FROM room_amenities WHERE house_id = ?");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$roomAmenities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("SELECT room_number, pricing_type FROM room_pricing_types WHERE house_id = ?");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$roomPricingTypes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("SELECT room_number, bed_number, image_path FROM bed_images WHERE house_id = ?");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$bedImages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get initial room occupancy types
$stmt = $conn->prepare("SELECT room_number, occupancy_type FROM room_occupancy_types WHERE house_id = ?");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$roomOccupancyTypes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// FIXED IMAGE HANDLING
$images = array_filter(explode(',', $house['images']));
$imageBasePath = '../';

$first_image = 'img/default.jpg';
// Check if we have images and they exist
if (!empty($images[0])) {
    $image_path = trim($images[0]);
    // Try with base path first
    if (file_exists($imageBasePath . $image_path)) {
        $first_image = $imageBasePath . $image_path;
    }
    elseif (file_exists($image_path)) {
        $first_image = $image_path;
    }
    // Try with different base paths
    elseif (file_exists('../' . $image_path)) {
        $first_image = '../' . $image_path;
    } elseif (file_exists('./' . $image_path)) {
        $first_image = './' . $image_path;
    }
}

$is_logged_in = !empty($_SESSION['email']) && !$is_guest_mode;
$has_active_booking = false;
$active_booking_message = '';
// Only check for active bookings if user IS logged in (and not in guest mode)
if ($is_logged_in) {
    $session_email = $_SESSION['email'];
    
    // Check for active or pending bookings in tenant_requests table that are NOT cancelled/terminated in yourbook
    // Allow booking again if the existing yourbook status indicates cancellation/termination
    $check_booking_sql = "SELECT COUNT(*) as booking_count FROM tenant_requests tr
                         LEFT JOIN yourbook yb ON tr.email = yb.email 
                         WHERE tr.email = ? 
                         AND tr.status IN ('pending', 'accepted')
                         AND (yb.status IS NULL OR yb.status NOT IN ('cancelled','cancelled_by_owner','terminated'))";
    $check_booking_stmt = $conn->prepare($check_booking_sql);
    $check_booking_stmt->bind_param("s", $session_email);
    $check_booking_stmt->execute();
    $booking_result = $check_booking_stmt->get_result()->fetch_assoc();
    
    if ($booking_result['booking_count'] > 0) {
        $has_active_booking = true;
        $active_booking_message = "You already have an active booking on Your dashbaord.";
    }
    $check_booking_stmt->close();
}
// Handle Reserve Bed form submission
if ($_POST && isset($_POST['action']) && $_POST['action'] == 'reserve_bed') {

    // ✅ If user is not logged in or in guest mode, redirect to register page
    if (empty($_SESSION['email']) || $is_guest_mode) {
        echo json_encode([
            'success' => false, 
            'message' => 'Please register or login to reserve a bed',
            'redirect' => '../auseregisterlogform/register.php'
        ]);
        exit();
    }

    if ($has_active_booking) {
        echo json_encode([
            'success' => false, 
            'message' => $active_booking_message
        ]);
        exit();
    }

    // ✅ Collect form data
    $fullName = trim($_POST['fullName']);
    $age = (int)$_POST['age'];
    $gender = $_POST['gender'];
    $address = trim($_POST['address']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $startDate = $_POST['startDate'];
    $roomNumber = (int)$_POST['roomNumber'];
    $session_email = $_SESSION['email'];
    $room_stmt = $conn->prepare("SELECT beds_count FROM house_rooms WHERE house_id = ? AND room_number = ?");
    $room_stmt->bind_param("ii", $house_id, $roomNumber);
    $room_stmt->execute();
    $room_result = $room_stmt->get_result()->fetch_assoc();
    
    if (!$room_result) {
        echo json_encode([
            'success' => false, 
            'message' => 'Room not found'
        ]);
        exit();
    }
    
    $total_beds = $room_result['beds_count'];
    $occupied_count = 0;
    $check_occupied = $conn->prepare("SELECT COUNT(*) as count FROM tenant_requests WHERE house_id = ? AND room_number = ? AND status = 'accepted'");
    $check_occupied->bind_param("ii", $house_id, $roomNumber);
    $check_occupied->execute();
    $occupied_result = $check_occupied->get_result()->fetch_assoc();
    $occupied_count = $occupied_result['count'];
    
    if ($occupied_count >= $total_beds) {
        echo json_encode([
            'success' => false, 
            'message' => 'Sorry, all beds in this room are now occupied. Please select another room.'
        ]);
        exit();
    }
    
    $bedNumber = 0;
    
    for ($i = 1; $i <= $total_beds; $i++) {
        $check_stmt = $conn->prepare("SELECT id FROM tenant_requests WHERE house_id = ? AND room_number = ? AND bed_number = ? AND status = 'accepted'");
        $check_stmt->bind_param("iii", $house_id, $roomNumber, $i);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows == 0) {
            $bedNumber = $i;
            break;
        }
    }
    
    if ($bedNumber == 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'Sorry, all beds in this room are occupied. Please select another room.'
        ]);
        exit();
    }
    $conn->begin_transaction();

    try {
        // Get room price for booking
    $price_stmt = $conn->prepare("SELECT price FROM room_prices WHERE house_id = ? AND room_number = ?");
            $price_stmt->bind_param("ii", $house_id, $roomNumber);
            $price_stmt->execute();
            $price_result = $price_stmt->get_result()->fetch_assoc();
            $room_price = $price_result ? '₱' . number_format($price_result['price'], 2) . '/MONTH' : 'Contact Owner for Price';
            $selectedBed = "Room $roomNumber, Bed $bedNumber";
            $stmt = $conn->prepare("INSERT INTO yourbook (fullName, age, gender, address, phone, email, startDate, boardingHouse, price, distance, availableSlots, imagePath) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $distance = isset($house['distance']) ? $house['distance'] . ' meters' : 'Contact Owner for Distance';

            $stmt->bind_param("sissssssssss", $fullName, $age, $gender, $address, $phone, $session_email, $startDate, $house['name'], $room_price, $distance, $selectedBed, $first_image);
            $stmt->execute();
            // Get booking ID
            $booking_id = mysqli_insert_id($conn);

            // Insert tenant request
            $tenant_stmt = $conn->prepare("INSERT INTO tenant_requests (house_id, room_number, bed_number, full_name, age, gender, address, phone, email, start_date, status, request_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $tenant_stmt->bind_param("iiisisssss", $house_id, $roomNumber, $bedNumber, $fullName, $age, $gender, $address, $phone, $session_email, $startDate);
            $tenant_stmt->execute();

            $tenant_request_id = mysqli_insert_id($conn);
            $update_booking = $conn->prepare("UPDATE yourbook SET tenant_request_id = ? WHERE id = ?");
            $update_booking->bind_param("ii", $tenant_request_id, $booking_id);
            $update_booking->execute();

            $stmt = $conn->prepare("INSERT INTO bed_occupancy (house_id, room_number, bed_number, is_occupied, tenant_name, booking_id) VALUES (?, ?, ?, 1, ?, ?)");
            $stmt->bind_param("iiisi", $house_id, $roomNumber, $bedNumber, $fullName, $booking_id);
            $stmt->execute();
        // Commit transaction
        $conn->commit();

        echo json_encode([
            'success' => true, 
            'message' => 'Booking submitted successfully!'
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false, 
            'message' => 'Error submitting booking: ' . $e->getMessage()
        ]);
    }

    exit();
}
$user_email = $_SESSION['email'] ?? '';
if (!empty($user_email)) {
    $user_query = $conn->prepare("SELECT fullname, phone FROM registerusers WHERE email = ?");
    $user_query->bind_param("s", $user_email);
    $user_query->execute();
    $user_result = $user_query->get_result();
    $user_data = $user_result->fetch_assoc();
    $user_name = $user_data ? $user_data['fullname'] : '';
    $user_phone = $user_data ? $user_data['phone'] : '';
} else {
    $user_name = '';
    $user_phone = '';
}

$business_permit_path = '';
if (!empty($house['business_permit'])) {
    $permit_file = trim($house['business_permit']);
    if (file_exists('../' . $permit_file)) {
        $business_permit_path = '../' . $permit_file;
    } elseif (file_exists($permit_file)) {
        $business_permit_path = $permit_file;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book <?php echo htmlspecialchars($house['name']); ?></title>
  <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="book_house.css">
</head>

<body class="bg-light">
    <header class="page-header">
      BOOKING 
    </header>
    
    <div class="fullscreen-image-modal" id="fullscreenModal">

    </div>
    
    <!-- ✅ NEW: Room Images Gallery Modal -->
    <div class="room-gallery-modal" id="roomGalleryModal">
        <button class="gallery-close-btn" onclick="closeRoomGallery()">×</button>
        <div class="gallery-container">
            <button class="gallery-nav gallery-prev" onclick="galleryPrev(event)">
                <i class="fas fa-chevron-left"></i>
            </button>
            <div class="gallery-images-wrapper">
                <div id="galleryImagesContainer"></div>
            </div>
            <button class="gallery-nav gallery-next" onclick="galleryNext(event)">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <div class="gallery-info">
            <p id="galleryRoomLabel"></p>
            <div class="gallery-indicators" id="galleryIndicators"></div>
        </div>
    </div>
    
    <!-- ✅ NEW: Business Permit Modal -->
    <div class="permit-modal" id="permitModal">
        <div class="permit-modal-content">
            <div class="permit-modal-header">
                <h3><i class="fas fa-certificate me-2"></i>Business Permit Verification</h3>
                <button class="permit-close-btn" onclick="closePermitModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="permit-modal-body">
                <?php if (!empty($business_permit_path) && file_exists($business_permit_path)): ?>
                    <div class="permit-image-container">
                        <img src="<?php echo htmlspecialchars($business_permit_path); ?>" alt="Business Permit" id="permitImage">
                    </div>
                    <div class="permit-info">
                        <div class="permit-info-item">
                            <i class="fas fa-building"></i>
                            <strong>Business Name:</strong>&nbsp;<?php echo htmlspecialchars($house['name']); ?>
                        </div>
                        <?php if (!empty($house['business_permit_number'])): ?>
                        <div class="permit-info-item">
                            <i class="fas fa-hashtag"></i>
                            <strong>Permit Number:</strong>&nbsp;<?php echo htmlspecialchars($house['business_permit_number']); ?>
                        </div>
                        <?php endif; ?>
                        <div class="permit-info-item">
                            <i class="fas fa-check-circle"></i>
                            <strong>Status:</strong>&nbsp;<span class="text-success">Verified by StayFinder</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="permit-not-available">
                        <i class="fas fa-file-excel"></i>
                        <h5>Business Permit Not Available</h5>
                        <p class="text-muted">The business permit image is not currently available.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<!-- ADD WARNING ALERT FOR EXISTING BOOKINGS (only for logged-in non-guest users) -->
<?php if ($has_active_booking && !$is_guest_mode): ?>
<div class="alert alert-warning alert-dismissible fade show position-fixed" role="alert" style="top: 20px; right: 20px; z-index: 1050; max-width: 400px;">
    <strong><i class="fas fa-exclamation-triangle me-2"></i>Booking Restriction</strong>
    <p class="mb-0 mt-2"><?php echo htmlspecialchars($active_booking_message); ?></p>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

    <div class="container-fluid pt-5">
        <div class="row">
            <div class="col-lg-4 col-md-5">
                <div class="card shadow-sm h-100">
                              <div class="card-body">
                        <!-- ✅ NEW: Back Button ABOVE Boarding House Name -->
                        <div class="text-start mb-3">
                            <a href="javascript:history.back()" style="width: 50px; height: 50px; background-color: #FFD700 !important; border-radius: 50% !important; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; border: 2px solid #FFD700 !important; cursor: pointer;" aria-label="Go back">
                                <i class="fas fa-arrow-left" style="color: black; font-size: 1.2rem; line-height: 1;" aria-hidden="true"></i>
                            </a>
                        </div>
                        
                        <div class="d-flex align-items-flex-start mb-4 gap-2">
                            <h1 class="h2 fw-bold mb-0 flex-grow-1"><?php echo htmlspecialchars($house['name']); ?></h1>
                        </div>
                        
                        <!-- ✅ Business Permit Icon - Below Title -->
                        <?php if (!empty($business_permit_path)): ?>
                        <div class="mb-3">
                            <span class="business-permit-icon" onclick="openPermitModal()" title="View Business Permit">
                                <i class="fas fa-info"></i>
                            </span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Fixed location display to handle "0" value -->
                        <p class="text-muted mb-5 fs-6"><?php 
                            $location = isset($house['full_location']) ? trim($house['full_location']) : '';
                            if (empty($location) || $location === '0') {
                                echo 'Location not provided';
                            } else {
                                echo htmlspecialchars($location);
                            }
                        ?></p>
                        
                        <!-- ROOMS GRID - Shows on mobile only, hidden on desktop -->
                        <div class="mb-5 d-lg-none" id="roomsGridMobile">
                            <div class="row g-3" id="roomsGrid">
                                <div class="col-12 text-center">
                                    <div class="spinner-border spinner-border-sm text-warning" role="status" style="width: 2rem; height: 2rem;">
                                        <span class="visually-hidden">Loading rooms...</span>
                                    </div>
                                    <p class="mt-2 text-muted fs-6">Loading rooms...</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Only show rating section to logged-in users -->
                        <?php if ($is_logged_in): ?>
                        <div class="rating-section">
                            <div class="rating-header">
                                <h5>
                                    <i class="fas fa-star rating-icon"></i>
                                    Rate This Boarding House
                                </h5>
                            </div>

                            <div class="current-stats">
                                <div class="stars-display" id="starsDisplay">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span class="star <?php echo $i <= $avg_rating ? 'filled' : 'empty'; ?>">
                                            <?php echo $i <= $avg_rating ? '★' : '☆'; ?>
                                        </span>
                                    <?php endfor; ?>
                                </div>
                                <div class="rating-count" id="ratingCountDisplay">
                                    Based on <span id="totalRatings"><?php echo $total_ratings; ?></span> rating<?php echo $total_ratings !== 1 ? 's' : ''; ?>
                                </div>
                            </div>

                            <div class="user-rating">
                                <h6>Your Rating:</h6>
                                
                                <div class="star-rating" id="starRating">
                                    <span class="star" data-rating="1">★</span>
                                    <span class="star" data-rating="2">★</span>
                                    <span class="star" data-rating="3">★</span>
                                    <span class="star" data-rating="4">★</span>
                                    <span class="star" data-rating="5">★</span>
                                </div>
                                
                                <div class="rating-text" id="ratingText">
                                    <?php if ($existing_rating > 0): ?>
                                        You rated this <?php echo $existing_rating; ?> star<?php echo $existing_rating > 1 ? 's' : ''; ?>
                                    <?php else: ?>
                                        Click stars to rate
                                    <?php endif; ?>
                                </div>

                                <div class="rating-buttons">
                                    <button type="button" class="btn btn-save-rating" id="saveRatingBtn" disabled>
                                        <i class="fas fa-save me-2"></i>Save Rating
                                    </button>
                                    <?php if ($existing_rating > 0): ?>
                                        <button type="button" class="btn btn-clear-rating" id="clearRatingBtn">
                                            <i class="fas fa-trash me-2"></i>Clear
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <div class="rating-status" id="ratingStatus"></div>
                            </div>
                        </div>
                        <?php else: ?>
                        <!-- Show login prompt for guests instead of rating section -->
                        <div class="login-prompt-section">
                            <i class="fas fa-star"></i>
                            <h5>Rate This Boarding House</h5>
                            <p>Sign in to share your rating and help other seekers find the perfect place.</p>
                            <a href="../auseregisterlogform/register.php" class="btn">Login / Register</a>
                        </div>
                        <?php endif; ?>
                        <!-- Only show comments section to logged-in users -->
                        <?php if ($is_logged_in): ?>
                        <!-- Added comments section -->
                        <div class="comments-section">
                            <h5 class="fw-semibold mb-4">
                                <i class="fas fa-comments me-2"></i>
                                Comments & Reviews
                            </h5>
                            
                            <div class="comment-form">
                                <h6 class="mb-3">Leave a Comment</h6>
                                <textarea 
                                    id="commentTextarea" 
                                    class="comment-textarea" 
                                    placeholder="Share your experience about this boarding house..."
                                    maxlength="1000"
                                ></textarea>
                                <div class="char-counter" id="charCounter">0 / 1000 characters</div>
                                <div class="d-flex justify-content-end mt-2">
                                    <button type="button" class="btn-submit-comment" id="submitCommentBtn">
                                        <i class="fas fa-paper-plane me-2"></i>Post Comment
                                    </button>
                                </div>
                                <div class="comment-status" id="commentStatus"></div>
                            </div>
                            
                            <div class="comments-list" id="commentsList">
                                <?php if (count($comments) > 0): ?>
                                    <?php foreach ($comments as $comment): ?>
                                        <div class="comment-item">
                                            <div class="comment-header">
                                                <div class="comment-author">
                                                    <i class="fas fa-user-circle"></i>
                                                    <?php echo htmlspecialchars($comment['user_name']); ?>
                                                </div>
                                                <div class="comment-date">
                                                    <?php 
                                                        $date = new DateTime($comment['created_at']);
                                                        echo $date->format('M d, Y g:i A'); 
                                                    ?>
                                                </div>
                                            </div>
                                            <p class="comment-text"><?php echo htmlspecialchars($comment['comment']); ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-comments">
                                        <i class="fas fa-comment-slash fa-2x mb-2"></i>
                                        <p>No comments yet. Be the first to share your experience!</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <!-- Show login prompt for guests instead of comment section -->
                        <div class="login-prompt-section">
                            <i class="fas fa-comments"></i>
                            <h5>Comments & Reviews</h5>
                            <p>Sign in to leave a comment and share your experience with other seekers.</p>
                            <a href="../auseregisterlogform/register.php" class="btn">Login / Register</a>
                        </div>
                        <?php endif; ?>
                        
                        <div class="border-top pt-5 mt-4">
                            <h5 class="fw-semibold mb-4">Business Contact Information</h5>
                            
                            <?php if (!empty($house['owner_email']) || !empty($house['owner_phone'])): ?>
                                
                                <?php if (!empty($house['owner_email'])): ?>
                                    <div style="margin-bottom: 4px;">
                                        <i class="fas fa-envelope"></i>
                                        <strong>Email:</strong>
                                        <a href="mailto:<?= htmlspecialchars($house['owner_email']) ?>">
                                            <?= htmlspecialchars($house['owner_email']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                
                               <?php if (!empty($house['owner_phone'])): ?>
                                    <div>
                                        <i class="fas fa-phone"></i>
                                        <strong>Phone:</strong>
                                        <a href="tel:<?= htmlspecialchars($house['owner_phone']) ?>">
                                            <?= htmlspecialchars($house['owner_phone']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Desktop Rooms Grid - Shows only on large screens -->
            <div class="col-lg-8 col-md-7 d-none d-lg-block">
                <div class="row g-4" id="roomsGridDesktop">
                    <div class="col-12 text-center">
                        <div class="spinner-border text-warning" role="status" style="width: 4rem; height: 4rem;">
                            <span class="visually-hidden">Loading rooms...</span>
                        </div>
                        <p class="mt-4 text-muted fs-5">Loading rooms...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ✅ UPDATED MODAL: Removed bed status display section completely -->
    <div class="modal fade" id="reserveModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reserve Bed - <span id="modalRoomTitle"></span></h5>
                    <button type="button" class="btn-close" onclick="closeReserveModal()"></button>
                </div>
                <div class="modal-body">
                    <p id="modalRoomPrice" class="text-success fw-semibold mb-3"></p>
                    
                    <!-- ✅ NEW: Display auto-assignment info message -->
                    <div class="alert alert-info mb-4">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Auto Bed Assignment:</strong> When you submit this form, you will be automatically assigned to the first available bed in this room.
                    </div>

                    <form id="reserveForm">
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="fullName" class="form-label">Full Name</label>
                                <input type="text" id="fullName" name="fullName" class="form-control" value="<?php echo htmlspecialchars($user_name); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="age" class="form-label">Age</label>
                                <input type="number" id="age" name="age" class="form-control" min="18" required>
                            </div>
                            <div class="col-md-6">
                                <label for="gender" class="form-label">Gender</label>
                                <select id="gender" name="gender" class="form-select" required>
                                    <option value="">Select Gender</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                           <div class="col-md-6">
    <label for="phone" class="form-label">Phone Number</label>
    <input type="tel" id="phone" name="phone" class="form-control" 
           value="<?php echo htmlspecialchars($user_phone); ?>" 
           pattern="[0-9+]{10,15}" 
           title="Please enter numbers only (10-15 digits, + allowed for country code)" 
           oninput="this.value = this.value.replace(/[^0-9+]/g, '');" 
           required>
</div>
                            <div class="col-12">
                                <label for="address" class="form-label">Address</label>
                                <input type="text" id="address" name="address" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user_email); ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label for="startDate" class="form-label">Start Date</label>
                                <input type="date" id="startDate" name="startDate" class="form-control" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeReserveModal()">Cancel</button>
                    <button type="submit" form="reserveForm" class="btn btn-reserve">Reserve Bed</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Leaflet Map Library -->
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script>
        let rooms = [];
        let occupiedBeds = [];
        let roomPrices = [];
        let roomAmenities = [];
        let roomPricingTypes = [];
        let bedImages = [];
        let roomOccupancyTypes = [];
        let selectedRoomForReservation = null;
        let selectedRating = <?php echo $existing_rating; ?>;
        let isRatingInProgress = false;

        let dbRooms = <?php echo json_encode($rooms); ?>;
        let initialOccupiedBeds = <?php echo json_encode($occupiedBeds); ?>;
        let initialRoomPrices = <?php echo json_encode($roomPrices); ?>;
        let initialRoomAmenities = <?php echo json_encode($roomAmenities); ?>;
        let initialRoomPricingTypes = <?php echo json_encode($roomPricingTypes); ?>;
        let initialBedImages = <?php echo json_encode($bedImages); ?>;
        let initialRoomOccupancyTypes = <?php echo json_encode($roomOccupancyTypes); ?>;

        rooms = dbRooms.map(room => ({
            id: room.id,
            beds: room.beds,
            room_type: room.room_type || 'regular'
        }));

        occupiedBeds = initialOccupiedBeds;
        roomPrices = initialRoomPrices;
        roomAmenities = initialRoomAmenities;
        roomPricingTypes = initialRoomPricingTypes;
        bedImages = initialBedImages;
        roomOccupancyTypes = initialRoomOccupancyTypes;

        function openPermitModal() {
            const modal = document.getElementById('permitModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closePermitModal() {
            const modal = document.getElementById('permitModal');
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Close permit modal when clicking outside
        document.getElementById('permitModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePermitModal();
            }
        });
        // Close permit modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const permitModal = document.getElementById('permitModal');
                if (permitModal.classList.contains('active')) {
                    closePermitModal();
                }
            }
        });
        function openFullscreenImage(imageSrc, bedLabel) {
            const modal = document.getElementById('fullscreenModal');
            const img = document.getElementById('fullscreenImage');
            const label = document.getElementById('fullscreenLabel');
            
            img.src = imageSrc;
            label.textContent = bedLabel;
            modal.classList.add('active');
            
            // Prevent body scroll when modal is open
            document.body.style.overflow = 'hidden';
        }
        
        function closeFullscreenImage() {
            const modal = document.getElementById('fullscreenModal');
            modal.classList.remove('active');
            
            // Restore body scroll
            document.body.style.overflow = 'auto';
        }

        // ✅ NEW: Room Gallery Functions
        let currentGalleryIndex = 0;
        let currentGalleryImages = [];
        
        function openRoomGallery(roomId, roomLabel) {
            // Get all images for this room
            const allImages = getRoomBedImages(roomId);
            if (allImages.length === 0) return;
            
            // Process image paths
            currentGalleryImages = allImages.map(image => {
                let imagePath = image.image_path;
                if (imagePath.startsWith('uploads/')) {
                    imagePath = '../baordinghouseOWNER/' + imagePath;
                } else if (imagePath.includes('baordinghouseOWNER/uploads/')) {
                    imagePath = '../' + imagePath.split('baordinghouseOWNER/')[1];
                    imagePath = '../baordinghouseOWNER/' + imagePath;
                } else if (!imagePath.startsWith('../')) {
                    const filename = imagePath.split('/').pop();
                    imagePath = '../baordinghouseOWNER/uploads/bed_images/' + filename;
                }
                return { path: imagePath, bed_number: image.bed_number };
            });
            
            currentGalleryIndex = 0;
            
            // Build gallery HTML
            const container = document.getElementById('galleryImagesContainer');
            container.innerHTML = currentGalleryImages.map((img, idx) => 
                `<div class="gallery-image-item ${idx === 0 ? 'active' : ''}">
                    <img src="${img.path}" alt="Bed ${img.bed_number}">
                </div>`
            ).join('');
            
            // Build indicators
            const indicators = document.getElementById('galleryIndicators');
            indicators.innerHTML = currentGalleryImages.map((img, idx) => 
                `<div class="gallery-indicator ${idx === 0 ? 'active' : ''}" onclick="galleryGoToIndex(${idx})"></div>`
            ).join('');
            
            // Set room label
            document.getElementById('galleryRoomLabel').textContent = `Room ${roomLabel} - Bed ${currentGalleryImages[0].bed_number}`;
            
            // Show modal
            const modal = document.getElementById('roomGalleryModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        function closeRoomGallery() {
            const modal = document.getElementById('roomGalleryModal');
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
            currentGalleryImages = [];
        }
        
        function galleryPrev(event) {
            event.preventDefault();
            event.stopPropagation();
            if (currentGalleryImages.length === 0) return;
            
            currentGalleryIndex = (currentGalleryIndex - 1 + currentGalleryImages.length) % currentGalleryImages.length;
            updateGalleryDisplay();
        }
        
        function galleryNext(event) {
            event.preventDefault();
            event.stopPropagation();
            if (currentGalleryImages.length === 0) return;
            
            currentGalleryIndex = (currentGalleryIndex + 1) % currentGalleryImages.length;
            updateGalleryDisplay();
        }
        
        function galleryGoToIndex(index) {
            if (index >= 0 && index < currentGalleryImages.length) {
                currentGalleryIndex = index;
                updateGalleryDisplay();
            }
        }
        
        function updateGalleryDisplay() {
            // Update active image
            const items = document.querySelectorAll('.gallery-image-item');
            items.forEach((item, idx) => {
                if (idx === currentGalleryIndex) {
                    item.classList.add('active');
                } else {
                    item.classList.remove('active');
                }
            });
            
            // Update active indicator
            const indicators = document.querySelectorAll('.gallery-indicator');
            indicators.forEach((indicator, idx) => {
                if (idx === currentGalleryIndex) {
                    indicator.classList.add('active');
                } else {
                    indicator.classList.remove('active');
                }
            });
            
            // Update label
            document.getElementById('galleryRoomLabel').textContent = `Bed ${currentGalleryImages[currentGalleryIndex].bed_number}`;
        }

        // Close gallery modal when clicking outside
        document.addEventListener('DOMContentLoaded', function() {
            const galleryModal = document.getElementById('roomGalleryModal');
            if (galleryModal) {
                galleryModal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeRoomGallery();
                    }
                });
            }
        });

        // Close modal when clicking outside the image
        document.getElementById('fullscreenModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeFullscreenImage();
            }
        });
        document.addEventListener('DOMContentLoaded', function() {
            const stars = document.querySelectorAll('.star');
            const ratingText = document.getElementById('ratingText');
            const saveBtn = document.getElementById('saveRatingBtn');
            const clearBtn = document.getElementById('clearRatingBtn');
            const ratingStatus = document.getElementById('ratingStatus');
            const ratingTexts = {
                1: '★ Poor',
                2: '★★ Fair',
                3: '★★★ Good',
                4: '★★★★ Very Good',
                5: '★★★★★ Excellent'
            };
            if (selectedRating > 0) {
                updateStarDisplay(selectedRating);
                ratingText.textContent = `You rated this ${selectedRating} star${selectedRating > 1 ? 's' : ''}`;
            }
            stars.forEach((star, index) => {
                star.addEventListener('click', function() {
                    const newRating = parseInt(this.dataset.rating);
                    
                    if (selectedRating === newRating) {
                        selectedRating = 0;
                        ratingText.textContent = 'Click stars to rate';
                        ratingText.style.color = '#333';
                        saveBtn.disabled = true;
                        updateStarDisplay(0);
                    } else {
                        selectedRating = newRating;
                        ratingText.textContent = ratingTexts[selectedRating];
                        ratingText.style.color = '#FFD700';
                        saveBtn.disabled = false;
                        updateStarDisplay(selectedRating);
                    }
                });

                star.addEventListener('mouseenter', function() {
                    const hoverRating = parseInt(this.dataset.rating);
                    stars.forEach((s, i) => {
                        if (i < hoverRating) {
                            s.style.color = '#FFD700';
                            s.style.transform = 'scale(1.2)';
                        } else {
                            if (!s.classList.contains('active')) {
                                s.style.color = '#ddd';
                                s.style.transform = 'scale(1)';
                            }
                        }
                    });
                });
                star.addEventListener('mouseleave', function() {
                    updateStarDisplay(selectedRating);
                });
            });
            if (saveBtn) {
                saveBtn.addEventListener('click', function() {
                    if (selectedRating > 0) {
                        saveRating(selectedRating);
                    }
                });
            }
            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    if (confirm('Are you sure you want to remove your rating?')) {
                        selectedRating = 0;
                        updateStarDisplay(0);
                        ratingText.textContent = 'Click stars to rate';
                        ratingText.style.color = '#333';
                        saveBtn.disabled = true;
                        saveRating(0);
                    }
                });
            }

            const commentTextarea = document.getElementById('commentTextarea');
            const submitCommentBtn = document.getElementById('submitCommentBtn');
            const charCounter = document.getElementById('charCounter');
            // Character counter
            if (commentTextarea) {
                commentTextarea.addEventListener('input', function() {
                    const length = this.value.length;
                    charCounter.textContent = `${length} / 1000 characters`;
                    
                    if (length > 900) {
                        charCounter.classList.add('warning');
                    } else {
                        charCounter.classList.remove('warning');
                    }
                    
                    submitCommentBtn.disabled = length === 0;
                });
                
                // Submit comment
                submitCommentBtn.addEventListener('click', function() {
                    submitComment();
                });
                
                // Allow Enter key to submit (Shift+Enter for new line)
                commentTextarea.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        if (this.value.trim().length > 0) {
                            submitComment();
                        }
                    }
                });
            }

            displayRooms();
        });

        async function submitComment() {
            const commentTextarea = document.getElementById('commentTextarea');
            const submitCommentBtn = document.getElementById('submitCommentBtn');
            const commentStatus = document.getElementById('commentStatus');
            const comment = commentTextarea.value.trim();
            
            if (comment.length === 0) {
                return;
            }
            
            try {
                submitCommentBtn.disabled = true;
                submitCommentBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Posting...';
                
                const formData = new FormData();
                formData.append('action', 'submit_comment');
                formData.append('comment', comment);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Clear textarea
                    commentTextarea.value = '';
                    document.getElementById('charCounter').textContent = '0 / 1000 characters';
                    
                    // Show success message
                    commentStatus.textContent = 'Comment posted successfully!';
                    commentStatus.className = 'comment-status success';
                    commentStatus.style.display = 'block';
                    
                    // Add new comment to the list
                    addCommentToList(result.comment);
                    
                    setTimeout(() => {
                        commentStatus.style.display = 'none';
                    }, 3000);
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                console.error('Error posting comment:', error);
                commentStatus.textContent = 'Error posting comment. Please try again.';
                commentStatus.className = 'comment-status error';
                commentStatus.style.display = 'block';
                
                setTimeout(() => {
                    commentStatus.style.display = 'none';
                }, 3000);
            } finally {
                submitCommentBtn.disabled = false;
                submitCommentBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Post Comment';
            }
        }

        function updateStarDisplay(rating) {
            const stars = document.querySelectorAll('#starRating .star');
            stars.forEach((star, index) => {
                if (index < rating) {
                    star.classList.add('active');
                    star.style.color = '#FFD700';
                    star.style.transform = 'scale(1)';
                } else {
                    star.classList.remove('active');
                    star.style.color = '#ddd';
                    star.style.transform = 'scale(1)';
                }
            });
        }
        
        async function saveRating(rating) {
            const ratingStatus = document.getElementById('ratingStatus');
            const saveBtn = document.getElementById('saveRatingBtn');
            const clearBtn = document.getElementById('clearRatingBtn');
            
            isRatingInProgress = true;
            
            try {
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
                
                const formData = new FormData();
                formData.append('action', 'save_rating');
                formData.append('rating', rating);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Update display with new stats
                    const avgEl = document.getElementById('avgRatingDisplay');
                    if (avgEl) avgEl.textContent = result.avg_rating;
                    document.getElementById('totalRatings').textContent = result.total_ratings;
                    
                    // Update stars display
                    const starsDisplay = document.getElementById('starsDisplay');
                    let starsHTML = '';
                    for (let i = 1; i <= 5; i++) {
                        starsHTML += `<span class="star ${i <= result.avg_rating ? 'filled' : 'empty'}">
                            ${i <= result.avg_rating ? '★' : '☆'}
                        </span>`;
                    }
                    starsDisplay.innerHTML = starsHTML;
                    
                    // Update rating count text
                    const countText = result.total_ratings !== 1 ? 'ratings' : 'rating';
                    document.querySelector('.rating-count').textContent = `Based on ${result.total_ratings} ${countText}`;
                    
                    // Show success message
                    ratingStatus.textContent = '✅ Rating saved successfully!';
                    ratingStatus.className = 'rating-status success';
                    ratingStatus.style.display = 'block';
                    
                    // Update button states
                    if (rating === 0) {
                        if (clearBtn) clearBtn.remove();
                    } else {
                        if (!clearBtn) {
                            const newClearBtn = document.createElement('button');
                            newClearBtn.type = 'button';
                            newClearBtn.className = 'btn btn-clear-rating';
                            newClearBtn.id = 'clearRatingBtn';
                            newClearBtn.innerHTML = '<i class="fas fa-trash me-2"></i>Clear';
                            newClearBtn.addEventListener('click', function() {
                                if (confirm('Are you sure you want to remove your rating?')) {
                                    selectedRating = 0;
                                    updateStarDisplay(0);
                                    document.getElementById('ratingText').textContent = 'Click stars to rate';
                                    document.getElementById('ratingText').style.color = '#333';
                                    saveBtn.disabled = true;
                                    saveRating(0);
                                }
                            });
                            saveBtn.parentElement.appendChild(newClearBtn);
                        }
                    }
                    
                    setTimeout(() => {
                        ratingStatus.style.display = 'none';
                    }, 3000);
                } else {
                    // Show server-provided error message to the user
                    const serverMsg = result.message || 'Error saving rating. Please try again.';
                    ratingStatus.textContent = '❌ ' + serverMsg;
                    ratingStatus.className = 'rating-status error';
                    ratingStatus.style.display = 'block';

                    setTimeout(() => {
                        ratingStatus.style.display = 'none';
                    }, 4000);
                    return;
                }
            } catch (error) {
                console.error('Error saving rating:', error);
                // Prefer server message, otherwise show error message
                const msg = (error && error.message) ? error.message : 'Error saving rating. Please try again.';
                ratingStatus.textContent = '❌ ' + msg;
                ratingStatus.className = 'rating-status error';
                ratingStatus.style.display = 'block';
                
                setTimeout(() => {
                    ratingStatus.style.display = 'none';
                }, 3000);
            } finally {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save me-2"></i>Save Rating';
                isRatingInProgress = false;
            }
        }

        function addCommentToList(comment) {
            const commentsList = document.getElementById('commentsList');
            // Remove "no comments" message if it exists
            const noComments = commentsList.querySelector('.no-comments');
            if (noComments) {
                noComments.remove();
            }
            // Create comment element
            const commentItem = document.createElement('div');
            commentItem.className = 'comment-item';
            
            const date = new Date(comment.created_at);
            const formattedDate = date.toLocaleDateString('en-US', { 
                month: 'short', 
                day: 'numeric', 
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
            
            commentItem.innerHTML = `
                <div class="comment-header">
                    <div class="comment-author">
                        <i class="fas fa-user-circle"></i>
                        ${escapeHtml(comment.user_name)}
                    </div>
                    <div class="comment-date">${formattedDate}</div>
                </div>
                <p class="comment-text">${escapeHtml(comment.comment)}</p>
            `;
            // Add to top of list
            commentsList.insertBefore(commentItem, commentsList.firstChild);
            // Animate in
            commentItem.style.opacity = '0';
            commentItem.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                commentItem.style.transition = 'all 0.3s ease';
                commentItem.style.opacity = '1';
                commentItem.style.transform = 'translateY(0)';
            }, 10);
        }
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        function isBedOccupied(roomId, bedId) {
            const isOccupied = occupiedBeds.some(bed =>
                parseInt(bed.room_number) === parseInt(roomId) && 
                parseInt(bed.bed_number) === parseInt(bedId)
            );
            return isOccupied;
        }
        function getRoomPrice(roomId) {
            const priceData = roomPrices.find(price => 
                parseInt(price.room_number) === parseInt(roomId)
            );
            return priceData ? parseFloat(priceData.price) : null;
        }
        function getRoomAmenities(roomId) {
            const amenityData = roomAmenities.find(amenity => 
                parseInt(amenity.room_number) === parseInt(roomId)
            );
            return amenityData ? amenityData.amenities : null;
        }
        function getRoomPricingType(roomId) {
            const pricingData = roomPricingTypes.find(pricing => 
                parseInt(pricing.room_number) === parseInt(roomId)
            );
            return pricingData ? pricingData.pricing_type : null;
        }
        function getRoomOccupancyType(roomId) {
            const occupancyData = roomOccupancyTypes.find(occupancy => 
                parseInt(occupancy.room_number) === parseInt(roomId)
            );
            return occupancyData ? occupancyData.occupancy_type : null;
        }
        function getRoomBedImages(roomId) {
            return bedImages.filter(image => 
                parseInt(image.room_number) === parseInt(roomId)
            );
        }
        async function updateRooms() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'action=get_rooms'
                });
                if (!response.ok) throw new Error('Network response was not ok');
                const data = await response.json();
                rooms = data.rooms.map(room => ({
                    id: room.room_number,
                    beds: room.beds_count,
                    room_type: room.room_type || 'regular'
                }));
                occupiedBeds = data.beds;
                roomPrices = data.prices;
                roomAmenities = data.amenities;
                roomPricingTypes = data.pricing_types;
                bedImages = data.bed_images;
                roomOccupancyTypes = data.occupancy_types;
                displayRooms();
                return true;
            } catch (error) {
                console.error('Update failed:', error);
                return false;
            }
        }
        
        // ✅ FIXED: Carousel functions for bed images - Verified working with touch support
        function displayRooms() {
            // Get both mobile and desktop containers
            const mobileContainer = document.getElementById('roomsGridMobile');
            const desktopContainer = document.getElementById('roomsGridDesktop');
            
            // Clear both containers
            if (mobileContainer) mobileContainer.innerHTML = '';
            if (desktopContainer) desktopContainer.innerHTML = '';

            if (rooms.length === 0) {
                const noRoomsMsg = '<div class="col-12 text-center"><p class="text-muted fs-5">No rooms available</p></div>';
                if (mobileContainer) mobileContainer.innerHTML = noRoomsMsg;
                if (desktopContainer) desktopContainer.innerHTML = noRoomsMsg;
                return;
            }

            rooms.forEach(room => {
                // ✅ Count only occupied beds for this specific room from tenant_requests
                const occupiedCount = occupiedBeds.filter(bed => 
                    parseInt(bed.room_number) === parseInt(room.id)
                ).length;
                let availableBeds = room.beds - occupiedCount;
                availableBeds = Math.max(0, availableBeds);
                const hasNoBeds = room.beds === 0;
                const isFull = !hasNoBeds && availableBeds <= 0;
                const roomPrice = getRoomPrice(room.id);
                const roomAmenitiesData = getRoomAmenities(room.id);
                const roomPricingType = getRoomPricingType(room.id);
                const roomOccupancyType = getRoomOccupancyType(room.id);
                const isFacultyRoom = room.room_type === 'faculty';
                const roomLabel = `Room ${room.id}`;

                const div = document.createElement('div');
                div.className = 'col-lg-6 col-xl-4';
                let priceDisplay = '';
                if (roomPrice !== null) {
                    priceDisplay = `₱${roomPrice.toLocaleString()} / Month`;
                } else {
                    priceDisplay = 'Contact Owner';
                }
                let amenitiesText = roomAmenitiesData && roomAmenitiesData.trim() ? roomAmenitiesData : 'Contact owner for amenities';
                let pricingTypeDisplay = '';
                if (roomPricingType) {
                   const pricingTypeText = roomPricingType === 'conditional' ? 'Per Head Pricing' : 'Per Room Pricing';
                    // Inline styles to force yellow/black palette (brute-force)
                    pricingTypeDisplay = `<div class="pricing-type-badge ${roomPricingType}" style="background:#FFD700!important;color:#000!important;border:2px solid #FFFFFF!important;padding:6px 10px;border-radius:18px;">${pricingTypeText}</div>`;
                }
                let roomTypeDisplay = '';
                if (roomOccupancyType) {
                    const roomTypeText = roomOccupancyType === 'single_occupancy' ? 'Single Occupancy' : 'Multi Occupancy';
                    // Inline styles to force black background and yellow text
                    roomTypeDisplay = `<div class="room-type-badge ${roomOccupancyType}" style="background:#000!important;color:#FFD700!important;border:2px solid #FFFFFF!important;padding:6px 10px;border-radius:18px;">${roomTypeText}</div>`;
                }
                // ✅ IMPROVED: Better button state handling with proper CSS classes
                let buttonText, buttonDisabled, buttonClass;
                if (hasNoBeds) {
                    buttonText = 'No Beds Available';
                    buttonDisabled = 'disabled';
                    buttonClass = 'btn-disabled';
                } else if (isFull) {
                    buttonText = 'Room Full';
                    buttonDisabled = 'disabled';
                    buttonClass = 'btn-disabled';
                } else {
                    buttonText = 'Reserve Bed';
                    buttonDisabled = '';
                    buttonClass = '';
                }
                
                // ✅ NEW: Get bed images for this room - Display as clickable gallery
                const roomBedImages = getRoomBedImages(room.id);
                let bedImagesHTML = '';
                if (roomBedImages.length > 0) {
                    const firstImagePath = (() => {
                        let imagePath = roomBedImages[0].image_path;
                        if (imagePath.startsWith('uploads/')) {
                            imagePath = '../baordinghouseOWNER/' + imagePath;
                        } else if (imagePath.includes('baordinghouseOWNER/uploads/')) {
                            imagePath = '../' + imagePath.split('baordinghouseOWNER/')[1];
                            imagePath = '../baordinghouseOWNER/' + imagePath;
                        } else if (!imagePath.startsWith('../')) {
                            const filename = imagePath.split('/').pop();
                            imagePath = '../baordinghouseOWNER/uploads/bed_images/' + filename;
                        }
                        return imagePath;
                    })();
                    
                    bedImagesHTML = `
                        <div class="bed-image-carousel-card" onclick="openRoomGallery(${room.id}, '${room.id}')">
                            <div class="carousel-container">
                                <div class="carousel-images-wrapper">
                                    <img src="${firstImagePath}" alt="Room ${room.id}" style="width: 100%; height: 100%; object-fit: cover; cursor: pointer;">
                                </div>
                                <div style="position: absolute; bottom: 15px; left: 50%; transform: translateX(-50%); background: rgba(0, 0, 0, 0.8); color: white; padding: 8px 16px; border-radius: 4px; font-size: 14px; z-index: 10;">
                                    Click to view ${roomBedImages.length} image(s)
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                div.innerHTML = `
                    <div class="card shadow-sm h-100 room-card-container">
                        ${bedImagesHTML}
                        <div class="card-body">
                            <h6 class="card-title fw-bold mb-3">${roomLabel}</h6>
                            <p class="text-muted mb-4 fs-6">Bed Availability: ${availableBeds}/${room.beds}</p>
                            
                            <div class="mb-4">
                                <p class="fw-semibold mb-3 fs-6">Amenities:</p>
                                <div class="amenities-list">
                                    ${amenitiesText.split(',').map(amenity => `<span class="amenity-badge" style="background:#000!important;color:#FFD700!important;border:2px solid #FFFFFF!important;padding:4px 8px;border-radius:6px;">${amenity.trim()}</span>`).join('')}
                                </div>
                                ${pricingTypeDisplay}
                                ${roomTypeDisplay}
                            </div>
                            
                            <div class="price-tag-red mb-4" style="background:#FFD700!important;color:#000!important;border:2px solid #000!important;min-width:140px;padding:8px 14px;border-radius:8px;text-align:center;">${priceDisplay}</div>
                            
                            <div class="d-flex gap-3 mt-auto">
                                <button class="btn btn-reserve btn-sm flex-fill ${buttonClass}" 
                                        ${buttonDisabled} 
                                        onclick="${isFull || hasNoBeds ? 'return false;' : `openReserveModal(${room.id})`}"
                                        style="${isFull || hasNoBeds ? 'opacity: 0.6; cursor: not-allowed;' : ''}">
                                    ${buttonText}
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                
                // Clone the card for both containers
                if (mobileContainer) mobileContainer.appendChild(div.cloneNode(true));
                if (desktopContainer) desktopContainer.appendChild(div.cloneNode(true));
            });
        }
        function openReserveModal(roomId) {
            <?php if (empty($_SESSION['email']) || $is_guest_mode): ?>
                alert('⚠️ Please register or login to reserve a bed');
                window.location.href = '../auseregisterlogform/register.php';
                return;
            <?php endif; ?>
            
            <?php if ($has_active_booking): ?>
                alert('⚠️ ' + '<?php echo htmlspecialchars($active_booking_message); ?>');
                return;
            <?php endif; ?>
            
            selectedRoomForReservation = roomId;
            const room = rooms.find(r => r.id === roomId);
            const roomPrice = getRoomPrice(roomId);
            document.getElementById('modalRoomTitle').textContent = `Room ${roomId}`;
            const priceElement = document.getElementById('modalRoomPrice');
            if (roomPrice !== null) {
                priceElement.textContent = `₱${roomPrice.toLocaleString()} / Month`;
                priceElement.className = 'modal-price fw-semibold mb-3';
                // Inline styles to force yellow/black in modal (brute-force)
                priceElement.setAttribute('style', 'background:#FFD700!important;color:#000!important;border:2px solid #000!important;padding:6px 10px;border-radius:6px;display:inline-block;');
            } else {
                priceElement.textContent = 'Contact Owner for Pricing';
                priceElement.className = 'modal-price-muted fw-semibold mb-3';
                priceElement.setAttribute('style', 'background:transparent!important;color:#6c757d!important;padding:6px 10px;border-radius:6px;display:inline-block;');
            }
            // ✅ REMOVED: Bed images are now in the room card, not in the modal
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('startDate').setAttribute('min', today);
            const modal = new bootstrap.Modal(document.getElementById('reserveModal'));
            modal.show();
        }
        // ✅ DEPRECATED: Bed images section removed - now displayed in room cards instead
        function createBedImagesSection(roomId) {
            const roomBedImages = getRoomBedImages(roomId);
            const modalBody = document.querySelector('#reserveModal .modal-body');
            
            const existingImagesSection = document.getElementById('bedImagesSection');
            if (existingImagesSection) {
                existingImagesSection.remove();
            }
            const imagesSection = document.createElement('div');
            imagesSection.id = 'bedImagesSection';
            imagesSection.className = 'bed-images-section mb-4';
            if (roomBedImages.length > 0) {
                const bedImagesHTML = roomBedImages.map(image => {
                    let imagePath = image.image_path;
                    
                    if (imagePath.startsWith('uploads/')) {
                        imagePath = '../baordinghouseOWNER/' + imagePath;
                    } else if (imagePath.includes('baordinghouseOWNER/uploads/')) {
                        imagePath = '../' + imagePath.split('baordinghouseOWNER/')[1];
                        imagePath = '../baordinghouseOWNER/' + imagePath;
                    } else if (!imagePath.startsWith('../')) {
                        const filename = imagePath.split('/').pop();
                        imagePath = '../baordinghouseOWNER/uploads/bed_images/' + filename;
                    }
                    
                    return `<div class="bed-image-item" onclick="openFullscreenImage('${imagePath}', 'Room ${roomId} - Bed ${image.bed_number}')"><img src="${imagePath}" alt="Bed ${image.bed_number}" onerror="console.error('Failed to load:', this.src); this.parentElement.innerHTML='<div class=\\'text-muted small\\'>Image unavailable</div>';"><div class="bed-image-label">Bed ${image.bed_number}</div></div>`;
                }).join('');

                imagesSection.innerHTML = `
                    <h6 class="fw-semibold mb-3">
                        <i class="fas fa-images me-2"></i>Bed Images (Click to enlarge)
                    </h6>
                    <div class="bed-images-grid">
                        ${bedImagesHTML}
                    </div>
                `;
            } else {
                imagesSection.innerHTML = `
                    <h6 class="fw-semibold mb-3">
                        <i class="fas fa-images me-2"></i>Bed Images
                    </h6>
                    <div class="no-images-message">
                        <i class="fas fa-camera fa-2x mb-2 text-muted"></i>
                        <p>No bed images available for this room</p>
                    </div>
                `;
            }
            // Insert before the alert message
            const alertMsg = modalBody.querySelector('.alert-info');
            if (alertMsg) {
                modalBody.insertBefore(imagesSection, alertMsg);
            }
        }
        function closeReserveModal() {
            const modal = bootstrap.Modal.getInstance(document.getElementById('reserveModal'));
            if (modal) modal.hide();
            selectedRoomForReservation = null;
        }
        // ✅ UPDATED: Submit form without bed selection
        document.getElementById('reserveForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            // ✅ CHECK IF USER IS LOGGED IN (NEW)
            <?php if (empty($_SESSION['email']) || $is_guest_mode): ?>
                alert('⚠️ Please register or login to reserve a bed');
                window.location.href = '../auseregisterlogform/register.php';
                return;
            <?php endif; ?>

            const formData = new FormData(this);
            formData.append('action', 'reserve_bed');
            formData.append('roomNumber', selectedRoomForReservation);

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    alert(`✅ ${result.message}`);
                    closeReserveModal();
                    updateRooms();
                    window.location.href = '../yourbook.php';
                } else {
                    if (result.redirect) {
                        alert('Please register or login to reserve a bed');
                        window.location.href = result.redirect;
                    } else {
                        alert('❌ ' + result.message);
                        updateRooms();
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ An error occurred. Please try again.');
            }
        });
        // Phone number validation
document.getElementById('phone').addEventListener('input', function(e) {
    // Remove any non-numeric characters except +
    this.value = this.value.replace(/[^0-9+]/g, '');
    
    // Optional: Validate length
    if (this.value.replace('+', '').length > 15) {
        this.value = this.value.slice(0, 16);
    }
});

// Form submission validation for phone
document.getElementById('reserveForm').addEventListener('submit', function(e) {
    const phoneInput = document.getElementById('phone');
    const phoneValue = phoneInput.value.replace('+', '');
    
    if (!/^\d{10,15}$/.test(phoneValue)) {
        e.preventDefault();
        alert('Please enter a valid phone number (10-15 digits)');
        phoneInput.focus();
        return false;
    }
});

// Map Initialization
let map = null;
let userMarker = null;
let houseMarker = null;

function initializeMap() {
    // Default center (boarding house location)
    const houseLatitude = 8.3605;
    const houseLongitude = 123.8425;
    
    if (!map) {
        // Create map centered on the boarding house
        map = L.map('mapContainer').setView([houseLatitude, houseLongitude], 15);
        
        // Add tile layer
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(map);
        
        // Add marker for boarding house
        houseMarker = L.marker([houseLatitude, houseLongitude], {
            icon: L.icon({
                iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png',
                shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                iconSize: [25, 41],
                iconAnchor: [12, 41],
                popupAnchor: [1, -34],
                shadowSize: [41, 41]
            })
        }).addTo(map).bindPopup('<strong>Boarding House Location</strong><br><?php echo htmlspecialchars($house["name"]); ?>');
    }
}

// Show My Location Button Handler
document.getElementById('showMyLocationBtn').addEventListener('click', function() {
    const button = this;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Getting your location...';
    
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                const latitude = position.coords.latitude;
                const longitude = position.coords.longitude;
                
                // Initialize map if not already done
                if (!map) {
                    initializeMap();
                }
                
                // Remove existing user marker if present
                if (userMarker) {
                    map.removeLayer(userMarker);
                }
                
                // Add marker for user's current location
                userMarker = L.marker([latitude, longitude], {
                    icon: L.icon({
                        iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-blue.png',
                        shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
                        iconSize: [25, 41],
                        iconAnchor: [12, 41],
                        popupAnchor: [1, -34],
                        shadowSize: [41, 41]
                    })
                }).addTo(map).bindPopup('<strong>Your Location</strong><br>Latitude: ' + latitude.toFixed(4) + '<br>Longitude: ' + longitude.toFixed(4));
                
                // Center map to show both markers
                const group = new L.featureGroup([userMarker, houseMarker]);
                map.fitBounds(group.getBounds().pad(0.1));
                
                // Update button
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-map-marker-alt me-2"></i>Show My Location';
            },
            function(error) {
                // Handle errors
                let errorMessage = 'Unable to get your location. ';
                switch(error.code) {
                    case error.PERMISSION_DENIED:
                        errorMessage += 'Permission denied. Please enable location access in your browser.';
                        break;
                    case error.POSITION_UNAVAILABLE:
                        errorMessage += 'Location information is unavailable.';
                        break;
                    case error.TIMEOUT:
                        errorMessage += 'The request to get your location timed out.';
                        break;
                }
                
                alert(errorMessage);
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-map-marker-alt me-2"></i>Show My Location';
            }
        );
    } else {
        alert('Geolocation is not supported by your browser.');
        button.disabled = false;
        button.innerHTML = '<i class="fas fa-map-marker-alt me-2"></i>Show My Location';
    }
});

// Initialize map on page load
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('mapContainer')) {
        initializeMap();
    }
});
    </script>

<footer class="footer" style="background-color:#111 !important; background-image:none !important; color:#fff !important; padding:8px 0; margin-top:20px; text-align:center; width:100%; margin-left:0; z-index:800;">
    <div class="container-fluid" style="width:100%; padding-left:15px; padding-right:15px;">
        <p style="margin:6px 0;font-size:13px;">StayFinder: Boarding Locator and Management System</p>
        <div class="footer-links" style="margin-top:4px;">
            <a href="../terms.php" target="_blank" rel="noopener" style="color:yellow !important; text-decoration:none !important; font-weight:600;">Terms &amp; Conditions</a>
        </div>
    </div>
</footer>


</body>
</html>