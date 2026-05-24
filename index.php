<?php
session_start();

$envPath = dirname(dirname(__FILE__)) . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}


// ✅ CLEAR SESSION FOR GUEST MODE
if (isset($_GET['logout']) || isset($_POST['logout'])) {
  session_unset();
  session_destroy();
  session_start();
}

include_once('connectiondatabase/main_connection.php');

$result = $conn->query("
    SELECT bh.*, 
           MIN(rp.price) as min_price, 
           MAX(rp.price) as max_price, 
           COUNT(rp.price) as price_count,
           COALESCE(bh.full_location, bh.purok, 'Not specified') as owner_address
    FROM boarding_houses bh 
    LEFT JOIN room_prices rp ON bh.id = rp.house_id 
    GROUP BY bh.id 
    ORDER BY bh.created_at DESC
");
$houses = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$locations_result = $conn->query("SELECT DISTINCT 
    CASE 
        WHEN full_location IS NOT NULL AND full_location != '' THEN full_location
        WHEN purok IS NOT NULL AND purok != '' THEN purok
        ELSE 'Unknown Location'
    END as location_name
    FROM boarding_houses 
    WHERE (full_location IS NOT NULL AND full_location != '') OR (purok IS NOT NULL AND purok != '')
    ORDER BY location_name ASC");
$unique_locations = $locations_result ? $locations_result->fetch_all(MYSQLI_ASSOC) : [];

// Separate houses with and without coordinates for statistics
$houses_with_coords = [];
$houses_without_coords = [];

foreach ($houses as $house) {
  if (!empty($house['map_lat']) && !empty($house['map_lng'])) {
    $houses_with_coords[] = $house;
  } else {
    $houses_without_coords[] = $house;
  }
}

// Get statistics with error handling
$totalHousesResult = $conn->query("SELECT COUNT(*) as count FROM boarding_houses");
$totalHouses = $totalHousesResult ? $totalHousesResult->fetch_assoc()['count'] : 0;

$totalTenantsResult = $conn->query("SELECT COUNT(*) as count FROM yourbook");
$totalTenants = $totalTenantsResult ? $totalTenantsResult->fetch_assoc()['count'] : 0;

$totalOwnersResult = $conn->query("SELECT COUNT(DISTINCT owner_id) as count FROM boarding_houses WHERE owner_id IS NOT NULL");
$totalOwners = $totalOwnersResult ? $totalOwnersResult->fetch_assoc()['count'] : 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>StayFinder - Find Your Perfect Boarding House</title>
  <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($_ENV['GOOGLE_MAPS_API_KEY'] ?? ''); ?>&libraries=places,directions&callback=initMainMap" async defer></script>
  <style>
/* BRUTE FORCE FIX: Use 100vw and 100vh for fixed positioning, ensure body is fixed */
html, body {
  margin: 0;
  padding: 0;
  overflow-x: hidden; /* prevent horizontal scrolling */
}

html.map-fullscreen-active {
  height: 100vh !important;
  overflow: hidden !important;
  position: fixed !important;
  width: 100vw !important;
}

body {
  font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
  background-color: #ffffff;
  padding-top: 0;
  margin: 0;
  padding: 0;
}

body.map-fullscreen-active {
  overflow: hidden !important;
  height: 100vh !important;
  width: 100vw !important;
  margin: 0 !important;
  padding: 0 !important;
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
}

.navbar-nav .nav-link,
.navbar .nav-link,
a.nav-link {
  color: #666 !important;
  font-weight: 500 !important;
  padding: 8px 16px !important;
  border-radius: 20px !important;
  transition: all 0.3s ease !important;
  white-space: nowrap !important;
  text-decoration: none !important;
  background-color: transparent !important;
  background-image: none !important;
  background: none !important;
  border: none !important;
}

.navbar-nav .nav-link:hover,
.navbar .nav-link:hover,
a.nav-link:hover,
.navbar-nav .nav-link:focus,
.navbar .nav-link:focus,
a.nav-link:focus {
  color: #000 !important;
  background-color: transparent !important;
  background-image: none !important;
  background: none !important;
  transform: translateY(-1px);
  border: none !important;
}

/* Sign up Tenant Button - ONLY THIS ONE IS YELLOW */
.navbar-nav .btn-register,
.btn-register,
a.btn-register {
  background: #ffd700 !important;
  background-color: #ffd700 !important;
  background-image: none !important;
  color: #000 !important;
  border: none !important;
  padding: 8px 25px !important;
  border-radius: 25px !important;
  font-weight: 600 !important;
  box-shadow: 0 2px 8px rgba(255, 215, 0, 0.3) !important;
  transition: all 0.3s ease !important;
  text-decoration: none !important;
  white-space: nowrap !important;
}

.navbar-nav .btn-register:hover,
.btn-register:hover,
a.btn-register:hover,
.navbar-nav .btn-register:focus,
.btn-register:focus,
a.btn-register:focus {
  background: #ffa500 !important;
  background-color: #ffa500 !important;
  background-image: none !important;
  color: #000 !important;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(255, 165, 0, 0.4) !important;
  border: none !important;
}

.location-control-btn,
button.location-control-btn,
a.location-control-btn,
.btn-primary[onclick*="showRouteToHouse"],
button.btn-primary[onclick*="showRouteToHouse"],
button[onclick*="showRouteToHouse"].btn-primary {
  background: linear-gradient(135deg, #4a90e2, #357abd) !important;
  background-color: #4a90e2 !important;
  background-image: linear-gradient(135deg, #4a90e2, #357abd) !important;
  border: none !important;
  border-color: transparent !important;
  border-radius: 8px !important;
  padding: 10px 16px !important;
  font-size: 13px !important;
  font-weight: 600 !important;
  color: white !important;
  cursor: pointer !important;
  transition: all 0.3s ease !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 8px !important;
  box-shadow: 0 2px 8px rgba(74, 144, 226, 0.3) !important;
  white-space: nowrap !important;
}

.location-control-btn:hover,
button.location-control-btn:hover,
a.location-control-btn:hover,
.btn-primary[onclick*="showRouteToHouse"]:hover,
button.btn-primary[onclick*="showRouteToHouse"]:hover,
button[onclick*="showRouteToHouse"].btn-primary:hover,
.btn-primary[onclick*="showRouteToHouse"]:focus,
button.btn-primary[onclick*="showRouteToHouse"]:focus {
  background: linear-gradient(135deg, #357abd, #2868a8) !important;
  background-color: #357abd !important;
  background-image: linear-gradient(135deg, #357abd, #2868a8) !important;
  color: white !important;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(74, 144, 226, 0.4) !important;
  border: none !important;
  border-color: transparent !important;
}

/* View Details Button - YELLOW GRADIENT */
.btn-custom,
button.btn-custom,
a.btn-custom {
  display: inline-block !important;
  padding: 8px 12px !important;
  border-radius: 8px !important;
  text-decoration: none !important;
  font-weight: 600 !important;
  font-size: 12px !important;
  text-align: center !important;
  transition: all 0.3s ease !important;
  border: none !important;
  cursor: pointer !important;
  background: linear-gradient(135deg, #f4c430, #e8b923) !important;
  background-color: #f4c430 !important;
  background-image: linear-gradient(135deg, #f4c430, #e8b923) !important;
  color: white !important;
}
/* Add this to your existing CSS in index.php */
.user-location-dot {
  width: 12px;
  height: 12px;
  background: #4285f4;
  border: 3px solid white;
  border-radius: 50%;
  box-shadow: 0 0 10px rgba(66, 133, 244, 0.8);
  animation: locationPulse 2s ease-in-out infinite;
}

@keyframes locationPulse {
  0%, 100% {
    box-shadow: 0 0 10px rgba(66, 133, 244, 0.8);
  }
  50% {
    box-shadow: 0 0 20px rgba(66, 133, 244, 1);
  }
}

.current-location-marker {
  z-index: 1000 !important;
}
.btn-custom:hover,
button.btn-custom:hover,
a.btn-custom:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(244, 196, 48, 0.4) !important;
  color: white !important;
  background: linear-gradient(135deg, #e8b923, #d4a615) !important;
  background-color: #e8b923 !important;
  border: none !important;
}

.btn-secondary-custom {
  background: linear-gradient(135deg, #ffd700, #ffa500) !important;
  background-color: #ffd700 !important;
  color: #000 !important;
}

.btn-secondary-custom:hover {
  box-shadow: 0 4px 12px rgba(255, 215, 0, 0.4) !important;
  color: #000 !important;
  background: linear-gradient(135deg, #ffa500, #ff8c00) !important;
}

/* ========================================
   HEADER STYLES
======================================== */

.header-gradient {
  background: rgba(255, 255, 255, 0.95);
  border-bottom: none;
  min-height: 80px;
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  width: 100%;
  z-index: 1050;
  backdrop-filter: blur(10px);
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
  transition: all 0.3s ease;
}

.header-gradient .container {
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 15px;
  width: 100%;
  max-width: 100%;
}

.navbar {
  width: 100%;
  padding: 0;
  min-height: 80px;
  display: flex !important;
  justify-content: space-between !important;
  align-items: center !important;
}
.route-info-box-container.active {
  display: block;
  min-height: 120px;
  animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.route-info-content {
  max-height: 300px;
  overflow-y: auto;
}
.navbar-brand {
  display: flex;
  align-items: center;
  color: #000 !important;
  font-weight: 700;
  font-size: 1.5rem;
  text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.1);
  gap: 12px;
  border: none !important;
  background: transparent !important;
  box-shadow: none !important;
  text-decoration: none !important;
  flex-shrink: 0;
  order: 1 !important;
}

.navbar-brand:hover {
  color: #000 !important;
}

.brand-text {
  font-size: 1.8rem;
  font-weight: 800;
  color: #000;
  letter-spacing: -0.5px;

}

.brand-text .stay {
  color: #000;
}

.brand-text .finder {
  color: #ffd700;

}

.navbar-brand img {
  max-height: 45px;
  width: auto;
  border-radius: 8px;
  object-fit: contain;
  box-shadow: none;
  border: none;
  background: transparent;
  transition: all 0.3s ease;
  flex-shrink: 0;
}

.navbar-brand img:hover {
  transform: scale(1.05);
}

.navbar-toggler {
  border: none !important;
  padding: 4px 8px;
  background: transparent !important;
  box-shadow: none !important;
  order: 3 !important;
  margin-left: auto !important;
}

.navbar-toggler:focus {
  box-shadow: none !important;
  border: none !important;
}

.navbar-toggler-icon {
  background-image: url("data:image/svg+xml;charset=utf8,%3Csvg viewBox='0 0 30 30' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath stroke='rgba%280, 0, 0, 0.7%29' stroke-width='2' stroke-linecap='round' stroke-miterlimit='10' d='M4 7h22M4 15h22M4 23h22'/%3E%3C/svg%3E");
  width: 24px;
  height: 24px;
}

.navbar-collapse {
  flex-grow: 0 !important;
  order: 2 !important;
  margin-left: auto !important;
  width: auto !important;
  justify-content: flex-end !important;
}

.navbar-nav {
  align-items: center !important;
  gap: 10px !important;
  flex-direction: row !important;
  margin-left: auto !important;
  justify-content: flex-end !important;
  width: auto !important;
}

@media (min-width: 992px) {
  .navbar-nav {
    display: flex !important;
    flex-direction: row !important;
    margin-left: auto !important;
    justify-content: flex-end !important;
    align-items: center !important;
    gap: 15px !important;
    width: auto !important;
  }

  .navbar-collapse {
    display: flex !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    width: auto !important;
    position: static !important;
    background: transparent !important;
    box-shadow: none !important;
    padding: 0 !important;
  }

  .nav-item {
    margin: 0 !important;
  }
}

.hero-section {
  background: url("img/background.png");
  background-size: cover;
  background-position: center bottom;
  background-repeat: no-repeat;
  min-height: 80vh;
  position: relative;
  overflow: hidden;
  background-color: #ffd700;
  width: 100%;
  left: 0;
  right: 0;
  box-sizing: border-box;
  margin-top: 80px;
}
/* Hero content spacing — responsive adjustments */
.hero-content {
  margin-top: 150px;
}

@media (max-width: 992px) {
  .hero-content {
    margin-top: 60px;
  }
}

@media (max-width: 576px) {
  .hero-content {
    margin-top: 20px;
  }
  .hero-pattern {
    display: none;
  }
  .search-btn {
    display: block;
    width: 100%;
    padding: 12px 18px;
    font-size: 1rem;
    box-sizing: border-box;
  }
  .main-title {
    font-size: 2rem;
  }
}
/* Enhanced user location dot styling */
.user-location-dot {
  width: 12px;
  height: 12px;
  background: #4285f4;
  border: 3px solid white;
  border-radius: 50%;
  box-shadow: 0 0 10px rgba(66, 133, 244, 0.8);
  animation: locationPulse 2s ease-in-out infinite;
}

@keyframes locationPulse {
  0%, 100% {
    box-shadow: 0 0 10px rgba(66, 133, 244, 0.8);
  }
  50% {
    box-shadow: 0 0 20px rgba(66, 133, 244, 1);
  }
}

.current-location-marker {
  z-index: 1000 !important;
}

/* Update route info icons to blue */
.route-info-item i {
  color: #4285f4 !important;
  font-size: 16px;
  width: 20px;
}
.hero-pattern {
  position: absolute;
  right: 0;
  top: 0;
  width: 60%;
  height: 100%;
  background-image: radial-gradient(circle, rgba(0, 0, 0, 0.1) 1px, transparent 1px);
  background-size: 20px 20px;
  opacity: 0.3;
}

.main-title {
  font-size: 3.5rem;
  font-weight: 700;
  color: #000;
  line-height: 1.2;
  margin-bottom: 2rem;
}

.search-btn {
  background: #ffd700 !important;
  color: #000 !important;
  border: none !important;
  padding: 15px 40px;
  border-radius: 50px;
  font-weight: 700;
  font-size: 1.1rem;
}

/* Remove hover effects completely */
.search-btn:hover {
  background: #ffd700 !important;
  color: #000 !important;
}

.description-text {
  font-size: 1.1rem;
  color: #333;
  line-height: 1.6;
  margin-bottom: 2rem;
}

.cta-text {
  font-size: 1.2rem;
  font-weight: 600;
  color: #000;
  margin-bottom: 2rem;
}

.map-section {
  background: #f8f9fa;
  padding: 60px 0;
}

/* Fixed map height and fullscreen support */
#mainMap {
  height: 800px;
  border-radius: 15px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
  width: 100%;
  position: relative;
  z-index: 1;
  overflow: hidden;
  border: none !important;
}

#mainMap.fullscreen-map {
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  width: 100vw !important;
  height: 100vh !important;
  border-radius: 0 !important;
  border: none !important;
  z-index: 9999 !important;
  margin: 0 !important;
  padding: 0 !important;
  max-height: none !important;
  max-width: none !important;
  height: 100vh !important;
}

.map-controls-container {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 15px;
  padding: 0 5px;
}
.btn-location {
  background: yellow !important;
  color: black !important; /* Changed from white to black */
  border: none !important;
  padding: 12px 20px !important;
  border-radius: 8px !important;
  font-weight: 600 !important;
  font-size: 14px !important;
  cursor: pointer !important;
  transition: all 0.3s ease !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 8px !important;
  box-shadow: 0 2px 8px rgba(74, 144, 226, 0.3) !important;
}
.btn-location:active {
  transform: translateY(0) !important;
}

.btn-location:disabled {
  opacity: 0.6 !important;
  cursor: not-allowed !important;
}

.map-fullscreen-active {
  overflow: hidden !important;
}

.fullscreen-close-btn {
  position: fixed !important;
  top: 20px !important;
  right: 20px !important;
  background: rgba(0, 0, 0, 0.7) !important;
  color: white !important;
  border: none !important;
  padding: 12px 20px !important;
  border-radius: 8px !important;
  font-weight: 600 !important;
  font-size: 14px !important;
  cursor: pointer !important;
  z-index: 10000 !important;
  transition: all 0.3s ease !important;
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
}

.fullscreen-close-btn:hover {
  background: rgba(0, 0, 0, 0.9) !important;
  transform: scale(1.05) !important;
}

.custom-popup {
  font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
}

.popup-header {
  background: linear-gradient(135deg, #ffd700, #ffa500);
  color: white;
  padding: 12px;
  margin: -10px -10px 12px -10px;
  border-radius: 8px 8px 0 0;
  font-weight: bold;
  font-size: 14px;
}

.popup-content {
  font-size: 12px;
}

.popup-content img {
  width: 100%;
  height: 80px !important;
  object-fit: cover;
  border-radius: 6px;
  margin-bottom: 8px;
  border: 2px solid #ddd;
}

.popup-price {
  background: linear-gradient(135deg, #ff8c00, #ff6347);
  color: white;
  padding: 6px 10px;
  border-radius: 15px;
  font-weight: bold;
  display: inline-block;
  margin: 8px 0;
  font-size: 12px;
}

.leaflet-popup-content-wrapper {
  max-width: 280px !important;
  padding: 10px !important;
}

.leaflet-popup-content {
  margin: 10px !important;
  max-width: 260px !important;
}
    
    .house-marker {
      background: #ffd700;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      border: none;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      color: white;
      font-weight: bold;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
      visibility: visible !important;
      opacity: 1 !important;
      z-index: 400 !important;
    }

    /* Hide markers completely when dimmed or hidden */
    .house-marker.hidden-marker,
    .house-marker.dimmed {
      display: none !important;
      visibility: hidden !important;
      opacity: 0 !important;
      pointer-events: none !important;
      z-index: -1 !important;
    }

    .house-marker.highlighted {
      background: #ffd700 !important;
      border: none !important;
      animation: pulse 2s infinite;
      box-shadow: 0 2px 12px rgba(255, 215, 0, 0.6) !important;
      z-index: 401 !important;
      display: flex !important;
      visibility: visible !important;
      opacity: 1 !important;
    }


@keyframes pulse {
  0%,
  100% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.1);
  }
}

/* Fixed height for route info box to prevent layout shift */
.route-info-box-container {
  display: none;
  margin-top: 20px;
  padding: 15px;
  background: #ffd700;
  border-radius: 12px;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
  width: 100%;
  min-height: 0;
  height: auto;
  overflow: hidden;
  transition: all 0.3s ease;
}

.route-info-box-container.active {
  display: block;
  min-height: 70px;
}

.route-info-box {
  background: #ffd700;
  border-radius: 12px;
  padding: 15px;
  color: #000;
}

.route-info-header {
  background: transparent;
  color: #000;
  padding: 0 0 12px 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 2px solid rgba(0, 0, 0, 0.1);
  margin-bottom: 12px;
}

.route-close-btn {
  background: rgba(0, 0, 0, 0.1) !important;
  border: none !important;
  color: #000 !important;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  cursor: pointer;
  transition: all 0.3s ease;
  font-weight: bold;
  font-size: 16px;
}

.route-close-btn:hover {
  background: rgba(0, 0, 0, 0.2) !important;
  transform: scale(1.1);
}

.route-info-content {
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
  color: #000;
}

.route-info-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px;
  background: rgba(0, 0, 0, 0.05);
  border-radius: 8px;
  color: #000;
}

.route-info-item i {
  color: #000;
  font-size: 16px;
  width: 20px;
}

.location-results-section {
  background: #fff;
  border-radius: 15px;
  box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
  padding: 30px;
  margin-top: 30px;
  display: none;
}

.location-results-header {
  display: flex;
  align-items: center;
  gap: 15px;
  margin-bottom: 25px;
  padding-bottom: 15px;
  border-bottom: 2px solid #ffd700;
  flex-wrap: wrap;
}

.location-results-header h3 {
  color: #000;
  font-weight: 700;
  margin: 0;
  flex: 1;
  min-width: 200px;
}

.location-badge {
  background: linear-gradient(135deg, #ffd700, #ffa500);
  color: #000;
  padding: 8px 16px;
  border-radius: 25px;
  font-weight: 600;
  font-size: 0.9rem;
  flex-shrink: 0;
}

.nearby-houses-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 20px;
  margin-top: 20px;
}

.nearby-house-card {
  border: none;
  border-radius: 15px;
  overflow: hidden;
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
  transition: all 0.3s ease;
  background: #fff;
  cursor: pointer;
}

.nearby-house-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
}

.nearby-card-image {
  height: 150px;
  background-size: cover;
  background-position: center;
  position: relative;
  overflow: hidden;
}

.nearby-card-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.nearby-price-badge {
  position: absolute;
  top: 10px;
  right: 10px;
  background: #ffd700;
  color: #000;
  padding: 8px 14px;
  border-radius: 18px;
  font-weight: 700;
  font-size: 1.05rem;
  box-shadow: 0 4px 12px rgba(0,0,0,0.12);
  z-index: 3;
}

.nearby-distance-badge {
  position: absolute;
  bottom: 10px;
  left: 10px;
  background: rgba(0, 0, 0, 0.7);
  color: #fff;
  padding: 4px 8px;
  border-radius: 10px;
  font-size: 0.7rem;
  font-weight: 600;
}

.features-section {
  background: #ffd700;
  padding: 60px 0;
}

.feature-card {
  background: rgba(255, 255, 255, 0.95);
  padding: 20px 15px;
  border-radius: 15px;
  text-align: center;
  transition: all 0.3s ease;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  height: 100%;
}

/* Ensure feature columns stretch to equal height */
.features-section .row {
  align-items: stretch;
}
.feature-icon {
  font-size: 3rem;
  margin-bottom: 20px;
}

.user-location-dot {
  width: 10px;
  height: 10px;
  background: #4a90e2;
  border: 3px solid white;
  border-radius: 50%;
  box-shadow: 0 0 8px rgba(74, 144, 226, 0.8);
  animation: locationPulse 2s ease-in-out infinite;
}

@keyframes locationPulse {
  0%,
  100% {
    box-shadow: 0 0 8px rgba(74, 144, 226, 0.8);
  }
  50% {
    box-shadow: 0 0 15px rgba(74, 144, 226, 1);
  }
}

@media (max-width: 991px) {
  .navbar-collapse {
    position: absolute !important;
    top: 100% !important;
    left: 0 !important;
    right: 0 !important;
    background: rgba(255, 255, 255, 0.98) !important;
    backdrop-filter: blur(10px) !important;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1) !important;
    border-radius: 0 0 15px 15px !important;
    padding: 20px !important;
  }

  .navbar-nav {
    flex-direction: column !important;
    width: 100% !important;
    gap: 15px !important;
    align-items: flex-end !important;
  }

  .nav-item {
    width: 100% !important;
    text-align: right !important;
  }

  .nav-link {
    text-align: right !important;
    width: 100% !important;
    display: block !important;
  }

  .btn-register {
    width: 100% !important;
    text-align: center !important;
    display: block !important;
  }
}

@media (max-width: 768px) {
  .header-gradient {
    min-height: 65px;
  }

  .main-title {
    font-size: 2.5rem;
  }

  .hero-section {
    margin-top: 65px;
    padding: 40px 0;
  }

  #mainMap {
    height: 600px;
  }

  .nearby-houses-grid {
    grid-template-columns: 1fr;
  }

  .fullscreen-close-btn {
    top: 10px !important;
    right: 10px !important;
    padding: 8px 12px !important;
    font-size: 12px !important;
  }

  .route-info-box-container {
    margin-top: 15px;
    padding: 12px;
  }
}

@media (max-width: 576px) {
  .main-title {
    font-size: 2rem;
  }

  #mainMap {
    height: 350px;
  }

  .feature-icon {
    font-size: 2rem;
  }

  .btn-location {
    padding: 8px 16px !important;
    font-size: 12px !important;
  }

  .route-info-box-container {
    margin-top: 15px;
    padding: 10px;
  }
}

    .navbar-nav .nav-link,
    .navbar .nav-link,
    a.nav-link {
      background: transparent !important;
      background-color: transparent !important;
      background-image: none !important;
      color: #666 !important;
    }

    .navbar-nav .nav-link:hover,
    .navbar .nav-link:hover {
      background: transparent !important;
      color: #000 !important;
    }

    .navbar-nav .btn-register,
    .btn-register,
    a.btn-register {
      background: transparent !important;
      background-color: transparent !important;
      background-image: none !important;
      color: #666 !important;
      border: 2px solid #666 !important;
      box-shadow: none !important;
    }

    .navbar-nav .btn-register:hover,
    .btn-register:hover,
    a.btn-register:hover {
      background: transparent !important;
      background-color: transparent !important;
      color: #000 !important;
      border-color: #000 !important;
      box-shadow: none !important;
    }

    /* Show Route Button - YELLOW GRADIENT */
    button[onclick*="showRouteToHouse"],
    .btn-primary[onclick*="showRouteToHouse"],
    button.btn-primary[onclick*="showRouteToHouse"] {
      background: linear-gradient(135deg, #ffd700, #ffa500) !important;
      background-color: #ffd700 !important;
      background-image: linear-gradient(135deg, #ffd700, #ffa500) !important;
      border-color: transparent !important;
      border: none !important;
      color: #000 !important;
      font-weight: 700 !important;
    }

    button[onclick*="showRouteToHouse"]:hover,
    .btn-primary[onclick*="showRouteToHouse"]:hover,
    button.btn-primary[onclick*="showRouteToHouse"]:hover {
      background: linear-gradient(135deg, #ffa500, #ff8c00) !important;
      background-color: #ffa500 !important;
      background-image: linear-gradient(135deg, #ffa500, #ff8c00) !important;
      border-color: transparent !important;
      color: #000 !important;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(255, 215, 0, 0.4) !important;
    }

    /* View Details button - Keep yellow */
    .btn-custom,
    button.btn-custom,
    a.btn-custom {
      background: linear-gradient(135deg, #f4c430, #e8b923) !important;
      background-color: #f4c430 !important;
      background-image: linear-gradient(135deg, #f4c430, #e8b923) !important;
      border-color: transparent !important;
      border: none !important;
      color: white !important;
    }

    .btn-custom:hover,
    button.btn-custom:hover,
    a.btn-custom:hover {
      background: linear-gradient(135deg, #e8b923, #d4a615) !important;
      background-color: #e8b923 !important;
      background-image: linear-gradient(135deg, #e8b923, #d4a615) !important;
      border-color: transparent !important;
      color: white !important;
    }

    /* Added styles for search results text */
    #searchResults {
      margin-top: 15px;
      padding: 10px 0;
      text-align: center;
      font-weight: 500;
      color: #666;
      min-height: 20px;
    }
  </style>
</head>

<body>

  <header class="header-gradient">
    <div class="container">
      <nav class="navbar navbar-expand-lg w-100">
        
        <a href="#" class="navbar-brand text-decoration-none">
          <img src="img/wy2.png" alt="StayFinder Logo">
          <div class="brand-text">
            <span class="stay">Stay</span><span class="finder">Finder</span>
          </div>
        </a>

        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mobileNav" 
                aria-controls="mobileNav" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>

         
        <div class="collapse navbar-collapse" id="mobileNav">
          <ul class="navbar-nav">
            <li class="nav-item">
              <a href="intendeduserlogin.php" class="nav-link">Login</a>
            </li>
            <li class="nav-item">
              <a href="intendeduser.php" class="btn btn-register">Sign up </a>
            </li>
          </ul>
        </div>

      </nav>
    </div>
  </header>
<section class="hero-section">
    <div class="hero-pattern"></div>
    
    <div class="container h-100">
        <div class="row h-100 align-items-center hero-content">
            <div class="col-12 col-lg-6">
                <h1 class="main-title">Where Comfort,<br>Meets Convenience</h1>
                
                <button class="search-btn" onclick="scrollToMap()">
                    Search for Boarding House
                </button>
                
                <br><br>
                <p class="description-text">
                    StayFinder helps you find the perfect boarding house for work, study, or short stays. With comfortable, convenient options to fit your needs and budget, finding a home away from home has never been easier.
                </p>
                
                <p class="cta-text mt-4">
                    Reserve your stay with StayFinder today!
                </p>
            </div>
        </div>
    </div>
</section>

  <section class="map-section" id="mapSection">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="fw-bold text-dark mb-3">Find the perfect boarding house near your work, school, or wherever you need to be!</h2>
        
        <div class="row justify-content-center mb-4">
          <div class="col-12 col-lg-8">
            <div class="input-group input-group-lg">
              <span class="input-group-text bg-white border-end-0">
                <i class="fas fa-search text-muted"></i>
              </span>
              
              <input 
                type="text" 
                class="form-control border-start-0 ps-0" 
                id="searchInput"
                placeholder="Search Google Map">
              <button class="btn btn-warning px-4 text-dark fw-bold" type="button" id="clearSearch">
                <i class="fas fa-times"></i>
              </button>
            </div>
            
             
            <div class="mt-2 text-center">
              <small class="text-muted">
              </small>
              <br>
              <!-- Added search results display -->
              <div id="searchResults" style="margin-top: 15px; padding: 10px 0; text-align: center; font-weight: 500; color: #666;"></div>
            </div>
          </div>
        </div>
      </div>

      <div id="mainMap" class="mb-4"></div>

      <!-- Fullscreen button with proper ID and onclick handler -->
      <div class="map-controls-container">
        <button id="useMyLocationButton" class="btn-location" onclick="useMyLocation()" title="Use your current location">
          <i class="fas fa-crosshairs"></i> Use My Location
        </button>
      </div>

      <!-- Add route info box container below the map (not inside it) -->
      <div id="routeInfoBoxContainer" class="route-info-box-container"></div>

      <!-- BOARDING HOUSE RESULTS SECTION - Shows cards with View Details button -->
      <div id="locationResultsSection" class="location-results-section" style="display: none;">
        <div class="location-results-header">
          <i class="fas fa-map-marker-alt text-warning" style="font-size: 1.5rem;"></i>
          <h3 id="locationResultsTitle">Boarding Houses Nearby</h3>
          <div class="location-badge" id="locationBadge">Location</div>
        </div>
        <!-- Single price filter placed inside the Boarding Houses near container as requested -->
        <div class="d-flex align-items-center gap-2 flex-wrap" style="margin-top:10px;">
          <div class="d-flex align-items-center" style="gap:8px;">
            <input type="number" id="priceInput" class="form-control" placeholder="Your budget (₱)" min="0" style="width:220px; font-size:1.05rem; padding:10px 12px;">
            <button id="applyPriceFilter" class="btn btn-outline-warning" style="padding:8px 12px; font-weight:600;">Apply</button>
          </div>
          <div class="text-muted" style="font-size:0.98rem; color:#444;">Enter a single price to show boarding houses with rooms at or below your budget.</div>
        </div>

        <div id="nearbyHousesGrid" class="nearby-houses-grid">
          <!-- Dynamic boarding house cards will be inserted here by script.js -->
        </div>
      </div>
    </div>
  </section>

  <section class="features-section">
    <div class="container">
      <div class="row g-4">
        <div class="col-12 col-md-4">
          <div class="feature-card">
            <div class="feature-icon">🏠</div>
            <h5 class="fw-bold mb-3">Quality Housing</h5>
            <p class="text-muted">Verified boarding houses with complete amenities</p>
          </div>
        </div>
        <div class="col-12 col-md-4">
          <div class="feature-card">
            <div class="feature-icon">💰</div>
            <h5 class="fw-bold mb-3">Affordable Prices</h5>
            <p class="text-muted">Compare prices and find budget-friendly options</p>
          </div>
        </div>
        <div class="col-12 col-md-4">
          <div class="feature-card">
            <div class="feature-icon">🔒</div>
            <h5 class="fw-bold mb-3">Safe & Secure</h5>
            <p class="text-muted">All listings verified for safety and security</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <footer class="footer" style="background-color:#111;color:#fff;padding:10px 0;text-align:center;">
    <div class="container">
      <div class="row">
        <div class="col-12">
          <p class="mb-0" style="font-size:13px;">
            StayFinder: Boarding Locator and Management System
          </p>
          <p class="mt-1 mb-0" style="font-size:13px;">
            <a href="terms.php" class="text-warning text-decoration-none fw-bold">Terms &amp; Conditions</a>
          </p>
 
        </div>
      </div>
    </div>
  </footer>

  <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  
<script>
    window.STAYFINDER_DATA = {
      boardingHouses: <?php echo json_encode(array_map(function($house) {
          return [
              'id' => (int)$house['id'],
              'name' => $house['name'],
              'map_lat' => $house['map_lat'],
              'map_lng' => $house['map_lng'],
              'owner_address' => $house['owner_address'] ?? $house['full_location'] ?? '',
              'purok' => $house['purok'] ?? '',
              'owner_contact' => $house['owner_phone'] ?? '',
              'images' => $house['images'] ?? '',
              'description' => $house['description'] ?? '',
              'min_price' => $house['min_price'] ?? null,
              'max_price' => $house['max_price'] ?? null,
              'owner_fullname' => '',
              'owner_email' => $house['owner_email'] ?? '',
              'all_amenities' => ''
          ];
      }, $houses_with_coords)); ?>,
      ustpCoords: [8.359995345948724, 123.84327331569628],
      totalCount: <?php echo count($houses_with_coords); ?>
    };
    console.log('[StayFinder] Data passed to window:', window.STAYFINDER_DATA);
</script>
  <script>
    // Smooth scroll to the search bar / map area and focus the input
    function scrollToMap() {
      try {
        const el = document.getElementById('searchInput');
        if (!el) return;
        // Account for fixed header height so the input isn't hidden
        const header = document.querySelector('.header-gradient');
        const headerOffset = header ? header.offsetHeight : 80;
        const elementPosition = el.getBoundingClientRect().top + window.pageYOffset;
        const offsetPosition = Math.max(elementPosition - headerOffset - 20, 0);
        window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
        // Focus after a short delay so mobile browsers open keyboard reliably
        setTimeout(() => { el.focus({ preventScroll: true }); }, 600);
      } catch (e) {
        console.error('scrollToMap error', e);
      }
    }
  </script>
  <script src="script.js?v=<?php echo time(); ?>"></script>

</body>

</html>
