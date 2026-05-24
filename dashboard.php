<?php
session_start();

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    session_start();
}

$is_guest_mode = isset($_GET['guest']) && $_GET['guest'] == '1';
$is_logged_in = isset($_SESSION['email']) && !empty($_SESSION['email']) && !$is_guest_mode;

if ($is_guest_mode) {
    $name = 'Guest Seeker';
    $email = 'Browse as Seeker';
    $profile_img = '';
} else {
    $name = $is_logged_in ? $_SESSION['name'] : 'Guest';
    $email = $is_logged_in ? $_SESSION['email'] : 'Not logged in';
    $profile_img = $is_logged_in && isset($_SESSION['profile_img']) ? $_SESSION['profile_img'] : '';
}

require_once __DIR__ . '/connectiondatabase/main_connection.php';

if (!$conn) {
    die("Database connection failed.");
}

if (empty($profile_img) && $is_logged_in) {
    $stmt = $conn->prepare("SELECT profile_img FROM registerusers WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $profile_img = $row['profile_img'];
        $_SESSION['profile_img'] = $profile_img;
    }
    $stmt->close();
}

$create_ratings_table = "
CREATE TABLE IF NOT EXISTS house_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    house_id INT NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_rating (house_id, user_email),
    FOREIGN KEY (house_id) REFERENCES boarding_houses(id) ON DELETE CASCADE
)";
$conn->query($create_ratings_table);

$create_favorites_table = "
CREATE TABLE IF NOT EXISTS favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    house_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_favorite (user_email, house_id),
    FOREIGN KEY (house_id) REFERENCES boarding_houses(id) ON DELETE CASCADE
)";
$conn->query($create_favorites_table);

$min_price_filter = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? floatval($_GET['min_price']) : null;
$max_price_filter = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? floatval($_GET['max_price']) : null;

// <CHANGE> Fixed location display - now using bh.full_location instead of owner.user_address
$query = "
    SELECT bh.*,
           COALESCE(AVG(hr.rating), 0) as avg_rating,
           COUNT(hr.rating) as total_ratings,
           COALESCE(price_stats.min_price, NULL) as min_price,
           COALESCE(price_stats.max_price, NULL) as max_price,
           COALESCE(bh.full_location, 'Not specified') as owner_address
    FROM boarding_houses bh
    LEFT JOIN house_ratings hr ON bh.id = hr.house_id
    LEFT JOIN (
        SELECT house_id, MIN(price) as min_price, MAX(price) as max_price
        FROM room_prices
        WHERE price IS NOT NULL AND price > 0
        GROUP BY house_id
    ) price_stats ON bh.id = price_stats.house_id
    WHERE bh.status = 'approved'
";

$where_conditions = [];
if ($min_price_filter !== null) {
    $where_conditions[] = "COALESCE(price_stats.min_price, 0) >= " . $min_price_filter;
}
if ($max_price_filter !== null) {
    $where_conditions[] = "COALESCE(price_stats.max_price, 999999) <= " . $max_price_filter;
}

if (!empty($where_conditions)) {
    $query .= " AND " . implode(" AND ", $where_conditions);
}

$query .= " GROUP BY bh.id ORDER BY bh.created_at DESC";

$result = $conn->query($query);
$database_houses = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$user_favorites = [];
if ($is_logged_in) {
    $stmt = $conn->prepare("SELECT house_id FROM favorites WHERE user_email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $favorites_result = $stmt->get_result();
    while ($row = $favorites_result->fetch_assoc()) {
        $user_favorites[] = $row['house_id'];
    }
    $stmt->close();
}

if (isset($_POST['action']) && $_POST['action'] === 'toggle_favorite' && $is_logged_in) {
    $house_id = intval($_POST['house_id']);

    $stmt = $conn->prepare("SELECT id FROM favorites WHERE user_email = ? AND house_id = ?");
    $stmt->bind_param("si", $email, $house_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare("DELETE FROM favorites WHERE user_email = ? AND house_id = ?");
        $stmt->bind_param("si", $email, $house_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'removed']);
    } else {
        $stmt = $conn->prepare("INSERT INTO favorites (user_email, house_id) VALUES (?, ?)");
        $stmt->bind_param("si", $email, $house_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'added']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StayFinderDashboard</title>
   <link rel="icon" href="img/494819260_1450448046102632_2947403985636425514_n (1).jpg" type="image/jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>

    <div id="showSideMenu" class="sideMenu">
        <div class="closeButton" id="closeMenu">
            <i class="fas fa-times"></i>
        </div>

        <div class="profile-section">

        </div>

        <div class="menuList">
            <?php if (!$is_logged_in): ?>
                <div class="menuGroup">
                    <a href="auseregisterlogform/register.php">
                        <i class="fas fa-user-plus"></i> Register
                    </a>
                    <a href="auseregisterlogform/login.php">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($is_logged_in && !$is_guest_mode): ?>
            <div class="menuGroup">
                <div class="groupTitle">My Account</div>
                <a href="dashboardprofile.php">
                    <i class="fas fa-user-circle"></i> My Profile
                </a>
            </div>
            <?php endif; ?>

        </div>
    </div>


    <div class="mainWrapper" id="mainWrapper">
        <div class="topBar">
            <div id="openMenu">
                <i class="fas fa-bars"></i>
            </div>
            <div class="titleNgSite">STAYFINDER</div>
        </div>

        <h2 class="pageHeader">Available Boarding Houses</h2>

        <?php if ($is_guest_mode): ?>
        <div class="price-filter-container">
            <div class="price-filter-box">
                <div class="price-filter-title">
                    <i class="fas fa-filter"></i>
                    Filter by Price Range
                </div>

                <form method="GET" action="dashboard.php" class="price-filter-form">
                    <!-- Preserve guest mode parameter in filter form -->
                    <input type="hidden" name="guest" value="1">
                    
                    <div class="price-input-group">
                        <label for="min_price">Minimum Price (₱)</label>
                        <input
                            type="number"
                            id="min_price"
                            name="min_price"
                            placeholder="e.g., 1000"
                            value="<?php echo $min_price_filter !== null ? $min_price_filter : ''; ?>"
                            min="0"
                            step="100"
                        >
                    </div>

                    <div class="price-input-group">
                        <label for="max_price">Maximum Price (₱)</label>
                        <input
                            type="number"
                            id="max_price"
                            name="max_price"
                            placeholder="e.g., 5000"
                            value="<?php echo $max_price_filter !== null ? $max_price_filter : ''; ?>"
                            min="0"
                            step="100"
                        >
                    </div>

                    <div class="price-filter-buttons">
                        <button type="submit" class="filter-btn filter-btn-apply">
                            <i class="fas fa-search"></i>
                            Apply Filter
                        </button>
                        <a href="dashboard.php?guest=1" class="filter-btn filter-btn-clear">
                            <i class="fas fa-times"></i>
                            Clear Filter
                        </a>
                    </div>
                </form>

                <?php if ($min_price_filter !== null || $max_price_filter !== null): ?>
                    <div class="active-filters">
                        <strong>Active Filters:</strong>
                        <?php if ($min_price_filter !== null): ?>
                            <span class="filter-badge">Min: ₱<?php echo number_format($min_price_filter, 2); ?></span>
                        <?php endif; ?>
                        <?php if ($max_price_filter !== null): ?>
                            <span class="filter-badge">Max: ₱<?php echo number_format($max_price_filter, 2); ?></span>
                        <?php endif; ?>
                        <span style="margin-left: 8px;">(<?php echo count($database_houses); ?> result<?php echo count($database_houses) !== 1 ? 's' : ''; ?> found)</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($database_houses)): ?>
            <div class="no-results">
                <i class="fas fa-search"></i>
                <h3>No Boarding Houses Found</h3>
                <p>We couldn't find any boarding houses matching your price range. Try adjusting your filters or <a href="dashboard.php<?php echo $is_guest_mode ? '?guest=1' : ''; ?>" style="color: #FFD700; font-weight: 600;">clear all filters</a> to see all available properties.</p>
            </div>
        <?php else: ?>
            <div class="listaBahay">
                <?php foreach ($database_houses as $house):
                    $images = explode(',', $house['images']);
                    $first_image = !empty($images[0]) ? trim($images[0]) : 'img/default.jpg';
                    $is_favorited = in_array($house['id'], $user_favorites);

                    $avg_rating = round($house['avg_rating'], 1);
                    $total_ratings = $house['total_ratings'];

                    $min_price = $house['min_price'];
                    $max_price = $house['max_price'];
                ?>
                    <div class="bahayCard">
                        <img src="<?php echo htmlspecialchars($first_image); ?>" alt="<?php echo htmlspecialchars($house['name']); ?>">


                        <?php if ($is_logged_in && !$is_guest_mode): ?>
                            <div class="favorite-heart <?php echo $is_favorited ? 'favorited' : ''; ?>"
                                 data-house-id="<?php echo $house['id']; ?>"
                                 onclick="toggleFavorite(this, <?php echo $house['id']; ?>)">
                                <i class="fas fa-heart"></i>
                            </div>
                        <?php else: ?>
                            <div class="favorite-heart guest-mode"
                                 title="Login to save favorites">
                                <i class="fas fa-heart"></i>
                            </div>
                        <?php endif; ?>

                        <div>
                            <h4><?php echo strtoupper(htmlspecialchars($house['name'])); ?></h4>


                            <div class="propertydetail">
                                <strong>📍 Full Location:</strong>
                                <p class="mb-0">
                                    <i class="fas fa-map-marker-alt me-2"></i>
                                    <?php 
                                    $location = $house['owner_address'] ?? 'Not specified';
                                    echo htmlspecialchars($location);
                                    ?>
                                </p>
                            </div>


                            <br>


                            <div class="bahay-amenities">
                                <h5><i class="fas fa-star"></i>RULES:</h5>
                                <div class="propertydescription"><?php echo htmlspecialchars(preg_replace('/\[OWNER:[^\]]+\]/', '', $house['description'])); ?></div>
                            </div>


                            <div class="rating-display">
                                <div class="rating-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span class="star <?php echo $i <= $avg_rating ? '' : 'empty'; ?>">
                                            <?php echo $i <= $avg_rating ? '⭐' : '☆'; ?>
                                        </span>
                                    <?php endfor; ?>
                                </div>
                                <div class="rating-info">
                                    <span class="rating-score"><?php echo $avg_rating; ?>/5</span>
                                    <span class="rating-count">(<?php echo $total_ratings; ?> rating<?php echo $total_ratings !== 1 ? 's' : ''; ?>)</span>
                                </div>
                            </div>


                            <div class="bahay-contact-info" style="margin-bottom: 16px; font-size: 15px;">
                                <?php if (!empty($house['owner_email']) || !empty($house['owner_phone'])): ?>
                                    <?php if (!empty($house['owner_email'])): ?>
                                        <div style="margin-bottom: 4px;">
                                            <i class="fas fa-envelope"></i>
                                            <strong>OwnerEmail:</strong>
                                            <a href="mailto:<?= htmlspecialchars($house['owner_email']) ?>">
                                                <?= htmlspecialchars($house['owner_email']) ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($house['owner_phone'])): ?>
                                        <div>
                                            <i class="fas fa-phone"></i>
                                            <strong>OwnerPhone:</strong>
                                            <a href="tel:<?= htmlspecialchars($house['owner_phone']) ?>">
                                                <?= htmlspecialchars($house['owner_phone']) ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div style="color: #888; font-style: italic;">Owner contact details not available.</div>
                                <?php endif; ?>
                            </div>


                            <div class="price-range-info">
                                <i class="fas fa-tag"></i>
                                <strong>Price Range:</strong>
                                <?php if ($min_price !== null && $max_price !== null): ?>
                                    <?php if ($min_price == $max_price): ?>
                                        <span>₱<?php echo number_format($min_price, 2); ?>/month</span>
                                    <?php else: ?>
                                        <span>₱<?php echo number_format($min_price, 2); ?> - ₱<?php echo number_format($max_price, 2); ?>/month</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #666; font-style: italic;">Contact owner for pricing</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($is_guest_mode): ?>
                                <!-- Pass guest=1 parameter to book_house.php to preserve guest mode -->
                                <a href="bookform/book_house.php?house_id=<?php echo $house['id']; ?>&guest=1" class="bookNa">
                                    <i class="fas fa-calendar-plus"></i> Register to Reserve
                                </a>
                                <a href="accommodationoverview/view_house_details.php?id=<?php echo $house['id']; ?>&guest=1" style="margin-top: 10px; display: block;">
                                    <button class="bookNa" style="background: linear-gradient(135deg, #4CAF50, #45a049); width: 100%;">
                                        <i class="fas fa-info-circle"></i> See More Details
                                    </button>
                                </a>
                            <?php else: ?>
                                <a href="bookform/book_house.php?house_id=<?php echo $house['id']; ?>">
                                    <button class="bookNa">
                                        <i class="fas fa-calendar-plus"></i> Check Availability
                                    </button>
                                </a>
                                <a href="accommodationoverview/view_house_details.php?id=<?php echo $house['id']; ?>" style="margin-top: 10px; display: block;">
                                    <button class="bookNa" style="background: linear-gradient(135deg, #4CAF50, #45a049); width: 100%;">
                                        <i class="fas fa-info-circle"></i> See More Details
                                    </button>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="bottomPart">
            <div> Stay Finder | Boarding | House Finder</div>
        </div>
    </div>


    <div id="toast" class="toast"></div>

    <script>
        const openMenu = document.getElementById('openMenu');
        const showSideMenu = document.getElementById('showSideMenu');
        const closeMenu = document.getElementById('closeMenu');
        const mainWrapper = document.getElementById('mainWrapper');

        openMenu.addEventListener('click', function() {
            const isActive = showSideMenu.classList.contains('active');
            
            if (isActive) {
                // Close sidebar
                showSideMenu.classList.remove('active');
                mainWrapper.classList.remove('shifted');
            } else {
                // Open sidebar
                showSideMenu.classList.add('active');
                if (window.innerWidth > 768) {
                    mainWrapper.classList.add('shifted');
                }
            }
        });

        closeMenu.addEventListener('click', function() {
            showSideMenu.classList.remove('active');
            mainWrapper.classList.remove('shifted');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768 &&
                !showSideMenu.contains(event.target) &&
                !openMenu.contains(event.target) &&
                showSideMenu.classList.contains('active')) {
                showSideMenu.classList.remove('active');
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth <= 768) {
                mainWrapper.classList.remove('shifted');
            } else if (showSideMenu.classList.contains('active')) {
                mainWrapper.classList.add('shifted');
            }
        });

        // Favorites functionality
        function toggleFavorite(heartElement, houseId) {
            <?php if (!$is_logged_in || $is_guest_mode): ?>
                showToast('Please login to save favorites', 'warning');
                return;
            <?php endif; ?>

            const formData = new FormData();
            formData.append('action', 'toggle_favorite');
            formData.append('house_id', houseId);

            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'added') {
                    heartElement.classList.add('favorited');
                    showToast('Added to favorites!', 'success');
                } else if (data.status === 'removed') {
                    heartElement.classList.remove('favorited');
                    showToast('Removed from favorites', 'info');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred. Please try again.', 'error');
            });
        }

        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.classList.add('show');

            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
    </script>
</body>
</html>