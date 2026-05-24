<?php

session_start();

$envPath = dirname(__DIR__, 2) . '/.env';

if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
} else {
    die("[ERROR] Missing .env file at: " . $envPath);
}

require_once '../connectiondatabase/main_connection.php';

$house_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($house_id <= 0) {
    header("Location: ../index.php");
    exit();
}

$envPath = dirname(__DIR__, 2) . '/.env';
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
} else {
    die("[ERROR] Missing .env file");
}

$conn = new mysqli(
    $env['DB_HOST'],
    $env['DB_USER'],
    $env['DB_PASS'],
    $env['DB_NAME']
);

if ($conn->connect_error) {
    die("[ERROR] Database connection failed: " . $conn->connect_error);
}
$stmt = $conn->prepare("
  SELECT bh.*, COALESCE(owner.user_address, 'Not specified') as owner_address
  FROM boarding_houses bh
  LEFT JOIN ownerregister owner ON bh.owner_id = owner.user_id
  WHERE bh.id = ?
");
$stmt->bind_param("i", $house_id);
$stmt->execute();
$result = $stmt->get_result();
$house = $result->fetch_assoc();

if (!$house) {
    header('Location: ../index.php');
    exit();
}

$is_guest_mode = isset($_GET['guest']) && $_GET['guest'] == '1';
$guest_param = $is_guest_mode ? '?guest=1' : '';
$back_url = $is_guest_mode ? "/dashboard.php?guest=1" : "/dashboard.php";
$back_text = "← Back to Dashboard";


$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$back_url = (strpos($referer, 'dashboard.php') !== false) ? "../dashboard.php" . $guest_param : "../index.php" . $guest_param;
$back_text = (strpos($referer, 'dashboard.php') !== false) ? "← Back to Dashboard" : "← Back to Home";

// Get rooms data
$roomsStmt = $conn->prepare("SELECT room_number, beds_count FROM house_rooms WHERE house_id = ? ORDER BY room_number");
$roomsStmt->bind_param("i", $house_id);
$roomsStmt->execute();
$roomsResult = $roomsStmt->get_result();
$dbRooms = [];
while ($row = $roomsResult->fetch_assoc()) {
    $dbRooms[] = $row;
}

// Get occupied beds
$occupancyStmt = $conn->prepare("SELECT room_number, bed_number FROM bed_occupancy WHERE house_id = ? AND is_occupied = 1");
$occupancyStmt->bind_param("i", $house_id);
$occupancyStmt->execute();
$occupancyResult = $occupancyStmt->get_result();
$occupiedBeds = [];
while ($row = $occupancyResult->fetch_assoc()) {
    $occupiedBeds[] = $row;
}

// Get room prices
$pricesStmt = $conn->prepare("SELECT room_number, price FROM room_prices WHERE house_id = ?");
$pricesStmt->bind_param("i", $house_id);
$pricesStmt->execute();
$pricesResult = $pricesStmt->get_result();
$roomPrices = [];
while ($row = $pricesResult->fetch_assoc()) {
    $roomPrices[] = $row;
}

// Get room amenities
$amenitiesStmt = $conn->prepare("SELECT room_number, amenities FROM room_amenities WHERE house_id = ?");
$amenitiesStmt->bind_param("i", $house_id);
$amenitiesStmt->execute();
$amenitiesResult = $amenitiesStmt->get_result();
$roomAmenities = [];
while ($row = $amenitiesResult->fetch_assoc()) {
    $roomAmenities[] = $row;
}

// Get bed images for this house - organized by room
$bedImagesStmt = $conn->prepare("SELECT room_number, bed_number, image_path FROM bed_images WHERE house_id = ? ORDER BY room_number, bed_number");
$bedImagesStmt->bind_param("i", $house_id);
$bedImagesStmt->execute();
$bedImagesResult = $bedImagesStmt->get_result();
$bedImages = [];
while ($row = $bedImagesResult->fetch_assoc()) {
    $bedImages[] = $row;
}

// Organize bed images by room for easier display
$bedImagesByRoom = [];
foreach ($bedImages as $img) {
    $room_num = $img['room_number'];
    if (!isset($bedImagesByRoom[$room_num])) {
        $bedImagesByRoom[$room_num] = [];
    }
    $bedImagesByRoom[$room_num][] = $img;
}

// Get average rating and total ratings for this house
$ratingStmt = $conn->prepare("SELECT AVG(rating) as avg_rating, COUNT(rating) as total_ratings FROM house_ratings WHERE house_id = ?");
$ratingStmt->bind_param("i", $house_id);
$ratingStmt->execute();
$ratingResult = $ratingStmt->get_result();
$rating_stats = $ratingResult->fetch_assoc();
$avg_rating = $rating_stats['avg_rating'] !== null ? round($rating_stats['avg_rating'], 1) : 0;
$total_ratings = $rating_stats['total_ratings'] ?? 0;

// Handle images from database
$images = array_filter(explode(',', $house['images']));
$images = array_map('trim', $images);

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
// Fallback images if none in database
if (empty($images)) {
    $images = [
        "img/1houseimagefigure/BEDVIEW.jpg",
        "img/1houseimagefigure/TVVIEW.jpg", 
        "img/1houseimagefigure/KITCHEN.jpg"
    ];
}
// Verify image file exists
$valid_images = [];
foreach ($images as $img) {
    if (file_exists("../" . $img) || filter_var($img, FILTER_VALIDATE_URL)) {
        $valid_images[] = $img;
    } else {
        error_log("Missing image: " . $img);
    }
}
// Use fallback if no valid images
if (empty($valid_images)) {
    $valid_images = [
        "img/1houseimagefigure/BEDVIEW.jpg",
        "img/1houseimagefigure/TVVIEW.jpg"
    ];
}
$images = $valid_images;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no"/>
  <title><?php echo htmlspecialchars($house['name']); ?></title>
   <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
<style>
    /* Updated color scheme to yellow and white */
body {
  background: linear-gradient(135deg, #fff9c4, #ffffff);
  color: #333;
  font-family: Arial, sans-serif;
  margin: 0;
  padding: 0;
}

/* Added header styles */
.site-header {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  color: #333;
  padding: 20px 0;
  box-shadow: 0 2px 10px rgba(255, 215, 0, 0.3);
}

.header-content {
  max-width: 1200px;
  margin: 0 auto;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 20px;
}

.logo {
  font-size: 2em;
  font-weight: bold;
  color: #333;
}

.nav-menu {
  display: flex;
  gap: 30px;
}

.nav-menu a {
  color: #333;
  text-decoration: none;
  font-weight: 500;
  transition: color 0.3s;
}

.nav-menu a:hover {
  color: #666;
}

/* Updated footer styles - less thick */
.site-footer {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  color: #333;
  padding: 15px 0 8px 0; /* Reduced thickness */
  margin-top: 20px;
}

.footer-bottom {
  text-align: center;
  padding-top: 10px;
  border-top: 1px solid rgba(51, 51, 51, 0.2);
  margin-top: 10px;
  color: #666;
  font-size: 0.85em;
}

.container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 20px;
  background: white;
  border-radius: 15px;
  box-shadow: 0 5px 20px rgba(255, 215, 0, 0.2);
  margin-top: 20px;
  margin-bottom: 20px;
}

/* Updated navigation buttons with yellow/white theme */
.navigation-buttons {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin: 20px 0;
  gap: 15px;
}

.back-button {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  color: #333;
  padding: 12px 24px;
  border-radius: 25px;
  text-decoration: none;
  font-weight: bold;
  transition: all 0.3s;
  border: 2px solid #ffd700;
}

.back-button:hover {
  background: white;
  color: #ffd700;
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(255, 215, 0, 0.3);
}

/* Boarding House Header with Book Now Button */
.boarding-house-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 15px;
  margin-bottom: 25px;
  flex-wrap: nowrap;
  width: 100%;
  box-sizing: border-box;
  overflow: hidden;
}

.boarding-house-header h1 {
  flex: 1;
  min-width: 0; 
  min-width: 0; 
  margin: 0;
  font-size: 2.5em;
  color: #333;
  font-weight: 800;
  word-wrap: break-word;
  overflow-wrap: break-word;
}
.book-now-btn {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  color: #333;
  padding: 15px 30px;
  border-radius: 25px;
  text-decoration: none;
  font-weight: bold;
  transition: all 0.3s ease;
  border: 2px solid #ffd700;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 1.1em;
  white-space: nowrap;
  box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
  flex-shrink: 0;
  box-sizing: border-box;
}

.book-now-btn:hover {
  background: white;
  color: #ffd700;
  transform: translateY(-3px);
  box-shadow: 0 8px 25px rgba(255, 215, 0, 0.4);
}

.book-now-btn i {
  font-size: 1.2em;
}

/* Tablet - Medium screens */
@media (max-width: 1024px) {
  .boarding-house-header {
    gap: 15px;
  }
  
  .boarding-house-header h1 {
    font-size: 2em;
  }
  
  .book-now-btn {
    padding: 12px 24px;
    font-size: 1em;
  }
}

/* Tablets - Smaller tablets */
@media (max-width: 900px) {
  .boarding-house-header {
    gap: 12px;
  }
  
  .boarding-house-header h1 {
    font-size: 1.7em;
  }
  
  .book-now-btn {
    padding: 12px 20px;
    font-size: 0.95em;
  }
}

/* Phone devices - Large phones */
@media (max-width: 768px) {
  .boarding-house-header {
    gap: 6px;
    padding: 0;
  }
  
  .boarding-house-header h1 {
    font-size: 1.15em;
    flex: 1;
    min-width: 0;
    overflow: hidden;
    word-wrap: break-word;
    overflow-wrap: break-word;
  }
  
  .book-now-btn {
    padding: 8px 12px;
    font-size: 0.75em;
    gap: 4px;
    flex-shrink: 0;
  }
  
  .book-now-btn i {
    font-size: 0.9em;
  }
}

/* Phone devices - Medium phones */
@media (max-width: 640px) {
  .boarding-house-header {
    gap: 5px;
    padding: 0;
  }
  
  .boarding-house-header h1 {
    font-size: 1.05em;
    min-width: 0;
    overflow: hidden;
    word-wrap: break-word;
    overflow-wrap: break-word;
  }
  
  .book-now-btn {
    padding: 7px 10px;
    font-size: 0.7em;
    gap: 3px;
  }
  
  .book-now-btn i {
    font-size: 0.85em;
  }
}

/* Phone devices - Small phones */
@media (max-width: 480px) {
  .boarding-house-header {
    gap: 4px;
    padding: 0;
  }
  
  .boarding-house-header h1 {
    font-size: 0.95em;
    min-width: 0;
    overflow: hidden;
    word-wrap: break-word;
    overflow-wrap: break-word;
  }
  
  .book-now-btn {
    padding: 6px 8px;
    font-size: 0.65em;
    gap: 2px;
  }
  
  .book-now-btn i {
    font-size: 0.8em;
  }
}

/* Extra small phones */
@media (max-width: 360px) {
  .boarding-house-header {
    gap: 3px;
    padding: 0;
  }
  
  .boarding-house-header h1 {
    font-size: 0.85em;
    min-width: 0;
    overflow: hidden;
    word-wrap: break-word;
    overflow-wrap: break-word;
  }
  
  .book-now-btn {
    padding: 5px 7px;
    font-size: 0.6em;
    gap: 2px;
  }
  
  .book-now-btn i {
    font-size: 0.75em;
  }
}

.book-now-button {
  background: linear-gradient(135deg, #ffed4e, #ffd700);
  color: #333;
  flex-grow: 1;
  text-align: center;
  max-width: 200px;
  padding: 12px 24px;
  border-radius: 25px;
  text-decoration: none;
  font-weight: bold;
  transition: all 0.3s;
  border: 2px solid #ffed4e;
}

.book-now-button:hover {
  background: white;
  color: #ffed4e;
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(255, 237, 78, 0.3);
}

@media (max-width: 768px) {
  .navigation-buttons {
    flex-direction: column;
    gap: 10px;
  }
  
  .book-now-button {
    max-width: 100%;
    width: 100%;
  }
}

/* Updated panorama section with yellow/white theme */
.panorama-section {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  border-radius: 15px;
  padding: 25px;
  margin: 20px 0;
  color: #333;
  text-align: center;
  box-shadow: 0 5px 15px rgba(255, 215, 0, 0.2);
}

/* Fixed panorama modal for proper mobile touch controls */
.panorama-viewer-modal {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.95);
  z-index: 2000;
  display: none;
  pointer-events: auto;
  touch-action: manipulation; /* Allow pinch-zoom and natural touches */
}

.panorama-viewer-modal.active {
  display: block;
  pointer-events: auto;
}

.panorama-viewer-content {
  width: 100%;
  height: 100%;
  position: relative;
  pointer-events: auto;
  touch-action: manipulation; /* Allow Pannellum to handle all touch events */
}

/* Pannellum container styling */
#pannellumContainer {
  width: 100% !important;
  height: 100% !important;
  position: relative !important;
}

/* Ensure Pannellum controls are properly styled */
.pnlm-container {
  width: 100% !important;
  height: 100% !important;
  position: absolute !important;
  top: 0 !important;
  left: 0 !important;
}

.panorama-controls {
  position: fixed;
  top: 20px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 2001;
  display: flex;
  gap: 10px;
  pointer-events: auto;
  flex-wrap: wrap;
  justify-content: center;
}

.panorama-controls button {
  background: rgba(255,215,0,0.95);
  border: none;
  padding: 12px 20px;
  border-radius: 8px;
  cursor: pointer;
  font-weight: bold;
  transition: all 0.3s;
  pointer-events: auto;
  touch-action: manipulation;
  color: #333;
}

.panorama-controls button:hover {
  background: #ffd700;
  color: #333;
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(255, 215, 0, 0.4);
}

.panorama-navigation {
  position: fixed;
  bottom: 30px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 2001;
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  justify-content: center;
  pointer-events: auto;
  max-width: 90%;
}

.panorama-nav-btn {
  background: rgba(255,215,0,0.95);
  border: none;
  padding: 10px 15px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 14px;
  font-weight: bold;
  transition: all 0.3s;
  pointer-events: auto;
  touch-action: manipulation;
  color: #333;
}

.panorama-nav-btn:hover {
  background: #ffd700;
  color: #333;
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(255, 215, 0, 0.3);
}

.panorama-nav-btn.active {
  background: #ffd700;
  color: #333;
  box-shadow: 0 4px 12px rgba(255, 215, 0, 0.5);
}

.panorama-preview-container {
  display: flex;
  gap: 10px;
  justify-content: center;
  flex-wrap: wrap;
  margin: 20px 0;
}

.panorama-preview-item {
  width: 120px;
  height: 80px;
  border-radius: 8px;
  overflow: hidden;
  cursor: pointer;
  transition: transform 0.3s ease;
  border: 3px solid rgba(255,255,255,0.3);
}

.panorama-preview-item:hover {
  transform: scale(1.05);
  border-color: white;
}

.panorama-preview-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.panorama-info {
  background: rgba(255,255,255,0.3);
  border-radius: 10px;
  padding: 15px;
  margin: 15px 0;
}

.panorama-button {
  background: linear-gradient(135deg, #ffed4e, #ffd700);
  color: #333;
  border: none;
  padding: 15px 30px;
  border-radius: 10px;
  font-size: 16px;
  font-weight: bold;
  cursor: pointer;
  transition: all 0.3s;
  margin: 10px;
}

.panorama-button:hover {
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(255, 215, 0, 0.4);
  background: white;
  color: #ffd700;
}

/* Updated room selection styles with yellow/white theme */
.rooms-section {
  background: linear-gradient(135deg, #fff9c4, #ffffff);
  border-radius: 15px;
  padding: 25px;
  margin: 20px 0;
  box-shadow: 0 8px 25px rgba(255, 215, 0, 0.1);
}

.rooms-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 20px;
  margin-top: 20px;
}

.room-item {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  border-radius: 15px;
  padding: 20px;
  color: #333;
  cursor: pointer;
  transition: all 0.3s ease;
  box-shadow: 0 8px 25px rgba(255, 215, 0, 0.2);
  position: relative;
  overflow: hidden;
}

.room-item:hover {
  transform: translateY(-5px);
  box-shadow: 0 15px 35px rgba(255, 215, 0, 0.3);
  background: white;
  border: 2px solid #ffd700;
}

.room-item.room-full {
  background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
  cursor: not-allowed;
  opacity: 0.7;
  color: #666;
}

.room-item.room-selected {
  background: linear-gradient(135deg, #ffed4e, #ffd700);
  transform: translateY(-5px);
  box-shadow: 0 15px 35px rgba(255, 237, 78, 0.4);
}

.room-header {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  font-size: 1.4em;
  font-weight: bold;
  margin-bottom: 15px;
  padding-bottom: 10px;
  border-bottom: 2px solid rgba(51, 51, 51, 0.2);
}

.room-status {
  font-size: 0.9em;
  text-align: center;
  margin-bottom: 15px;
  padding: 8px 12px;
  border-radius: 20px;
  background: rgba(255,255,255,0.5);
  backdrop-filter: blur(5px);
}

.room-price {
  background: linear-gradient(135deg, #ffed4e, #ffd700);
  color: #333;
  padding: 12px 16px;
  border-radius: 10px;
  font-size: 1em;
  font-weight: bold;
  margin-bottom: 12px;
  text-align: center;
  border: 2px solid rgba(255,255,255,0.3);
  box-shadow: 0 4px 8px rgba(255, 215, 0, 0.2);
}

.room-no-price {
  background: rgba(255,255,255,0.3);
  color: #666;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 0.9em;
  margin-bottom: 12px;
  text-align: center;
  border: 1px dashed rgba(51, 51, 51, 0.4);
  font-style: italic;
}

.room-amenities {
  background: linear-gradient(135deg, #fff9c4, #ffffff);
  color: #333;
  padding: 12px 16px;
  border-radius: 10px;
  font-size: 0.9em;
  font-weight: 500;
  text-align: center;
  border: 2px solid rgba(255, 215, 0, 0.3);
  box-shadow: 0 4px 8px rgba(255, 215, 0, 0.1);
  line-height: 1.4;
  min-height: 50px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.room-no-amenities {
  background: rgba(255,255,255,0.2);
  color: #666;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 0.85em;
  text-align: center;
  border: 1px dashed rgba(51, 51, 51, 0.3);
  font-style: italic;
  min-height: 50px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.status-message {
  margin-top: 20px;
  padding: 15px;
  border-radius: 10px;
  background: linear-gradient(135deg, #fff9c4, #ffffff);
  border: 1px solid #ffd700;
  text-align: center;
  font-weight: 500;
}

/* Updated modal styles with yellow/white theme */
.modal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0,0,0,0.8);
  backdrop-filter: blur(5px);
}

.modal-content {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  margin: 3% auto;
  padding: 30px;
  border-radius: 20px;
  width: 90%;
  max-width: 800px;
  color: #333;
  box-shadow: 0 20px 60px rgba(255, 215, 0, 0.5);
  max-height: 85vh;
  overflow-y: auto;
}

.modal-title {
  text-align: center;
  font-size: 1.8em;
  font-weight: bold;
  margin-bottom: 25px;
  padding-bottom: 15px;
  border-bottom: 2px solid rgba(51, 51, 51, 0.2);
}

.modal-amenities {
  background: linear-gradient(135deg, #fff9c4, #ffffff);
  color: #333;
  padding: 20px;
  border-radius: 15px;
  margin: 20px 0;
  border: 2px solid rgba(255, 215, 0, 0.3);
  box-shadow: 0 6px 12px rgba(255, 215, 0, 0.2);
}

.modal-amenities strong {
  display: block;
  margin-bottom: 10px;
  font-size: 1.2em;
}

.modal-no-amenities {
  background: rgba(255,255,255,0.3);
  color: #666;
  padding: 15px;
  border-radius: 10px;
  margin: 20px 0;
  text-align: center;
  border: 1px dashed rgba(51, 51, 51, 0.3);
  font-style: italic;
}

.beds-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 20px;
  margin: 25px 0;
}

.bed-item {
  background: linear-gradient(135deg, #ffed4e, #ffd700);
  border-radius: 15px;
  padding: 20px;
  text-align: center;
  cursor: pointer;
  transition: all 0.3s ease;
  min-height: 120px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  box-shadow: 0 6px 15px rgba(255, 215, 0, 0.2);
  color: #333;
}

.bed-item.bed-occupied {
  background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
  cursor: not-allowed;
  color: #666;
}

.bed-item.bed-selected {
  background: linear-gradient(135deg, #fff9c4, #ffffff);
  transform: scale(1.05);
  box-shadow: 0 8px 25px rgba(255, 215, 0, 0.4);
  border: 2px solid #ffd700;
}

.bed-item:hover:not(.bed-occupied) {
  transform: translateY(-3px);
  box-shadow: 0 10px 25px rgba(255, 215, 0, 0.3);
  background: white;
  border: 2px solid #ffd700;
}

.modal-buttons {
  display: flex;
  gap: 15px;
  justify-content: center;
  margin-top: 25px;
}

.modal-btn {
  padding: 12px 25px;
  border: none;
  border-radius: 25px;
  font-weight: bold;
  cursor: pointer;
  transition: all 0.3s ease;
  font-size: 1em;
}

.close-btn {
  background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
  color: #666;
}

.modal-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 15px rgba(0,0,0,0.2);
}

/* Updated general styles */
h1, h2 {
  color: #333;
}

.image-container {
  position: relative;
  margin: 20px 0;
  border-radius: 15px;
  overflow: visible;
  box-shadow: 0 5px 20px rgba(255, 215, 0, 0.2);
}

.main-image {
  width: 100%;
  height: 400px;
  object-fit: cover;
  border-radius: 15px;
  display: block;
}

/* Arrow Navigation Styles */
.arrow {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  color: #000;
  border: 3px solid #ff9800;
  border-radius: 50%;
  width: 60px;
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32px;
  font-weight: bold;
  cursor: pointer;
  user-select: none;
  transition: all 0.3s ease;
  z-index: 150;
  box-shadow: 0 8px 24px rgba(255, 215, 0, 0.8), inset 0 1px 0 rgba(255, 255, 255, 0.5);
}

.arrow-left {
  left: 20px;
}

.arrow-right {
  right: 20px;
}

.arrow:hover {
  background: linear-gradient(135deg, #ffed4e, #ffd700);
  color: #000;
  transform: translateY(-50%) scale(1.15);
  box-shadow: 0 12px 32px rgba(255, 215, 0, 1), inset 0 1px 0 rgba(255, 255, 255, 0.7);
}

.thumbnails-container {
  background: linear-gradient(135deg, #fff9c4, #ffffff);
  padding: 15px;
  border-radius: 10px;
  margin: 20px 0;
}

.contact-info {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  padding: 20px;
  border-radius: 15px;
  margin: 20px 0;
  color: #333;
}

.map-section {
  background: linear-gradient(135deg, #fff9c4, #ffffff);
  padding: 20px;
  border-radius: 15px;
  margin: 20px 0;
}

/* Location Distance Section Styles */
.location-distance-section {
  background: linear-gradient(135deg, #fff9c4, #ffffff);
  border-radius: 15px;
  padding: 20px;
  margin: 20px 0;
  box-shadow: 0 5px 15px rgba(255, 215, 0, 0.1);
}

.location-button {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  color: #333;
  border: none;
  padding: 12px 20px;
  border-radius: 25px;
  font-weight: bold;
  cursor: pointer;
  transition: all 0.3s;
  font-size: 16px;
  margin-bottom: 15px;
}

.location-button:hover {
  background: white;
  color: #ffd700;
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(255, 215, 0, 0.3);
}

.location-button:disabled {
  opacity: 0.7;
  cursor: not-allowed;
  transform: none;
}

.distance-result {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  padding: 15px;
  border-radius: 10px;
  margin-top: 10px;
  text-align: center;
}

.distance-text, .travel-time {
  font-size: 16px;
  font-weight: bold;
  margin: 8px 0;
  color: #333;
}

/* Arrow back button styles */
.arrow-back-button {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #ffd700, #ffed4e);
    border: 2px solid #ffd700;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 8px rgba(255, 215, 0, 0.3);
}

.arrow-back-button:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(255, 215, 0, 0.4);
}

.arrow-back-button i {
    color: #333;
    font-size: 1.2rem;
    font-weight: bold;
}

/* Rating Display Styles */
.rating-display-section {
    background: linear-gradient(135deg, #ffd700, #ffed4e);
    border-radius: 15px;
    padding: 25px;
    margin: 20px 0;
    color: #333;
    box-shadow: 0 5px 15px rgba(255, 215, 0, 0.2);
}

.rating-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
    font-size: 1.3em;
    font-weight: bold;
}

.rating-icon {
    color: #ffd700;
    font-size: 1.4em;
}

.stars-display {
    display: flex;
    gap: 5px;
    margin-bottom: 10px;
    font-size: 1.5em;
}

.star {
    color: #ffd700;
    text-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

.star.empty {
    color: #ddd;
}

.star.filled {
    color: #ffd700;
}

.rating-count {
    font-size: 0.95em;
    color: #666;
    margin-bottom: 15px;
}

.rating-stats {
    background: rgba(255,255,255,0.5);
    padding: 15px;
    border-radius: 10px;
    text-align: center;
}

.rating-value {
    font-size: 2em;
    font-weight: bold;
    color: #333;
    margin-right: 10px;
}

.rating-text-value {
    font-size: 1.1em;
    color: #666;
}

/* ✅ BED IMAGES SECTION STYLES */
.bed-images-section {
  background: linear-gradient(135deg, #fff9c4, #ffffff);
  border-radius: 15px;
  padding: 25px;
  margin: 20px 0;
  box-shadow: 0 8px 25px rgba(255, 215, 0, 0.1);
}

.bed-images-section h2 {
  color: #333;
  text-align: center;
  margin-bottom: 25px;
  font-size: 1.8em;
  padding-bottom: 15px;
  border-bottom: 2px solid rgba(255, 215, 0, 0.3);
}

.room-bed-images-container {
  margin-bottom: 30px;
}

.room-bed-header {
  background: linear-gradient(135deg, #ffd700, #ffed4e);
  color: #333;
  padding: 15px;
  border-radius: 10px;
  margin-bottom: 15px;
  font-weight: bold;
  font-size: 1.2em;
  box-shadow: 0 4px 8px rgba(255, 215, 0, 0.2);
}

.bed-images-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 15px;
  margin-bottom: 20px;
}

.bed-image-item {
  position: relative;
  border-radius: 10px;
  overflow: hidden;
  background: white;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  transition: all 0.3s ease;
  cursor: pointer;
  border: 2px solid #f0f0f0;
}

.bed-image-item:hover {
  transform: translateY(-5px);
  box-shadow: 0 8px 20px rgba(255, 215, 0, 0.3);
  border-color: #ffd700;
}

.bed-image-wrapper {
  width: 100%;
  padding-bottom: 75%;
  position: relative;
  background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
}

.bed-image-wrapper img {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.3s ease;
}

.bed-image-item:hover .bed-image-wrapper img {
  transform: scale(1.05);
}

.bed-label {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
  color: white;
  padding: 12px;
  font-weight: bold;
  font-size: 0.9em;
  display: none;
}

.no-bed-images-message {
  background: rgba(255, 215, 0, 0.2);
  border: 2px dashed #ffd700;
  border-radius: 10px;
  padding: 30px;
  text-align: center;
  color: #666;
  font-weight: 500;
}

.bed-image-modal {
  display: none;
  position: fixed;
  z-index: 2000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.95);
  padding: 20px;
  overflow: auto;
}

.bed-image-modal.active {
  display: flex;
  justify-content: center;
  align-items: center;
}

.bed-image-modal-content {
  position: relative;
  background: white;
  border-radius: 15px;
  max-width: 90%;
  max-height: 90vh;
  overflow: hidden;
}

.bed-image-modal img {
  width: 100%;
  height: auto;
  max-height: 90vh;
  object-fit: contain;
}

.bed-image-close {
  position: absolute;
  top: 20px;
  right: 20px;
  background: rgba(0, 0, 0, 0.7);
  color: white;
  border: none;
  border-radius: 50%;
  width: 40px;
  height: 40px;
  font-size: 28px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 2001;
  transition: all 0.3s ease;
}

.bed-image-close:hover {
  background: rgba(0, 0, 0, 0.95);
  transform: scale(1.1);
}

@media (max-width: 768px) {
  .bed-images-grid {
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 10px;
  }
  
  .bed-image-modal-content {
    max-width: 95%;
    max-height: 95vh;
  }
}

</style>

  <!-- Pannellum for panoramic images (like Google Street View) -->
  <link rel="stylesheet" href="https://cdn.pannellum.org/2.5/pannellum.css" />
  <script src="https://cdn.pannellum.org/2.5/pannellum.js"></script>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<header class="site-header">
  <div class="header-content">
    <div class="logo">
      <i class="fas fa-home"></i> StayFinder
    </div>
  </div>
</header>

<div class="container">

<div class="navigation-buttons">
    <a href="javascript:history.back()" class="arrow-back-button">
        <i class="fas fa-arrow-left"></i>
    </a>
</div>

<div class="boarding-house-header">
  <h1><?php echo strtoupper(htmlspecialchars($house['name'])); ?></h1>
</div>

  <!-- Rating Display Section -->
  <div class="rating-display-section">
    <div class="rating-header">
      <i class="fas fa-star rating-icon"></i>
      <span>Boarding House Rating</span>
    </div>
    
    <div class="rating-stats">
      <div style="display: flex; align-items: center; justify-content: center; gap: 15px;">
        <div class="stars-display">
          <?php 
            // Display stars based on average rating
            $full_stars = floor($avg_rating);
            $has_half_star = ($avg_rating - $full_stars) >= 0.5;
            
            // Display full stars
            for ($i = 1; $i <= 5; $i++) {
              if ($i <= $full_stars) {
                echo '<span class="star filled">★</span>';
              } elseif ($i == $full_stars + 1 && $has_half_star) {
                echo '<span class="star filled">★</span>';
              } else {
                echo '<span class="star empty">☆</span>';
              }
            }
          ?>
        </div>
        <div style="text-align: left;">
          <div class="rating-value" style="display: inline-block; font-size: 1.8em; color: #333;">
            <?php echo $avg_rating > 0 ? $avg_rating : 'No'; ?> 
            <span style="font-size: 0.7em; color: #666;">/ 5.0</span>
          </div>
          <div class="rating-text-value">
            Based on <?php echo $total_ratings; ?> rating<?php echo $total_ratings !== 1 ? 's' : ''; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <h2>House Images Capture</h2>
  
<div class="image-container" style="position: relative; margin: 20px auto; border-radius: 15px; overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.1); max-width: 600px; background: #f9f9f9;">
    <div class="arrow arrow-left" id="arrowLeft" style="position: absolute; top: 50%; left: 10px; transform: translateY(-50%); background-color: #FFFF00; color: #000; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 10;">◀</div>

    <img src="<?php echo '../' . htmlspecialchars($images[0]); ?>" 
         alt="<?php echo htmlspecialchars($house['name']); ?>" 
         class="main-image" 
         id="mainImage" 
         style="width: 100%; height: 350px; object-fit: contain; display: block; cursor: pointer;"
         onclick="openFullImage(this.src)">

    <div class="arrow arrow-right" id="arrowRight" style="position: absolute; top: 50%; right: 10px; transform: translateY(-50%); background-color: #FFFF00; color: #000; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 10;">▶</div>
  </div>

  <div class="thumbnails-container" style="text-align: center; margin-top: 10px;">
    
    <div class="thumbnails" id="thumbnails" style="display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; padding: 10px;">
      <?php foreach ($images as $index => $image): ?>
        <img src="<?php echo '../' . htmlspecialchars($image); ?>" 
             alt="Thumbnail <?php echo $index + 1; ?>" 
             class="thumbnail <?php echo $index === 0 ? 'active' : ''; ?>" 
             style="width: 80px; height: 60px; object-fit: cover; border-radius: 8px; cursor: pointer; border: 2px solid <?php echo $index === 0 ? '#FFFF00' : '#eee'; ?>;"
             onclick="changeMainImage(this.src, this)"
             onerror="this.onerror=null; this.src='https://via.placeholder.com/800x350/2a475e/ffffff?text=No+Image';">
      <?php endforeach; ?>
    </div>
  </div>

  <div class="panorama-section">
    <h2><i class="fas fa-globe"></i> 360° Panorama View</h2>
    
    <?php if (!empty($panorama_images)): ?>
      <div class="panorama-info">
        <p><strong><?php echo count($panorama_images); ?> Panoramic Views Available</strong></p>
        <p>Experience immersive 360° views of this boarding house</p>
      </div>
      
      <div class="panorama-preview-container">
        <?php foreach ($panorama_images as $index => $panorama_path): ?>
        <div class="panorama-preview-item" onclick="open360Viewer(<?php echo $index; ?>)">
          <img src="<?php echo '../' . htmlspecialchars($panorama_path); ?>" 
               alt="360° View <?php echo $index + 1; ?>" 
               onerror="this.src='https://via.placeholder.com/120x80/2a475e/ffffff?text=360+View'">
        </div>
        <?php endforeach; ?>
      </div>
      
      <button class="panorama-button" onclick="open360Viewer(0)">
        <i class="fas fa-play-circle"></i> View 360° Panoramic 
      </button>
      
    <?php else: ?>
      <div class="panorama-info">
        <p><i class="fas fa-info-circle"></i> No 360° panorama images available</p>
        <p>Contact the owner for virtual tour availability</p>
      </div>
    <?php endif; ?>
  </div>

  <!-- ✅ BED IMAGES SECTION - Display all room bed images -->
  <div class="bed-images-section">
    <h2><i class="fas fa-image"></i> Room & Bed Images</h2>
    
    <?php if (!empty($bedImages)): ?>
      <div class="bed-images-grid">
        <?php foreach ($bedImages as $image): ?>
        <div class="bed-image-item" onclick="openBedImageModal('<?php echo htmlspecialchars($image['image_path']); ?>')">
          <div class="bed-image-wrapper">
            <?php 

              $db_path = trim($image['image_path']);
              $display_path = '';

              
              if (strpos($db_path, 'uploads/') !== false) {
                // Extract just the filename from uploads/bed_images/
                $filename = basename($db_path);
                // Use the same path as book_house.php
                $display_path = '../baordinghouseOWNER/uploads/bed_images/' . $filename;
              } else {
                $display_path = '../baordinghouseOWNER/uploads/bed_images/' . basename($db_path);
              }
            ?>
            <img src="<?php echo htmlspecialchars($display_path); ?>" 
                 alt="Bed Image"
                 loading="lazy"
                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22150%22%3E%3Crect fill=%22%23ddd%22 width=%22200%22 height=%22150%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 font-family=%22Arial%22 font-size=%2214%22 fill=%22%23999%22%3ENo Image%3C/text%3E%3C/svg%3E';">
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="no-bed-images-message">
        <i class="fas fa-image" style="font-size: 2em; margin-bottom: 10px; display: block;"></i>
        <p><strong>No bed images available</strong></p>
        <p style="font-size: 0.9em; color: #999;">Contact the owner to view bed and room photos</p>
      </div>
    <?php endif; ?>
  </div>

<div class="location-distance-section">
  <h2>📍 Distance from Your Location</h2>
  <div class="distance-info">
    <button id="getLocationBtn" class="location-button">
      <i class="fas fa-location-arrow"></i> Show Distance from My Location
    </button>
    <div id="distanceResult" class="distance-result" style="display: none;">
      <div class="distance-text">
        <i class="fas fa-road"></i> 
        <span id="distanceValue"></span> away
      </div>
      <div class="travel-time">
        <i class="fas fa-clock"></i> 
        Approximately <span id="travelTime"></span> by car
      </div>
    </div>
  </div>
</div>

<!-- Map Section -->
<div class="map-section">
  <h2>📍 Location</h2>
  <?php if (!empty($house['map_lat']) && !empty($house['map_lng'])): ?>
    <div id="houseMap" style="height: 400px; border-radius: 8px; margin: 20px 0;"></div>
    <script>
      let userLocation = null;
      let directionsRenderer = null;
      let directionsService = null;
      let userMarker = null;
      let houseMarker = null;
      
      function initMap() {
        const houseLocation = { 
          lat: <?php echo $house['map_lat']; ?>, 
          lng: <?php echo $house['map_lng']; ?> 
        };
        
        const map = new google.maps.Map(document.getElementById("houseMap"), {
          zoom: 17,
          center: houseLocation,
          mapTypeId: google.maps.MapTypeId.HYBRID,
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
          scrollwheel: true,
          gestureHandling: 'greedy',
          disableDoubleClickZoom: false
        });

        // Create boarding house marker
        const houseIcon = {
          url: '../img/icons/house_9408891.png',
          scaledSize: new google.maps.Size(40, 40),
          anchor: new google.maps.Point(20, 40)
        };

        houseMarker = new google.maps.Marker({
          position: houseLocation,
          map: map,
          title: '<?php echo htmlspecialchars($house['name']); ?>',
          icon: houseIcon
        });

        const infoWindow = new google.maps.InfoWindow({
          content: '<div style="padding: 10px;"><strong><?php echo htmlspecialchars($house['name']); ?></strong></div>'
        });

        houseMarker.addListener('click', () => {
          infoWindow.open(map, houseMarker);
        });

        // Initialize directions service
        directionsService = new google.maps.DirectionsService();
        directionsRenderer = new google.maps.DirectionsRenderer({
          map: map,
          suppressMarkers: true,
          polylineOptions: {
            strokeColor: '#4285F4',
            strokeOpacity: 0.8,
            strokeWeight: 6
          }
        });

        // Store map globally
        window.map = map;
        
        // Add event listener to location button
        document.getElementById('getLocationBtn').addEventListener('click', getUserLocation);
        
        // Open info window by default
        infoWindow.open(map, houseMarker);
      }

      function getUserLocation() {
        const locationBtn = document.getElementById('getLocationBtn');
        const distanceResult = document.getElementById('distanceResult');
        
        locationBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Detecting Location...';
        locationBtn.disabled = true;
        
        if (navigator.geolocation) {
          navigator.geolocation.getCurrentPosition(
            function(position) {
              userLocation = {
                lat: position.coords.latitude,
                lng: position.coords.longitude
              };
              
              calculateDistanceAndRoute(userLocation);
              locationBtn.innerHTML = '<i class="fas fa-location-arrow"></i> Update My Location';
              locationBtn.disabled = false;
            },
            function(error) {
              console.error('Error getting location:', error);
              alert('Unable to get your location. Please make sure location services are enabled.');
              locationBtn.innerHTML = '<i class="fas fa-location-arrow"></i> Show Distance from My Location';
              locationBtn.disabled = false;
            },
            {
              enableHighAccuracy: true,
              timeout: 10000,
              maximumAge: 60000
            }
          );
        } else {
          alert('Geolocation is not supported by this browser.');
          locationBtn.innerHTML = '<i class="fas fa-location-arrow"></i> Show Distance from My Location';
          locationBtn.disabled = false;
        }
      }
      
      function calculateDistanceAndRoute(userLoc) {
        const houseLocation = { 
          lat: <?php echo $house['map_lat']; ?>, 
          lng: <?php echo $house['map_lng']; ?> 
        };
        
        const request = {
          origin: userLoc,
          destination: houseLocation,
          travelMode: google.maps.TravelMode.DRIVING
        };
        
        directionsService.route(request, function(result, status) {
          if (status === 'OK') {
            // Show the blue route line
            directionsRenderer.setDirections(result);
            
            // Extract distance and duration
            const route = result.routes[0];
            const leg = route.legs[0];
            const distance = leg.distance.text;
            const duration = leg.duration.text;
            
            // Display distance information
            document.getElementById('distanceValue').textContent = distance;
            document.getElementById('travelTime').textContent = duration;
            document.getElementById('distanceResult').style.display = 'block';
            
            // Add blue dot for user location
            if (userMarker) {
              userMarker.setMap(null);
            }
            
            userMarker = new google.maps.Marker({
              position: userLoc,
              map: window.map,
              title: 'Your Location',
              icon: {
                url: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png',
                scaledSize: new google.maps.Size(32, 32)
              }
            });
            
            console.log('✅ Blue route line and user marker added successfully');
            
          } else {
            console.error('Directions request failed: ' + status);
            alert('Could not calculate route. Please try again.');
          }
        });
      }

      // Initialize map when page loads
      document.addEventListener('DOMContentLoaded', function() {
        if (typeof google !== 'undefined' && google.maps) {
          initMap();
        } else {
          setTimeout(initMap, 500);
        }
      });
    </script>
  <?php else: ?>
    <p style="color: #888; font-style: italic;">Location not set by owner yet.</p>
  <?php endif; ?>
</div>

   <h2>About This Boarding House</h2>
   
  <p><?php echo nl2br(htmlspecialchars(preg_replace('/\[OWNER:[^\]]+\]/', '', $house['description']))); ?></p>
  
<!-- LOCATION DETAILS - Fixed to display actual owner address from database -->
<div>
  <h2>Location Details</h2>
  <p><strong>Full Location:</strong> <?php 
    // Try multiple location fields in priority order
    if (!empty($house['owner_address']) && $house['owner_address'] !== 'Not specified') {
      echo htmlspecialchars($house['owner_address']);
    } elseif (!empty($house['full_location'])) {
      echo htmlspecialchars($house['full_location']);
    } elseif (!empty($house['purok'])) {
      echo htmlspecialchars($house['purok']);
    } else {
      echo 'Location not specified';
    }
  ?></p>
</div>

<div class="contact-info" style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; background-color: #FFFF00; padding: 20px; border-radius: 12px;">
    <div style="flex:1; min-width:0;">
      <h2 style="margin-top: 0;">Contact Information</h2>
      <?php if (!empty($house['owner_email']) || !empty($house['owner_phone'])): ?>
        <div style="font-size: 1.1em; margin-top: 10px;">
          <?php if (!empty($house['owner_email'])): ?>
            <div style="margin-bottom: 8px;">
              <i class="fas fa-envelope"></i> <strong>Email:</strong> 
              <a href="mailto:<?= htmlspecialchars($house['owner_email']) ?>" style="color: #000; text-decoration: underline;"><?= htmlspecialchars($house['owner_email']) ?></a>
            </div>
          <?php endif; ?>
          <?php if (!empty($house['owner_phone'])): ?>
            <div>
              <i class="fas fa-phone"></i> <strong>Phone:</strong> 
              <a href="tel:<?= htmlspecialchars($house['owner_phone']) ?>" style="color: #000; text-decoration: underline;"><?= htmlspecialchars($house['owner_phone']) ?></a>
            </div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div style="color: #333; font-style: italic;">Owner contact details not available.</div>
      <?php endif; ?>
    </div>
</div>

  <div style="display:flex;align-items:center;margin-top:20px;">
    <?php
      // Build booking URL, preserve guest param if present
      $bookingUrl = '../bookform/book_house.php?house_id=' . intval($house_id);
      if (!empty($guest_param)) {
          // guest_param already contains the leading '?', convert to '&' when appending
          $bookingUrl .= '&guest=1';
      }
    ?>
    <a href="<?= htmlspecialchars($bookingUrl) ?>" class="btn-check-availability" style="background:#FFFF00;color:#000;padding:10px 14px;border-radius:8px;font-weight:700;text-decoration:none;box-shadow:0 6px 18px rgba(0,0,0,0.12);">
      <i class="fas fa-calendar-plus" style="margin-right:8px;font-size:0.95em;"></i> Check Availability
    </a>
  </div>

  </div>

  <footer class="footer" style="background-color:#111;color:#fff;padding:8px 0;text-align:center;">
    <div class="container" style="background:transparent !important; box-shadow:none !important; border-radius:0 !important; padding:0 !important;">
      <div class="row" style="margin:0;">
        <div class="col-12" style="padding:6px 0;">
          <p class="mb-0" style="font-size:13px; color:#fff; margin:0;">
            StayFinder: Boarding Locator and Management System
          </p>
          <p class="mt-1 mb-0" style="font-size:13px; margin:4px 0 0 0;">
            <a href="../terms.php" style="color:#ffd700; text-decoration:none; font-weight:700;">Terms &amp; Conditions</a>
          </p>
        </div>
      </div>
    </div>
  </footer>

<div id="bedModal" class="modal">
  <div class="modal-content">
    <div class="modal-title">🏠 Room <span id="modalRoomNumber"></span> - Bed Details</div>
    <div id="modalRoomPrice" class="room-price" style="margin: 15px 0; display: none;"></div>
    <div id="modalRoomAmenities" class="modal-amenities" style="display: none;">
      <strong>🏨 Room Amenities:</strong>
      <div id="modalAmenitiesText"></div>
    </div>
    <div class="beds-container" id="bedsContainer">
     
    </div>
    <div class="modal-buttons">
      <button class="modal-btn close-btn" onclick="closeModal()">Close</button>
    </div>
  </div>
</div>

<div class="panorama-viewer-modal" id="panoramaViewer">
  <div class="panorama-viewer-content">
    <div class="panorama-controls">
      <button onclick="close360Viewer()" title="Close panoramic view">
        <i class="fas fa-times"></i> Close
      </button>
      <button onclick="resetPanoramaView()" title="Reset to center view">
        <i class="fas fa-home"></i> Reset View
      </button>
      <button onclick="togglePanoramaFullscreen()" title="Toggle fullscreen mode">
        <i class="fas fa-expand"></i> Fullscreen
      </button>
      <button onclick="zoomPanoramaIn()" title="Zoom in">
        <i class="fas fa-plus"></i> Zoom In
      </button>
      <button onclick="zoomPanoramaOut()" title="Zoom out">
        <i class="fas fa-minus"></i> Zoom Out
      </button>
    </div>

    <!-- Pannellum panoramic viewer container -->
    <div id="pannellumContainer" style="width: 100%; height: 100%; position: relative;"></div>
    
    <div class="panorama-navigation" id="panoramaNavigation">
    </div>
  </div>
</div>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($env['GOOGLE_MAPS_API_KEY'] ?? ''); ?>&libraries=places&callback=initMap" async defer></script>
<script>
const images = <?php echo json_encode(array_map(function($img) { return '../' . trim($img); }, $images)); ?>;
const thumbs = document.querySelectorAll('.thumbnail');
const main = document.getElementById('mainImage');
const left = document.getElementById('arrowLeft');
const right = document.getElementById('arrowRight');
let currentIndex = 0;

function showImage(index) {
    if (main && images[index]) {
        main.classList.add('image-loading');
        const newImg = new Image();
        newImg.onload = function() {
            main.src = images[index];
            main.classList.remove('image-loading');
            thumbs.forEach((t, i) => {
                t.classList.toggle('active', i === index);
            });
            currentIndex = index;
        };
        newImg.onerror = function() {
            main.src = 'https://via.placeholder.com/800x350/2a475e/ffffff?text=Image+Not+Available';
            main.classList.remove('image-loading');
        };
        newImg.src = images[index];
    }
}

thumbs.forEach((thumb, index) => {
    thumb.onclick = () => showImage(index);
});

if (left) {
    left.onclick = () => {
        const newIndex = (currentIndex - 1 + images.length) % images.length;
        showImage(newIndex);
    };
    left.onmouseover = function() {
        this.style.background = 'linear-gradient(135deg, #ffed4e, #ffd700)';
        this.style.transform = 'translateY(-50%) scale(1.15)';
        this.style.boxShadow = '0 12px 32px rgba(255, 215, 0, 1)';
    };
    left.onmouseout = function() {
        this.style.background = 'linear-gradient(135deg, #ffd700, #ffed4e)';
        this.style.transform = 'translateY(-50%)';
        this.style.boxShadow = '0 8px 24px rgba(255, 215, 0, 0.8)';
    };
}
if (right) {
    right.onclick = () => {
        const newIndex = (currentIndex + 1) % images.length;
        showImage(newIndex);
    };
    right.onmouseover = function() {
        this.style.background = 'linear-gradient(135deg, #ffed4e, #ffd700)';
        this.style.transform = 'translateY(-50%) scale(1.15)';
        this.style.boxShadow = '0 12px 32px rgba(255, 215, 0, 1)';
    };
    right.onmouseout = function() {
        this.style.background = 'linear-gradient(135deg, #ffd700, #ffed4e)';
        this.style.transform = 'translateY(-50%)';
        this.style.boxShadow = '0 8px 24px rgba(255, 215, 0, 0.8)';
    };
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') left.onclick();
    else if (e.key === 'ArrowRight') right.onclick();
});
if (images.length > 0) showImage(0);

let panoramaImages = <?php echo json_encode($panorama_images); ?>;
let currentPanoramaIndex = 0;
let pannellumViewer = null;
let isViewerInitialized = false;

// Room selection variables and functions
let rooms = [];
let occupiedBeds = [];
let roomPrices = [];
let roomAmenities = [];
let dbRooms = <?php echo json_encode($dbRooms); ?>;
let initialOccupiedBeds = <?php echo json_encode($occupiedBeds); ?>;
let initialRoomPrices = <?php echo json_encode($roomPrices); ?>;
let initialRoomAmenities = <?php echo json_encode($roomAmenities); ?>;

// Initialize room data
rooms = dbRooms.map(room => ({
    id: room.room_number,
    beds: room.beds_count
}));
occupiedBeds = initialOccupiedBeds;
roomPrices = initialRoomPrices;
roomAmenities = initialRoomAmenities;

// Helper functions
function getRoomPrice(roomId) {
    const priceData = roomPrices.find(p => p.room_number == roomId);
    return priceData ? parseFloat(priceData.price) : null;
}

function getRoomAmenities(roomId) {
    const amenitiesData = roomAmenities.find(a => a.room_number == roomId);
    return amenitiesData ? amenitiesData.amenities : null;
}

function isBedOccupied(roomId, bedId) {
    return occupiedBeds.some(bed => 
        bed.room_number == roomId && bed.bed_number == bedId
    );
}
// Display rooms function
function displayRooms() {
    const container = document.getElementById('roomsContainer');
    container.innerHTML = '';

    if (rooms.length === 0) {
        container.innerHTML = '<p style="color: #e74c3c; font-size: 14px; text-align: center; padding: 20px;">No rooms available</p>';
        return;
    }

    rooms.forEach(room => {
        const occupiedCount = occupiedBeds.filter(bed =>
            bed.room_number === room.id
        ).length;

        const availableBeds = room.beds - occupiedCount;
        const isFull = availableBeds === 0;
        const roomPrice = getRoomPrice(room.id);
        const roomAmenitiesData = getRoomAmenities(room.id);

        const div = document.createElement('div');
        div.className = `room-item ${isFull ? 'room-full' : 'room-available'}`;

        let priceHtml = '';
        if (roomPrice !== null) {
            priceHtml = `<div class="room-price">💰 ₱${roomPrice.toLocaleString()}/month</div>`;
        } else {
            priceHtml = `<div class="room-no-price">💸 Contact Owner for Price</div>`;
        }

        let amenitiesHtml = '';
        if (roomAmenitiesData && roomAmenitiesData.trim()) {
            const truncatedAmenities = roomAmenitiesData.length > 40 
                ? roomAmenitiesData.substring(0, 40) + '...' 
                : roomAmenitiesData;
            amenitiesHtml = `<div class="room-amenities">🏨 ${truncatedAmenities}</div>`;
        } else {
            amenitiesHtml = `<div class="room-no-amenities">No amenities listed</div>`;
        }

        div.innerHTML = `
            <div class="room-header">
                <span>🏠</span>
                <span>Room ${room.id}</span>
            </div>
            <div class="room-status">
                ${isFull ? 
                    '🔴 FULL' : 
                    `🟢 ${availableBeds} bed${availableBeds !== 1 ? 's' : ''} available`}
            </div>
            ${!isFull ? priceHtml : ''}
            ${!isFull ? amenitiesHtml : ''}
        `;

        if (!isFull) {
            div.onclick = () => openBedModal(room.id, room.beds);
        }

        container.appendChild(div);
    });
}

// Open bed modal function
function openBedModal(roomId, totalBeds) {
    document.getElementById('modalRoomNumber').textContent = roomId;
    
    const roomPrice = getRoomPrice(roomId);
    const modalRoomPrice = document.getElementById('modalRoomPrice');
    if (roomPrice !== null) {
        modalRoomPrice.innerHTML = `💰 Room ${roomId} - ₱${roomPrice.toLocaleString()}/month`;
        modalRoomPrice.style.display = 'block';
        modalRoomPrice.className = 'room-price';
    } else {
        modalRoomPrice.innerHTML = `💸 Room ${roomId} - Contact Owner for Price`;
        modalRoomPrice.style.display = 'block';
        modalRoomPrice.className = 'room-no-price';
    }
    const roomAmenitiesData = getRoomAmenities(roomId);
    const modalRoomAmenities = document.getElementById('modalRoomAmenities');
    const modalAmenitiesText = document.getElementById('modalAmenitiesText');
    
    if (roomAmenitiesData && roomAmenitiesData.trim()) {
        modalAmenitiesText.textContent = roomAmenitiesData;
        modalRoomAmenities.style.display = 'block';
        modalRoomAmenities.className = 'modal-amenities';
    } else {
        modalAmenitiesText.textContent = 'No amenities have been listed for this room';
        modalRoomAmenities.style.display = 'block';
        modalRoomAmenities.className = 'modal-no-amenities';
    }
    
    const container = document.getElementById('bedsContainer');
    container.innerHTML = '';

    for (let i = 1; i <= totalBeds; i++) {
        const isOccupied = isBedOccupied(roomId, i);

        const div = document.createElement('div');
        div.className = `bed-item ${isOccupied ? 'bed-occupied' : 'bed-available'}`;

        div.innerHTML = `
            <div style="font-size: 28px; margin-bottom: 8px;">🛏️</div>
            <div style="font-weight: bold; font-size: 1.1em;">Bed ${i}</div>
            <div style="font-size: 0.9em; margin-top: 5px;">
                ${isOccupied ? 
                    '🔴 OCCUPIED' : 
                    '🟢 AVAILABLE'}
            </div>
        `;

        container.appendChild(div);
    }

    document.getElementById('bedModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('bedModal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('bedModal');
    if (event.target === modal) {
        closeModal();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    displayRooms();
});

function open360Viewer(startIndex = 0) {
  if (!panoramaImages || panoramaImages.length === 0) {
    alert('No panorama images available');
    return;
  }
  
  currentPanoramaIndex = startIndex;
  const viewer = document.getElementById('panoramaViewer');
  const navigation = document.getElementById('panoramaNavigation');

  navigation.innerHTML = '';
  if (panoramaImages.length > 1) {
    // Add previous/next buttons
    const prevBtn = document.createElement('button');
    prevBtn.className = 'panorama-nav-btn';
    prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i> Previous';
    prevBtn.onclick = () => switchPanorama(currentPanoramaIndex - 1);
    navigation.appendChild(prevBtn);
    
    // Add numbered buttons
    panoramaImages.forEach((image, index) => {
      const btn = document.createElement('button');
      btn.className = `panorama-nav-btn ${index === startIndex ? 'active' : ''}`;
      btn.textContent = `View ${index + 1}`;
      btn.onclick = () => switchPanorama(index);
      navigation.appendChild(btn);
    });
    
    const nextBtn = document.createElement('button');
    nextBtn.className = 'panorama-nav-btn';
    nextBtn.innerHTML = 'Next <i class="fas fa-chevron-right"></i>';
    nextBtn.onclick = () => switchPanorama(currentPanoramaIndex + 1);
    navigation.appendChild(nextBtn);
  }
  viewer.classList.add('active');
  viewer.style.display = 'block';
  
  // Ensure body doesn't scroll when viewer is open
  document.body.style.overflow = 'hidden';
  
  if (!isViewerInitialized) {
    setTimeout(() => {
      initializePannellum();
      loadPanorama(startIndex);
    }, 500);
  } else {
    loadPanorama(startIndex);
  }
}

function initializePannellum() {
  const container = document.getElementById('pannellumContainer');
  
  if (container && typeof pannellum !== 'undefined') {
    console.log('✅ Pannellum library loaded');
    isViewerInitialized = true;
  } else {
    console.warn('⚠️ Pannellum not ready, retrying...');
    setTimeout(initializePannellum, 500);
  }
}

function switchPanorama(index) {
  if (!panoramaImages || panoramaImages.length === 0) return;
  if (index < 0) index = panoramaImages.length - 1;
  if (index >= panoramaImages.length) index = 0;
  
  currentPanoramaIndex = index;
  loadPanorama(index);
  
  // Update navigation buttons
  const navButtons = document.querySelectorAll('.panorama-nav-btn');
  navButtons.forEach((btn, i) => {
    if (i > 0 && i <= panoramaImages.length) {
      btn.classList.toggle('active', i - 1 === index);
    }
  });
}

function loadPanorama(index) {
  if (!panoramaImages[index]) {
    console.error('[ERROR] Panorama image not found at index:', index);
    return;
  }

  // Construct absolute image path
  const imagePath = '../' + panoramaImages[index];
  console.log('🔄 Loading panorama:', imagePath);
  
  // Preload image first to ensure it exists
  const testImg = new Image();
  testImg.crossOrigin = 'anonymous';
  
  testImg.onload = function() {
    console.log('✅ Panorama image loaded successfully:', imagePath);
    
    try {
      // Destroy existing viewer if any
      if (pannellumViewer !== null) {
        pannellumViewer.destroy();
      }
      
      const container = document.getElementById('pannellumContainer');
      
      // Create Pannellum viewer with optimized settings for smooth panoramic viewing
      pannellumViewer = pannellum.viewer('pannellumContainer', {
        'type': 'equirectangular',
        'panorama': imagePath,
        'autoLoad': true,
        'autoRotate': -5,
        'autoRotateInactivityDelay': 3000,
        'mouseZoom': true,
        'touchZoom': true,
        'showFullscreenCtrl': false,
        'showZoomCtrl': false,
        'showControls': false,
        'keyboardZoom': true,
        'friction': 0.15,
        'orientationSensor': false,
        'minHfov': 30,
        'maxHfov': 120,
        'hfov': 100,
        'vOffset': 0,
        'fallback': function() {
          console.error('[ERROR] Failed to load panorama. Fallback triggered');
          alert('Failed to load panorama image.');
        }
      });
      
      console.log('✅ Pannellum viewer created successfully');
      
      // Enable smooth controls
      pannellumViewer.on('load', function() {
        console.log('✅ Pannellum viewer loaded and ready');
      });
      
    } catch (error) {
      console.error('[ERROR] Error setting up Pannellum viewer:', error);
    }
  };
  
  testImg.onerror = function() {
    console.error('[ERROR] Failed to load panorama image:', imagePath);
    alert('Failed to load panorama image. Please contact the owner.');
  };
  
  testImg.src = imagePath;
}

function close360Viewer() {
  const viewer = document.getElementById('panoramaViewer');
  if (document.fullscreenElement) {
    document.exitFullscreen().then(() => {
      closeViewerModal();
    }).catch(err => {
      console.error('Error exiting fullscreen:', err);
      closeViewerModal();
    });
  } else {
    closeViewerModal();
  }
}

function closeViewerModal() {
  const viewer = document.getElementById('panoramaViewer');
  
  // Destroy Pannellum viewer
  if (pannellumViewer !== null) {
    pannellumViewer.destroy();
    pannellumViewer = null;
  }
  
  viewer.classList.remove('active');
  viewer.style.display = 'none';
  document.body.style.overflow = '';
  isViewerInitialized = false;
}

function resetPanoramaView() {
  if (pannellumViewer !== null) {
    pannellumViewer.setPitch(0);
    pannellumViewer.setYaw(0);
    console.log('✅ View reset to center');
  }
}

function zoomPanoramaIn() {
  if (pannellumViewer !== null) {
    const currentHfov = pannellumViewer.getHfov();
    pannellumViewer.setHfov(Math.max(30, currentHfov - 10), 1000);
  }
}

function zoomPanoramaOut() {
  if (pannellumViewer !== null) {
    const currentHfov = pannellumViewer.getHfov();
    pannellumViewer.setHfov(Math.min(120, currentHfov + 10), 1000);
  }
}

function togglePanoramaFullscreen() {
  const viewer = document.getElementById('panoramaViewer');
  
  if (!document.fullscreenElement) {
    viewer.requestFullscreen().catch(err => {
      console.error('Error attempting to enable fullscreen:', err);
    });
  } else {
    document.exitFullscreen().catch(err => {
      console.error('Error attempting to exit fullscreen:', err);
    });
  }
}

document.addEventListener('fullscreenchange', function() {
  const viewer = document.getElementById('panoramaViewer');
  
  if (!document.fullscreenElement) {
    console.log('Exited fullscreen mode');
    viewer.style.pointerEvents = 'auto';
  } else {
    console.log('Entered fullscreen mode');
  }
});

// ✅ BED IMAGE MODAL FUNCTIONS
let bedImageModal = null;

function initBedImageModal() {
  // Create modal if it doesn't exist
  if (!bedImageModal) {
    bedImageModal = document.createElement('div');
    bedImageModal.id = 'bedImageModal';
    bedImageModal.className = 'bed-image-modal';
    bedImageModal.innerHTML = `
      <div class="bed-image-modal-content">
        <button class="bed-image-close" onclick="closeBedImageModal()">×</button>
        <img id="bedImagePreview" src="" alt="Bed Image" style="width: 100%; height: auto; max-height: 90vh; object-fit: contain; cursor: pointer;" onclick="closeBedImageModal()">
      </div>
    `;
    document.body.appendChild(bedImageModal);
  }
}

function openBedImageModal(imagePath) {
  initBedImageModal();
  
  const preview = document.getElementById('bedImagePreview');
  
  // imagePath from database is: uploads/bed_images/house_XXX_room_X_bed_X.jpg
  // Images are actually in: baordinghouseOWNER/uploads/bed_images/
  // From accommodationoverview folder: ../baordinghouseOWNER/uploads/bed_images/filename
  
  let fullPath = imagePath;
  
  if (imagePath.startsWith('uploads/')) {
    // Extract filename and construct path like book_house.php does
    const filename = imagePath.split('/').pop();
    fullPath = '../baordinghouseOWNER/uploads/bed_images/' + filename;
  } else if (!imagePath.startsWith('../') && !imagePath.startsWith('http')) {
    const filename = imagePath.split('/').pop();
    fullPath = '../baordinghouseOWNER/uploads/bed_images/' + filename;
  }
  
  preview.src = fullPath;
  preview.alt = 'Bed Image';
  
  // Show modal
  bedImageModal.classList.add('active');
  document.body.style.overflow = 'hidden';
  
  // Handle loading errors
  preview.onerror = function() {
    console.error('Failed to load image:', fullPath);
    this.src = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22500%22 height=%22400%22%3E%3Crect fill=%22%23ddd%22 width=%22500%22 height=%22400%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 font-family=%22Arial%22 font-size=%2220%22 fill=%22%23999%22%3EImage Not Found%3C/text%3E%3C/svg%3E';
  };
}

function closeBedImageModal() {
  if (bedImageModal) {
    bedImageModal.classList.remove('active');
    document.body.style.overflow = 'auto';
  }
}

// Close modal when clicking outside the image
document.addEventListener('click', function(event) {
  if (bedImageModal && event.target === bedImageModal) {
    closeBedImageModal();
  }
});

// Close modal on Escape key
document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape') {
    closeBedImageModal();
  }
});

// Initialize modal on page load
document.addEventListener('DOMContentLoaded', function() {
  initBedImageModal();
});
function changeMainImage(src, element) {
    // Change the main image source
    document.getElementById('mainImage').src = src;
    
    // Remove active border from all thumbnails
    const thumbnails = document.querySelectorAll('.thumbnail');
    thumbnails.forEach(thumb => {
        thumb.style.border = '2px solid #eee';
    });
    
    // Add yellow border to the clicked thumbnail
    element.style.border = '2px solid #FFFF00';
}

function openFullImage(src) {
    // This opens the image in a new tab for "Full View"
    window.open(src, '_blank');
}
</script>

</body>
</html>
