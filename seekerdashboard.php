<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

require_once 'connectiondatabase/main_connection.php';

// Check if user is logged in
if (!isset($_SESSION['email'])) {
    header("Location: ../auseregisterlogform/loginseeker.php");
    exit();
}

$session_email = $_SESSION['email'];

// Fetch user information
$user_sql = "SELECT id, fullname, profile_img FROM registerusers WHERE email = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("s", $session_email);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();
$user_stmt->close();

if (!$user) {
    header("Location: ../auseregisterlogform/loginseeker.php");
    exit();
}
$user_id = $user['id'];
$user_name = $user['fullname'] ?? 'User';
$user_profile_img = $user['profile_img'] ?? null;

// Fetch all bookings for this user
// Fetch all bookings for this user
$bookings_sql = "SELECT * FROM yourbook WHERE TRIM(email) = TRIM(?) ORDER BY id DESC";
$bookings_stmt = $conn->prepare($bookings_sql);
$bookings_stmt->bind_param("s", $session_email);
$bookings_stmt->execute();
$bookings_result = $bookings_stmt->get_result();

$bookings = [];
while ($row = $bookings_result->fetch_assoc()) {
    $bookings[] = $row;
}
$bookings_stmt->close();

// DEBUG: Check if user has any confirmed bookings
$confirmed_sql = "SELECT COUNT(*) as confirmed_count 
                  FROM tenant_requests 
                  WHERE email = ? AND status = 'accepted'";
$confirmed_stmt = $conn->prepare($confirmed_sql);
$confirmed_stmt->bind_param("s", $session_email);
$confirmed_stmt->execute();
$confirmed_result = $confirmed_stmt->get_result();
$confirmed_data = $confirmed_result->fetch_assoc();

$hasConfirmedBooking = ($confirmed_data['confirmed_count'] > 0);
$confirmed_stmt->close();

// DEBUG: Let's see what's happening
// echo "";
// echo "";
// echo "";

// DEBUG: Let's also check what's actually in the tenant_requests table
// $debug_sql = "SELECT id, house_id, status, full_name, email FROM tenant_requests WHERE email = ?";
// $debug_stmt = $conn->prepare($debug_sql);
// $debug_stmt->bind_param("s", $session_email);
// $debug_stmt->execute();
// $debug_result = $debug_stmt->get_result();
// while ($debug_row = $debug_result->fetch_assoc()) {
//     echo "";
// }
// $debug_stmt->close();

// --- NEW: Fetch user's favorite houses IDs ---
$favorites_sql = "SELECT house_id FROM favorites WHERE user_email = ?";
$favorites_stmt = $conn->prepare($favorites_sql);
$favorites_stmt->bind_param("s", $session_email);
$favorites_stmt->execute();
$favorites_result = $favorites_stmt->get_result();
$favorite_house_ids = [];
while ($row = $favorites_result->fetch_assoc()) {
    $favorite_house_ids[] = $row['house_id'];
}
$favorites_stmt->close();
// ---------------------------------------------


// Fetch all available boarding houses with coordinates and ratings
$houses_sql = "
    SELECT bh.*,
           COALESCE(AVG(hr.rating), 0) as avg_rating,
           COUNT(hr.rating) as total_ratings,
           COALESCE(price_stats.min_price, NULL) as min_price,
           COALESCE(price_stats.max_price, NULL) as max_price
    FROM boarding_houses bh
    LEFT JOIN house_ratings hr ON bh.id = hr.house_id
    LEFT JOIN (
        SELECT house_id, MIN(price) as min_price, MAX(price) as max_price
        FROM room_prices
        WHERE price IS NOT NULL AND price > 0
        GROUP BY house_id
    ) price_stats ON bh.id = price_stats.house_id
    WHERE bh.status = 'approved' AND bh.map_lat IS NOT NULL AND bh.map_lng IS NOT NULL
    GROUP BY bh.id
    ORDER BY bh.created_at DESC
";

$houses_result = $conn->query($houses_sql);
$all_houses = $houses_result ? $houses_result->fetch_all(MYSQLI_ASSOC) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seeker Dashboard - StayFinder</title>
    <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
  <link rel="icon" href="../img/stayfinder.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #ffffff 0%, #f9f9f9 100%);
            color: #000;
            min-height: 100vh; 
            display: flex;
            flex-direction: column;
        }

        /* --- NEW HEADER STYLE --- */
        .page-header {
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            color: #000;
            padding: 20px 30px;
            text-align: center;
            font-size: 28px;
            font-weight: 800;
            box-shadow: 0 8px 16px rgba(255, 215, 0, 0.3);
            letter-spacing: 2px;
        }
        /* --- END NEW HEADER STYLE --- */

        .dashboard-wrapper {
            display: flex;
            min-height: calc(100vh - 50px - 55px);
            flex: 1;
        }
        
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #333333 0%, #555555 100%);
            color: white;
            padding: 30px 20px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 4px 0 12px rgba(255, 215, 0, 0.2);
        }

        .sidebar-profile {
            position: relative;
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #FFD700;
            padding-bottom: 20px;
            padding-top: 30px;
        }

        .profile-img {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #FFD700;
            margin-bottom: 12px;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
        }

        .profile-name {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 5px;
            color: #FFD700;
        }

        .profile-email {
            font-size: 12px;
            opacity: 0.85;
            word-break: break-word;
            color: #ccc;
        }

        .notification-bell {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #FFD700;
            color: #000;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            border: 3px solid #000;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.4);
            transition: all 0.3s ease;
        }

        .notification-bell:hover {
            box-shadow: 0 6px 16px rgba(255, 215, 0, 0.5);
        }

        .sidebar-menu {
            list-style: none;
            margin-bottom: 30px;
        }

        .sidebar-menu li {
            margin-bottom: 15px;
        }

        .sidebar-menu a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
            border-left: 3px solid transparent;
        }

        .sidebar-menu a:hover {
            background: rgba(255, 215, 0, 0.15);
            transform: translateX(8px);
            border-left-color: #FFD700;
            color: #FFD700;
        }

        .sidebar-menu i {
            width: 20px;
            text-align: center;
        }

        .sidebar-logout {
            border-top: 3px solid #FFD700;
            padding-top: 20px;
        }

        .logout-btn {
            width: 100%;
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            color: #000;
            border: none;
            padding: 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
        }

        .logout-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(255, 215, 0, 0.4);
        }

        .main-content {
            flex: 1;
            padding: 30px;
            background: linear-gradient(135deg, #ffffff 0%, #f5f5f5 100%);
        }

        .header {
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 8px 20px rgba(255, 215, 0, 0.25);
        }

        .header-title {
            font-size: 28px;
            font-weight: 800;
            color: #000;
            letter-spacing: 0.5px;
        }

        .map-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
            border: 3px solid #FFD700;
        }

        .map-section-title {
            font-size: 20px;
            font-weight: 800;
            color: #000;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            letter-spacing: 0.5px;
        }

        .search-container {
            margin-bottom: 18px;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 14px 16px;
            border: 3px solid #FFD700;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            transition: all 0.3s ease;
            background: white;
            color: #000;
        }

        .search-input:focus {
            outline: none;
            border-color: #000;
            box-shadow: 0 0 12px rgba(255, 215, 0, 0.4);
            transform: scale(1.02);
        }

        #map {
            width: 100%;
            height: 600px;
            border-radius: 8px;
            overflow: hidden;
            border: 3px solid #FFD700;
        }

        .location-button-container {
            margin-top: 18px;
            display: flex;
            justify-content: flex-end;
        }

        #useMyLocationButton {
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            color: #000;
            border: 2px solid #000;
            padding: 14px 24px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 6px 16px rgba(255, 215, 0, 0.3);
            width: 250px;
            justify-content: center;
        }

        #useMyLocationButton:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(255, 215, 0, 0.4);
        }

        .location-filter-display {
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 18px;
            display: none;
            align-items: center;
            justify-content: space-between;
            border-left: 6px solid #000;
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.25);
        }

        .location-filter-display.active {
            display: flex;
        }

        .location-filter-text {
            font-size: 15px;
            color: #000;
            font-weight: 700;
        }

        .reset-location-btn {
            background: #000;
            color: #FFD700;
            border: 2px solid #FFD700;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .reset-location-btn:hover {
            background: #FFD700;
            color: #000;
            transform: scale(1.05);
        }

        .houses-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
            border: 3px solid #FFD700;
        }

        .section-title {
            font-size: 20px;
            font-weight: 800;
            color: #000;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            letter-spacing: 0.5px;
        }

        .houses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-top: 20px;
        }

        .house-card {
            border: 3px solid #FFD700;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            background: white;
            cursor: pointer;
            position: relative;
        }

        .house-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 28px rgba(255, 215, 0, 0.25);
            border-color: #000;
        }
        
        .favorite-btn {
            position: absolute;
            top: 12px;
            left: 12px;
            background: rgba(255, 255, 255, 0.95);
            color: #ccc;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 3px solid #FFD700;
            z-index: 10;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        .favorite-btn.is-favorite {
            color: #FFD700;
            border-color: #000;
            background: #fff;
        }

        .favorite-btn:hover {
            color: #FFD700;
            border-color: #000;
            transform: scale(1.15);
            background: #fff;
        }

        .house-card-image {
            height: 220px;
            background-size: cover;
            background-position: center;
            position: relative;
            overflow: hidden;
        }

        .house-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .house-price-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: #ffffff; /* Make price badge white */
            color: #000000; /* Price text black */
            padding: 12px 20px; /* increased padding for larger badge */
            border-radius: 24px;
            font-weight: 900;
            font-size: 18px; /* larger price text */
            z-index: 5;
            border: none; /* Remove yellow ring */
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.09);
        }

        .house-card-content {
            padding: 18px;
        }

        .house-card-name {
            font-size: 17px;
            font-weight: 800;
            color: #000;
            margin-bottom: 10px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .house-card-location {
            font-size: 13px;
            color: #666;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }

        .house-card-footer {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 2px solid #FFD700;
        }

        .house-rating {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            color: #FFD700;
            font-weight: 700;
        }

        .house-card-footer-buttons { 
            display: flex;
            gap: 10px;
        }

        .view-btn {
            background: #ffffff; /* Make View Details button white */
            color: #000000; /* Button text black */
            border: 1px solid rgba(0,0,0,0.08);
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            flex: 1;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }

        .view-btn:hover {
            background: #f6f6f6;
            color: #000;
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(0,0,0,0.08);
        }
        
        .reserve-btn {
            background: linear-gradient(135deg, #FFFF00 0%, #FFD700 100%);
            color: #333;
            border: 2px solid #FFD700;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            flex: 1;
            text-align: center;
        }

        .reserve-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
        }

        .no-houses-message {
            text-align: center;
            padding: 50px 30px;
            color: #999;
        }

        .no-houses-message i {
            font-size: 56px;
            margin-bottom: 18px;
            opacity: 0.6;
            color: #FFD700;
        }

        .no-houses-message p {
            font-size: 17px;
            margin: 0;
            font-weight: 500;
        }

        .custom-popup {
            font-family: 'Poppins', sans-serif;
            padding: 12px;
            background: white;
            border-radius: 8px;
        }

        .popup-header {
            font-weight: 800;
            font-size: 15px;
            margin-bottom: 12px;
            color: #000;
            border-bottom: 3px solid #FFD700;
            padding-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .popup-favorite-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 20px;
            color: #ccc;
            transition: all 0.3s ease;
            padding: 0;
        }
        
        .popup-favorite-btn.is-favorite {
            color: #FFD700;
        }

        .popup-favorite-btn:hover {
            color: #FFD700;
            transform: scale(1.2);
        }

        .popup-content {
            font-size: 12px;
        }

        .popup-price {
            color: #FFD700;
            font-weight: 800;
            margin: 10px 0;
            font-size: 14px;
        }

        .btn-custom {
            background: linear-gradient(135deg, #FFFF00 0%, #FFD700 100%);
            color: #333;
            border: 2px solid #FFD700;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.3s ease;
        }

        .btn-custom:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
        }

        .btn-secondary-custom {
            background: #444444;
            color: #FFD700;
            border: 2px solid #FFD700;
        }

        .btn-secondary-custom:hover {
            background: #FFD700;
            color: #000;
        }

        .page-footer {
            background-color: #111; /* Black footer like index.php */
            color: #fff;
            padding: 12px 0;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            margin-top: auto;
            width: 100%;
            letter-spacing: 0.5px;
        }

        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #FFD700;
            color: #000;
            padding: 14px 22px;
            border-radius: 8px;
            font-weight: 700;
            z-index: 9999;
            transform: translateX(120%);
            transition: transform 0.3s ease-out;
            box-shadow: 0 6px 16px rgba(255, 215, 0, 0.35);
            border: 2px solid #000;
        }

        .toast-notification.show {
            transform: translateX(0);
        }

        .toast-notification.success {
            background: #FFD700;
            color: #000;
        }

        .toast-notification.removed {
            background: #000;
            color: #FFD700;
            border: 2px solid #FFD700;
        }

        @media (max-width: 768px) {
            .dashboard-wrapper {
                flex-direction: column;
                min-height: calc(100vh - 50px - 45px);
            }

            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                padding: 20px;
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .header-title {
                font-size: 22px;
            }

            #map {
                height: 400px;
            }

            .houses-grid {
                grid-template-columns: 1fr;
            }

            .location-button-container {
                justify-content: center;
            }
            
            #useMyLocationButton {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    
    <header class="page-header">
   SEEKER DASHBOARD
    </header>
    <div class="dashboard-wrapper">
        <div class="sidebar">
                      <div class="sidebar-profile">
                <img src="<?php echo htmlspecialchars($user_profile_img) ?: 'https://via.placeholder.com/80'; ?>" alt="Profile" class="profile-img">
                  <a href="notif.php" class="notification-bell">
        <i class="fas fa-bell"></i>
    </a>
                <div class="profile-name"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="profile-email"><?php echo htmlspecialchars($session_email); ?></div>
            </div>
               <ul class="sidebar-menu">
                <li>
                    <a href="profile.php">
                        <i class="fas fa-user"></i>
                        Account Info
                    </a>
                </li>
                <li>
                    <a href="yourbook.php">
                        <i class="fas fa-calendar-check"></i>
                        Your Bookings
                    </a>
                </li>
                <li>
                    <a href="favorites.php">
                        <i class="fas fa-heart"></i>
                        My Wishlist
                    </a>
                </li>
                <?php if ($hasConfirmedBooking): ?>
                <li>
                    <a href="dashboardprofile.php?email=<?php echo urlencode($session_email); ?>">
                        <i class="fas fa-door-open"></i>
                        Access Renter Portal
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <div class="sidebar-logout">
                <button class="logout-btn" onclick="window.location.href='index.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>

        <div class="main-content">
            <div class="header">

            </div>

            <div class="map-section">
                <div class="map-section-title">📍 Find Boarding Houses Near You</div>
                <div class="search-container">
                    <input 
                        type="text" 
                        id="searchInput" 
                        class="search-input" 
                        placeholder="Search Google" 
                        autocomplete="off">
                </div>
                <div id="locationFilterDisplay" class="location-filter-display">
                    <span class="location-filter-text">
                        <i class="fas fa-check-circle"></i>
                        <strong id="searchedLocation">Searching...</strong>
                    </span>
                    <button class="reset-location-btn" onclick="resetLocationFilter()">
                        <i class="fas fa-times"></i> Reset
                    </button>
                </div>
                <div id="map"></div>

                                <!-- Show My Location Button (bottom right under map) -->
                                <div style="display: flex; justify-content: flex-end; margin-top: 10px;">
                                    <button id="useMyLocationButton" class="btn-location" onclick="useMyLocation()" title="Show My Location">
                                        <i class="fas fa-crosshairs"></i> Show My Location
                                    </button>
                                </div>
                                </div>

            <div class="houses-section">
                <div class="section-title" id="my-favorites">🏘️ All Available Boarding Houses</div>
                <div id="housesContainer" class="houses-grid">
                    <div class="no-houses-message">
                        <i class="fas fa-map-marker-alt"></i>
                        <p>Search for a location on the map or use your current location to see available boarding houses nearby</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <footer class="page-footer">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <p class="mb-0" style="font-size:13px;">StayFinder: Boarding Locator and Management System</p>
                    <p class="mt-1 mb-0" style="font-size:13px;"><a href="terms.php" class="text-decoration-none fw-bold" style="color: #FFD700;">Terms &amp; Conditions</a></p>
                </div>
            </div>
        </div>
    </footer>
    <div id="toastNotification" class="toast-notification"></div>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($_ENV['GOOGLE_MAPS_API_KEY'] ?? ''); ?>&libraries=places&callback=initMap" async defer></script>
    <script>
        window.STAYFINDER_DATA = {
            boardingHouses: <?php echo json_encode($all_houses); ?>,
            defaultCenter: { lat: 8.359995345948724, lng: 123.84327331569628 },
            // --- NEW DATA ---
            userEmail: "<?php echo htmlspecialchars($session_email); ?>", 
            favoriteHouseIds: [<?php echo implode(', ', $favorite_house_ids); ?>]
            // ----------------
        };

        let map;
        let searchBox;
        let allMarkers = [];
        let visibleHouses = [];
        let lastSearchedLocation = null;
        let userLocation = null; 
        let userLocationMarker = null; 
                        // Show My Location logic
                        function useMyLocation() {
                            if (!navigator.geolocation) {
                                alert('Geolocation is not supported by your browser.');
                                return;
                            }
                            document.getElementById('useMyLocationButton').disabled = true;
                            navigator.geolocation.getCurrentPosition(function(position) {
                                const lat = position.coords.latitude;
                                const lng = position.coords.longitude;
                                userLocation = { lat, lng };
                                if (userLocationMarker) {
                                    userLocationMarker.setMap(null);
                                }
                                userLocationMarker = new google.maps.Marker({
                                    position: userLocation,
                                    map: map,
                                    icon: {
                                        path: google.maps.SymbolPath.CIRCLE,
                                        scale: 10,
                                        fillColor: '#4285f4',
                                        fillOpacity: 1,
                                        strokeWeight: 3,
                                        strokeColor: '#fff',
                                    },
                                    title: 'Your Location',
                                    zIndex: 1000
                                });
                                map.panTo(userLocation);
                                document.getElementById('useMyLocationButton').disabled = false;
                            }, function(error) {
                                alert('Unable to retrieve your location.');
                                document.getElementById('useMyLocationButton').disabled = false;
                            });
                        }
        let activeRoutePolyline = null; 
        const SEARCH_RADIUS_KM = 5; 
        
        // Helper function to check if a house is a favorite
        function isFavorite(houseId) {
            return window.STAYFINDER_DATA.favoriteHouseIds.includes(parseInt(houseId));
        }


        function createHouseMarker(house) {
            const lat = Number.parseFloat(house.map_lat);
            const lng = Number.parseFloat(house.map_lng);
            if (isNaN(lat) || isNaN(lng)) return null;

            const position = { lat: lat, lng: lng };
            const houseMarker = new google.maps.Marker({
                position: position,
                map: map,
                icon: {
                    url: 'img/icons/house_9408891.png',
                    scaledSize: new google.maps.Size(40, 40),
                    anchor: new google.maps.Point(20, 40)
                },
                title: house.name
            });

            // Prepare InfoWindow Content
            const images = house.images ? house.images.split(",") : [];
            const firstImage = images.length > 0 ? images[0].trim() : "img/default.jpg";

            let popupPriceDisplay = "";
            if (house.min_price && house.max_price) {
                const minPrice = Number.parseFloat(house.min_price);
                const maxPrice = Number.parseFloat(house.max_price);

                if (minPrice > 0 && maxPrice > 0) {
                    if (minPrice === maxPrice) {
                        popupPriceDisplay = `💰 ₱${minPrice.toLocaleString()}`;
                    } else {
                        popupPriceDisplay = `💰 ₱${minPrice.toLocaleString()} - ₱${maxPrice.toLocaleString()}`;
                    }
                } else {
                    popupPriceDisplay = `💰 Contact Owner for Price`;
                }
            } else {
                popupPriceDisplay = `💰 Contact Owner for Price`;
            }

            const popupLocationDisplay = house.full_location
                ? house.full_location
                : house.purok || house.owner_address || "Contact for exact location";
                
            const isFav = isFavorite(house.id);
            const favoriteClass = isFav ? 'is-favorite' : '';
            const favoriteIcon = isFav ? 'fas fa-heart' : 'far fa-heart';


            const infoWindowContent = `
                <div class="custom-popup">
                    <div class="popup-header">
                        <i class="fas fa-home" style="margin-right: 8px;"></i>
                        ${house.name}
                        <button class="popup-favorite-btn ${favoriteClass}" data-house-id="${house.id}" onclick="toggleFavorite(this, ${house.id}, true)">
                            <i class="${favoriteIcon}"></i>
                        </button>
                        </div>
                    <div class="popup-content">
                        <img src="${firstImage}" alt="${house.name}" style="width: 100%; height: 120px; object-fit: cover; border-radius: 8px; margin-bottom: 10px; border: 2px solid #ddd;" onerror="this.src='img/default.jpg'">
                        <div class="popup-price">${popupPriceDisplay}</div>
                        <div style="font-size: 11px; margin-top: 6px;">
                            ${house.owner_email || house.owner_contact
                                ? `${house.owner_email ? `<div><i class="fas fa-envelope"></i> <strong>Email:</strong> <a href="mailto:${house.owner_email}">${house.owner_email}</a></div>` : ""}${house.owner_contact ? `<div><i class="fas fa-phone"></i> <strong>Phone:</strong> <a href="tel:${house.owner_contact}">${house.owner_contact}</a></div>` : ""}`
                                : `<div style="color:#888; font-style:italic;">Owner contact details not available.</div>`
                            }
                        </div>
                        <div style="background: #fff3cd; padding: 6px; border-radius: 4px; margin: 6px 0; font-size: 10px;">
                            <p style="margin: 1px 0;"><strong>📍 Address:</strong> ${popupLocationDisplay}</p>
                        </div>
                    <div style="text-align: center; margin-top: 6px; display: flex; gap: 5px;">
    <a href="accommodationoverview/view_house_details.php?id=${house.id}" class="btn-custom btn-secondary-custom" style="flex: 1;">
        <i class="fas fa-eye"></i> View Details
    </a>
    <a href="bookform/book_house.php?house_id=${house.id}" class="btn-custom" style="flex: 1; background: linear-gradient(135deg, #FFFF00 0%, #FFD700 100%); color: #000;">
        <i class="fas fa-calendar-plus"></i> Check Availability
    </a>
</div>
<div style="text-align: center; margin-top: 6px;">
    <button class="btn-custom" onclick="showRouteFromMap(${house.id}, ${lat}, ${lng}, '${house.name}')" style="width: 100%; background: linear-gradient(135deg, #4A90E2 0%, #357abd 100%); color: white;">
        <i class="fas fa-route"></i> Show Route
    </button>
</div>
                    </div>
                </div>
            `;

            const infoWindow = new google.maps.InfoWindow({
                content: infoWindowContent,
                maxWidth: 280
            });

            houseMarker.addListener('click', function() {
                // Close any open InfoWindow before opening a new one (optional, but good practice)
                allMarkers.forEach(m => {
                    if (m.infoWindow) m.infoWindow.close();
                });
                
                infoWindow.open(map, houseMarker);
                // Clear any existing route when opening a new marker
                clearDirectionsRoute();
                
                // NEW: Re-bind event listener after InfoWindow opens, as content is recreated
                google.maps.event.addListener(infoWindow, 'domready', function() {
                    const favBtn = document.querySelector(`.custom-popup [data-house-id="${house.id}"]`);
                    if (favBtn) {
                        favBtn.onclick = function() {
                            toggleFavorite(this, house.id, true);
                        };
                    }
                });
            });
            
            return {
                marker: houseMarker,
                house: house,
                infoWindow: infoWindow // Store InfoWindow for closing later
            };
        }


        function initMap() {
            
            map = new google.maps.Map(document.getElementById('map'), {
                center: window.STAYFINDER_DATA.defaultCenter,
                zoom: 13,
                mapTypeId: google.maps.MapTypeId.HYBRID,
                mapTypeControl: true,
                mapTypeControlOptions: {
                    style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
                    position: google.maps.ControlPosition.TOP_CENTER,
                    mapTypeIds: [
                        google.maps.MapTypeId.ROADMAP,
                        google.maps.MapTypeId.SATELLITE,
                        google.maps.MapTypeId.HYBRID,
                        google.maps.MapTypeId.TERRAIN
                    ]
                },
                zoomControl: true,
                zoomControlOptions: {
                    position: google.maps.ControlPosition.RIGHT_CENTER
                },
                fullscreenControl: true,
                streetViewControl: true
            });
            
            // Initial call to display ALL houses on map load
            displayAllHousesOnMap();

            // Setup SearchBox
            const input = document.getElementById('searchInput');
            searchBox = new google.maps.places.SearchBox(input);

            map.addListener('bounds_changed', () => {
                searchBox.setBounds(map.getBounds());
            });

            searchBox.addListener('places_changed', () => {
                const places = searchBox.getPlaces();

                if (places.length === 0) {
                    return;
                }

                const place = places[0];
                const newLocation = {
                    name: place.formatted_address || place.name,
                    lat: place.geometry.location.lat(),
                    lng: place.geometry.location.lng()
                };
                
                // Clear user location marker/data if a search is performed
                clearUserLocation();
                
                lastSearchedLocation = newLocation;

                // 1. Update the filter display
                document.getElementById('searchedLocation').textContent = lastSearchedLocation.name;
                document.getElementById('locationFilterDisplay').classList.add('active');

                // 2. Center map on searched location
                const bounds = new google.maps.LatLngBounds();
                places.forEach((place) => {
                    if (place.geometry && place.geometry.location) {
                        bounds.extend(place.geometry.location);
                    }
                });
                map.fitBounds(bounds);
                
                // 3. Filter and display houses
                updateMapAndHouseCards(lastSearchedLocation); 
            });

            // Initial load: Render the initial placeholder message (as no location is searched)
            renderHouseCards(); 
        }

        function displayAllHousesOnMap() {
            // Clear existing markers
            allMarkers.forEach((markerData) => {
                markerData.marker.setMap(null);
            });
            allMarkers = [];
            
            // Loop through all houses and create markers without filtering
            window.STAYFINDER_DATA.boardingHouses.forEach(house => {
                const markerData = createHouseMarker(house);
                if (markerData) {
                    allMarkers.push(markerData);
                }
            });
        }
        
        function clearRoutePolyline() {
            if (activeRoutePolyline) {
                activeRoutePolyline.setMap(null);
                activeRoutePolyline = null;
            }
        }
        
        function clearUserLocation() {
            if (userLocationMarker) {
                userLocationMarker.setMap(null);
                userLocationMarker = null;
            }
            userLocation = null;
            clearRoutePolyline();
        }

        function calculateDistance(lat1, lng1, lat2, lng2) {
            const R = 6371; // Earth's radius in km
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLng = (lng2 - lng1) * Math.PI / 180;
            const a = 
                Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLng / 2) * Math.sin(dLng / 2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            return R * c;
        }

        /**
         * The unified function to filter houses, update markers, and render cards.
         * @param {object} searchCenter - The location to filter around (lastSearchedLocation or userLocation).
         */
        function updateMapAndHouseCards(searchCenter) {
            
            // 1. Clear old markers and polylines
            allMarkers.forEach((markerData) => {
                markerData.marker.setMap(null);
            });
            allMarkers = [];
            visibleHouses = [];
            clearRoutePolyline();

            // Only proceed with filtering if a search location (manual or user location) exists
            if (!searchCenter) {
                // If no search center, display all markers back and stop here.
                displayAllHousesOnMap();
                renderHouseCards(); // Will show default or current message
                return;
            }

            const newBounds = new google.maps.LatLngBounds();
            newBounds.extend(new google.maps.LatLng(searchCenter.lat, searchCenter.lng)); // Include the search center

            // 2. Filter houses and create/display new markers
            window.STAYFINDER_DATA.boardingHouses.forEach((house) => {
                if (house.map_lat && house.map_lng) {
                    const lat = Number.parseFloat(house.map_lat);
                    const lng = Number.parseFloat(house.map_lng);

                    if (isNaN(lat) || isNaN(lng)) {
                        return; 
                    }

                    const distance = calculateDistance(
                        searchCenter.lat,
                        searchCenter.lng,
                        lat,
                        lng
                    );
                    
                    if (distance <= SEARCH_RADIUS_KM) {
                        visibleHouses.push(house);
                        
                        // Create and add the marker for the filtered house
                        const markerData = createHouseMarker(house);
                        if(markerData) {
                            allMarkers.push(markerData);
                            newBounds.extend(markerData.marker.getPosition());
                        }
                    }
                }
            });

            // If user location is active, ensure the blue dot is displayed
            if (userLocation && userLocationMarker) {
                 userLocationMarker.setMap(map);
                 newBounds.extend(userLocationMarker.getCenter()); // Ensure the blue dot is in view
            }


            // 3. Render the cards for the filtered houses
            renderHouseCards();

            // 4. Adjust the map bounds to fit all filtered markers and the center
            if (!newBounds.isEmpty()) {
                map.fitBounds(newBounds);
            }
        }

        function renderHouseCards() {
            const housesContainer = document.getElementById('housesContainer');
            const searchCenter = lastSearchedLocation || userLocation;

            // If no search center is set, show the default message
            if (!searchCenter) {
                // If no search is active, visibleHouses should be all houses, but the message should reflect that the list is based on the initial view.
                // However, based on user request, we want the default state to show the prompt.
                housesContainer.innerHTML = `
                    <div class="no-houses-message">
                        <i class="fas fa-map-marker-alt"></i>
                        <p>Search for a location on the map or use your current location to see available boarding houses nearby</p>
                    </div>
                `;
                return;
            }
            
            const locationName = lastSearchedLocation ? `**${lastSearchedLocation.name}**` : `your **Current Location**`;

            if (visibleHouses.length === 0) {
                housesContainer.innerHTML = `
                    <div class="no-houses-message">
                        <i class="fas fa-map-marker-alt"></i>
                        <p>No boarding houses found near ${locationName} within ${SEARCH_RADIUS_KM}km.</p>
                    </div>
                `;
                return;
            }

            // Render cards for filtered houses
            housesContainer.innerHTML = visibleHouses.map(house => {
                const images = house.images ? house.images.split(",") : [];
                const firstImage = images.length > 0 ? images[0].trim() : "img/default.jpg";
                
                let priceDisplay = "Contact for Price";
                if (house.min_price && house.max_price) {
                    const minPrice = Number.parseFloat(house.min_price);
                    const maxPrice = Number.parseFloat(house.max_price);
                    if (minPrice > 0 && maxPrice > 0) {
                        if (minPrice === maxPrice) {
                            priceDisplay = `₱${minPrice.toLocaleString()}`;
                        } else {
                            priceDisplay = `₱${minPrice.toLocaleString()} - ₱${maxPrice.toLocaleString()}`;
                        }
                    }
                }

                const ratingDisplay = house.avg_rating > 0 ? `${Number.parseFloat(house.avg_rating).toFixed(1)} ⭐` : "No ratings yet";
                
                const isFav = isFavorite(house.id);
                const favoriteClass = isFav ? 'is-favorite' : '';

    return `
    <div class="house-card" data-house-id="${house.id}">
        <button class="favorite-btn ${favoriteClass}" data-house-id="${house.id}" onclick="toggleFavorite(this, ${house.id}, false)" title="${isFav ? 'Remove from Favorites' : 'Add to Favorites'}">
            <i class="${isFav ? 'fas fa-heart' : 'far fa-heart'}"></i>
        </button>
        <div class="house-card-image">
            <img src="${firstImage}" alt="${house.name}" onerror="this.src='img/default.jpg'">
            <div class="house-price-badge">${priceDisplay}</div>
        </div>
        <div class="house-card-content">
            <div class="house-card-name">${house.name}</div>
            <div class="house-card-location">
                <i class="fas fa-map-marker-alt"></i>
                ${house.full_location || house.purok || 'Location'}
            </div>
            <div class="house-card-footer" style="display: flex; flex-direction: column; gap: 8px;">
                <div class="house-rating">${ratingDisplay}</div>
                <div style="display: flex; gap: 8px;">
                    <a href="accommodationoverview/view_house_details.php?id=${house.id}" class="view-btn" style="flex: 1; text-align: center;">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                    <a href="bookform/book_house.php?house_id=${house.id}" class="view-btn" style="flex: 1; text-align: center; background: linear-gradient(135deg, #FFFF00 0%, #FFD700 100%); color: #000;">
                        <i class="fas fa-calendar-plus"></i> Check Availability
                    </a>
                </div>
            </div>
        </div>
    </div>
`;
            }).join('');
        }
        
        // --- NEW: Favorite Logic and Toast Notification Functions ---
        
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toastNotification');
            toast.textContent = message;
            toast.className = `toast-notification show ${type}`;
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        /**
         * Toggles a house between favorited and unfavorited status via AJAX.
         * @param {HTMLElement} element - The button element that was clicked.
         * @param {number} houseId - The ID of the boarding house.
         * @param {boolean} isPopup - True if the element is from the map InfoWindow popup.
         */
        async function toggleFavorite(element, houseId, isPopup) {
            const isCurrentlyFavorite = isFavorite(houseId);
            const action = isCurrentlyFavorite ? 'remove' : 'add';
            
            // Visually update the icon immediately (optimistic update)
            updateFavoriteIcon(element, !isCurrentlyFavorite, houseId);
            
            // Send AJAX request
            try {
                const response = await fetch('handle_favorite.php', { // MUST CREATE THIS FILE
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `house_id=${houseId}&action=${action}&user_email=${window.STAYFINDER_DATA.userEmail}`
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const result = await response.json();
                
                if (result.success) {
                    // Update global state
                    const houseIdInt = parseInt(houseId);
                    if (action === 'add') {
                        window.STAYFINDER_DATA.favoriteHouseIds.push(houseIdInt);
                        showToast('Added to Wishlist! 🎉', 'success');
                    } else {
                        window.STAYFINDER_DATA.favoriteHouseIds = window.STAYFINDER_DATA.favoriteHouseIds.filter(id => id !== houseIdInt);
                        showToast('Removed from Wishlist.', 'removed');
                    }
                    
                    // Re-sync all heart icons on the page (to handle multiple cards/popups open)
                    updateAllFavoriteIcons(houseIdInt);

                } else {
                    // If server fails, revert the icon state
                    updateFavoriteIcon(element, isCurrentlyFavorite, houseId);
                    showToast('Error: Could not update wishlist. ' + (result.message || ''), 'removed');
                    console.error('Server error:', result.message);
                }

            } catch (error) {
                // If AJAX fails, revert the icon state
                updateFavoriteIcon(element, isCurrentlyFavorite, houseId);
                showToast('Network error. Check console.', 'removed');
                console.error('Fetch error:', error);
            }
        }
        
        /**
         * Updates the visual state of a specific favorite icon button.
         * @param {HTMLElement} element - The button element to update.
         * @param {boolean} isFav - The new favorite status.
         */
        function updateFavoriteIcon(element, isFav, houseId) {
            const icon = element.querySelector('i');
            if (isFav) {
                element.classList.add('is-favorite');
                icon.className = 'fas fa-heart';
                element.title = 'Remove from Favorites';
            } else {
                element.classList.remove('is-favorite');
                icon.className = 'far fa-heart';
                element.title = 'Add to Favorites';
            }
        }
        
        /**
         * Finds and updates all favorite buttons for a specific house ID on the page.
         * @param {number} houseId - The ID of the boarding house.
         */
        function updateAllFavoriteIcons(houseId) {
            const isFav = isFavorite(houseId);
            const allButtons = document.querySelectorAll(`[data-house-id="${houseId}"]`);
            allButtons.forEach(btn => {
                updateFavoriteIcon(btn, isFav, houseId);
            });
        }

        // --- END NEW: Favorite Logic and Toast Notification Functions ---


        function resetLocationFilter() {
            lastSearchedLocation = null;
            document.getElementById('searchInput').value = '';
            document.getElementById('locationFilterDisplay').classList.remove('active');
            
            // Clear user location data and marker/polyline
            clearUserLocation();
            
            // Reset map to default center and zoom
            map.setCenter(window.STAYFINDER_DATA.defaultCenter);
            map.setZoom(13);
            
            // Display all houses on the map again
            displayAllHousesOnMap();
            
            // Update cards to show initial 'Search' message
            renderHouseCards(); 
        }

        function useMyLocation() {
            if (navigator.geolocation) {
                // Indicate loading state (optional)
                document.getElementById('useMyLocationButton').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Locating...';
                document.getElementById('useMyLocationButton').disabled = true;

                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const pos = {
                            lat: position.coords.latitude,
                            lng: position.coords.longitude,
                        };
                        
                        // Clear any manual search data
                        lastSearchedLocation = null;
                        document.getElementById('searchInput').value = '';
                        clearRoutePolyline();

                        // Set and mark user location
                        userLocation = {
                            name: 'My Current Location',
                            lat: pos.lat,
                            lng: pos.lng
                        };
                        
                        // Remove previous user marker if it exists
                        clearUserLocation(); 

                        // Use the blue circle (Circle object) for the user's location dot
                        userLocationMarker = new google.maps.Circle({
                            strokeColor: '#4A90E2',
                            strokeOpacity: 0.8,
                            strokeWeight: 2,
                            fillColor: '#4A90E2',
                            fillOpacity: 0.35,
                            map: map,
                            center: pos,
                            radius: 50 // small radius for the blue dot
                        });
                        
                        // Update filter display
                        document.getElementById('searchedLocation').textContent = 'My Current Location';
                        document.getElementById('locationFilterDisplay').classList.add('active');

                        // Center map and find houses
                        map.setCenter(pos);
                        map.setZoom(15); 
                        updateMapAndHouseCards(userLocation);
                        
                        // Reset button state
                        document.getElementById('useMyLocationButton').innerHTML = '<i class="fas fa-crosshairs"></i> Use My Location';
                        document.getElementById('useMyLocationButton').disabled = false;
                    },
                    (error) => {
                        console.error('Geolocation Error:', error);
                        alert('Error: The Geolocation service failed. Please ensure location services are enabled or try searching manually.');
                        // Reset button state
                        document.getElementById('useMyLocationButton').innerHTML = '<i class="fas fa-crosshairs"></i> Use My Location';
                        document.getElementById('useMyLocationButton').disabled = false;
                    }
                );
            } else {
                alert('Error: Your browser does not support Geolocation. Please search manually.');
            }
        }
        
        function drawRoutePolyline(origin, destination) {
            // Clear existing polyline first
            clearRoutePolyline();
            
            // Draw a simple straight line (Polyline)
            activeRoutePolyline = new google.maps.Polyline({
                path: [
                    new google.maps.LatLng(origin.lat, origin.lng),
                    new google.maps.LatLng(destination.lat, destination.lng)
                ],
                geodesic: true,
                strokeColor: '#4A90E2', // Blue color for the line
                strokeOpacity: 0.8,
                strokeWeight: 4,
                map: map
            });
        }

        let directionsService = null;
        let directionsRenderer = null;

        function clearDirectionsRoute() {
            if (directionsRenderer) {
                directionsRenderer.setDirections({ routes: [] });
            }
        }

        function showRouteFromMap(houseId, houseLat, houseLng, houseName) {
            // If user location is not available, get it first
            if (!userLocation) {
                if (navigator.geolocation) {
                    showToast('Getting your current location...', 'success');
                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            userLocation = {
                                name: 'My Current Location',
                                lat: position.coords.latitude,
                                lng: position.coords.longitude
                            };
                            
                            // Remove previous user marker if it exists
                            if (userLocationMarker) {
                                userLocationMarker.setMap(null);
                            }

                            // Create the user location marker
                            userLocationMarker = new google.maps.Circle({
                                strokeColor: '#4A90E2',
                                strokeOpacity: 0.8,
                                strokeWeight: 2,
                                fillColor: '#4A90E2',
                                fillOpacity: 0.35,
                                map: map,
                                center: { lat: userLocation.lat, lng: userLocation.lng },
                                radius: 50
                            });
                            
                            // Now calculate and show the route
                            calculateAndShowRoute(userLocation, houseLat, houseLng, houseName);
                        },
                        (error) => {
                            showToast('Could not get your location. Please enable location services.', 'removed');
                            console.error('Geolocation Error:', error);
                        }
                    );
                } else {
                    showToast('Geolocation not supported. Please use "My Current Location" first.', 'removed');
                }
                return;
            }

            // User location is already available, calculate route directly
            calculateAndShowRoute(userLocation, houseLat, houseLng, houseName);
        }

        function calculateAndShowRoute(userLocation, houseLat, houseLng, houseName) {
            // Initialize directions service if not already done
            if (!directionsService) {
                directionsService = new google.maps.DirectionsService();
                directionsRenderer = new google.maps.DirectionsRenderer({
                    map: map,
                    suppressMarkers: true,
                    polylineOptions: {
                        strokeColor: '#4A90E2',
                        strokeOpacity: 0.8,
                        strokeWeight: 5
                    }
                });
            } else {
                // Clear previous route before showing new one
                directionsRenderer.setDirections({ routes: [] });
            }

            const request = {
                origin: { lat: userLocation.lat, lng: userLocation.lng },
                destination: { lat: parseFloat(houseLat), lng: parseFloat(houseLng) },
                travelMode: google.maps.TravelMode.DRIVING
            };

            directionsService.route(request, function(result, status) {
                if (status === 'OK') {
                    directionsRenderer.setDirections(result);
                    
                    // Get route details
                    const route = result.routes[0];
                    const leg = route.legs[0];
                    const distance = leg.distance.text;
                    const duration = leg.duration.text;

                    showToast(`Route to ${houseName}: ${distance} • ${duration}`, 'success');
                    
                    // Fit map bounds to show the entire route
                    const bounds = new google.maps.LatLngBounds();
                    result.routes[0].overview_path.forEach(point => bounds.extend(point));
                    map.fitBounds(bounds);
                } else {
                    showToast('Could not calculate route. Please try again.', 'removed');
                    console.error('Directions request failed: ' + status);
                }
            });
        }


        window.addEventListener('load', initMap);
    </script>
</body>
</html>