<?php
session_start();

if (!isset($_SESSION['house_owner_logged_in']) || !$_SESSION['house_owner_logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../connectiondatabase/main_connection.php';

$house_id = $_GET['house_id'] ?? $_SESSION['house_owner_id'];

if (!$house_id) {
    http_response_code(400);
    echo json_encode(['error' => 'House ID required']);
    exit();
}

try {
    // Get all rooms for this house
    $stmt = $conn->prepare("SELECT * FROM house_rooms WHERE house_id = ? ORDER BY room_number");
    $stmt->bind_param("i", $house_id);
    $stmt->execute();
    $rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get room prices
    $stmt = $conn->prepare("SELECT room_number, price FROM room_prices WHERE house_id = ?");
    $stmt->bind_param("i", $house_id);
    $stmt->execute();
    $prices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $price_map = [];
    foreach ($prices as $price) {
        $price_map[$price['room_number']] = $price['price'];
    }
    
    // Get occupancy data
    $stmt = $conn->prepare("SELECT room_number, COUNT(*) as occupied_beds FROM bed_occupancy WHERE house_id = ? AND is_occupied = 1 GROUP BY room_number");
    $stmt->bind_param("i", $house_id);
    $stmt->execute();
    $occupancy = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $occupancy_map = [];
    foreach ($occupancy as $occ) {
        $occupancy_map[$occ['room_number']] = $occ['occupied_beds'];
    }
    
    // Combine data
    foreach ($rooms as &$room) {
        $room['price'] = $price_map[$room['room_number']] ?? null;
        $room['occupied_beds'] = $occupancy_map[$room['room_number']] ?? 0;
    }
    
    header('Content-Type: application/json');
    echo json_encode($rooms);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
